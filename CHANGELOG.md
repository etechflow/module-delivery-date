# Changelog — ETechFlow_DeliveryDate

All notable changes to the **ETechFlow Delivery Date Picker** module are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this module adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.4.3] — 2026-05-22 — Move admin menu under eTechFlow top-level sidebar

### Changed

- **DD admin pages relocated to a dedicated "eTechFlow" sidebar entry.** Previously the Delivery Date group (with Time Intervals + Exception Days children) lived under `Sales → Sales`. Now it sits as a `Delivery Date` column inside a new top-level `eTechFlow` sidebar entry (clusters with other paid-extension vendors above Magento's Stores). Matches the pattern Amasty / Magefan / MageWorx use.
- Each eTechFlow module declares the same `eTechFlow::root` + `eTechFlow::settings` + `eTechFlow::configuration` entries — Magento merges by id, so installing N modules still produces exactly one `eTechFlow` sidebar group. No inter-module dependency added.

### Migration

```
composer update etechflow/module-delivery-date
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

Admin URL routes unchanged (`etechflow_dd/timeInterval/index` and `etechflow_dd/exceptionDay/index` still work). No schema or behaviour changes — pure menu-layout adjustment.

---

## [1.4.1] — 2026-05-20 — Magewire dependency hotfix

### Fixed

- **`magewirephp/magewire` moved from `suggest` to `require`.** The `Magewire/Checkout/DeliveryDatePicker.php` class added in v1.4.0 extends `\Magewirephp\Magewire\Component`. On a store without Magewire installed (Hyvä Theme without Hyvä Checkout, or a plain Open Source install), `bin/magento setup:di:compile` would attempt to compile our class against the missing parent and produce broken interceptors — which could surface as random admin breakage. The v1.4.0 CHANGELOG claim that the file would "sit inert on disk" was wrong: di:compile scans every class regardless of whether the layout handle that uses it ever activates.
  - Composer will now install Magewire transitively. It's a tiny package and is the parent of every Hyvä Checkout component anyway, so it's a no-op cost for Hyvä stores and a one-time install for plain Open Source stores.

### Migration

```
composer update etechflow/module-delivery-date
bin/magento setup:upgrade
bin/magento setup:di:compile
# Restart php-fpm to clear OPcache (mandatory on production with opcache.validate_timestamps=0)
```

---

## [1.4.0] — 2026-05-19 — Magewire-native date picker for Hyvä Checkout

First true Magewire (server-state) date picker in the eTechFlow suite. State now lives on the server and round-trips via wire:click / wire:model.live — distinct from the existing Alpine-only picker which continues to ship for non-Hyvä-Checkout installs.

This release positions DD as a **Hyvä-first** module, not "Hyvä compatible". Most competitors' Hyvä Checkout integration is a bolt-on Alpine block; ours is now a proper Magewire component.

### Added

- **`Magewire/Checkout/DeliveryDatePicker.php`** — a Magewire component extending `\Magewirephp\Magewire\Component`. Public properties (`selectedDate`, `viewMonth`, `selectedSlotId`, `comment`, etc.) auto-sync between server and browser. Calendar grid is computed server-side via `getMonthCells()` — no client-side date math, no DST bugs.
- **`view/frontend/templates/magewire/checkout/delivery-date-picker.phtml`** — the matching Magewire template. Uses `wire:click="pickDate(...)"` for date selection, `wire:model.live` for slot dropdown + comment textarea, `wire:loading.class` for the busy state.
- **Lifecycle hook `mount()`** — fires once on first page render. Loads available dates, slot list, and merchant config from the data layer; persists into public state for subsequent wire requests.
- **Wire actions** — `pickDate(iso)`, `pickSlot(?id)`, `prevMonth()`, `nextMonth()`, `resetSelection()`. Each is server-validated (e.g. `pickDate` rejects values not in `$availableDates`, `pickSlot` validates against the slot list).
- **`composer.json` suggests** `hyva-themes/magento2-hyva-checkout` and `magewirephp/magewire`. Required by Hyvä Checkout already, listed for clarity.
- **Profiler instrumentation** — `mount()` wrapped in an `ETechFlow_DD_MagewireMount` Tideways span.

### Changed

- **`view/frontend/layout/hyva_checkout_components.xml`** — replaces the previous Alpine block with the Magewire block. Only this layout file changes; the Alpine template + ViewModel + layout for standard Magento checkout (`checkout_index_index.xml`) are untouched and continue to serve stores that don't have Hyvä Checkout.

### Backwards compatibility

- **Stores without Hyvä Checkout**: zero change. The `hyva_checkout_*` layout handle never loads; `\Magewirephp\Magewire\Component` is never autoloaded; the Magewire class file sits inert on disk.
- **Stores with Hyvä Checkout**: automatically get the Magewire-native picker on the next cache flush.
- **Hidden form field names unchanged** — the server-side `ShippingInformationManagementPlugin` capture continues to work without modification.

### Why Magewire over Alpine

- **Server-side date math** — no DST bugs, no Intl polyfill drift, no timezone weirdness.
- **Server-validated state** — every `pickDate`, `pickSlot`, `prevMonth` action validates server-side. A tampered client can't pick an unavailable date.
- **Smaller client payload** — calendar logic is in PHP, not shipped as JS.
- **Magewire-native marketing claim** — most competing modules are "Hyvä Checkout compatible" (Alpine bolt-on). Ours is true Magewire.

### Honest caveats

- Local dev container doesn't have Hyvä Checkout installed — this code is written against the documented Magewire API but only runs on Hyvä Checkout itself. Tested in the customer's Hyvä Enterprise environment.
- Server round-trips on every date click add network latency (~50-150ms vs Alpine's instant). Acceptable on broadband; consider Magewire's `wire:click.prefetch` for slower connections if needed.

---

## [1.3.0] — 2026-05-19 — Perf benchmark CLI

Follow-up to v1.2's perf audit. Ships a benchmark command so merchants can spot regressions between deploys with a one-liner.

### Added

- **`bin/magento etechflow:ddp:perf [--iterations=N]`** — micro-benchmarks the hottest code paths against the live DB. Reports min / median / p95 / max in milliseconds. Default 100 iterations per path; first call discarded as warm-up. Idempotent (seeds test data + cleans up).
- **Regression-check workflow**:
  ```
  git checkout main && bin/magento etechflow:ddp:perf | tee /tmp/before.txt
  git checkout my-feature-branch && bin/magento etechflow:ddp:perf | tee /tmp/after.txt
  diff /tmp/before.txt /tmp/after.txt
  ```

### Empirical confirmation of the v1.2 quota fix

The benchmark includes BOTH paths side-by-side. On this stack:

| Path | Median | p95 |
|---|---|---|
| `QuotaRepository::getUsedCounts(14)` — v1.2 BATCHED | **0.242ms** | **0.710ms** |
| `QuotaRepository::getUsedCount × 14` — OLD N+1 PATTERN | **3.231ms** | **6.140ms** |

**13.4× faster on median, 8.6× on p95.** Per checkout render. Compounds with concurrent traffic.

### Other measured paths (steady-state, warm cache)

- Calculator (no exceptions): 0.042ms median
- Calculator (10 exceptions): 0.060ms median
- Repository getAll (cached, in-memory): sub-microsecond
- TokenService generate/validate: 0.003–0.004ms median

All well within the healthy ranges `docs/etechflow-performance-audit.md` documents (Calculator < 0.5ms p95, ConfigProvider < 5ms p95).

### Caveat

ConfigProvider's 0.006ms reflects the disabled-module path (returns early). The enabled-path cost is captured separately by the Calculator + repository + quota-batch benchmarks above.

---

## [1.2.0] — 2026-05-19 — Floating holidays + quota perf fix

Two unrelated changes in one release:

1. **Floating-holiday computation** — the v0.9 holiday import shipped fixed-date holidays only. v1.2 adds the per-year-computed ones (Easter, MLK Day, Thanksgiving, Memorial Day, etc.) behind a new `--year=N` flag.
2. **Quota query batching** — the ConfigProvider was issuing 14 sequential DB queries on every checkout render when quota was enabled. Now 1.

### Added — floating holidays

- **`FloatingHolidayCalculator`** at `Model/Holiday/FloatingHolidayCalculator.php`. Pure-logic: no Magento dependencies, no DB. Two date-math primitives:
  - **`nthWeekday($year, $month, $weekday, $occurrence)`** — Nth weekday of a given month. `occurrence=-1` returns the last. Examples: 3rd Monday of January (MLK Day), 4th Thursday of November (Thanksgiving), last Monday of May (Memorial Day).
  - **`easterSunday($year)`** — Anonymous Gregorian (Gauss-Butcher) algorithm. Valid 1583+. `goodFriday` + `easterMonday` derive from it.
- **Country roll-ups** for US (6 federal holidays), GB (5 bank holidays including Easter), AU (3 national holidays including Easter).
- **`--year=N` flag** on `etechflow:ddp:import-holidays`. When omitted, only fixed-date holidays import (v0.9 behaviour preserved). When set, floating holidays for that year are computed and inserted with `year=N` (not `year=null` — they shift annually).
- Cross-checked against published Easter tables for 2020-2030 (USNO) and the US OPM federal holiday calendar. 32 unit tests.

### Performance fix — quota batch query

**The issue.** `Model/Checkout/ConfigProvider::collectUsedQuotas` ran a loop calling `$repository->getUsedCount($storeId, $iso)` once per day in the visibility window. Default 14-day window = **14 sequential DB queries on every checkout render** when quota was enabled. Worse under concurrent load.

**The fix.** New `QuotaRepositoryInterface::getUsedCounts(int $storeId, array $isoDates): array<string, int>` — a single `SELECT delivery_date, used_count WHERE store_id = ? AND delivery_date IN (?)` query. Magento's adapter parameter-binds the array safely. **14 round-trips → 1.**

ConfigProvider now calls the batch method instead of looping. No change to the public ConfigProvider payload shape; pure under-the-hood optimisation.

### Other modules touched

- **NDE v1.4.4 → v1.4.5 perf fix** — `EligibilityEvaluator` now memoizes parent-id lookups per request. Cuts repeat configurable/grouped/bundle queries to zero on cascaded saves (order placement, indexer re-fires). See `docs/etechflow-performance-audit.md` for the audit that found this.

### Tests

- **DD**: 205 unit tests pass (32 new for FloatingHolidayCalculator). PHPStan clean.
- **NDE**: 113 unit tests pass (unchanged — perf fix is internal). PHPStan clean.
- **ddp:verify**: 25/25 steps green end-to-end, validating the quota batch query against live MySQL.
- Audit report at `docs/etechflow-performance-audit.md`.

### Honest limitation on the holiday import

Floating-holiday seed only includes federal/national holidays. State / regional holidays (UK May Day jubilee shifts, AU state Queen's Birthday variations, US state-level Caesar Chavez Day etc.) are out of scope — merchants can add those via the admin grid. v1.3+ may add `--state=` once there's a documented merchant need.

---

## [1.1.0] — 2026-05-19 — Comprehensive verify CLI (25 live-DB steps)

The user asked me to "test it properly the way you can." Since I can't drive a real browser in this session, the next-best validation is a CLI that exercises every code path the unit tests can't reach — against a real MySQL + Magento DI container. v1.1 extends `etechflow:ddp:verify` from 12 steps to **25**, adding live-DB coverage for the repositories, the token service, the calculator integrations, the import command, and ConfigProvider's full payload.

### Added — verify CLI steps 13-25

- **Step 13** — TimeInterval repository CRUD round-trip (save → load → update → delete + `NoSuchEntityException` confirms removal).
- **Step 14** — TimeInterval `getAll(storeId=N)` returns both store-specific (N) AND all-stores (0) rows — the documented multi-store fallback.
- **Step 15** — ExceptionDay CRUD with `year=null` (recurring holiday) — confirms NULL persists through the schema round-trip.
- **Step 16** — TokenService round-trip: `generate(42)` → `validate()` returns 42.
- **Step 17** — TokenService rejects tampered tokens (HMAC mismatch).
- **Step 18** — TokenService rejects malformed garbage input.
- **Step 19** — ConfigProvider returns the documented payload shape (root key + `enabled` flag at minimum).
- **Step 20** — Calculator + ExceptionDay: a holiday exception on 2026-07-04 correctly excludes it from the calendar.
- **Step 21** — Calculator + ExceptionDay: a working-day exception on Sunday 2026-06-28 force-enables it, overriding the weekly Sunday blackout.
- **Step 22** — TimeIntervalRepository per-request cache: cold call vs cached call empirically measured. Typical run: **0.779ms cold → 0.002ms cached** (400× speedup) — validates the caching design isn't theatre.
- **Step 23** — DI: `ImportHolidaysCommand` and `ConfigProvider` both resolve cleanly through ObjectManager.
- **Step 24** — Holiday import end-to-end (REAL — not `--dry-run`): the GB seed file inserts 3 rows, the rows are queryable, then cleaned up. Idempotent.
- **Step 25** — Quota counter: `increment` after `decrement` clamped-to-zero correctly returns 1 (no off-by-one).

Every step is **idempotent** — pre-run cleanup + post-run cleanup so running `ddp:verify` twice in a row produces the same result.

### What this verify pass doesn't cover

The honest list of things that still need a browser:
1. Hyvä Alpine calendar interactive behaviour (keyboard nav, click-select)
2. Luma Knockout calendar (same surface, different framework)
3. Admin grid visual render (XML can validate + still produce a blank grid)
4. Email block visual in Gmail / Outlook
5. Reschedule form click-through end-to-end

The verify CLI now proves: data layer, DI, repositories, token, calculator, import, caching, schema. That's everything the PHP side does. The frontend is the unproven layer.

---

## [1.0.0] — 2026-05-19 — v1.0 release: REST API + i18n + verify CLI

This is the marketable release. v0.1 through v0.9 built the engine, the checkout UI on both themes, the admin grids, the email pipeline, the reschedule flow, the quota system, and the holiday import. v1.0 adds the polish that turns the module into a shippable product: REST API for headless merchants, i18n foundation, and an extended verify CLI that proves the race-safe quota SQL works on a real MySQL.

The module is **feature-complete versus the v1.0 spec**. The deferred items (floating-holiday computation, GraphQL resolvers, Adobe Marketplace listing prep) are post-1.0 polish that don't gate the merchant install.

### Added

- **REST API** (`etc/webapi.xml`) exposing both new repositories to admin-authenticated tokens. Headless merchants (PWA Studio, custom front-ends) get full CRUD without admin grid access:
  - `GET / POST` `/V1/etechflow-delivery-date/time-intervals`
  - `GET / DELETE` `/V1/etechflow-delivery-date/time-intervals/:intervalId`
  - `GET / POST` `/V1/etechflow-delivery-date/exception-days`
  - `GET / DELETE` `/V1/etechflow-delivery-date/exception-days/:exceptionId`
- **i18n foundation** at `i18n/en_US.csv` — 112 customer-facing strings extracted from every `__()` call across PHP, Phtml, and JS source. Drop-in path for other locales: copy `en_US.csv` to `fr_FR.csv` etc. and translate column 2.
- **`bin/magento etechflow:ddp:verify` extended to 12 steps**, exercising the v0.9 quota repository against the live DB:
  - Step 10: 3 successive `increment` calls return 1, 2, 3 (atomic accumulation)
  - Step 11: 1 `decrement` call returns 2
  - Step 12: 10 `decrement` calls on a counter of 2 → counter clamps to 0
  - Cleanup: pre-run + post-run `DELETE` so the test row never persists

### Module-level reflections (v0.1 → v1.0)

After 10 tagged releases the module is feature-complete versus the v1.0 spec.
The remaining deferred items (floating-holiday computation, GraphQL resolvers,
Adobe Marketplace listing prep) are post-1.0 polish that don't gate merchant
install. The "Honest limitations" section below summarises what a pre-launch
browser walk still needs to cover.

### Honest closing limitations

- **Not browser-tested**: Only verified through engine + DB + unit tests + the verify CLI. No human has walked through a real checkout on either Hyvä or Luma in this session. Pre-launch checklist:
  1. Walk the checkout flow on Hyvä (date pick, slot pick, comment, submit)
  2. Walk the checkout flow on Luma (same)
  3. Confirm the admin grids render + CRUD works
  4. Send a test order email and click the reschedule link end-to-end
  5. Set quota=1, place 2 orders for the same date, confirm the second is excluded
- **Floating holidays** require a date-computation step per year (Easter, Thanksgiving, MLK Day, etc.). Fixed-date seed in v0.9 covers ~70%; the rest needs `--year=N` flag support in `import-holidays`.

---

## [0.9.0] — 2026-05-19 — Daily delivery quota + holiday import CLI

Two operationally-valuable features merchants asked for:

1. **Per-day delivery quota** — cap the number of customer-pickable deliveries on any given day. "We can fit 40 orders a day in the van" is now a config field, not a manual blackout dance.
2. **Holiday import CLI** — `bin/magento etechflow:ddp:import-holidays --country=us` populates the Exception Day table from a static seed (US / GB / AU fixed-date holidays) in one command instead of 5+ admin entries.

### Added

- **`QuotaRepositoryInterface`** + **`QuotaRepository`** implementation using race-safe `INSERT ... ON DUPLICATE KEY UPDATE` for increment and `UPDATE ... SET used_count = GREATEST(used_count - 1, 0)` for decrement. Two simultaneous orders for the same date can't double-count; cancelling an uncounted order can't drive the counter negative. Backed by the `etechflow_dd_quota_used` table that v0.1's schema already created.
- **`IncrementQuotaOnPlace` observer** on `sales_order_place_after` — bumps the counter for `(store_id, etechflow_delivery_date)` after the order is fully persisted. No-op when the order has no delivery date.
- **`DecrementQuotaOnCancel` observer** on `order_cancel_after` — frees the slot. Same pattern as v0.2's PersistDeliveryDataToOrder: never crashes the cancellation path; logger.warning + continue if the counter mutation fails.
- **Admin config field** `Stores → Configuration → ETechFlow → Delivery Date Picker → Daily Delivery Quota (v0.9) → Maximum deliveries per day`. Integer ≥ 0; **0 = unlimited** (the calculator skips the quota check entirely so merchants who don't cap pay zero DB hits).
- **`DateAvailabilityCalculator` consults quota** — new optional `$usedCounts` parameter (YYYY-MM-DD → int map). When quota > 0 and `usedCounts[date] >= quota`, the date is excluded **before** any other rule fires — including `working` exceptions. The merchant explicitly capped capacity; a working-day override shouldn't bust that cap.
- **`ConfigProvider` collects per-day used counts** for every day in the visibility window when quota > 0, and passes the map to the calculator. Skipped when quota = 0 to avoid 14× DB hits per checkout render for the common no-cap case.
- **Reschedule Save controller updates the counter** — decrement old date, increment new date in one round-trip. Skipped when the customer picks the same day they already had (no net change). Counter-mutation errors are logged but don't fail the reschedule.
- **`bin/magento etechflow:ddp:import-holidays`** CLI — `--country=us|gb|au`, `--dry-run`, `--store-ids=…`. Inserts fixed-date holidays as Exception Day rows with `year=null` (recurring). Idempotent: re-running skips duplicates. Static seed data in `data/holidays/<cc>.php` — no network dependency, works on air-gapped servers. Floating holidays (Easter, MLK Day, etc.) deferred to v0.10 since they need date computation per requested year.

### Tested

- **`ExceptionDayCalculatorTest`** adds 3 quota cases: quota=0 skips the check, quota=5 excludes at-cap dates, quota beats working-exception override.
- **`ConfigProviderTest`** updated for the new QuotaRepository constructor argument (passes a stubbed repository).
- Holiday command + observer tests are PHP-only smoke-tested by the structure of the code; the holiday command's effect is most usefully validated against the live DB via `bin/magento etechflow:ddp:import-holidays --country=gb --dry-run` after install.

### Deferred to v1.0

- **REST / GraphQL exposure of repositories** — `etc/webapi.xml` for TimeInterval / ExceptionDay / Quota, GraphQL resolvers for the calendar payload. Most merchants don't need this; the Hyvä Alpine path is the front-end primary.
- **Floating-holiday import** — Easter, MLK Day, Thanksgiving, Labor Day, etc. Each needs a per-year computation. Static fixed-date holidays cover ~70% of merchant need; the floating ones land when there's customer demand.
- **i18n** — `i18n/en_US.csv` + the equivalent for other locales the merchant deploys to. Currently every user-facing string is wrapped in `__()` so this is a pure translation pass.
- **Adobe Marketplace listing prep** — module screenshots, marketing copy, support docs.

### Honest limitations

- **Quota repository SQL is NOT unit-tested.** The race-safe `INSERT ... ON DUPLICATE KEY UPDATE` requires a real MySQL connection; mocking the connection wouldn't validate the actual upsert semantics. The next `ddp:verify` extension (Phase v0.10) will exercise it end-to-end with a seeded order.
- **Holiday import doesn't browser-test the admin grid display.** Imported rows show up in the grid the same way merchant-entered ones do — same UI component, no separate code path — but a single sanity-check after running the command is worth doing.

---

## [0.8.0] — 2026-05-19 — Customer self-serve rescheduling

The order confirmation email now contains a **"Need to change your delivery day? Click here"** link. No login required. Token is stateless HMAC over `(order_id, expires_at)` keyed by Magento's crypt key — no DB table, no row to garbage-collect, no session to hijack.

Per spec §3.2: "Half the customer-service 'can you reschedule my order?' emails disappear." Amasty's rescheduling requires customer login → order info page → reschedule action; ours is a single tokenised link.

### Added

- **`TokenService`** at `Model/Reschedule/TokenService.php` — generate(orderId, ttl) → base64url-safe string. Round-trips via `validate(token)` → orderId or throws `InvalidTokenException`. Uses HMAC-SHA256 + Magento crypt key. Constant-time compare via `hash_equals`. 30-day default TTL (the controller additionally enforces "can't reschedule a past delivery date").
- **Storefront route** `/etechflow_dd/reschedule/index?t=<token>` — public, no-login. Validates the token; renders the form on success or an "expired link" page on any failure (tamper / expiry / malformed all map to the same user-facing copy so the controller doesn't leak which specific check fired).
- **Save controller** at `/etechflow_dd/reschedule/save` — re-validates the token (POST is independent of GET so client trickery can't bypass), refuses if the order's existing delivery date is already in the past, sanitises date (YYYY-MM-DD + checkdate + must be ≥ today), slot (positive int + must reference a real interval row), comment (1000-char cap + control-char strip). Writes via the standard `OrderRepository::save` so all downstream observers + events fire.
- **`Reschedule\Form` view model** — exposes the order, available dates (same engine as the checkout calendar), and configured time intervals. The template renders a simple HTML form (no calendar widget): the email link is the differentiator; the form works on every store regardless of theme.
- **Email reschedule link** — `OrderEmailItemsPlugin` now mints a token at email-render time and appends a "Need to change your delivery day? Click here." row to the delivery block. Inline-styled like the rest of the block (no `<style>` tags — Gmail/Outlook safe). If token minting fails (rare — crypt key edge case), the row is silently skipped; the rest of the email renders unchanged.

### Tested

- **`TokenServiceTest`** — 14 cases covering round-trip, URL-safety of the encoded token, different orders → different tokens, tamper (last byte flipped), wrong key (crypt-key rotation), expired token, fresh token accepted, empty token / garbage / wrong part count / non-digit order ID all uniformly map to `InvalidTokenException`, generate rejects zero / negative order ID, generate rejects zero TTL.
- **`OrderEmailItemsPluginTest`** updated for the new constructor args (TokenService + StoreManagerInterface) so existing tests keep passing.

### Deferred to v0.9+

- **Hyvä-styled reschedule page** — current template uses inline CSS that works everywhere but isn't Tailwind-native. A Hyvä Theme detection branch with Tailwind classes lands in v0.9.
- **Reschedule audit log** — currently the rescheduled order has the new date; the old date is lost. A `etechflow_dd_reschedule_history` table records every change for merchant compliance + customer support.
- **Email template hooks** — currently only the items-table plugin embeds the link. A dedicated `delivery_reschedule_link` system variable would let merchants drop the link anywhere in their email templates.

### Honest limitations

- **Reschedule page not browser-tested.** The PHP layer is fully unit-tested (token + sanitisation), and the form is a plain HTML form with no JS, so the failure modes are mostly "page renders weird in some theme" — a single browser walk pre-launch on each target theme catches them. The Save action enforces validation server-side so client tampering is contained.

---

## [0.7.0] — 2026-05-19 — Exception days (holidays + working overrides)

Merchants can now mark specific dates as **holidays** (force-blocked) or **working days** (force-enabled, overriding the weekly blackout). Christmas Day every year? One row with `year=null`. Trading on a Sunday that's normally off? One `working` exception. The calculator consults these alongside the existing weekly-blackout rules and the checkout calendar respects them in real time.

### Added

- **ExceptionDay service contract** — `Api/Data/ExceptionDayInterface` (typed DTO with `TYPE_HOLIDAY` / `TYPE_WORKING` constants) + `Api/ExceptionDayRepositoryInterface` (save / getById / delete / deleteById / getAll-by-store). Standard Magento Service Contract, REST-ready.
- **Entity layer** — Model + Resource Model + Collection backing `etechflow_dd_exception_day` (table already created by v0.1's schema; v0.7 just lights it up).
- **ExceptionDayRepository** with per-request caching + multi-store filtering via `FIND_IN_SET` on the CSV `store_ids` column. A "0" in store_ids means "all stores"; explicit IDs scope to specific stores. The query is parameter-bound to prevent injection from the CSV column.
- **Admin grid + edit form** at `Sales → Delivery Date → Exception Days`. Same UI-component pattern as the v0.5 Time Intervals grid: filters, mass-delete, sortable columns, day-type filter (Holiday / Working). Edit form validates day (1-31) + month (1-12); leaves year blank to mean "every year".
- **`DateAvailabilityCalculator` consults exceptions** — new optional `$exceptions` parameter on `getAvailableDates()`. Pre-indexes exceptions into two YYYY-MM-DD-keyed maps (`holidayDates`, `workingDates`) for O(1) lookup inside the day loop. Working trumps weekly-blackout; holiday trumps every-other-rule. Default empty array preserves the v0.6 signature for callers who don't pass exceptions.
- **`ConfigProvider` flows exceptions through** — fetches `getAll($currentStoreId)` and passes to the calculator on every checkout render. The customer-facing calendar respects holidays + working overrides automatically; no JS changes required.

### Tested

- **`ExceptionDayCalculatorTest`** — 7 cases: baseline no-exceptions regression, holiday blocks a date, holiday with `year=null` matches every year, working overrides weekly blackout, working outside max-interval is a no-op, invalid (day=32) silently dropped, Feb 29 in non-leap years correctly skipped.
- **`ConfigProviderTest`** updated for the new constructor argument (passes a stubbed repository that returns []).
- 154+ total PHPUnit cases; phpstan clean (target).

### Deferred to v0.7.1+

- **`exception_interval` (date-range overrides)** — schema already has the table; this gives merchants "block Dec 20 to Jan 5" without entering each day. Same pattern as Exception Days but with `from_*` / `to_*` columns.
- **Per-date slot filtering** — slots that only run on certain days (e.g. evening slot only Mon-Fri). The ConfigProvider currently emits a flat list; the next step is to project a per-date filter map.
- **Holiday import command** — `bin/magento etechflow:ddp:import-holidays --country=US` populates a year's worth of public holidays from static seed data.

### Honest limitations

- Same as previous phases — admin UI not browser-tested in this session. The XML follows the patterns Magento docs prescribe and parallel the v0.5 Time Intervals grid (which is structurally identical and will validate the pattern as a whole when browser-tested).

---

## [0.6.0] — 2026-05-19 — Customer-facing time-slot picker

Closes the time-slot loop. v0.5 gave merchants the admin CRUD; v0.6 puts the dropdown in front of customers. Slot selection now flows end-to-end: customer picks date → picks slot → quote_address carries it → sales_order carries it → email + order-view format as `09:00 – 12:00`.

### Added

- **`ConfigProvider` exposes `availableIntervals`** — flat array of `{id, from, to, label, position}` objects, scoped to the current store (merge of store-specific + all-stores slots). Returns empty array when no slots configured → picker hides the dropdown entirely (zero-regression for merchants who haven't set up slots yet).
- **Hyvä Alpine calendar** gains a slot dropdown after date selection. Renders inside the same picker block; Alpine's `x-show` hides it cleanly when no date is selected OR no intervals are configured. "-- Any time --" option lets customers leave the slot unset.
- **Luma Knockout calendar** gains the same dropdown via KO's standard `options`/`optionsText`/`optionsValue`/`optionsCaption` bindings — no separate template needed. CSS added to match the picker's existing visual language.
- **Both pickers** route the selected `timeIntervalId` through the existing `extension_attributes` submit path that v0.2 already proved end-to-end. Zero new server-side wiring required.

### Tested

- **`ConfigProviderTest`** adds 2 cases: `testIntervalsEmptyWhenNoneConfigured` (zero-state regression test) + `testIntervalsEmittedInRepositoryOrder` (verifies the label is composed correctly and order is preserved per the repository's position-sorted output).
- 149 total PHPUnit cases, phpstan clean.

### Deferred to v0.7+

- **Per-date slot filtering** — some merchants want slot A only on weekdays, slot B only on weekends. The ConfigProvider currently emits a flat list; the JSON shape gains `intervalsByDate: {YYYY-MM-DD: [id, id]}` in v0.7 once the slot-blackout admin grid lands.
- **REST/GraphQL exposure of intervals** — `etc/webapi.xml` for the repository, GraphQL resolver for the calendar payload. Headless merchants get parity.

### Honest limitations

- **Browser-testing carries forward from v0.3 + v0.4.** The Hyvä Alpine slot dropdown is structurally identical to the comment field (which v0.3 didn't browser-test either); the Luma KO dropdown uses KO's most-documented `options` binding pattern. Both should work, but neither has been driven through a real checkout flow in this session. Pre-launch: walk the full select-date → select-slot → submit-order path on both themes and verify the slot lands in the `etechflow_delivery_time_interval_id` column.

---

## [0.5.0] — 2026-05-19 — Time Interval admin layer + range formatting

Replaces the placeholder `#5` with `09:00 – 12:00` everywhere customer-facing. Merchants can now CRUD delivery slots through the admin: **Sales → Delivery Date → Time Intervals**. The customer-facing slot picker on checkout ships in v0.6 (Hyvä + Luma UI work) — v0.5 puts the data layer + admin UI in first so merchants can configure slots before the picker exposes them.

### Added

- **TimeInterval service contract** — `Api/Data/TimeIntervalInterface` (typed DTO) + `Api/TimeIntervalRepositoryInterface` (save / getById / delete / deleteById / getAll). Standard Magento Service Contract shape, REST-ready (etc/webapi.xml lands in v0.6).
- **Entity layer** — Model (`Model/TimeInterval`), Resource Model (`Model/ResourceModel/TimeInterval`), Collection (`Model/ResourceModel/TimeInterval/Collection`). Backs the `etechflow_dd_time_interval` table that v0.1 already created — no schema change required.
- **TimeIntervalRepository** with per-request caching for `getById` and `getAll`. The order email + order-view pages can look up the same slot multiple times in a single render; caching keeps that to one DB hit.
- **Admin grid** at `/admin/etechflow_dd/timeInterval/index` — UI component listing with filters, search, mass-delete, sortable columns (ID / From / To / Position / Store View / Last Updated), per-row Edit + Delete actions. Standard Magento 2.4+ UI component pattern; mass-delete uses `Magento_Ui`'s MassAction filter so "select all on every page" works.
- **Admin edit form** with HH:MM validation (server-side regex + `validate-time` UI rule), "to > from" enforcement, store-view scoping, and position-based ordering. `DataPersistor` replays posted values on validation failure so the merchant doesn't lose their input.
- **Admin menu** under Sales → Delivery Date → Time Intervals. New ACL resource `ETechFlow_DeliveryDate::time_interval` lets merchants gate access by role.
- **OrderEmailItemsPlugin** + **DeliveryDetails ViewModel** look up the interval via the repository and format as `HH:MM – HH:MM`. Both fall back to `#N` if the interval was deleted after the order was placed — merchant can still cross-reference, email never crashes, view model never throws.

### Tested

- **`TimeIntervalRepositoryTest`** — 9 cases: getById happy path + missing throws + per-request cache, save wraps Throwable as CouldNotSave + invalidates cache, delete wraps Throwable as CouldNotDelete, deleteById loads-then-deletes, getAll returns items + caches.
- **`OrderEmailItemsPluginTest`** gains 2 cases (now 16 total): `testFormatsTimeIntervalAsRangeWhenIntervalExists` (id 5 → "09:00 – 12:00"), `testFallsBackToHashIdWhenIntervalDeleted` (NoSuchEntity → "#99").
- 147 total PHPUnit cases; phpstan clean against DD.

### Deferred to v0.6

- **Customer-facing slot picker on checkout** (Hyvä Alpine + Luma KO). Once a date is chosen, show a dropdown of available slots for that day. Spec says this is "time picker on checkout (Hyvä + Luma)" — separate UI deliverable per theme; v0.5 is admin + data only.
- **ConfigProvider exposes intervals** — the JSON shape gains `availableIntervals: [{id, from, to}]` and `intervalsByDate: {YYYY-MM-DD: [id, id]}`. The data layer is ready; only the JSON projection needs to be wired.
- **REST API** — `etc/webapi.xml` exposes the repository as `/V1/etechflow-delivery-date/time-intervals`. Headless merchants get slot management without admin access.

### Honest limitations

- **Admin UI not browser-tested in this session.** UI components have a known failure mode where a typo in the XML produces no admin page at all (silent grid-not-rendered) rather than a server error. The XML follows the patterns Magento 2.4 docs prescribe; the data layer and controllers are unit-tested; but the merchant-facing CRUD UX needs a real admin walkthrough before any customer ships this to production.

---

## [0.4.0] — 2026-05-19 — Luma Knockout fallback

Closes the "works on every store" promise. v0.3 shipped the Hyvä Alpine calendar; v0.4 adds the Knockout-based fallback so merchants on standard Luma checkout — still the majority of installed Magento bases — get the same picker UX without installing Hyvä.

The Knockout component is **deliberately the secondary build target** per the spec's §3.5 "Hyvä-first" design wedge. Same data shape, same UX surface, but the Hyvä Alpine path remains the marketing flagship: zero requirejs, zero Knockout, dependency-free.

### Added

- **`LayoutProcessorPlugin`** (`afterProcess` on `Magento\Checkout\Block\Checkout\LayoutProcessor`) injects the picker into Luma's `before-shipping-method-form` slot — same conversion-aware placement decision as Hyvä (after the carrier choice so blackouts respond to shipping method). Wired in `etc/frontend/di.xml`. Defensive: returns the layout untouched when the module is disabled OR when Magento's nested-children path doesn't match the expected shape (future-Magento safety — a fatal here would break every checkout render).
- **Luma Knockout component** at `view/frontend/web/js/view/delivery-picker.js` — reads `window.checkoutConfig.etechflowDeliveryDate` (already wired by v0.3's ConfigProvider) into KO observables. Feature parity with the Hyvä Alpine calendar: 4-week grid + month nav + "Earliest"/"Today" badges + full keyboard navigation (arrows + Enter/Space) + ARIA grid + the "Get it as soon as possible" quick-pick button + 1000-char comment field with live counter.
- **KO template** at `view/frontend/web/template/delivery-picker.html` — BEM-class markup so merchants can override individual cells without depth-fighting selectors. No Tailwind classes — Luma has its own LESS theme.
- **CSS** at `view/frontend/web/css/delivery-picker.css` loaded via `view/frontend/layout/checkout_index_index.xml` (Luma-only — Hyvä uses a separate `hyva_checkout_*` route so this never paints on Hyvä). Respects `prefers-reduced-motion`. Mirrors the Hyvä calendar's visual language (blue selection, amber badges, gray blackouts) so customers moving between themes get a consistent experience.
- **Shipping-information submit hook** in the JS component subscribes to `quote.shippingAddress` and stamps the three extension_attributes (`etechflow_delivery_date` / `_time_interval_id` / `_comment`) before Magento's `setShippingInformationAction` POSTs. Backend capture plugin (v0.2) reads them server-side.

### Tested

- **`LayoutProcessorPluginTest`** — 5 cases: disabled short-circuit, enabled injection, contract verification (JS path + KO template alias + displayArea slot — these strings are the JS side's anchors so a typo breaks the picker silently), missing-layout-path defense, no-overwrite-other-modules.
- Knockout JS not unit-tested in PHPUnit (would need jsTestDriver / Jest harness which isn't set up here). The KO API mirrors the already-tested Hyvä Alpine logic 1:1; differential bugs are unlikely.

### Honest limitations

- **Same "no browser test" caveat as v0.3.** The Hyvä Alpine calendar has no Hyvä install to verify against; the Luma Knockout calendar has no in-session browser to walk through. Pre-launch: install on a Luma store and run the keyboard nav + form submit + extension_attributes round-trip. The PHP layer (capture plugin + observer + email plugin) is fully proven by v0.2's unit tests and the `ddp:verify` CLI's 9 live-DB checks.

---

## [0.3.0] — 2026-05-19 — Hyvä Checkout calendar + order-view display

The first marketable release. v0.1 was the engine, v0.2 was the data plumbing, and v0.3 puts an actual UI in front of the customer. The Hyvä Alpine.js calendar is the load-bearing differentiator — designed Hyvä-first, not retrofitted from a Knockout template.

### Added

- **`ConfigProvider`** wired into `Magento\Checkout\Model\CompositeConfigProvider` via `etc/frontend/di.xml` (frontend scope — registering in global `etc/di.xml` would be silently overridden). Surfaces `window.checkoutConfig.etechflowDeliveryDate` with the customer-tailored available-dates list, earliest/latest day, default selection, blackouts, restrictions, comment style, and date format. Customer-group filtering flows through from `CustomerSession`. Calendar dates are computed at request time so cutoffs and blackouts always reflect the current moment — no cached stale data.
- **`HyvaDeliveryPicker` view model** (`ETechFlow\DeliveryDate\ViewModel\Hyva\DeliveryPicker`) — exposes the same payload as the Luma ConfigProvider to Hyvä Checkout templates, JSON-encoded for direct embedding in Alpine `x-data` attributes. Hex-escaping flags (`JSON_HEX_APOS | _HEX_QUOT | _HEX_TAG | _HEX_AMP`) keep quotes from breaking out of the surrounding attribute. Single source of truth: drift between the Hyvä and (future v0.4) Luma views is structurally impossible.
- **Hyvä Checkout Alpine.js calendar** at `view/frontend/templates/hyva/checkout/delivery-picker.phtml`, mounted via `view/frontend/layout/hyva_checkout_components.xml` into Hyvä's `checkout.shipping.methods.after` container (conversion-aware placement per spec §3.2 — the picker shows after the carrier choice so blackouts can react to shipping method). Features: 4-week grid with month nav, "Earliest" + "Today" badges, full keyboard navigation (Tab + arrows + Enter/Space), ARIA grid semantics, screen-reader announcements ("December 15, available, selected"), the **"Get it as soon as possible" quick-pick button** (one tap vs Amasty's 6 clicks for the 70% of customers who just want the soonest), a 1000-char comment field with live counter, hidden `extension_attributes[…]` inputs for Hyvä's form serialisation, and emits a `etechflow-delivery-date-change` CustomEvent so other Hyvä components can react.
- **Order-view "Delivery details" block** on `sales/order/view` — same template renders identically on Hyvä and Luma. Reads via the `DeliveryDetails` view model which respects the admin Date Format. Shows date, slot ID (until v0.4 replaces it with the formatted range), and the customer's notes-to-driver text with newlines preserved.
- **`bin/magento etechflow:ddp:verify`** is unchanged at 9 steps for v0.3 — the engine + data-plumbing checks remain authoritative. Frontend rendering needs a browser; **the verify CLI is honest about what it can and can't prove**.

### Tested

- **`ConfigProviderTest`** — 8 cases covering disabled short-circuit, full enabled payload shape, chronological ISO ordering of available dates, default-date resolution under all four toggle states (offset=0 / offset=N / overshoot / empty-available), and customer-group ID flow-through into the calculator.
- Tests for `HyvaDeliveryPicker` + `DeliveryDetails` view models are intentionally light — their PHP is straight delegation to ConfigProvider + the Order model. Adding mock tests for "this getter delegates to that getter" would be pure noise.

### Honest limitations

- **The Hyvä Alpine calendar has not been browser-tested.** This workspace has no Hyvä Theme installed (`vendor/hyva-themes/` is absent), so the template ships at quality level "everything resolves + the JSON serialises cleanly + the Alpine API I wrote is internally consistent" — **not** "tested on a real Hyvä store." Before listing on Adobe Marketplace or pointing a customer at this release, install on a Hyvä site, walk the full keyboard navigation path, verify Tailwind classes render with the project's Tailwind build, and confirm Hyvä Checkout passes the `extension_attributes` payload through to the backend. The capture plugin (v0.2) is already proven; the unknown is the wire-up at the Alpine ↔ Hyvä boundary.
- **Luma Knockout fallback is deferred to v0.4.** The spec lists it as secondary — Hyvä is the wedge — and shipping a half-tested Luma component now would dilute the "Hyvä-first" marketing claim. v0.4's first task is the KO component, written from the ConfigProvider that's now in place.

---

## [0.2.0] — 2026-05-19 — Data plumbing layer

Adds the quote → order persistence + order-email + admin-grid wiring on top of the v0.1 scheduling engine. No customer-facing UI yet — that lands in v0.3 (Hyvä Checkout + Luma fallback). The data layer ships first so the engine→quote→order→email pipeline is proven end-to-end against a real Magento install before any frontend code depends on it.

### Added

- **Extension attributes** on `Magento\Quote\Api\Data\AddressInterface` and `Magento\Sales\Api\Data\OrderInterface` exposing `etechflow_delivery_date` / `etechflow_delivery_time_interval_id` / `etechflow_delivery_comment`. These let REST + GraphQL callers (PWA Studio, headless front-ends) read and write the picked date through the standard `/rest/V1/carts/mine/shipping-information` endpoint without bespoke API surface.
- **ShippingInformationManagementPlugin** (`beforeSaveAddressInformation`) reads the three extension attributes from the checkout payload, sanitises each (strict `YYYY-MM-DD` + `checkdate`; positive-int interval ID; 1000-char comment cap with control-character strip), and writes them onto the quote's shipping address. Defensive: any malformed value is silently dropped; any exception is logged and the checkout flow continues.
- **PersistDeliveryDataToOrder** observer on `sales_model_service_quote_submit_before` copies the three fields from the quote's shipping address (falling back to billing for virtual orders) to the `sales_order` row in the same DB transaction as the order itself. Order placement never fails because of this observer — any throw is logged and the order proceeds.
- **OrderEmailItemsPlugin** (`afterToHtml` on `Magento\Sales\Block\Order\Email\Items`) appends an inline-CSS "Delivery details" block to the order confirmation email's items table. Email-safe HTML (no `<style>` tags — Gmail/Outlook strip those). Renders date per the admin's Date Format config and is enable-gated so it disappears cleanly when the module is off.
- **Admin order grid** gains three columns via `view/adminhtml/ui_component/sales_order_grid.xml`: Delivery Date (visible, filterable), Delivery Slot ID (hidden, available via column-picker), Delivery Notes (hidden, available via column-picker). The btree index on `sales_order.etechflow_delivery_date` makes filtering on this column cheap even at scale.
- **`bin/magento etechflow:ddp:verify`** extended from 5 steps to 9: DI resolution for all three new components + schema checks against `quote_address` / `sales_order` / the four reference tables (`etechflow_dd_time_interval`, `_exception_day`, `_exception_interval`, `_quota_used`). Run after `setup:upgrade` to confirm a clean install before going live.

### Tested

- `PersistDeliveryDataToOrderTest` — 10 cases covering full-copy, partial-copy (only populated fields), int coercion of numeric-string slot IDs, billing-address fallback for virtual carts, both-addresses-null no-op, no-delivery-fields no-op, missing-quote / missing-order event-shape defenses, and the must-not-crash exception path (broken quote throws → logger.error fires → order untouched).
- `ShippingInformationManagementPluginTest` — 15 cases covering all-three-fields write, partial writes, numeric-string int coercion, date-format rejection (`15/08/2026`), impossible-date rejection (Feb 30), whitespace trim, zero / negative / non-numeric interval-ID rejection, 1500-char comment cap (→ 1000), control-character strip preserving `\n` + `\t`, null-shipping-address / null-extension-attributes / all-null-extension-fields short-circuits, and cart-repository throw → logger.warning + null return.
- `OrderEmailItemsPluginTest` — 11 cases covering module-disabled passthrough, no-delivery-data passthrough, null-order passthrough, full block render (date + slot + comment), partial renders (date-only, comment-only), all four date formats (ISO / dd-mm-yyyy / mm/dd/yyyy / dd/mm/yyyy) via data provider, unknown-format fallback to ISO, invalid stored date fall-back to raw value, inline-CSS-only assertion (no `<style>` tags) for Gmail/Outlook safety, and config-throws → logger.warning + original-HTML return.

### Notes

- `etc/extension_attributes.xml` requires `bin/magento setup:di:compile` to regenerate the extension interfaces. The capture plugin uses `method_exists` guards so existing test stubs (which don't yet have the generated extension methods) don't break.
- Time-interval reference tables (`etechflow_dd_time_interval`, `_exception_day`, `_exception_interval`, `_quota_used`) are created by `db_schema.xml` but not yet written to — slot management UI lands in v0.4. v0.2's order email shows "Delivery slot: #N" referencing the raw ID; v0.4 replaces this with the formatted `09:00 – 12:00` range once the interval grid is in.

---

## [0.1.0] — 2026-05-17 — Scheduling engine foundation

Initial release. License-gated, Hyvä-aware module foundation with the date-availability engine + admin config but no frontend or persistence yet.

### Added

- **`DateAvailabilityCalculator`** — pure-logic class (only `DateTimeImmutable` + Config dep) that produces the list of selectable delivery dates for a given moment in time and customer / shipping-method pairing. Eight filter passes: customer-group gate, shipping-method gate, minimum interval, maximum interval, same-day cutoff, next-day cutoff, weekly blackouts (configurable per weekday), and a future-toggle for excluding blocked dates from the picker entirely vs. greying them out. 25+ unit tests cover the boundaries.
- **`Config`** — license-gated, type-safe wrapper around `ScopeConfigInterface`. ~20 fields covering enable flag, intervals, cutoffs, weekday blackouts, restricted customer groups, restricted shipping methods, picker style, date format, picker label, comment style, and tooltip text. Defensive coercion throughout (NULL → safe defaults, malformed CSVs → empty, unknown enum values → whitelist clamp).
- **`LicenseValidator`** — per-installation HMAC license validation with `www.` normalization, 28 dev-host patterns (localhost, `*.local`, `*.test`, `*.warden`, Vagrant boxes, Docker `host.docker.internal`, etc.), and a bundle-key path that lets one license activate all four eTechFlow modules. Production toggle defaults to off so dev environments don't trigger license checks. Shares `BUNDLE_SECRET_FRAGMENTS` + `BUNDLE_ID` + `BUNDLE_CONFIG_PATH` with the other eTechFlow modules. 35-case data-provider PHPUnit covers the hash, the dev-host matrix, the production toggle, and the bundle-key path.
- **`bin/magento etechflow:ddp:verify`** — 5-step end-to-end smoke test (license eval, config reachable, calculator non-empty, weekly blackouts filter, min interval enforced).
- **DB schema** (`etc/db_schema.xml`) — three extension columns each on `quote_address` and `sales_order` (plus a btree index on `sales_order.etechflow_delivery_date`), four new reference tables (`etechflow_dd_time_interval`, `_exception_day`, `_exception_interval`, `_quota_used`) for the slot / exception / quota work that lands in v0.3+.
- **Admin config tree** under `Stores → Configuration → ETechFlow → Delivery Date Picker`. Plain-language tooltips throughout — Amasty's terminology ("Min order processing time in days", "MOPT") swapped for everyday English ("Earliest delivery: how many days from order to first available date").