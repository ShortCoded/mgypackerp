# Patch availability and attribution

The review packet contains five patches covering the single authoritative 59-path source closure: 55 tracked files in the Git-derived HEAD-to-current patch plus four required untracked files in individual patches.

- `head-to-current-tracked.patch` — SHA-256 `f544e0f4bcd0e0a21a7ed6c7cecad68e1f1a8219b1da0e3e4f64fd38d64fdeda`
- `new-product-component-alternatives-migration.patch` — SHA-256 `e9ffb1d45e07e728578ad40e7a249b050dfb83abae9e7372c7e04aff88990729`
- `new-product-component-alternative-model.patch` — SHA-256 `fcbf5ced2540b3960ca6e780582f2baa69a293abfb091350bfffc37555debcb1`
- `new-material-identity-migration.patch` — SHA-256 `ea4d95538d8499b7818fc23f688fc5c525eef2a7c04da9a856d76a9fdd25f0bf`
- `new-material-allocation-ledger-migration.patch` — SHA-256 `a1d57e4d8544d564eb3b6526bbd900b5ddf34e9c3eac3b2842e47b00c5cfc7bf`

`changed-files.tsv` is the authoritative path-to-hash-to-patch mapping. It has 59 rows, and every hash matched both the shared working tree and the clean projection.

## Historical attribution boundary

`source-hashes.tsv` preserves the earlier 44-path boundary captured before the allocation-ledger follow-up. It is historical attribution evidence, not the complete deployment manifest. The mixed HEAD patches contain both the earlier BOM-alternative implementation and the follow-up changes; they must not be described as wholly new in the follow-up.

Mixed paths (pre-existing BOM hunks plus allocation-ledger follow-up hunks):

- `modules/Production/Http/Controllers/ProductionMaterialRequestController.php`
- `modules/Production/Http/Requests/StoreProductionMaterialRequest.php`
- `modules/Production/Models/ProductionMaterialRequestLine.php`
- `modules/Production/Models/ProductionMaterialRequirement.php`
- `modules/Production/Services/ProductionCycleService.php`
- `modules/Production/Services/ProductionMaterialDemandService.php`
- `modules/Production/Services/ProductionMaterialRequestService.php`
- `resources/lang/ar/production_execution.php`
- `resources/lang/en/production_execution.php`
- `resources/views/modules/production/material-requests/form.blade.php`
- `resources/views/reports/production/material-request.blade.php`

Paths first changed by the allocation-ledger follow-up:

- `app/Observers/OperationalNotificationObserver.php`
- `modules/Inventory/Http/Controllers/InventoryDocumentController.php`
- `modules/Inventory/Services/InventoryReservationService.php`
- `modules/Production/DataTables/ProductionExecutionDataTable.php`
- `modules/Production/Database/Migrations/2026_10_13_005559_add_material_allocation_ledger_to_production_execution.php`
- `modules/Production/Models/ProductionMaterialRequest.php`
- `modules/Production/Routes/web.php`
- `modules/Production/Services/ProductionOutputEvidenceService.php`

Path first changed by the multi-position availability follow-up:

- `modules/Inventory/Services/InventoryAvailabilityService.php`

`reporting-followup-baseline-hashes.tsv` records the file contents immediately before the aggregate-reporting follow-up. The following paths were first changed by that follow-up:

- `modules/Production/Exports/ProductionReportExport.php`
- `modules/Production/Http/Controllers/ProductionReportController.php`
- `modules/Production/Services/ProductionReportService.php`
- `resources/views/modules/production/reports/index.blade.php`
- `resources/views/modules/production/runs/batch-show.blade.php`
- `resources/views/reports/production/operations.blade.php`

The following reporting-follow-up paths already contained earlier BOM-alternative work and are mixed:

- `modules/Production/Http/Controllers/ProductionRunController.php`
- `resources/lang/ar/production_execution.php`
- `resources/lang/en/production_execution.php`
- `resources/views/modules/production/runs/show.blade.php`
- `resources/views/reports/production/partials/run-formula.blade.php`
- `resources/views/reports/production/run-materials.blade.php`

The independent-review fixes are also mixed into the existing BOM/allocation paths `ProductionMaterialDemandService.php`, `ProductionMaterialRequestService.php`, `InventoryReservationService.php`, `ProductionReportService.php`, the two production translation files, and `ProductionAnyStageMaterialDemandTest.php`. They add the one-budget lifecycle validation, requirement-local capped reservation credit, procurement-time budget revalidation, frozen-unit checks before zero-stock availability and shortage procurement, conservative additional-return accounting, planned-only report split activation, canonical return-cost lineage in financial material reporting, exact-position reservation availability, and unexecuted targeted regression specifications.

`reviewer-followup.md` records the post-review repairs and source anchors for narrow review. The five current patch hashes are recorded above, and their combined clean projection resolves to exactly the 59 manifest paths.

All other dirty paths shown by the initial `git status --short` were pre-existing work outside this BOM allocation scope and were preserved. No staging, commit, reset, clean, checkout, or push command was run.
