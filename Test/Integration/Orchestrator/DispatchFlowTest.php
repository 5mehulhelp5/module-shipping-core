<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Shubo\ShippingCore\Test\Integration\Orchestrator;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\ObjectManagerInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Shubo\ShippingCore\Api\CarrierRegistryInterface;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentRequest;
use Shubo\ShippingCore\Api\Data\Dto\ShipmentResponse;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingCore\Api\ShipmentOrchestratorInterface;
use Shubo\ShippingCore\Api\ShipmentRepositoryInterface;
use Shubo\ShippingCore\Test\Integration\Model\Resilience\Fixtures\EventCapture;
use Shubo\ShippingCore\Test\Unit\Fake\FakeCarrierGateway;

require_once __DIR__ . '/../Model/Resilience/_fixtures/EventCapture.php';

// Real-Magento smoke test following the same direct-bootstrap pattern as
// CircuitBreakerIntegrationTest — we do not use Magento's TestFramework
// integration runner (which reinstalls a throwaway DB on every run). That
// lets us read and write against the real duka MySQL + events + log.
// phpcs:disable Magento2.Security.InsecureFunction.Found

/**
 * End-to-end smoke test for the Phase 4 dispatch flow.
 *
 * The unit tests ({@see \Shubo\ShippingCore\Test\Unit\Model\Orchestrator\ShipmentOrchestratorTest})
 * already cover the orchestrator's branches with mocks. What they CANNOT
 * catch are broken DI wiring, a missing db_schema column, or a misnamed
 * event — all of which would ship to production even with green unit tests.
 *
 * This test verifies, against real infrastructure, that:
 * 1. {@see ShipmentOrchestratorInterface} resolves from DI with all
 *    collaborators wired and reaches a real carrier gateway.
 * 2. A dispatch produces a row in `shubo_shipping_shipment` with the
 *    client_tracking_code and carrier_tracking_id persisted.
 * 3. The dispatch fires `shubo_shipping_shipment_created` AND
 *    `shubo_shipping_shipment_dispatched` (captured via an EventManager
 *    spy swapped into the shared-instances cache).
 * 4. Idempotency holds against the live DB + orchestrator: re-dispatching
 *    the same client_tracking_code returns the original row and does not
 *    fire a second `_dispatched` event.
 */
class DispatchFlowTest extends TestCase
{
    private const CARRIER = 'fake';
    private const CONTAINER_BP = '/var/www/html';

    /** @var PDO Direct MySQL connection used to seed/clean fixture rows. */
    private PDO $pdo;

    /** @var ObjectManagerInterface Production object manager booted against the installed duka instance. */
    private ObjectManagerInterface $objectManager;

    /** @var EventCapture Spy wrapping the real EventManager and recording every dispatch. */
    private EventCapture $eventCapture;

    /** @var FakeCarrierGateway In-process carrier fake so the orchestrator has a gateway to call. */
    private FakeCarrierGateway $gateway;

    /** @var list<string> client_tracking_code values seeded in this run. Cleaned up in tearDown. */
    private array $seededCodes = [];

