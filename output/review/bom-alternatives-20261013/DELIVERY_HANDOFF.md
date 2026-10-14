# BOM alternatives delivery handoff

## Frozen delivery state

- Source is frozen for final dependency-closure and migration review.
- Current tracked review patch: `head-to-current-tracked.patch`, SHA-256 `f544e0f4bcd0e0a21a7ed6c7cecad68e1f1a8219b1da0e3e4f64fd38d64fdeda`.
- Authoritative deployment closure: `59` paths (`55` tracked plus `4` required untracked), recorded in `changed-files.tsv`, SHA-256 `4672e9b1011bfb66fe149d372aebf36c59eb3d4ecd725e3710b31409626a1e35`.
- The working tree remains uncommitted and unpushed. No operational migration, deployment, permission grant, or publication occurred.

This handoff is present at `/mnt/Me/MB/Projects/ShortCoded/MgyPack/ERP/output/review/bom-alternatives-20261013/DELIVERY_HANDOFF.md`. It was not visible to a reviewer inspecting only tracked Git paths because the review packet directory itself is untracked; the verified file was preserved rather than reconstructed from memory.

## Deployable patch packet

Apply all five patches to the recorded `HEAD` base:

1. `head-to-current-tracked.patch` — 55 tracked paths — `f544e0f4bcd0e0a21a7ed6c7cecad68e1f1a8219b1da0e3e4f64fd38d64fdeda`
2. `new-product-component-alternative-model.patch` — `fcbf5ced2540b3960ca6e780582f2baa69a293abfb091350bfffc37555debcb1`
3. `new-product-component-alternatives-migration.patch` — `e9ffb1d45e07e728578ad40e7a249b050dfb83abae9e7372c7e04aff88990729`
4. `new-material-identity-migration.patch` — `ea4d95538d8499b7818fc23f688fc5c525eef2a7c04da9a856d76a9fdd25f0bf`
5. `new-material-allocation-ledger-migration.patch` — `a1d57e4d8544d564eb3b6526bbd900b5ddf34e9c3eac3b2842e47b00c5cfc7bf`

## Implemented scope

- Company-scoped BOM alternative definitions with conversion factors, soft deletion, duplicate-active guards, nested product/component UI, validation, auditing, and frozen release snapshots.
- Original-versus-actual product, unit, quantity, and conversion lineage on production requirements, material-request lines, substitutions, printouts, and reports.
- Mixed primary/alternative planned allocations under one original-equivalent BOM cap, including create, edit, restore, approval, procurement revalidation, frozen-unit checks, and concurrent request protection.
- Positional production reservations, partial issue, return, shortage, unissued reallocation, and immutable reallocation evidence with request/requirement/reservation lineage.
- Planned-versus-additional reporting separation, split-allocation projections, localized Arabic/English PDF/XLSX output, and canonical return-cost attribution when a return has requirement lineage but no reservation ID.
- Exact-dimension availability now subtracts only reservations at the same physical location/batch tuple; aggregate availability retains the established exact-or-null behavior.

## Required migration order

Apply through Laravel's normal migration runner in timestamp order; do not reorder these files:

1. `modules/Core/Database/Migrations/2026_10_13_003118_create_product_component_alternatives_table.php`
   - Creates the alternative-definition master table and active uniqueness guard.
2. `modules/Production/Database/Migrations/2026_10_13_003120_add_material_identity_to_production_requirements_and_requests.php`
   - Adds original product/unit, original-equivalent quantities, and conversion lineage to requirements, request lines, and substitutions.
3. `modules/Production/Database/Migrations/2026_10_13_005559_add_material_allocation_ledger_to_production_execution.php`
   - Adds allocation roots, per-material uniqueness, reallocated counters, and the immutable request-reallocation ledger.

Immediately before step 3, run `preflight-005559-production-requirement-duplicates.sql` against the deployment database. It is read-only and blocks on duplicate non-null `(production_run_id, product_component_id, product_id)` tuples without modifying them.

Each migration has evidence-aware rollback guards. The isolated target completed all 356 repository migrations in this order. The operational database was not migrated by this work.

## Completed verification

- Source: targeted PHP syntax checks passed; scoped Laravel Pint passed; `git diff --check` is clean.
- Clean projection: `HEAD` plus the five patches produced exactly 59 changed paths, with zero unexpected files, zero missing files, and zero manifest hash mismatches.
- Projection boot: 45 PHP files linted, Laravel booted, 199 production routes registered, and all Blade views compiled.
- Build: `pnpm run build` passed with Vite 7.3.2, 54 modules transformed, and ignored production assets emitted under `public/build`.
- Migration preflight: the read-only operational check found zero duplicate requirement tuples that would block migration `005559`.
- Canonical isolated runtime: mixed allocation, cap rejection, approval, partial issue, unissued reallocation, target approval/issue, alternative return, balanced journals, frozen-unit rejection, report projection, and canonical costing passed once on `mgypack_bom_acceptance_20261013`.
- Cost reconciliation: alternative issued `135`, returned `15`, reported/canonical net `120`; total direct-material cost `160`.
- Concurrency: bounded two-process request race admitted one `60`-original request and rejected the competing over-cap request; concurrent approvals of the winning and complementary requests both passed at exactly `100` original-equivalent reserved.
- Multi-position runtime: approval reserved `40 + 60` exactly once; issuing `70` consumed `40 + 30`, retained both reservation IDs and request/requirement lineage, posted cost `248`, and left one exact-position reservation with `30` remaining.
- Operational compatibility: an explicit read-only transaction audited all 9 active null-dimension reservations in 4 exact tuples. All groups were covered by stock at their exact null/null tuple; wildcard-looking and unsupported groups were both zero.
- PDF: Arabic and English order, run-material, and aggregate-material PDFs all rendered as valid one-page A4 landscape documents, were rasterized at 144 DPI, and were visually non-blank and legible.
- XLSX: Arabic and English material exports opened successfully; both contained the retained alternative row. Arabic RTL was enabled and English RTL was disabled.
- Isolation: operational `mgypack` remained at 1 company, 18 production orders, 37 runs, 507 inventory transactions, quantity-in `4565687.27342000`, quantity-out `670233.20542000`, and zero material reallocations.

## Remaining gates and gaps

- Final reviewer dependency-closure and migration review is still pending; this handoff is not release approval.
- Test-runner commands remain unexecuted by instruction. Focused regression specifications are present but not runtime test results.
- Browser control has no usable provider and its transport/reset calls are closed. No fresh UI acceptance is claimed for production print routes or the previously corrected supplier/customer debt summaries and date filters.
- The synthetic acceptance user lacks production order/run/report print and export permissions. No rights were added, so PDF/XLSX evidence proves underlying render/export services, not the protected route-policy matrix.
- Repeated stress, multi-host concurrency, and deadlock/retry variation remain outside the bounded concurrency evidence.
- The final multi-position remainder was not reallocated or released because its frozen BOM snapshot contains no approved alternative; automatic mutation would not be a supported continuation.

Detailed evidence remains in `clean-projection-evidence.md`, `isolated-runtime-acceptance.md`, `isolated-concurrency-and-projections.md`, `reviewer-followup.md`, and `PATCH_STATUS.md` in this directory.
