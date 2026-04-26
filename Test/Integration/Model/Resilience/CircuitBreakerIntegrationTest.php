<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Integration\Model\Resilience;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Shubo\ShippingCore\Api\CircuitBreakerInterface;
use Shubo\ShippingCore\Api\Data\CircuitBreakerStateInterface;
use Shubo\ShippingCore\Model\Resilience\CircuitBreakerStateRepository;
use Shubo\ShippingCore\Test\Integration\Model\Resilience\Fixtures\EventCapture;

// Like RateLimiterConcurrencyTest this is a real-Magento smoke test that boots
// the installed duka instance directly — we are not running through Magento's
// integration test framework (which reinstalls a throwaway DB on every run).
// That means we talk to the real MySQL, real event manager, and the real
// shubo_shipping.log file the container writes to.
// phpcs:disable Magento2.Security.InsecureFunction.Found

/**
 * End-to-end smoke test for {@see \Shubo\ShippingCore\Model\Resilience\CircuitBreaker}.
 *
 * Unit tests (see {@see \Shubo\ShippingCore\Test\Unit\Model\Resilience\CircuitBreakerTest})
 * already exhaustively cover the state-machine logic with mocks. What they
 * CANNOT catch is a broken DI wiring, a missing db_schema column, or a
 * logger virtual-type that no longer resolves — all of which would ship to
 * production without the breaker ever actually transitioning.
 *
 * This test proves, against real infrastructure, that:
 * 1. {@see CircuitBreakerInterface} resolves from DI with all collaborators wired.
 * 2. Five consecutive callable failures persist a row in `shubo_shipping_circuit_breaker`
 *    with state=open and populated timing fields.
 * 3. The transition dispatches `shubo_shipping_carrier_breaker_opened` with the
 *    expected payload (captured via a spy EventManager swapped into the shared-
 *    instances cache before breaker construction).
 * 4. {@see \Shubo\ShippingCore\Model\Logging\StructuredLogger::logBreakerTransition()}
 *    emits a JSON log line to `var/log/shubo_shipping.log` tagged with the
 *    expected `event`, `carrier_code`, `from`, and `to` fields.
 * 5. The admin override path ({@see CircuitBreakerInterface::forceState()}) resets
 *    the row and logs the forced transition with `metadata.forced === true`.
 */
class CircuitBreakerIntegrationTest extends TestCase
{
    private const CARRIER = 'int_test_carrier';
    private const CONFIG_FAILURE_THRESHOLD = 'shubo_shipping/breaker/failure_threshold';
    private const CONFIG_FAILURE_WINDOW = 'shubo_shipping/breaker/failure_window_seconds';
    private const CONFIG_COOLDOWN = 'shubo_shipping/breaker/cooldown_seconds';
    private const FAILURE_THRESHOLD = 5;

    private const CONTAINER_BP = '/var/www/html';
    private const LOG_PATH = self::CONTAINER_BP . '/var/log/shubo_shipping.log';

    /** @var PDO Direct MySQL connection used to seed/clean fixture rows without touching DI. */
    private PDO $pdo;

    /** @var ObjectManagerInterface Real production object manager booted against the installed duka instance. */
    private ObjectManagerInterface $objectManager;

    /** @var EventCapture Spy that wraps the real EventManager and records every dispatch. */
    private EventCapture $eventCapture;

