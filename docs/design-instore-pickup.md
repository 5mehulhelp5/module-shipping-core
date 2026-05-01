# Pickup-in-Store Carrier (`shubopickup`)

Architect: Nika. Module: `shubo/module-shipping-core` (standalone — Vendor
distribution; canonical here, Composer-installed in duka). Status: design
only.

## 1. Why this carrier exists

Today only `shuboflat` ships in core. That carrier always implies courier
delivery. Real merchants like Tikha Ceramics need a "customer comes to my
studio and pays cash" option — the pickup + offline cell of the
(shipping x payment) matrix in
`Shubo_Payout/docs/design-funds-destination-2026-05-01.md` §1. Without a
pickup carrier, merchants must fake-attach a flat-rate shipment to a
counter-cash sale, and the offline-order ledger writes do not have a
clean trigger.

We deliberately do NOT use `Magento_InStorePickup`. That module is built
on MSI and assumes multi-source inventory + multi-pickup-location
fulfilment graphs. Our marketplace runs single-source-per-merchant on
the default stock; pulling MSI in for one carrier is overkill and adds
a maintenance surface this team doesn't want.

`shubopickup` is the second built-in carrier. It is intentionally
trivial — by design it does no work the existing event chain cannot
handle. The point of the module is to give the merchant a checkout
choice that triggers the merchant-collected branch in
`Shubo_Payout` cleanly.

## 2. Carrier code

`shubopickup`. **No underscore** — same constraint as `shuboflat`.
`CreateShipmentOnMagentoShipment::resolveCarrierCode()` does
`explode('_', $shippingMethod, 2)[0]`, so a code like `shubo_pickup`
would resolve to just `shubo` and fail the registry lookup.
`/home/nika/module-shipping-core/Model/Carrier/FlatRateGateway.php:34-37`
documents the same precedent.

## 3. Display name

`'Pickup in store'` (English). Localisation entries land in the duka
`Shubo/i18n/ka_GE` mirror module in a follow-up commit (out of scope of
this carrier). Future enhancement: read merchant pickup_address display
name when a merchant has multiple pickup points — out of scope here.

## 4. Quote behaviour

Single rate option:

| Field          | Value     |
|----------------|-----------|
| `carrierCode`  | `shubopickup` |
| `methodCode`   | `pickup` |
| `priceCents`   | `0` |
| `etaDays`      | `1` |
| `serviceLevel` | `pickup` |
| `rationale`    | `in_store_pickup` |

Customer is expected to collect same day; eta=1 keeps the SLA wording
sensible without promising same-day fulfilment.

`quote()` does not read scope config — the price is hard-coded to zero
because charging shipping for a pickup-at-counter contradicts the entire
point of the flow.

## 5. Capabilities

| Capability                     | Value | Notes |
|--------------------------------|-------|-------|
| `code()`                       | `shubopickup` | matches gateway |
| `displayName()`                | `'Pickup in store'` | |
| `supportsWebhooks()`           | `false` | nothing to webhook from |
| `supportsSandbox()`            | `true`  | live code is sandbox-equivalent |
| `supportsCodReconciliationApi()` | `false` | COD never enabled (see below) |
| `supportsPudo()`               | `false` | the merchant's address is not a PUDO |
| `supportsExpress()`            | `false` | |
| `supportsMultiParcel()`        | `false` | |
| `supportsReturns()`            | `false` | customer comes back for returns; manual |
| `supportsCancelAfterPickup()`  | `true`  | analogous to flatrate |
| `ratelimitPerMinute()`         | `600` | local-only computation |
| `checkoutTimeoutMs()`          | `100` | |
| `serviceAreaSubdivisions()`    | all 12 GE subdivisions | merchant decides availability per pickup_address; carrier itself is universally available |
| `producibleStatuses()`         | `[pending, ready_for_pickup, delivered, cancelled]` | no in_transit; pickup skips the courier states |

### 5.1 Why `supportsCod=false` is implicit (no flag exists)

`CarrierCapabilitiesInterface` does not currently have a
`supportsCod()` method — COD support is implied by the absence of
courier behaviour. Pickup carriers should always be created with
`cod_enabled=false` and `cod_amount_cents=0` on the Shubo shipment row.
The orchestrator passes through whatever the request specifies, so
`CreateShipmentOnMagentoShipment` (which already sets
`codEnabled=false`, `codAmountCents=0`) is the binding contract.

