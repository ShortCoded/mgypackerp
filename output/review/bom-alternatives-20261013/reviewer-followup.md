# Independent-review follow-up

This follow-up addresses the three defects reported after the first independent review. It is source evidence for another narrow review, not executed acceptance.

## 1. Requirement-local reservation credit

- `ProductionMaterialDemandService::assertPlannedRequestBudget()` still locks the complete sibling requirement ledger and computes one original-equivalent component cap.
- Unlinked reservation credit is now taken only from requirements present in the submitted component payload.
- Each selected requirement's credit is capped by that same requirement's submitted actual quantity before conversion to the original unit.
- A primary reservation can therefore offset a new primary request only to the extent that the request reuses that primary hold; it cannot credit an alternative sibling.
- `ProductionMaterialRequestService::createPurchaseRequisition()` revalidates the request's remaining unissued and unreallocated planned quantity under the same budget before creating or replacing procurement, while already issued quantity remains committed by the requirement ledger. This covers requests approved under earlier source without double-counting their issued portion.

Unexecuted regression specification: `an unlinked primary reservation cannot credit an alternative sibling or exceed its selected primary quantity` reserves the full primary plan, then requires both a full alternative sibling request and a mixed request that attempts to reuse all primary credit for a smaller primary row to fail with `material_request_exceeds_bom`.

## 2. Frozen unit before availability and procurement

- `InventoryReservationService::assertProductionMaterialIdentity()` locks the frozen requirement, optional request line, and current actual product.
- It requires request-line product/unit identity to equal the requirement and the product's current base unit to equal the frozen requirement unit.
- Direct reservation and across-position reservation call the guard before stock-position or availability work.
- Material-request approval and later shortage allocation call the guard before their availability calculation, including zero-availability paths that create no reservation.
- Shortage requisition generation repeats the guard before converting a shortage line into procurement input.

Unexecuted regression specification: `zero stock approval still rejects product unit drift from the frozen requirement` removes all synthetic alternative availability, changes its current base unit, and requires approval to fail while the request remains submitted.

## 3. Planned split-report activation

- `ProductionReportService::projectMaterialAllocationPlans()` activates split planned reporting for an alternative child only when it has a non-zero active planned-request selection or planned `issued_quantity`.
- Generic reservation, additional issue, return, consumption, and waste counters no longer activate a planned split.
- When a child exists only because of additional-material activity, the allocation root retains its stored planned quantity, the child projects zero planned quantity, and the component original-equivalent plan remains the stored root plan.

Unexecuted regression specification: `additional-only alternative activity preserves the stored root reporting plan` creates an alternative child with only `additional_issued_quantity` and asserts root `200`, child `0`, and split flag false.

## 4. Canonical return-cost lineage

- The isolated walkthrough produced canonical alternative issues costing `135.00000000`, an alternative return costing `15.00000000`, and canonical run direct-material cost `120.00000000` for that material.
- Financial material reporting incorrectly retained `135.00000000` because `applyMaterialMetrics()` required every cost line to join through `inventory_reservations`.
- Canonical return lines intentionally have no reservation ID; they preserve the frozen requirement through `source_line_type = ProductionMaterialRequirement` and `source_line_id`.
- The aggregate now left-joins reservations and accepts either relationship, grouping on the reservation requirement when present and otherwise the frozen source requirement. Existing issue linkage remains unchanged while returns and other canonical requirement-linked lines are no longer omitted.

Unexecuted regression specification: `financial material reporting subtracts canonical alternative returns from actual cost` issues alternative material costing `90`, returns `30`, and requires both report actual cost and canonical run direct-material cost to equal `60`.

## 5. Exact multi-position reservation availability

- A new isolated scenario retained one older null-location/null-batch position with quantity `40` and one exact location/batch position with quantity `60` for the same product.
- Approval of a request for `100` reproduced a false shortage: the null/null reservation created for the first position was also subtracted while checking the second exact position.
- `InventoryAvailabilityService::forProduct()` now preserves the aggregate exact-or-null reservation lookup when `exactDimensions` is false, while exact-dimension lookups require the reservation location and batch to equal the physical position exactly.
- The failed approval transaction rolled back all counters and reservations; no partial reservation evidence survived.
- Independent source review approved the exact-tuple interpretation. The corrected retained request then passed once: approval reserved `40 + 60`, and a `70` issue consumed `40 + 30` while preserving reservation, request-line, requirement, position, and cost lineage.

Unexecuted regression specification: `multi-position approval reserves null and exact stock once and keeps issue lineage` expects reservations `40 + 60`, request and requirement reserved counters `100`, then issues `70` as `40 + 30` with both reservation IDs, request-line lineage, non-null costs, and consumed counters preserved.

## Deterministic checks

- PHP syntax passed for the four changed services and the focused specification file.
- Laravel Pint passed when scoped to those exact five files.
- `git diff --check` returned no whitespace errors.
- `pnpm run build` completed with Vite 7.3.2, 54 modules transformed, and production CSS/JS plus `manifest.json` emitted under ignored `public/build` output.
- After the runtime-discovered report repair, scoped Pint and PHP syntax passed for `ProductionReportService.php` and the focused specification file; `git diff --check` remained clean.
- No test-runner command was executed.
- The multi-position reproduction harness ran once on only new isolated records. After review, the guarded corrected approval/issue harness also ran once against that retained request. PHP syntax, Pint, and `git diff --check` passed after the narrow correction.
- The isolated target was migrated. The first two synthetic walkthrough attempts ran inside one outer transaction and rolled back. After independent review of patch `e60ef18e802ee9462d03aebb9ec6e1dfc23db46bec85a5ba4aa27230c85dc9cb`, the corrected walkthrough passed once and its synthetic evidence was retained in the isolated target. No operational database write was executed.

## Isolated acceptance target

The user authorized a fresh target named `mgypack_bom_acceptance_20261013`, with no mutation of operational `mgypack` or the mixed historical acceptance databases.

- The normal shell executor used its default `workspace-write` sandbox with restricted networking. In that mode, the operating-system socket call failed before connection with `Operation not permitted`; the same restriction caused Laravel's initial read-only database query to fail with SQLSTATE class `7`.
- This was a technical sandbox restriction, not an explicit policy or user denial. The supported one-time `require_escalated` approval flow was available.
- Under that approval, the exact read-only GET to `http://127.0.0.1:8001/` succeeded and returned the application's redirect to `/login`.
- A separately approved read-only `select current_database()` returned `mgypack`.
- A separately approved read-only PostgreSQL catalog query for the exact target name returned `target_exists: false` before creation.
- No alternate address, socket, environment, credential, or machine was used. The target was then created with the existing database role and all 356 migrations completed through a process-local `DB_DATABASE` override.

The first two synthetic walkthroughs rolled back completely: the first exposed a fixture document-number collision, and the independently reviewed rerun exposed the canonical return-cost reporting defect described above. Independent review then passed the corrected tracked patch, the exact-target and empty-fixture guards passed, and the reviewed walkthrough ran once successfully. The isolated target now retains that synthetic evidence for review. See `isolated-runtime-acceptance.md` for exact lineage, cost, rejection, reallocation, and invariant evidence. The repository `.env` and operational connection remain unchanged.