    protected function setUp(): void
    {
        $this->pdo = $this->openConnection();

        // Reset any state from a previous run before the breaker is constructed
        // so the first `execute()` starts from a synthetic closed row.
        $this->truncateBreakerRow(self::CARRIER);

        // Pin the breaker config to known defaults so this test is hermetic
        // regardless of any site-wide overrides in core_config_data.
        $this->upsertScopeConfig(self::CONFIG_FAILURE_THRESHOLD, (string)self::FAILURE_THRESHOLD);
        $this->upsertScopeConfig(self::CONFIG_FAILURE_WINDOW, '120');
        $this->upsertScopeConfig(self::CONFIG_COOLDOWN, '60');

        // Truncate the dedicated Shubo shipping log so we read only this
        // test's lines when asserting. This file is the dedicated channel
        // wired via the `ShuboShippingCoreLogHandler` virtual type in
        // etc/di.xml:20-24.
        $this->truncateLog();

        // Boot Magento once against the installed duka instance — same
        // approach RateLimiterConcurrencyTest uses (see class docblock for
        // the rationale).
        $this->objectManager = $this->bootMagento();

        // Flush any previously-cached config so the values we just wrote to
        // core_config_data are observed by the breaker. We go through the
        // ScopeConfig cache manually instead of bin/magento cache:clean to
        // avoid spawning a subprocess.
        $this->objectManager->get(ScopeConfigInterface::class)->clean();

        // Install the EventManager spy so that CircuitBreaker's dispatch
        // calls are recorded. Note on indirection:
        //   - app/etc/di.xml:70 declares the preference
        //     EventManagerInterface -> Magento\Framework\Event\Manager\Proxy
        //   - CircuitBreaker's generated factory (see InterceptionFactory
        //     output via ConfigInterface::getArguments) explicitly requests
        //     `Magento\Framework\Event\Manager\Proxy` — not the interface —
        //     as the constructor argument for `$eventManager`.
        //   - The Proxy lazily resolves its `_subject` on the first
        //     dispatch() call and caches it.
        // So the only swap that reliably intercepts every breaker dispatch
        // is to put our spy into the shared-instances slot for the Proxy
        // class itself, *before* CircuitBreaker is first resolved. The spy
        // implements EventManagerInterface and forwards to the real Manager
        // so unrelated observers still fire.
        $realManager = $this->objectManager->get(\Magento\Framework\Event\Manager::class);
        $this->eventCapture = new EventCapture($realManager);
        $this->replaceSharedInstance(\Magento\Framework\Event\Manager\Proxy::class, $this->eventCapture);
        // Mirror the swap on the concrete and the interface for any other
        // consumer that asks for them directly.
        $this->replaceSharedInstance(\Magento\Framework\Event\Manager::class, $this->eventCapture);
        $this->replaceSharedInstance(EventManagerInterface::class, $this->eventCapture);
        // Drop any cached breaker so the next get() re-wires with the spy.
        $this->removeSharedInstance(CircuitBreakerInterface::class);
        $this->removeSharedInstance(\Shubo\ShippingCore\Model\Resilience\CircuitBreaker::class);
    }

    protected function tearDown(): void
    {
        $this->truncateBreakerRow(self::CARRIER);
        $this->deleteScopeConfig(self::CONFIG_FAILURE_THRESHOLD);
        $this->deleteScopeConfig(self::CONFIG_FAILURE_WINDOW);
        $this->deleteScopeConfig(self::CONFIG_COOLDOWN);
        $this->truncateLog();
    }

    public function testExecuteOpensBreakerAfterFailureThreshold(): void
    {
        /** @var CircuitBreakerInterface $breaker */
        $breaker = $this->objectManager->get(CircuitBreakerInterface::class);

        $beforeTs = time();

        // Drive exactly FAILURE_THRESHOLD consecutive failures. Each
        // execute() re-raises the underlying RuntimeException; we swallow
        // them so the test itself continues.
        for ($i = 0; $i < self::FAILURE_THRESHOLD; $i++) {
            try {
                $breaker->execute(self::CARRIER, static function (): never {
                    throw new \RuntimeException('integration-boom');
                });
                self::fail('Breaker must re-raise the callable exception.');
            } catch (\RuntimeException) {
                // Expected — propagated from the callable.
            }
        }

        $afterTs = time();

        // --- Assert 1: DB row reflects the transition.
        /** @var CircuitBreakerStateRepository $repo */
        $repo = $this->objectManager->get(CircuitBreakerStateRepository::class);
        $state = $repo->getByCarrierCode(self::CARRIER);

        self::assertSame(
            CircuitBreakerStateInterface::STATE_OPEN,
            $state->getState(),
            'After N failures the row must be persisted with state=open.',
        );
        self::assertNotNull($state->getOpenedAt(), 'opened_at must be populated on transition to open.');
        self::assertNotNull($state->getCooldownUntil(), 'cooldown_until must be populated on transition to open.');
        self::assertSame(
            0,
            $state->getFailureCount(),
            'failure_count must reset to 0 on transition per CircuitBreaker::recordFailure.',
        );

        // --- Assert 2: Event was dispatched with the expected payload.
        $openEvents = $this->eventCapture->eventsNamed('shubo_shipping_carrier_breaker_opened');
        self::assertNotEmpty(
            $openEvents,
            'CircuitBreaker must dispatch shubo_shipping_carrier_breaker_opened on transition to open.',
        );
        $first = $openEvents[0];
        self::assertSame(self::CARRIER, $first['data']['carrier_code'] ?? null);
        self::assertIsInt(
            $first['data']['opened_at'] ?? null,
            'opened_at payload must be an integer seconds-since-epoch.',
        );
        self::assertGreaterThanOrEqual(
            $beforeTs,
            (int)$first['data']['opened_at'],
            'opened_at must be at or after the start of the test window.',
        );
        self::assertLessThanOrEqual(
            $afterTs + 10,
            (int)$first['data']['opened_at'],
            'opened_at must be within a 10s window of real time (no clock drift).',
        );

        // --- Assert 3: Structured JSON log line was written.
        $logLines = $this->readBreakerLogLines(self::CARRIER);
        $openTransitions = array_values(array_filter(
            $logLines,
            static fn (array $entry): bool => ($entry['to'] ?? null) === CircuitBreakerStateInterface::STATE_OPEN,
        ));
        self::assertNotEmpty(
            $openTransitions,
            sprintf(
                'Expected at least one shubo_shipping.log JSON line with to=open for carrier %s; got %d lines.',
                self::CARRIER,
                count($logLines),
            ),
        );
        $logEntry = $openTransitions[0];
        self::assertSame('breaker_transition', $logEntry['event'] ?? null);
        self::assertSame(self::CARRIER, $logEntry['carrier_code'] ?? null);
        self::assertSame(
            CircuitBreakerStateInterface::STATE_CLOSED,
            $logEntry['from'] ?? null,
            'Transition must originate from state=closed (the synthetic initial state).',
        );
        self::assertSame('failure_threshold_reached', $logEntry['reason'] ?? null);
    }

