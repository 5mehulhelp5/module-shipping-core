<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Integration\Model\Resilience;

use PDO;
use PHPUnit\Framework\TestCase;

// This integration test deliberately uses proc_* to spawn child PHP
// processes — that is the only way to produce real concurrent acquire()
// calls against a shared MySQL row. Those calls are safe here because
// the command array is constructed from constants, not user input.
// phpcs:disable Magento2.Security.InsecureFunction.Found

/**
 * Proves that {@see \Shubo\ShippingCore\Model\Resilience\RateLimiter::acquire()}
 * cannot over-issue tokens under real concurrent invocation.
 *
 * Strategy: spawn 20 child PHP workers via proc_open(); each boots its own
 * Magento instance (fresh DB connection + fresh object graph) and calls
 * acquire() three times in a tight loop. With RPM configured at 30 the
 * invariant is strict: exactly 30 OKs and exactly 30 NOs across 60 total
 * attempts. Any extra OK is a correctness bug — the limiter has issued
 * more tokens than the cap.
 *
 * This is NOT a unit test. It deliberately bypasses Magento's integration
 * test framework (which re-installs the DB on every run); instead each
 * worker boots the already-installed duka instance, reads config/DI from
 * app/etc/env.php, and talks to the real MySQL + Redis the container uses.
 * That is what "real concurrency" means here.
 */
class RateLimiterConcurrencyTest extends TestCase
{
    private const CARRIER = 'test_carrier_concurrency';
    private const RPM = 30;
    private const WORKERS = 20;
    private const ACQUIRES_PER_WORKER = 3;
    private const CONFIG_PATH = 'shubo_shipping/rate_limit/default_rpm';

    private const WORKER_SCRIPT = __DIR__ . '/_fixtures/rate_limit_worker.php';
    private const CONTAINER_BP = '/var/www/html';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->openConnection();

        // Configure the limit so workers read rpm=30 through the normal
        // scope-config path.
        $this->upsertScopeConfig(self::CONFIG_PATH, (string)self::RPM);

        // Start with a clean rate-limit row for this carrier.
        $this->truncateRateLimitRow(self::CARRIER);

        // Full cache flush: ensures (1) workers read the freshly-written
        // default_rpm value from core_config_data instead of a stale
        // cached copy, and (2) any leftover shubo_shipping_rl_* Redis
        // keys from a previous run are gone so every run starts from
        // a deterministic zero-state window.
        $this->magentoCli('cache:flush');

        // Pre-warm DI / generated/code by booting Magento once here,
        // so the 20 workers don't stampede-compile the same classes in
        // parallel (which is slow and has been observed to push workers
        // past the per-worker timeout). Also confirms the rate limiter
        // class is constructable under the current signature before we
        // spawn anything.
        $this->warmDi();

