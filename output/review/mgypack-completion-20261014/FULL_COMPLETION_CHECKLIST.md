# MgyPack completion and acceptance checklist

Date: 2026-10-14  
Repository base inspected: `06a2f091b95a8996dfd641ccf78d9f9f313379cf`  
Runtime inspected: `http://127.0.0.1:8001`  
Scope rule: preserve the existing dirty tree; no test runner, credential entry, destructive or operational database action, commit, push, deployment, or PLUMA access. Authorized state-changing scenarios ran only in clearly named isolated databases and rolled back to retained baselines.

## Status legend

- **Implemented / verified**: current source and an allowed deterministic or read-only runtime check support the behavior.
- **Implemented / isolated runtime verified**: the normal application service/controller path was exercised with retained fixture actors inside a clearly named isolated acceptance database and rolled back to its exact baseline.
- **Implemented / limited by operational dataset**: source and isolated acceptance are healthy, but the operational data does not contain a record needed for one specific live example.
- **Blocked**: an explicit prerequisite is unavailable and the requested deliverable was not produced.

## 1. Sales-order component-derived presentation without exposing raw material

**Status: Implemented / authenticated UI accepted.**

- `modules/Sales/Services/SalesCycleReadService.php:23` returns only color, sales unit, packing factor, and piece count after company/unit validation.
- `modules/Sales/Http/Controllers/SalesCycleController.php:811` and `modules/Sales/Routes/web.php:76` expose the scoped line-presentation endpoint.
- `resources/views/modules/sales/cycle/sales-order-form.blade.php:146` renders Color, Number in pieces, and Packing columns.
- `public/assets/js/modules/Sales/sales-cycle.js:257` fills the derived values.
- The focused specification at `tests/Feature/SalesUiNormalizationTest.php:347` asserts these fields and rejects a raw-material payload. It exists but was not executed by instruction.
- Authenticated Arabic light-mode acceptance opened the create form and confirmed the visible line fields Color, Number in pieces, and Packing. No raw-material field or payload was exposed. An existing released sales order correctly denied direct amendment with the established 409 reopen-first policy.

## 2. Persistent DataTable pagination and bounded page length

**Status: Implemented / authenticated persistence accepted.**

- `public/assets/js/modules/Core/datatables-defaults.js` centrally limits page lengths to exact membership in `10, 25, 50, 75, 100`, maps invalid or legacy values to `100`, and re-normalizes after custom load/save callbacks so a module callback cannot reintroduce an unsupported length.
- The canonical module-table audit found one actual initializer without persistence: the purchase-orders grid. `public/assets/js/modules/Purchases/purchase-orders.js:261` now sets `stateSave: true`.
- The other files found without a `stateSave:` literal only retrieve an already-initialized DataTable. Production client-report tables intentionally use `stateSave: false` because they are transient report projections, not canonical record grids.
- Static JS syntax checks pass. Focused static regression expectations were aligned in `tests/Feature/Auth/RolesCrudTest.php`; they were not executed by instruction.
- Authenticated Arabic light-mode acceptance selected `25`, reloaded the purchase-order grid, and observed `25` again from the exact `[10, 25, 50, 75, 100]` menu.

## 3. Separate finished-production receipts and understandable missing-cost completion

**Status: Implemented / authenticated UI accepted.**

- Separate production-receipt routes and permissions exist at `modules/Inventory/Routes/web.php:27`.
- Separate Warehouse Receipts from Production navigation exists at `config/menu/inventory.php:17`.
- Separate receipt-cost-completion routes and navigation exist at `modules/Inventory/Routes/web.php:81` and `config/menu/inventory.php:104`.
- `modules/Production/Services/ProductionCycleService.php:2376` owns finished-goods receipt preparation and preserves final-stage, material-reconciliation, quality, inventory, and cost guards.
- EN/AR messages explain that every issued material must have a known cost (`resources/lang/en/production_execution.php:407`, `resources/lang/ar/production_execution.php:393`).
- Authenticated Arabic light-mode acceptance confirmed separate menu entries and protected pages for production receipts and unpriced receipt-cost completion. The current Main Branch dataset has no eligible rows, so no posting action was attempted.

## 4. Finished/semi-finished input issue through material requests with run lineage