    public function testForceStateResetRewritesDbAndLogs(): void
    {
        /** @var CircuitBreakerInterface $breaker */
        $breaker = $this->objectManager->get(CircuitBreakerInterface::class);

        // Seed an open row directly (independent of the previous test's order)
        // so this test stands alone.
        $this->seedOpenRow(self::CARRIER);

        $breaker->forceState(
            self::CARRIER,
            CircuitBreakerStateInterface::STATE_CLOSED,
            'admin smoke test',
        );

        // --- Assert 1: DB row reset.
        /** @var CircuitBreakerStateRepository $repo */
        $repo = $this->objectManager->get(CircuitBreakerStateRepository::class);
        $state = $repo->getByCarrierCode(self::CARRIER);

        self::assertSame(CircuitBreakerStateInterface::STATE_CLOSED, $state->getState());
        self::assertNull($state->getOpenedAt(), 'forceState(closed) must clear opened_at.');
        self::assertNull($state->getCooldownUntil(), 'forceState(closed) must clear cooldown_until.');
        self::assertSame(0, $state->getFailureCount());
        self::assertSame(0, $state->getSuccessCountSinceHalfopen());

        // --- Assert 2: Forced-transition JSON log line present.
        $logLines = $this->readBreakerLogLines(self::CARRIER);
        $forcedClosures = array_values(array_filter(
            $logLines,
            static function (array $entry): bool {
                return ($entry['to'] ?? null) === CircuitBreakerStateInterface::STATE_CLOSED
                    && ($entry['forced'] ?? null) === true;
            },
        ));
        self::assertNotEmpty(
            $forcedClosures,
            sprintf(
                'Expected at least one shubo_shipping.log JSON line with to=closed and forced=true; got %d lines.',
                count($logLines),
            ),
        );
        $logEntry = $forcedClosures[0];
        self::assertSame('breaker_transition', $logEntry['event'] ?? null);
        self::assertSame(self::CARRIER, $logEntry['carrier_code'] ?? null);
        self::assertSame('admin smoke test', $logEntry['admin_note'] ?? null);
    }

    /**
     * Boot Magento in the current process — same pattern as
     * {@see RateLimiterConcurrencyTest}'s warmDi() path, minus the
     * subprocess. Safe to call once per test class instance.
     */
    private function bootMagento(): ObjectManagerInterface
    {
        $bootstrapPath = self::CONTAINER_BP . '/app/bootstrap.php';
        if (!defined('BP') && is_file($bootstrapPath)) {
            // phpcs:ignore Magento2.Security.IncludeFile
            require_once $bootstrapPath;
        }

        $params = $_SERVER;
        $app = Bootstrap::create(BP, $params);
        return $app->getObjectManager();
    }