    protected function setUp(): void
    {
        $this->pdo = $this->openConnection();

        // Ensure no residue from a previous run collides with this one.
        $this->pdo->exec("DELETE FROM shubo_shipping_shipment WHERE carrier_code = '" . self::CARRIER . "'");

        $this->objectManager = $this->bootMagento();

        // Install the EventManager spy before the orchestrator is resolved so
        // every dispatch call is captured. See CircuitBreakerIntegrationTest
        // for the rationale on why we must cover both the Proxy and the
        // concrete Manager in shared-instances.
        $realManager = $this->objectManager->get(\Magento\Framework\Event\Manager::class);
        $this->eventCapture = new EventCapture($realManager);
        $this->replaceSharedInstance(\Magento\Framework\Event\Manager\Proxy::class, $this->eventCapture);
        $this->replaceSharedInstance(\Magento\Framework\Event\Manager::class, $this->eventCapture);
        $this->replaceSharedInstance(EventManagerInterface::class, $this->eventCapture);

        // Register a FakeCarrierGateway in the registry so dispatch() has a
        // concrete adapter to call. Because CarrierRegistry's `$gateways` is
        // readonly, we can't mutate it in place — we construct a fresh
        // registry instance with the gateway baked in and swap the shared-
        // instance entry so every downstream lookup sees it.
        $this->gateway = new FakeCarrierGateway(self::CARRIER);

        $structuredLogger = $this->objectManager->get(
            \Shubo\ShippingCore\Model\Logging\StructuredLogger::class,
        );
        $testRegistry = new \Shubo\ShippingCore\Model\Carrier\CarrierRegistry(
            $structuredLogger,
            [self::CARRIER => $this->gateway],
        );
        $this->replaceSharedInstance(CarrierRegistryInterface::class, $testRegistry);
        $this->replaceSharedInstance(
            \Shubo\ShippingCore\Model\Carrier\CarrierRegistry::class,
            $testRegistry,
        );

        // Drop any cached orchestrator so it picks up the spy + swapped registry.
        $this->removeSharedInstance(ShipmentOrchestratorInterface::class);
        $this->removeSharedInstance(\Shubo\ShippingCore\Model\Orchestrator\ShipmentOrchestrator::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->seededCodes as $code) {
            $stmt = $this->pdo->prepare(
                'DELETE FROM shubo_shipping_shipment WHERE client_tracking_code = :c',
            );
            $stmt->execute([':c' => $code]);
        }
        $this->seededCodes = [];
    }

    public function testDispatchCreatesRowAndFiresCreatedAndDispatchedEvents(): void
    {
        $clientCode = $this->clientCode('happy');
        $request = $this->newRequest($clientCode);

        $this->gateway->setNextResponse('createShipment', new ShipmentResponse(
            carrierTrackingId: 'INT-TRK-001',
            labelUrl: null,
            status: ShipmentInterface::STATUS_READY_FOR_PICKUP,
        ));

        /** @var ShipmentOrchestratorInterface $orchestrator */
        $orchestrator = $this->objectManager->get(ShipmentOrchestratorInterface::class);
        $result = $orchestrator->dispatch($request);

        // --- Assert 1: persisted row has the fields we expect.
        self::assertNotNull($result->getShipmentId(), 'Dispatched shipment must have an ID after save.');
        self::assertSame('INT-TRK-001', $result->getCarrierTrackingId());
        self::assertSame(self::CARRIER, $result->getCarrierCode());
        self::assertSame($clientCode, $result->getClientTrackingCode());
        self::assertSame(ShipmentInterface::STATUS_READY_FOR_PICKUP, $result->getStatus());

        // --- Assert 2: direct DB read confirms the row landed in the right table.
        $stmt = $this->pdo->prepare(
            'SELECT carrier_code, carrier_tracking_id, status FROM shubo_shipping_shipment '
            . 'WHERE client_tracking_code = :c',
        );
        $stmt->execute([':c' => $clientCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, 'Expected a row in shubo_shipping_shipment after dispatch.');
        self::assertSame(self::CARRIER, $row['carrier_code']);
        self::assertSame('INT-TRK-001', $row['carrier_tracking_id']);
        self::assertSame(ShipmentInterface::STATUS_READY_FOR_PICKUP, $row['status']);

        // --- Assert 3: events fired via the captured EventManager.
        $createdEvents = $this->eventCapture->eventsNamed('shubo_shipping_shipment_created');
        $dispatchedEvents = $this->eventCapture->eventsNamed('shubo_shipping_shipment_dispatched');
        self::assertNotEmpty($createdEvents, 'shubo_shipping_shipment_created must fire on dispatch.');
        self::assertNotEmpty($dispatchedEvents, 'shubo_shipping_shipment_dispatched must fire on carrier success.');

        $dispatched = $dispatchedEvents[0];
        self::assertSame('INT-TRK-001', $dispatched['data']['carrier_tracking_id'] ?? null);
    }

    public function testIdempotentDoubleDispatchReturnsExistingRowAndSkipsSecondCarrierCall(): void
    {
        $clientCode = $this->clientCode('idem');
        $request = $this->newRequest($clientCode);

        $this->gateway->setNextResponse('createShipment', new ShipmentResponse(
            carrierTrackingId: 'INT-TRK-IDEM',
            labelUrl: null,
            status: ShipmentInterface::STATUS_READY_FOR_PICKUP,
        ));

        /** @var ShipmentOrchestratorInterface $orchestrator */
        $orchestrator = $this->objectManager->get(ShipmentOrchestratorInterface::class);
        $first = $orchestrator->dispatch($request);

        // Arm a trap: the gateway should NOT be called on the second dispatch.
        // If it is, this exception propagates and the test fails.
        $this->gateway->setNextError('createShipment', new \LogicException(
            'Idempotency violated — second dispatch reached the carrier.',
        ));

        $second = $orchestrator->dispatch($request);

        self::assertSame(
            $first->getShipmentId(),
            $second->getShipmentId(),
            'Re-dispatching the same client_tracking_code must return the same row ID.',
        );
        self::assertSame($first->getCarrierTrackingId(), $second->getCarrierTrackingId());

        $dispatchedEvents = $this->eventCapture->eventsNamed('shubo_shipping_shipment_dispatched');
        self::assertCount(
            1,
            $dispatchedEvents,
            'Only the first dispatch should fire shubo_shipping_shipment_dispatched.',
        );

        // Double check via DB that there is exactly one row for this key.
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM shubo_shipping_shipment WHERE client_tracking_code = :c',
        );
        $stmt->execute([':c' => $clientCode]);
        self::assertSame(1, (int)$stmt->fetchColumn(), 'Expected exactly one row after two dispatches.');

        // Repository also sees the same ID.
        /** @var ShipmentRepositoryInterface $repo */
        $repo = $this->objectManager->get(ShipmentRepositoryInterface::class);
        $loaded = $repo->getByClientTrackingCode($clientCode);
        self::assertSame($first->getShipmentId(), $loaded->getShipmentId());
    }

