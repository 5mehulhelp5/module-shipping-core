# Changelog

All notable changes to `shubo/module-shipping-core` are documented here.
Format: Keep a Changelog 1.1.0; SemVer.

## v0.12.0 — 2026-05-01

### Carriers

- Added `shubopickup` (Pickup in store) carrier — zero-cost, single-rate carrier for merchants offering customer pickup at their physical location. Triggers the merchant-collected branch in `Shubo_Payout`'s offline-order ledger flow when paired with an offline payment method (cashondelivery, banktransfer, checkmo, purchaseorder). No COD support; pickup-in-store implies the merchant collects cash directly at counter. See `docs/design-instore-pickup.md`.