    /**
     * Reflection-based replacement of a shared-instance entry. Equivalent
     * to {@see \Magento\TestFramework\ObjectManager::addSharedInstance()}
     * but works against the production ObjectManager that our direct
     * bootstrap hands us.
     */
    private function replaceSharedInstance(string $className, object $instance): void
    {
        $ref = new ReflectionClass($this->objectManager);
        // `_sharedInstances` lives on the abstract ObjectManager parent.
        $parent = $ref->getParentClass();
        while ($parent !== false && !$parent->hasProperty('_sharedInstances')) {
            $parent = $parent->getParentClass();
        }
        self::assertNotFalse($parent, 'Could not locate _sharedInstances on ObjectManager hierarchy.');
        $prop = $parent->getProperty('_sharedInstances');
        $prop->setAccessible(true);
        $shared = $prop->getValue($this->objectManager);
        $shared[$className] = $instance;
        $prop->setValue($this->objectManager, $shared);
    }

    private function removeSharedInstance(string $className): void
    {
        $ref = new ReflectionClass($this->objectManager);
        $parent = $ref->getParentClass();
        while ($parent !== false && !$parent->hasProperty('_sharedInstances')) {
            $parent = $parent->getParentClass();
        }
        if ($parent === false) {
            return;
        }
        $prop = $parent->getProperty('_sharedInstances');
        $prop->setAccessible(true);
        $shared = $prop->getValue($this->objectManager);
        unset($shared[$className]);
        $prop->setValue($this->objectManager, $shared);
    }

    private function openConnection(): PDO
    {
        $env = require self::CONTAINER_BP . '/app/etc/env.php';
        $db = $env['db']['connection']['default'] ?? [];
        $host = (string)($db['host'] ?? 'mysql');
        $dbname = (string)($db['dbname'] ?? 'magento');
        $user = (string)($db['username'] ?? 'root');
        $password = (string)($db['password'] ?? 'root');

        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function truncateBreakerRow(string $carrierCode): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM shubo_shipping_circuit_breaker WHERE carrier_code = :c',
        );
        $stmt->execute([':c' => $carrierCode]);
    }

    /**
     * Create an open row directly via PDO so
     * {@see CircuitBreakerInterface::forceState()} has a real starting
     * state to reset from, independent of any other test's order.
     */
    private function seedOpenRow(string $carrierCode): void
    {
        $this->truncateBreakerRow($carrierCode);
        $stmt = $this->pdo->prepare(
            'INSERT INTO shubo_shipping_circuit_breaker '
            . '(carrier_code, state, failure_count, success_count_since_halfopen, '
            . 'opened_at, cooldown_until) '
            . 'VALUES (:c, "open", 0, 0, UTC_TIMESTAMP(), '
            . 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 60 SECOND))',
        );
        $stmt->execute([':c' => $carrierCode]);
    }

    private function upsertScopeConfig(string $path, string $value): void
    {
        $sql = 'INSERT INTO core_config_data (scope, scope_id, path, value) '
            . 'VALUES ("default", 0, :path, :value) '
            . 'ON DUPLICATE KEY UPDATE value = VALUES(value)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':path' => $path, ':value' => $value]);
    }

    private function deleteScopeConfig(string $path): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM core_config_data WHERE scope = "default" AND scope_id = 0 AND path = :path',
        );
        $stmt->execute([':path' => $path]);
    }

    private function truncateLog(): void
    {
        if (is_file(self::LOG_PATH)) {
            file_put_contents(self::LOG_PATH, '');
        }
    }

    /**
     * Parse `var/log/shubo_shipping.log` and return the JSON-context
     * payloads of lines that correspond to breaker transitions for the
     * given carrier. Monolog's default line formatter is:
     *     [timestamp] channel.LEVEL: message {context} {extra}
     * We grab the first {...} block on each line.
     *
     * @return list<array<string, mixed>>
     */
    private function readBreakerLogLines(string $carrierCode): array
    {
        if (!is_file(self::LOG_PATH)) {
            return [];
        }
        $raw = (string)file_get_contents(self::LOG_PATH);
        $out = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Match the first balanced-looking {...} blob on the line. The
            // Monolog default formatter emits context then extra as two
            // separate JSON objects; the first one is what we care about.
            if (!preg_match('/(\{.+?\})\s*(\[\]|\{.*\})?\s*$/', $line, $m)) {
                continue;
            }
            $decoded = json_decode($m[1], true);
            if (!is_array($decoded)) {
                continue;
            }
            if (($decoded['event'] ?? null) !== 'breaker_transition') {
                continue;
            }
            if (($decoded['carrier_code'] ?? null) !== $carrierCode) {
                continue;
            }
            $out[] = $decoded;
        }
        return $out;
    }
}
