# Acceptance evidence - 14 October 2026

## Authenticated browser

- Provider: Codex In-app Browser, tab 5, existing saved ERP authentication; no credentials were entered.
- Theme: `data-bs-theme=light`.
- Dashboard: Main Branch, second half of FY 2026, EGP, filtered from 01/10/2026 through 14/10/2026.
  - Customer opening debit 3,329,343.3155; opening credit/advance 118,793.75.
  - Customer period debit 152,637.5; period credit 0.
  - Customer closing debit 3,481,980.8155; closing credit/advance 118,793.75.
  - Supplier opening credit 75,900; opening advance debit 0.
  - Supplier period credit/debit movements 0/0.
  - Supplier closing credit 75,900; closing advance debit 0.
- Work order: protected `PROD-00018` detail page opened and exposed its Print action.
- Print route: `GET /admin/production/work-orders/PROD-00018/print` returned `200 application/pdf`, was not served by the service worker, and yielded 49,507 bytes with `%PDF-1.4`, one content reference, `startxref`, and `%%EOF`. SHA-256: `76f30b5e7405a9f851cad969a9f918e40d20d636ffb1646c512cbdda97a7deb3`.
- The in-app browser's bundled PDF viewer showed an empty dark shell. The direct response bytes and retained Poppler render prove the application PDF is populated; regular Chrome/Edge viewer acceptance remains separate.

## Transaction-wrapped runtime evidence

No permission was granted by these checks. Every scenario ran inside a database transaction and restored the complete before/after fingerprints of the affected table rows. PostgreSQL sequence values were not reset and are not claimed as restored.

### Semi-finished primary and alternative material lifecycle

- Database: `mgypack_bom_acceptance_20261013`.
- Existing actor: `SYNTHETIC-BOM-ACCEPTANCE-USER-1`.
- Semi-finished primary product 5 passed `componentItems()`, created `PMR-00007`, reserved, approved, issued, costed at 2.00, and returned at 2.00.
- Alternative `PMR-00008` reserved, issued, costed at 3.00, and returned at 3.00.
- A request above the frozen remaining planned capacity was rejected atomically.
- The complete before/after row fingerprint now includes `account_classifications`, the table temporarily changed by this isolated fixture.
- `row_fingerprints_restored=true`; `postgres_sequences_not_reset_or_claimed=true`.

### Native cost completion and finished receipt

- Database: `mgypack_production_demo_20261007`; existing run 33 (`PROD-00016-R011`).
- Existing `User-00001` prepared the cost proposal and independent existing `User-00005` approved it. No permission was granted.
- Native finished receipt first rejected an isolated posted material issue with missing cost.
- The accepted path did not inject cost with `forceFill()`: `InventoryReceiptCostProposalService::prepare()` created proposal 2 for unpriced receipt `INV-MOV-00061`; the independent `approve()` created value adjustment 2. The source receipt line remained unpriced by design, while its canonical completion basis priced downstream production issue `INV-MOV-00062` at 1.00000000.
- Native `receiveFinishedGoods()` then posted receipt `INV-MOV-00063`, cost 534.91222068, received quantity 1, and journal 122.
- `row_fingerprints_restored=true`; `postgres_sequences_not_reset_or_claimed=true`.

### Controller and endpoint authorization

- Restricted actor received 403 from the real material issue controller, work-order print endpoint, and production-run show endpoint.
- An existing authorized actor issued `PMR-00031` through `ProductionMaterialRequestController::issue`, producing posted movement `INV-MOV-00061`, request line 68, and cost 79.
- No permission grant occurred. All affected-row fingerprints were restored.
- Disposable database `mgypack_oct14_endpoint_acceptance` was dropped and confirmed absent.

## Read-only semi-finished posting-account preflight

- Local configured database `mgypack`: exactly one active `semi_finished_goods_inventory` classification (`ACLS-00040`) and exactly one eligible classified-posting account for `Company-00001` (`ACC-01416`, code `11221`).
- Local production-demo database: the same canonical classification exists once. `Company-00001` resolves to `ACC-01416` / `11221`; the isolated demo company resolves to its sole eligible `ACC-00015` / `1135` account.
- The BOM-only isolated fixture database intentionally has no active semi-finished classification. Its acceptance harness temporarily recodes the raw classification inside the rollback transaction and now fingerprints `account_classifications`; it is not a deployment target and is not evidence of target posting readiness.
- The actual deployment target was not identified or contacted. Before deployment, run the same read-only check there and stop unless the active classification count is exactly one and every in-scope company has exactly one account satisfying `Account::eligibleForClassifiedPosting()` for that classification. Do not create, rename, or guess a mapping during preflight.

## Source, syntax, and build commands

No test-runner command was used. These exact non-test commands completed successfully on the current tree:

- `vendor/bin/pint tmp/pdfs/create_client_guide.php tmp/acceptance-finished-receipt-20261014.php tmp/acceptance-bom-runtime-20261014.php --format agent` - exit 0; Pint formatted the two acceptance harnesses and reported no generator change.
- `php -l tmp/pdfs/create_client_guide.php`, `php -l tmp/acceptance-finished-receipt-20261014.php`, and `php -l tmp/acceptance-bom-runtime-20261014.php` - exit 0; each reported no syntax errors.
- `pnpm run build` - exit 0; Vite 7.3.2 transformed 54 modules and completed in 7.98 seconds, writing `public/build/manifest.json`, `app-BY5-c6eb.css`, and `app-DFwq5eDd.js`. The Browserslist age notice was non-fatal.
- `VIEW_COMPILED_PATH=/tmp/mgypack-view-cache-20261014-final php artisan view:cache --no-interaction` - exit 0; Blade templates cached successfully in the isolated path.
- `git diff --check` - exit 0 with no whitespace errors.
- `DB_DATABASE=mgypack_production_demo_20261007 php tmp/acceptance-finished-receipt-20261014.php` - exit 0 with native cost proposal/independent approval, native issue and finished receipt, and complete affected-row fingerprint restoration.
- `DB_DATABASE=mgypack_bom_acceptance_20261013 php tmp/acceptance-bom-runtime-20261014.php` - exit 0 with `account_classifications` included in the restored affected-row fingerprint.

## Deliberate limits

- No test-runner command was executed.
- No operational record, permission, credential, production target, commit, push, deployment, or PLUMA state was changed.
- Browser screenshots are retained under `screenshots/` and embedded in the client guide.

