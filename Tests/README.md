# MyFlyingBox — Tests

## Run

```bash
ddev exec "cd Thelia2.6/local/modules/MyFlyingBox && vendor/bin/phpunit --testsuite unit"
```

## Coverage tiers

| Tier         | Suite        | What it covers                                              | Needs                |
|--------------|--------------|-------------------------------------------------------------|----------------------|
| Unit         | `unit`       | Pure services, no DB. Mocks Order/Offer via `dg/bypass-finals`. | PHP only           |
| Functional   | `functional` | BO endpoints (3 routes) + E2E scenario (BO → postage → save). | DDEV + admin user + Propel fixtures |

## Bootstrap notes

`Tests/bootstrap.php` does three things:

1. Loads the parent Thelia project autoloader so Thelia/Symfony classes resolve.
2. Loads the module's local autoloader (PHPUnit, dev deps).
3. Registers a fallback PSR-4 loader for Propel-generated `Base/...` and `Map/...`
   classes in `var/cache/<env>/propel/model/`. These classes are only present
   after Thelia has built its container at least once. If you see
   `Class "Thelia\Model\Base\Order" not found`, run any `bin/console` command
   in DDEV to regenerate the propel cache.
4. Calls `\DG\BypassFinals::enable()` so PHPUnit can mock final readonly
   services (`PriceSurchargeService`, etc.) without a production refactor.

## Module-id static cache

`BackOfficeShipmentUpdater` calls `MyFlyingBox::getModuleId()` (inherited from
`BaseModule`) which hits `ModuleConfigQuery` on a cache miss. Tests prime
`BaseModule::$moduleIds` reflectively in `setUp()` to avoid a DB lookup.

## Functional tests — TODO

The functional suite is intentionally empty in this commit. Writing it
requires:

- a Thelia kernel boot (`Thelia\Tests\Tools\Bootstrap` pattern) so admin auth,
  CSRF token providers, and Propel are wired up;
- DB fixtures for an `Order` + `Cart` + `MyFlyingBoxQuote` + offers;
- a stub for `LceApiService::getDeliveryLocations()` to keep the relay-list
  endpoint deterministic.

Tracking issue: see THE-552 follow-up comment.
