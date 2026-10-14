# Runtime evidence and limits

## Read-only walkthrough performed

An unsaved `ProductionMaterialRequirement` was instantiated in memory with:

- Original planned quantity: `100.00000000`.
- Conversion factor: `1.50000000`.
- Actual planned quantity: `150.00000000`.

Calling the production model's current conversion helpers returned:

- `conversionFactor()`: `1.50000000`.
- `toActualQuantity(100.00000000)`: `150.00000000`.
- `toOriginalQuantity(30.00000000)`: `20.00000000`.
- `originalPlannedQuantity()`: `100.00000000`.

This confirms the arithmetic helper behavior only. It does not prove stock reservation, posting, returns, or cost valuation.

## Operational transaction walkthrough not performed

No draft or posted inventory/financial documents were created. The local database has no alternative definition, frozen alternative snapshot, converted requirement, converted request line, or converted substitution to inspect. There is also no identified disposable company/branch/period/store target in the authorization supplied for this task.

Creating a BOM alternative, order, run, request, reservations, stock issue, return, and valuation chain against existing operational data—even inside an intended rollback transaction—would exercise document numbering, locks, audit logging, inventory layers, and accounting services. That is not a safely isolated reversible draft walkthrough. The request explicitly forbids creating stock or financial postings merely to claim acceptance, so the runtime boundary stops at read-only schema/data inspection and in-memory conversion arithmetic.

At that earlier operational-data checkpoint, the allocation-ledger migration was applied but read-only counts remained zero for child allocations, reallocated request lines, ledger rows, and split component groups. No fixture was created in operational `mgypack`; the later synthetic evidence described below exists only in the explicitly authorized isolated target.

## Read-only reporting projection example

Two unsaved requirements were passed to the current report projection while the service performed only its normal read query for active request allocations:

- Primary actual planned/issued evidence: `60.00000000` at factor `1`.
- Alternative actual planned/issued evidence: `80.00000000` at factor `2`.
- Projected primary original equivalent: `60.00000000`.
- Projected alternative original equivalent: `40.00000000`.
- Projected component original-equivalent total: `100.00000000`.

This confirms the report arithmetic and no-double-count projection in the current service. It does not provide transaction acceptance for request approval, inventory reservation, issue, or return.

## Frozen-unit and return-counter population

A final read-only join inspected active reservations linked to production material requirements:

- Active material reservations: `5`.
- Reservation product/unit/transaction-unit/factor mismatches against the frozen requirement: `0`.
- Current product base-unit mismatches against the frozen requirement: `0`.

The local database also has `0` requirements with both additional-issued quantity and returned quantity. The conservative return-accounting branch therefore has source and unexecuted regression-spec coverage, but no current transaction row that exercises it.

## Existing production-order PDF reproduction

The standard `reports.production.order` PDF was rendered read-only in memory for existing order `PROD-00017` using the controller's current eager-load and formula mapping:

- Order lines: `8`.
- Formula sections: `8`.
- Component rows: `15`.
- PDF bytes: `61,405`.
- Header: `%PDF-1.4`.
- Content type: `application/pdf`.

This disproves a blank payload for that existing local order with the current source. It does not replace authenticated browser acceptance of the route.

## Deterministic verification completed

- `php -l` passed for every PHP source, migration, fixture helper, and specification touched by the allocation-ledger follow-up.
- `vendor/bin/pint --format agent` passed on the scoped PHP files; it only reordered the imports in the new specification file.
- `php artisan view:cache --no-interaction` completed successfully.
- `php artisan route:list --name=admin.production.material-requests.reallocate --json` confirmed the POST route and its authentication, edit, approve, and required-idempotency middleware.
- `php artisan migrate:status --no-interaction` confirmed the allocation-ledger migration ran in batch 45.
- `git diff --check` returned no whitespace errors.
- A focused PHP service projection completed with primary actual `60`, alternative actual `80`, and original-equivalent component total `100`.
- A read-only active-reservation audit found five production material reservations and zero frozen-unit mismatches.
- An in-memory render of existing order `PROD-00017` produced a populated 61,405-byte PDF with eight order/formula sections and 15 component rows.
- A final read-only database query returned zero alternative definitions, allocation children, reallocated lines, and ledger rows, confirming this work created no operational transaction evidence.
- After the second review, PHP syntax and scoped Pint passed for the four corrected services and focused specification file; `git diff --check` remained clean.
- `pnpm run build` completed with Vite 7.3.2, transformed 54 modules, and emitted the production manifest, CSS, and JavaScript under ignored `public/build` output.
- Local shell and filesystem access were available. The local browser-control state probe failed immediately with `Transport closed`; a browser-control reset returned the same error. Browser acceptance therefore remains blocked by the unavailable transport and is not claimed.

## Fresh isolated acceptance target status

The user authorized a new isolated database named `mgypack_bom_acceptance_20261013` after source review, explicitly excluding operational `mgypack` and the mixed historical acceptance databases. The default `exec_command` path ran in the `workspace-write` sandbox with restricted networking, where local socket creation failed with `Operation not permitted`; this was a technical sandbox restriction rather than an explicit policy denial. The supported one-time `require_escalated` approval flow was available: the exact read-only GET to `127.0.0.1:8001` returned the application login redirect, `select current_database()` returned `mgypack`, and an exact read-only catalog lookup returned `target_exists: false`. No alternate address, socket, environment, credential, or machine was used.

The isolated target was subsequently created with the existing role and all 356 migrations completed. The first guarded walkthrough exposed only a fixture numbering collision. After correction and independent review, the second reached canonical issue, reallocation, return, journals, and costing before exposing the financial report's omission of requirement-linked return costs. Both attempts rolled back fully.

Independent review then passed the corrected tracked patch at SHA-256 `e60ef18e802ee9462d03aebb9ec6e1dfc23db46bec85a5ba4aa27230c85dc9cb`. Exact-target, empty-fixture, and classification guards passed, and the same reviewed script ran once successfully. The isolated target retains its synthetic evidence for review. A read-only lineage query confirms the return line has `inventory_reservation_id = null`, `source_line_type = Modules\Production\Models\ProductionMaterialRequirement`, and `source_line_id = 4`, matching both alternative issue lines. Operational invariants remained unchanged. See `isolated-runtime-acceptance.md` for exact values.

## Verification deliberately not performed

- No test command was run.
- Targeted regression specifications were added for the combined sibling budget on update/restore/approval, alternative report projection, additional-return demand accounting, and canonical return-cost reporting, but they were deliberately not executed.
- Three additional specifications cover cross-sibling reservation-credit leakage, zero-stock frozen-unit drift, and additional-only report projection. They were deliberately not executed.
- No browser session was fabricated.
- Read-only Git status/diff/show inspection was used to build the exact review patches; no index or history state was changed.
- No migration rollback or destructive cleanup was run.
- No test command, operational stock posting, operational financial posting, or destructive cleanup was run.