**Status: Implemented / authenticated lineage accepted / isolated selector runtime accepted.**

- `Product::componentItemClassifications()` now includes `ClassificationSemiFinished`; the shared selector and both component validators already consume `componentItems()`, so the correction is company-scoped, active-only, and consistent across standalone and full-product forms.
- Semi-finished products are already stockable through `stockableItemClassifications()`. Primary-material demand and issue validation are product/unit based; the intentionally narrower alternative-material rule remains raw-material-only.
- Existing self-reference and percentage-reference-cycle guards remain unchanged; allowing semi-finished products follows the same direct-component path already used by finished products and does not bypass those guards.
- The form persists `production_run_id` and presents the run's BOM-selected requirement at `resources/views/modules/production/material-requests/form.blade.php:49` and the partial-issue surface at line 247.
- Permissions and actions are exposed under the production material-request menu (`config/menu/production.php:111`).
- `modules/Production/Services/ProductionMaterialDemandService.php` enforces run/requirement identity and original-equivalent component capacity across stages.
- `tests/Feature/Core/ProductComponentTest.php` now covers semi-finished classification, form validation/persistence, selector visibility, and unit options. `tests/Feature/ProductionAnyStageMaterialDemandTest.php` contains later-stage, partial-issue, lineage, alternative, return, and reallocation specifications. They were not executed by instruction.
- Authenticated Arabic light-mode acceptance opened PMR-00028 and visibly confirmed production-run lineage, original BOM material, selected actual material, conversion factor, quantities, and issue documents. This also exposed and closed a real Blade parse defect in the same protected path.
- A fresh transaction-wrapped acceptance on isolated database `mgypack_production_demo_20261007` created a synthetic semi-finished product from an existing company-scoped active material, exercised the real `admin.select2.component-products` controller, and proved:
  - the primary component selector returned `SYNTHETIC-SEMI-FINISHED-COMPONENT-20261014`;
  - the raw-only alternative selector rejected that semi-finished row;
  - the raw-only selector still returned existing raw material `SYNTHETIC-DEMO-RAW-201347`.
- The isolated product count and row fingerprint returned to baseline after rollback. The operational database still contains zero active semi-finished products, so no operational master data was added.

## 5. Safe correction of old or completed production runs without deleting history

**Status: Implemented / authenticated UI accepted / isolated completed-run correction accepted.**

- The completed-run action is visible only to correction roles at `resources/views/modules/production/runs/show.blade.php:41`.
- `modules/Production/Services/ProductionRunCorrectionService.php:104` enforces approval; line 303 restricts this workflow to completed runs.
- The service supports original-period and separately privileged later-open-period posting, independent approval, dependency blockers, linked receipt/material reversal evidence, correction sequencing, and reopening for corrected accounting/quality/receipt.
- The UI and permissions are present in `resources/views/modules/production/runs/correction.blade.php` and `config/menu/production.php:97`.
- EN/AR explanations explicitly preserve original dates/batches and describe canonical reversals (`resources/lang/en/production_run_correction.php`, `resources/lang/ar/production_run_correction.php`).
- `tests/Feature/ProductionCorrectionDependencyTest.php` contains dependency and tampered-reversal specifications; it was not executed by instruction.
- Authenticated Arabic light-mode acceptance opened completed run `PROD-00010-R001` and its protected correction screen. The page visibly identified linked inventory documents, inventory/journal impact, preservation of the original run, reversal behavior, and independent approval.
- A fresh transaction-wrapped correction ran in isolated database `mgypack_acceptance_closure_20261003` against retained completed run `PROD-00001-R001` using existing actors `SYNTHETIC-CLOSURE-USER-1164` and `SYNTHETIC-CLOSURE-USER-1165`:
  - the preparer was denied self-approval;
  - the second actor approved correction `235`;
  - the run reopened as `running` at good/rejected `9/1`, received quantity `0`, correction sequence `1`;
  - the original material issue stayed posted while the material-consumption, waste, and finished-receipt documents reversed;
  - three reversal inventory transactions and two reversal journals were created inside the transaction.
- The outer transaction then restored the exact run, document, correction, inventory-transaction, and journal baselines. No retained fixture or operational history changed.