A future `supportsCod()` capability flag is a separate, registry-wide
change — out of scope for this carrier. Document the intent in the
gateway's class PHPDoc so the next maintainer doesn't add COD support
to a pickup carrier.

## 6. Adapter operations

All operations mirror `FlatRateGateway`. No real network call, no real
side effects. Tracking id format: `SHUBO-PICKUP-{hex}` produced by the
same `random_bytes(5)` + `time()` pattern.

| Method                | Behaviour |
|-----------------------|-----------|
| `code()`              | returns `shubopickup` |
| `quote()`             | single zero-cost option (§4) |
| `createShipment()`    | returns `ShipmentResponse(carrier_tracking_id='SHUBO-PICKUP-{hex}', label_url=null, status=pending, raw=[client_tracking_code, order_id, merchant_id])` |
| `cancelShipment()`    | no-op success |
| `getShipmentStatus()` | returns `STATUS_PENDING` (admin promotes to `ready_for_pickup` -> `delivered` via row actions) |
| `fetchLabel()`        | minimal valid PDF header with `%PDF-1.4\n%SHUBO-PICKUP-DEMO\n` body. Even though customers don't need a shipping label for pickup, the orchestrator always invokes `fetchLabel` after createShipment; returning a no-op PDF avoids special-casing the orchestrator |
| `listCities()`        | `[]` |
| `listPudos()`         | `[]` |

## 7. The trigger chain — and why this is a one-line module

Customer arrives at the studio, merchant rings up the sale, merchant
clicks Magento's normal **Ship** action on the order. That fires
`sales_order_shipment_save_after` -> the existing
`CreateShipmentOnMagentoShipment` observer creates a Shubo shipment row
with `carrier_code=shubopickup`, `cod_amount_cents=0`. The orchestrator
fires `shubo_shipping_shipment_created` -> the (newly re-targeted)
`Shubo_Payout\RecordOfflineOrderObserver` sees `cod_amount_cents == 0`
and writes the merchant-collected pair (`+grand_total
offline_sale_received`, `-commission offline_commission_debt`).

**No new event, no new observer, no admin button.** This is the
reason the gateway can be a near-clone of FlatRate — the work happens
upstream in the existing event chain, and the new carrier exists just
to occupy a row in the registry.

## 8. DI registration

Register the gateway and capabilities in
`/home/nika/module-shipping-core/etc/di.xml`, alongside `shuboflat`:

```xml
<type name="Shubo\ShippingCore\Model\Carrier\CarrierRegistry">
    <arguments>
        <argument name="gateways" xsi:type="array">
            <item name="shuboflat" xsi:type="object">Shubo\ShippingCore\Model\Carrier\FlatRateGateway</item>
            <item name="shubopickup" xsi:type="object">Shubo\ShippingCore\Model\Carrier\PickupInStoreGateway</item>
        </argument>
        <argument name="capabilities" xsi:type="array">
            <item name="shuboflat" xsi:type="object">Shubo\ShippingCore\Model\Carrier\FlatRateCapabilities</item>
            <item name="shubopickup" xsi:type="object">Shubo\ShippingCore\Model\Carrier\PickupInStoreCapabilities</item>
        </argument>
    </arguments>
</type>
```

No new interfaces. No virtualType. The registry composition pattern
exists explicitly so adapter additions are one-line config edits.

## 9. Files

| Path | Purpose |
|------|---------|
| `Model/Carrier/PickupInStoreGateway.php` | implements `CarrierGatewayInterface` |
| `Model/Carrier/PickupInStoreCapabilities.php` | implements `CarrierCapabilitiesInterface` |
| `etc/di.xml` | +2 array items in `CarrierRegistry` arguments |
| `Test/Unit/Model/Carrier/PickupInStoreGatewayTest.php` | new |
| `Test/Unit/Model/Carrier/PickupInStoreCapabilitiesTest.php` | new (pure value-bag verification — `FlatRateCapabilities` has no equivalent today, but the simple class deserves one when added in the same commit) |

## 10. Tests

