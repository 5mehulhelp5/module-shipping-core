# Shubo_ShippingCore

Carrier-agnostic shipping orchestration framework for Magento 2 marketplaces and multi-vendor stores.

`Shubo_ShippingCore` is the foundation module that provides common abstractions — a pluggable carrier-adapter interface, shipment orchestration, resilience primitives (circuit breaker, retry, rate limiting, idempotency), a polling scheduler for carriers without webhooks, a webhook dispatcher for carriers that have them, and an invoice reconciliation framework.

Per-carrier adapters (e.g. `Shubo_ShippingTrackings`, `Shubo_ShippingWoltDrive`, `Shubo_ShippingDelivo`) are shipped as separate modules that plug into Core.

## Development

The module ships a standalone composer setup so the three quality gates can run
outside of a full Magento install. From a clean checkout:

```bash
composer install
composer phpunit     # or vendor/bin/phpunit
composer phpstan     # or vendor/bin/phpstan analyse
composer phpcs       # or vendor/bin/phpcs
```

`composer install` pulls `magento/framework` from the open-source
[Mage-OS mirror](https://mirror.mage-os.org/) — no Adobe Commerce
credentials required. PHP is pinned to `8.4.0` via `config.platform.php`
to match the runtime; the CI matrix exercises 8.1 through 8.4.

## Status

**Early development.** APIs are not yet stable. This README will be fleshed out in the v1.0.0 release. See `docs/design/shipping-core.md` in the downstream integration project for the current design document.

## Installation

```bash
composer require shubo/module-shipping-core
bin/magento module:enable Shubo_ShippingCore
bin/magento setup:upgrade
bin/magento setup:di:compile
```

## Requirements

- Magento 2.4.8 or later
- PHP 8.1 or later
- `phpoffice/phpspreadsheet` ^2.0 or ^3.0 (for carrier invoice import)

## Architecture overview

```
                  ┌────────────────────────────────────┐
                  │        Shubo_ShippingCore          │
                  │                                    │
                  │  CarrierRegistry                   │
                  │  ShipmentOrchestrator              │
                  │  TrackingPoller    WebhookDispatcher│
                  │  RateQuoteService  ReconciliationSvc│
                  │  CircuitBreaker    RetryPolicy     │
                  │  RateLimiter       IdempotencyStore│
                  └───────┬────────────────────────────┘
                          │ CarrierGatewayInterface
                          │ CarrierCapabilitiesInterface
                          │ WebhookHandlerInterface
                          │ InvoiceImporterInterface
                          │
             ┌────────────┼────────────┬──────────────┐
             ▼            ▼            ▼              ▼
     TrackingsAdapter  WoltDrive   DelivoAdapter  (your adapter)
```

## Writing a carrier adapter

A carrier adapter module implements:

1. `CarrierGatewayInterface` — `quote`, `createShipment`, `cancelShipment`, `getShipmentStatus`, `fetchLabel`, `listCities`, `listPudos`
2. `CarrierCapabilitiesInterface` — declares what the carrier supports (webhooks, sandbox, COD reconciliation API, PUDO, express, etc.)
3. Optional `WebhookHandlerInterface` — if the carrier pushes status updates
4. Optional `InvoiceImporterInterface` — if the carrier settles fees/COD via downloadable invoices

A full adapter-authoring guide ships with v1.0.0.

## License

Apache-2.0 — see [LICENSE](LICENSE) and [NOTICE](NOTICE).

## Author

[Nikoloz Shubitidze](https://github.com/nshubitidze) (Shubo)