## 6. Blank production-order printing

**Status: root cause reproduced and fixed; authenticated response and rendered content verified.**

- Exact standard route: `GET admin/production/work-orders/{productionOrder}/print`, name `admin.production.work-orders.print`, permission `production.orders.print` (`modules/Production/Routes/web.php:145`).
- Exact controller/view path: `ProductionOrderController::print()` -> `printDocument()` -> `ReportPdfService::stream('reports.production.order', ...)` (`modules/Production/Http/Controllers/ProductionOrderController.php:507,517,573`; `resources/views/reports/production/order.blade.php`).
- The show-page button points to that named route at `resources/views/modules/production/work-orders/show.blade.php:27`.
- Read-only inspection found 18 active real orders. Four representative controller-path renders were valid and populated:
  - `PROD-00018`: 87,313 bytes, 1 page, SHA-256 `5346da0db6a2a260180910af049925fdf03b74bb889f31b901698207e90eb97a`.
  - `PROD-00017`: 99,887 bytes, 4 pages.
  - `PROD-00016`: 109,406 bytes, 6 pages.
  - `PROD-00013`: 90,219 bytes, 1 page.
- `PROD-00018` was rasterized and visually inspected: header, order metadata, line, BOM requirements, and footer were visible.
- The separate aggregate production-orders PDF route uses `ProductionReportController::print()` and `reports.production.operations`. Real branch renders were also nonblank: branch 1 had 12 orders/30 runs and a 69,327-byte PDF; branch 3 had 6 orders/7 runs and a 67,797-byte PDF. Branch 2 correctly rendered an empty-state report rather than a blank document.
- Authenticated navigation reproduced the blank dark PDF viewer. DevTools network evidence showed the `200 application/pdf` response was being handled by the PWA service worker (`fromServiceWorker: true`) and the top-level body completed with zero encoded bytes.
- The correction makes routes ending in `/print` or `/pdf`, plus the dynamic `/admin/purchases/procurement-print/` family, network-only in `PwaSettingsController::serviceWorker()`. After activating the updated worker, the same production-order request returned `200 application/pdf`, `Content-Disposition: inline; filename="production-order-prod-00018.pdf"`, and `fromServiceWorker: false`.
- A representative procurement request (`PR-00003`) also returned `200 application/pdf`, `Content-Disposition: inline; filename="purchase-requisition-pr-00003.pdf"`, and `fromServiceWorker: false`.
- A one-asset fixed-assets request (`FA-00001`) returned `200 application/pdf`, `Content-Disposition: inline; filename="fixed-assets-register.pdf"`, `fromServiceWorker: false`, and a complete 54,133-byte `%PDF-1.4` body ending in `%%EOF` (SHA-256 `533739e9f13ede15a409e8fd095060cd035cedd4a553d07b897d22571c3de844`). It took 102.874 seconds to return headers in this environment. The local HTTP connection completed in under 1 ms and source inspection found no remote font or image fetch in this PDF path.
- The bounded runtime diagnosis did not find a PostgreSQL blocker or query-wait issue: PostgreSQL is local; connection setup took 53.086 ms, `SELECT 1` took 14.823 ms, aggregate diagnostics took 16.477 ms, with zero blocked sessions, zero waiting locks, and one active connection with no wait event. By contrast, a no-database `artisan config:show` needed 65.31 seconds while consuming only 1.13 CPU seconds. The repository is mounted through `fuseblk` on NTFS, `mount.ntfs` was observed in uninterruptible I/O state, and long-running local file scans were present while CPU remained 88-90% idle with 21 GiB available memory. The dominant reproduced bottleneck is therefore local FUSE/NTFS filesystem/process contention, not residual PWA interception, an incomplete PDF body, or a demonstrated report-query defect.
- The Codex in-app browser's built-in Chrome PDF extension still displays an empty dark plug-in shell and reports an internal `ERR_BLOCKED_BY_CLIENT`; this is a provider limitation, not an application response. Downloading the exact authenticated print link produced a valid 49,509-byte, one-page A4 PDF.
- Poppler rasterization of that authenticated download visibly shows the company header, order metadata, sales order, product line, two BOM requirements with calculations, and footer. The application PDF is no longer blank and no controller/view rewrite was needed.