        // Wait until we are at least WINDOW_HEADROOM_SECONDS away from
        // the next minute rollover. The rate limiter uses a 1-minute
        // wall-clock window; 20 workers cold-starting Magento across
        // 10+s near a minute boundary would land in two different
        // windows and each window's 30-token cap would be "correctly"
        // hit for 60 total OKs — a false positive for over-issue.
        // Align so the whole burst happens inside one window.
        $this->waitForWindowHeadroom();
    }

    /**
     * Boot Magento once in the parent process so /generated is warm
     * before workers spawn. Idempotent.
     */
    private function warmDi(): void
    {
        // Worker script does its own bootstrap; just invoke it once with
        // zero acquires against a throwaway carrier so the generated
        // code gets written before the real burst.
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = ['php', self::WORKER_SCRIPT, 'di_warmup_carrier', '0', 'warmup'];
        $pipes = [];
        $proc = proc_open($cmd, $descriptors, $pipes, self::CONTAINER_BP);
        if (!is_resource($proc)) {
            return;
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        // Clean up any row the warmup might have created.
        $this->truncateRateLimitRow('di_warmup_carrier');
    }

    /**
     * Sleep until the current wall-clock position is at least
     * WINDOW_HEADROOM_SECONDS before the next minute boundary.
     */
    private function waitForWindowHeadroom(): void
    {
        $headroom = 30; // seconds — generous for 20 cold-starts
        $secondsIntoWindow = (int)(time() % 60);
        $remaining = 60 - $secondsIntoWindow;
        if ($remaining < $headroom) {
            // Sleep past the boundary then recheck.
            sleep($remaining + 1);
        }
    }

    protected function tearDown(): void
    {
        $this->truncateRateLimitRow(self::CARRIER);
        $this->deleteScopeConfig(self::CONFIG_PATH);
        $this->magentoCli('cache:clean config');
    }

    public function testAcquireCannotOverIssueUnderConcurrentWorkers(): void
    {
        $totalAttempts = self::WORKERS * self::ACQUIRES_PER_WORKER;
        self::assertSame(60, $totalAttempts, 'fixture invariant: 20 workers x 3 acquires = 60');

        // Spawn all workers asynchronously so they contend on the same
        // 60-second window. proc_open returns immediately; we collect
        // output in a single non-blocking loop below.
        $workers = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $workers[] = $this->spawnWorker($i);
        }

        $results = ['OK' => 0, 'NO' => 0];
        $exitCodes = [];
        $stderrAll = [];
        foreach ($workers as $worker) {
            [$stdout, $stderr, $exitCode] = $this->waitForWorker($worker);
            $exitCodes[] = $exitCode;
            if ($stderr !== '') {
                $stderrAll[] = "worker {$worker['id']}: {$stderr}";
            }
            foreach (explode("\n", trim($stdout)) as $line) {
                $line = trim($line);
                if ($line === 'OK') {
                    $results['OK']++;
                } elseif ($line === 'NO') {
                    $results['NO']++;
                }
                // DONE marker and blank lines are ignored here.
            }
        }

        self::assertSame(
            array_fill(0, self::WORKERS, 0),
            $exitCodes,
            'All workers must exit 0. stderr: ' . implode(' | ', $stderrAll),
        );

        $observed = $results['OK'] + $results['NO'];
        self::assertSame(
            $totalAttempts,
            $observed,
            sprintf(
                'Expected %d total acquire results, got %d (OK=%d, NO=%d). stderr: %s',
                $totalAttempts,
                $observed,
                $results['OK'],
                $results['NO'],
                implode(' | ', $stderrAll),
            ),
        );

        self::assertSame(
            self::RPM,
            $results['OK'],
            sprintf(
                'RateLimiter over-issued: expected exactly %d OKs (rpm cap), got %d. '
                . 'This is the TOCTOU race in the Redis-primary path — see design §9.5.',
                self::RPM,
                $results['OK'],
            ),
        );
        self::assertSame(
            $totalAttempts - self::RPM,
            $results['NO'],
            sprintf('Expected exactly %d rejections, got %d.', $totalAttempts - self::RPM, $results['NO']),
        );
    }

    /**
     * @return array{id: int, proc: resource, pipes: array<int, resource>}
     */
    private function spawnWorker(int $workerId): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmd = [
            'php',
            self::WORKER_SCRIPT,
            self::CARRIER,
            (string)self::ACQUIRES_PER_WORKER,
            (string)$workerId,
        ];
        $pipes = [];
        $proc = proc_open($cmd, $descriptors, $pipes, self::CONTAINER_BP);
        if (!is_resource($proc)) {
            self::fail("proc_open() failed for worker {$workerId}");
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['id' => $workerId, 'proc' => $proc, 'pipes' => $pipes];
    }

    /**
     * Drain a worker's stdout/stderr and return (stdout, stderr, exitCode).
     *
     * @param array{id: int, proc: resource, pipes: array<int, resource>} $worker
     * @return array{0: string, 1: string, 2: int}
     */
    private function waitForWorker(array $worker): array
    {
        $stdout = '';
        $stderr = '';
        // 20 cold-starting Magento workers can take a while to compile DI
        // + connect to MySQL; give each one a generous ceiling. Even with
        // stampede-induced locking, a single acquire should resolve in
        // tens of milliseconds, so if we hit this we have a real problem.
        $deadline = microtime(true) + 180.0;

        while (true) {
            $chunkOut = stream_get_contents($worker['pipes'][1]);
            if (is_string($chunkOut) && $chunkOut !== '') {
                $stdout .= $chunkOut;
            }
            $chunkErr = stream_get_contents($worker['pipes'][2]);
            if (is_string($chunkErr) && $chunkErr !== '') {
                $stderr .= $chunkErr;
            }

            $status = proc_get_status($worker['proc']);
            if (!$status['running']) {
                // Drain any remaining buffered output after the process exited.
                $leftoverOut = stream_get_contents($worker['pipes'][1]);
                $leftoverErr = stream_get_contents($worker['pipes'][2]);
                if (is_string($leftoverOut)) {
                    $stdout .= $leftoverOut;
                }
                if (is_string($leftoverErr)) {
                    $stderr .= $leftoverErr;
                }
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                $exitCode = (int)$status['exitcode'];
                proc_close($worker['proc']);
                return [$stdout, $stderr, $exitCode];
            }

            if (microtime(true) > $deadline) {
                proc_terminate($worker['proc']);
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                proc_close($worker['proc']);
                self::fail("worker {$worker['id']} exceeded 60s timeout");
            }

            usleep(20_000);
        }
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
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    }

    private function truncateRateLimitRow(string $carrierCode): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM shubo_shipping_rate_limit WHERE carrier_code = :c');
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

    /**
     * Invoke Magento's CLI synchronously so config / cache state is
     * committed before the next test step runs. When mutating
     * core_config_data directly we must flush at least the config
     * cache so workers read the new value; `cache:flush` also clears
     * the default Redis keys used by the rate limiter.
     */
    private function magentoCli(string $args): void
    {
        $cmd = 'php ' . escapeshellarg(self::CONTAINER_BP . '/bin/magento')
            . ' ' . $args . ' 2>&1';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = proc_open($cmd, $descriptors, $pipes, self::CONTAINER_BP);
        if (!is_resource($proc)) {
            self::fail("Unable to invoke Magento CLI: {$args}");
        }
        fclose($pipes[0]);
        // Drain both pipes before proc_close() so the child isn't wedged
        // on a full pipe (bin/magento prints a few lines to stdout).
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
}