### 10.1 `PickupInStoreGateway` (mirrors `FlatRateGatewayTest`)

- `testCodeIsShubopickup`: `code() === 'shubopickup'`.
- `testQuoteReturnsZeroPriceOption`: `quote()` -> 1 option, `priceCents=0`,
  `carrierCode='shubopickup'`, `methodCode='pickup'`, `etaDays=1`,
  `serviceLevel='pickup'`, `rationale='in_store_pickup'`.
- `testQuoteIgnoresScopeConfig`: ScopeConfig getValue is never invoked
  (no `carriers/shubopickup/price` config — price is constant zero).
  Verify by passing a strict mock that fails on any call.
- `testCreateShipmentReturnsShuboPickupPrefixedTrackingId`: with a
  fixed id-generator stub, `createShipment()` returns
  `SHUBO-PICKUP-fixed-id`.
- `testCreateShipmentEchoesMetadataInRawPayload`: `client_tracking_code`,
  `order_id`, `merchant_id` echoed in raw, identical to FlatRate test.
- `testDefaultIdGeneratorProducesUniqueIds`: two calls produce distinct
  tracking ids.
- `testGetShipmentStatusReturnsPending`: returns `STATUS_PENDING`.
- `testCancelShipmentAlwaysReportsSuccess`: idempotent success response.
- `testFetchLabelReturnsPdfHeader`: `pdfBytes` starts with `%PDF-`,
  contentType `application/pdf`, filename
  `label-SHUBO-PICKUP-{id}.pdf`.
- `testListCitiesAndPudosReturnEmptyArrays`.

### 10.2 `PickupInStoreCapabilities`

- `testCode`: `'shubopickup'`.
- `testDisplayName`: `'Pickup in store'`.
- `testSupportsWebhooks`: false.
- `testSupportsSandbox`: true.
- `testSupportsCodReconciliationApi`: false.
- `testSupportsPudo`: false.
- `testSupportsExpress`: false.
- `testSupportsMultiParcel`: false.
- `testSupportsReturns`: false.
- `testSupportsCancelAfterPickup`: true.
- `testRatelimitPerMinute`: 600.
- `testCheckoutTimeoutMs`: 100.
- `testServiceAreaSubdivisions`: all 12 GE codes
  (`GE-TB`,`GE-AB`,`GE-AJ`,`GE-GU`,`GE-IM`,`GE-KA`,`GE-KK`,`GE-MM`,`GE-RL`,`GE-SJ`,`GE-SK`,`GE-SZ`).
- `testProducibleStatuses`: contains exactly `[pending,
  ready_for_pickup, delivered, cancelled]` — verifies the no-courier
  lifecycle.

### 10.3 No DI integration test

`CarrierRegistry` is wired via DI and tested generically; adding a
specific test for "shubopickup is registered" duplicates the existing
registry tests. Skip.

## 11. Versioning

Current latest tag (verified):
`git -C ~/module-shipping-core describe --tags --abbrev=0` -> `v0.11.4`.

Bump to **`v0.12.0`** — minor bump because we add a new carrier
(public API surface). No breaking changes; existing `shuboflat`
behaviour identical. CHANGELOG: add a "Carriers" subsection under
`v0.12.0` documenting the addition; the standalone repo currently has
no CHANGELOG.md, so the developer creates one in this same release.

## 12. Composer impact in duka

Standalone-first: tag and push the `module-shipping-core` repo at
`v0.12.0`, ensure Packagist mirrors the tag (per memory:
`reference_module_distribution.md` — Vendor pattern: standalone is
canonical, duka pulls via Composer). Then in duka:

```
composer update shubo/module-shipping-core
git add composer.lock
git commit -m "chore(deps): bump shubo/module-shipping-core to v0.12.0 (shubopickup)"
```

The pre-push sync hook (`check_public_module_sync`) intentionally does
NOT cover Vendor modules, so duka's push is unblocked once
`composer.lock` is committed.

## 13. Out of scope

- Pickup window scheduling / time slots.
- Per-merchant pickup_address display name in the carrier label.
- COD support on pickup carriers (would need a registry-wide
  `supportsCod` capability).
- Customer-facing notification email overrides.
- ka_GE translation file (covered by the language-pack mirror module).