## 7. Supplier/customer debt summaries, opening balances, and date filters

**Status: service, live posted-ledger reconciliation, and authenticated UI accepted.**

- Supplier statement date filters and explicit opening row are implemented in `modules/Purchases/Services/Reports/ProcurementCycleReport.php:485-515`; controller metrics expose opening/closing balances at `modules/Purchases/Http/Controllers/ProcurementWorkflowController.php:1164-1167`; filter inputs are at `resources/views/modules/purchases/procurement/report.blade.php:51-55`.
- Dashboard customer/supplier cards call `LedgerQueryService::accountBalanceSummary()` from `modules/Core/Services/PlasticsDashboardService.php:243` and line 322.
- `modules/Accounting/Services/LedgerQueryService.php:76` folds pre-range entries into opening balances, keeps in-range debit/credit movement, and splits ending balances per partner account before summing sides so advances are not netted against unrelated debts.
- A fresh read-only reconciliation compared the service with an independent posted-ledger aggregate that omitted receipt-detail joins. All six company/branch/type combinations matched exactly.
- For `2026-10-01` through `2026-10-14`:
  - Branch 2 customers: opening debit `3,329,343.3155`, opening credit `118,793.7500`; period debit `152,637.5000`; ending debit `3,481,980.8155`, ending credit `118,793.7500`.
  - Branch 3 suppliers: opening debit `230.0000`, opening credit `5,867,711.0900`; no October movement; ending equals opening.
- This proves that the date filter changes the opening/period split while retaining the correct ending balance.
- Authenticated Arabic light-mode dashboard acceptance with `01/10/2026` through `14/10/2026` visibly matched the reconciled Branch 2 values: customer opening debit `3,329,343.3155`, opening credit `118,793.75`, period debit `152,637.5`, closing debit `3,481,980.8155`, closing credit `118,793.75`; supplier opening/closing credit `75,900`, advance debit `0`, and zero period movement.

## 8. Nested BOM alternatives and original-versus-actual production lineage

**Status: implemented; operational schema applied; protected lineage UI accepted; isolated alternative lifecycle accepted; test-runner execution pending.**

- Frozen 59-path delivery packet: `output/review/bom-alternatives-20261013/DELIVERY_HANDOFF.md`.
- Master alternative definitions, conversion factors, company scope, soft deletion, auditing, and snapshotting are implemented by `ProductComponentAlternative`, `ProductBomService`, product UI/validation, and the first migration.
- Production requirements/request lines preserve original product, unit, original-equivalent quantity, selected actual material, and conversion lineage.
- Reservation, partial issue, return, shortage, unissued reallocation, immutable reallocation evidence, cost attribution, PDF, and XLSX projections are included in the frozen packet.
- Fresh migration status differs from the packet's historical status: all three required migrations are now applied to the operational database in batches 43, 44, and 45. This review did not run them.
- Fresh schema checks confirm the master table, identity columns, reallocation counters, and reallocation ledger. Blocking duplicate groups: `0`. Current master alternatives: `0` rows. Current material-request reallocations: `0` rows.
- The authenticated PMR-00028 page demonstrates the frozen original/actual material identity and issue-document lineage in the live UI. Current operational master alternatives and reallocation ledgers contain zero rows, so a non-empty operational alternative/reallocation example remains limited by dataset.
- A fresh transaction-wrapped runtime on `mgypack_bom_acceptance_20261013` used retained actor `SYNTHETIC-BOM-ACCEPTANCE-USER-1` and the retained approved alternative on run `PROD-00001-R001`:
  - the planned over-cap request (`104.33333334` against `103.33333334` remaining) was rejected atomically;
  - an additional alternative request reserved, issued, and returned `1.00000000` of product `6` through the normal request, reservation, issue, and return services;
  - the reservation became consumed exactly once;
  - the additional issue posted at cost `3.00000000` with request-line and requirement lineage;
  - the return posted at cost `3.00000000`, retained requirement lineage, and correctly carried no reservation ID.
- The outer transaction restored the exact retained request, requirement, reservation, document, transaction, and journal fingerprints.

## 9. Arabic/English, permissions, migration and deployment readiness

**Status: source/build checks and isolated permission matrix pass; not full production readiness.**