    // -- helpers ------------------------------------------------------------

    private function clientCode(string $tag): string
    {
        $code = sprintf('int_%s_%d', $tag, random_int(1_000_000, 9_999_999));
        $this->seededCodes[] = $code;
        return $code;
    }

    private function newRequest(string $clientCode): ShipmentRequest
    {
        $dest = new ContactAddress(
            name: 'Integration Test',
            phone: '+995599123456',
            email: 'int@example.com',
            country: 'GE',
            subdivision: 'GE-TB',
            city: 'Tbilisi',
            district: null,
            street: '1 Integration Ave',
            building: null,
            floor: null,
            apartment: null,
            postcode: '0108',
            latitude: null,
            longitude: null,
            instructions: null,
        );
        $origin = new ContactAddress(
            name: 'Merchant',
            phone: '+995599999999',
            email: null,
            country: 'GE',
            subdivision: 'GE-TB',
            city: 'Tbilisi',
            district: null,
            street: '1 Origin St',
            building: null,
            floor: null,
            apartment: null,
            postcode: '0100',
            latitude: null,
            longitude: null,
            instructions: null,
        );
        $parcel = new ParcelSpec(
            weightGrams: 750,
            lengthMm: 200,
            widthMm: 150,
            heightMm: 100,
            declaredValueCents: 50_00,
        );
        return new ShipmentRequest(
            orderId: 1,
            merchantId: 1,
            clientTrackingCode: $clientCode,
            origin: $origin,
            destination: $dest,
            parcel: $parcel,
            codEnabled: false,
            codAmountCents: 0,
            preferredCarrierCode: self::CARRIER,
        );
    }

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

    private function replaceSharedInstance(string $className, object $instance): void
    {
        $ref = new ReflectionClass($this->objectManager);
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
}