- Relevant dashboard, production-execution, and production-run-correction EN/AR translation key sets match exactly (`en_only=0`, `ar_only=0`).
- Route/menu permission boundaries exist for production order print, production reports, receipts, cost completion, material requests, corrections, and dashboard sales/purchases summaries.
- Current allowed checks pass:
  - targeted PHP syntax: 15 relevant source/migration files;
  - JS syntax: shared DataTable defaults, purchase orders, sales cycle, and production execution;
  - Blade compilation: `php artisan view:cache`;
- the pre-existing production asset build evidence remains unchanged; this review added targeted JS syntax checks and did not rerun the build after the final narrow patches;
  - whitespace: `git diff --check`.
- The current operational schema contains the three BOM migrations, but no deployment/publication action was performed here.
- The retained BOM actor allowed exactly material-request create/view/edit/approve and denied material-request issue, production-order print, run view, materials-report view, and product edit. No permission was added or changed.
- The isolated browser at `http://127.0.0.1:8013` visibly loaded the synthetic BOM company login page. A direct protected PMR URL redirected to `/login`; the randomized fixture credential was intentionally not retained, and session-material probing was explicitly blocked. Protected isolated-browser acceptance therefore remains authentication-blocked rather than falsely claimed.
- Not proven: focused test-runner results, a broader route-policy matrix beyond the representative denied abilities, repeated/multi-host concurrency, deployment rollback rehearsal, or production release approval.

## 10. Client PDF guide with light-mode screenshots

**Status: Produced with verified print-output imagery and isolated workflow evidence; browser-screenshot export is provider-limited.**

- The supplied authenticated session enabled protected Arabic light-mode acceptance without credential entry.
- The client guide is generated at `output/pdf/mgypack-client-acceptance-guide-20261014.pdf` and includes the visually verified production-order print render, isolated semi-finished/alternative/correction evidence, and a concise acceptance/usage matrix.
- The in-app browser exposes screenshots to the review surface but does not provide a permitted file-export path for embedding them in a local artifact. The guide therefore uses the real authenticated PDF render as its embedded light-mode evidence rather than fabricating UI screenshots.

## Current blockers and release decision

1. Test-runner commands remain intentionally unexecuted.
2. Isolated state-mutating scenarios and representative permission denials passed, but protected isolated-browser acceptance is blocked by the deliberately unavailable fixture credential. No credential or session workaround was used.
3. The operational database contains no semi-finished component or approved alternative/reallocation rows for non-empty live examples; those paths were accepted only in named isolated databases and rolled back.
4. The in-app browser's PDF extension remains incapable of painting the downloaded PDF even though the application response and Poppler render are valid; regular Chrome/Edge confirmation is still desirable.
5. The fixed-assets report endpoint is functionally valid but took 102.874 seconds even for a one-asset filter under the current FUSE/NTFS filesystem contention. PostgreSQL waits and basic query timings were healthy, but production performance/SLA acceptance still requires a clean deployment-like environment and remains separate from this correctness review.
6. The repository remains broadly dirty and uncommitted. The frozen 59-path BOM packet must not be confused with the additional narrow corrections in this review.

**Decision: the requested narrow corrections, protected read-only acceptance, isolated state-changing workflows, and representative permission denials are complete, but this is not a production-release authorization.** Focused test-runner execution, authenticated isolated-browser acceptance, a broader route-policy matrix, and clean-environment performance validation remain open release gates.

## Changes made by this review

- Added `stateSave: true` to the purchase-orders DataTable initializer.
- Normalized shared saved page lengths to exact menu membership before and after custom callbacks.
- Added semi-finished products to the shared BOM-component classification and aligned focused expectations.
- Fixed the PMR protected view's inline Blade/PHP parse defect.
- Bypassed PWA navigation handling for `/print`, `/pdf`, and procurement-print routes so native PDF responses reach the browser directly.
- Exercised semi-finished selection, alternative cap/reservation/issue/return, completed-run correction, and representative permission denials in named isolated databases with exact rollback checks.
- Updated this checklist outside the frozen BOM packet.
- No operational database data, retained isolated fixture rows, permissions, credentials, commits, pushes, deployments, or PLUMA resources were changed.
