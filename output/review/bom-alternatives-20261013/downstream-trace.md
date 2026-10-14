# BOM alternative downstream trace

This is a source trace of the current implementation. “Implemented” means the current source contains the path; it does not mean the path received transaction-level runtime acceptance.

## 1. Definition and immutable release snapshot

1. Nested alternatives are validated as active company raw materials with active base units, unique per component, different from the primary product, and with a positive explicit factor in `ValidatesProductPayload` and `ValidatesProductComponentPayload`.
2. `ProductBomService` synchronizes alternative rows transactionally, preserves public IDs, audits updates, clones alternatives, and soft-deletes removed alternatives. Relevant anchors: `ProductBomService.php:69`, `:207`, and `syncAlternatives()` near `:397`.
3. `ProductionCycleService::releaseOrder()` locks the component definitions and alternative products, validates current eligibility, and embeds product, unit, factor, and public ID in `bom_snapshot`. Relevant anchor: `ProductionCycleService.php:553-645`.
4. Run requirement creation records the primary material as both original and actual, factor 1, and embeds `selected_material`. Relevant anchor: `ProductionCycleService.php:3174-3200`.

Status: implemented in source. Snapshot immutability after release is achieved by reading later choices from `bom_snapshot`, not the mutable master table.

## 2. Material request selection and lineage

1. The request form presents the primary and every currently eligible frozen alternative as separate quantity rows, so one request can explicitly split a component across materials.
2. `ProductionMaterialRequestController` submits virtual allocation rows as `(component, material choice, quantity)` tuples instead of collapsing them into one component-keyed map.
3. `ProductionMaterialDemandService::materialSelection()` accepts only `primary` or a frozen alternative public ID, then rechecks that the current product and unit remain active and compatible: `ProductionMaterialDemandService.php:277-326`.
4. `allocationRequirement()` locks all requirement rows for the component and finds or creates one immutable actual-material child allocation. Existing requirement identity is never rewritten.
5. A unique `(run, component, actual product)` constraint and the company/run/component row locks prevent duplicate children under concurrent create/approval paths.
6. Request creation/update writes actual product/unit plus original product/unit, original-equivalent requested quantity, and factor: `ProductionMaterialRequestService.php:113-123` and `:200-210`.
7. Cumulative BOM caps convert committed actual quantities from each requirement back to original equivalents before comparing them with the frozen BOM cap: `ProductionMaterialDemandService.php:116-187`.

Status: implemented in source for simultaneous primary/alternative partial allocations under one original-equivalent BOM cap.

## 3. Approval and reservation

1. Approval checks availability by the request line's actual `product_id`: `ProductionMaterialRequestService.php:317-356`.
2. It reserves through the selected requirement and request-line link.
3. `InventoryReservationService::reserveForProduction()` locks the requirement, loads the requirement's actual product, creates the reservation with that product and its base unit, and increments the requirement's actual reserved quantity: `InventoryReservationService.php:24-119`.
4. Partial availability produces an actual-material reservation plus an actual-material shortage on the request line.
5. A generated shortage requisition uses the request line's actual product, unit, and shortage quantity: `ProductionMaterialRequestService.php:689-713`.

Status: implemented through existing reservation infrastructure. Not exercised locally with an alternative because no alternative data exists.

Approval now rechecks the entire planned-request payload through one locked shared component budget. Create, update, restore, and approval can no longer validate sibling primary/alternative lines independently. Explicit alternative-child quantities are valid input; the child row's zero implicit default is not treated as its request ceiling.

Unlinked reservation credit is requirement-local and capped by that same selected requirement's submitted quantity. A primary hold cannot credit an alternative request. Shortage requisition creation repeats the shared-budget check so an earlier invalid approval cannot become excess procurement.

Reservation creation locks the actual product, requires its current base unit to match the frozen requirement unit, and writes both reservation unit fields from the requirement. Approval and shortage allocation run the same identity guard before availability, including zero-stock paths, and shortage procurement rechecks identity before creating requisition lines. Consumption locks the requirement and product again and rejects mismatched reservation product/unit/conversion evidence. No implicit unit conversion is performed.

## 4. Partial issue and actual inventory posting

1. `ProductionMaterialRequestService::issue()` accepts an issue quantity per request line and bounds it by that line's remaining reserved actual quantity: `ProductionMaterialRequestService.php:432-475`.
2. It maps the quantity to the selected requirement and passes the request-line reservation link to `ProductionCycleService::issueMaterials()`: `ProductionMaterialRequestService.php:477-487`.
3. `issueMaterials()` consumes linked reservations and builds movement lines with the requirement's actual `product_id`, actual `unit_id`, and actual quantity: `ProductionCycleService.php:994-1103`.
4. The inventory movement is created and posted immediately through `InventoryMovementService::createAndPost()`: `ProductionCycleService.php:1105` and `InventoryMovementService.php:30-39`.
5. The request line and requirement counters are incremented in actual units, and the request becomes partially issued until every approved line is fully issued.

Status: request-linked partial issue is implemented for multiple actual-material children. The issue service maintains a transaction-local original-equivalent budget across the children. Implicit run reservation, implicit issue, and batch issue are explicitly blocked with localized messages once a component is split, preventing the system from silently choosing a material.

## 5. Return path

1. `ProductionCycleService::returnMaterials()` bounds a return by the selected requirement's unaccounted actual issued quantity: `ProductionCycleService.php:1328-1372`.
2. `materialStagingLines()` uses the requirement's actual product and unit when moving staging stock back to available stock: `ProductionCycleService.php:2975-3035`.
3. The posted return increments that requirement's actual `returned_quantity`: `ProductionCycleService.php:1374-1388`.

Status: implemented for the selected requirement material. Inventory return documents do not independently duplicate the original-equivalent quantity; original-versus-actual lineage remains on the requirement/request line.

The requirement counter has cumulative planned issue, additional issue, and return totals but no per-return planned/additional provenance. For shared planned-demand capacity, returns are therefore offset against additional issued quantity first. Only returns above the additional-issued total reduce the planned commitment, preventing an additional-material return from reopening the frozen BOM cap.

## 6. Aggregate reporting, dashboards, and prints

1. `ProductionReportService::projectMaterialAllocationPlans()` projects a report-only planned quantity without changing operational requirement counters.
2. Components with no alternative evidence retain the allocation root's stored plan and project inactive child rows as zero.
3. Once an alternative child has planned-request or planned-issued evidence, each requirement row reports the actual material quantity in its own unit. Active planned requests are reduced by reallocated quantity; issued planned quantity is retained as a lower bound. Additional-only activity does not activate a planned split and therefore preserves the root's stored plan.
4. A separate original-equivalent component attribute converts each actual row once and sums only within the same run/component group. It is not used to merge unlike material/unit rows.
5. The projection is consumed by the standard material reconciliation, production-control report, XLSX export, run dashboard, batch display, traveler/material PDFs, and control-run report/print.
6. Operational reserve/issue/batch decisions continue to read the stored plan, not the reporting projection.

Status: implemented in source. A read-only projection example produced primary `60`, alternative `80` at factor `2`, and one original-equivalent component total of `100` (`60 + 80/2`), rather than the duplicated stored total of `300`.

## 7. Reservation release

1. Reopen and cancel-unissued paths call `releaseForMaterialRequestLine()`.
2. The release service locks reservations linked to the actual request line and requirement, rejects consumed or inconsistent reservations, marks active reservations released, and decrements the requirement's actual reserved counter: `InventoryReservationService.php:615-662`.

Status: full cancellation/reopen release remains limited to untouched reservations. The explicit authorized reallocation workflow can release only the unconsumed active remainder of a partially consumed reservation, leaving consumed/posted quantities unchanged.

## 8. Actual-material valuation and production cost

1. Movement posting values the actual product on each inventory document line from the actual stock position or selected receipt layer, then stores `unit_cost` and `total_cost`: `InventoryDocumentPostingService.php:130-267`.
2. `ProductionCostService::runPosition()` sums posted production material issue/additional issue line costs, subtracts posted return and waste costs, and reports direct material cost: `ProductionCostService.php:21-142`.

Status: wired through existing valuation and costing services because the new path changes the requirement and movement line product rather than applying a synthetic BOM-standard cost. This is source-proven but not transaction-runtime-proven locally.

## 9. Approved late substitution

1. Replacement lookup is restricted to the frozen primary and alternatives: `ProductionMaterialSubstitutionService.php:64-80` and `:361-389`.
2. Source actual quantity is converted to original equivalent, then to replacement actual quantity with the replacement factor: `ProductionMaterialSubstitutionService.php:83-113`.
3. Approval revalidates the factor/quantity seal, returns the source material, reduces the source requirement in both actual and original quantities, creates a replacement requirement on the same component lineage, reserves and issues the converted replacement quantity, and records evidence: `ProductionMaterialSubstitutionService.php:133-248`.

Status: implemented for the service's narrow eligibility window. It is not a general mixed-issue mechanism.

## Explicit gaps and assumptions

- Implemented only through the new explicit material-request reallocation action: changing a request-linked material. It requires both edit and approval permissions, a reason, an open period/run, no linked purchase requisition, and a planned request in approved/shortage/partially-issued state.
- The source line keeps cumulative issued, reallocated, reserved, and conversion counters. A new submitted target request and immutable source/target ledger row are created; the target must still be approved before reservation/issue.
- Limited by design: late substitution remains a separate direct-issued correction workflow and still rejects request-linked requirements. Request-linked changes must use the new remainder-reallocation action.
- Explicitly unsupported: enabling output-evidence accounting after a component has been split, adding a new allocation after evidence policy freeze, and batch/implicit issue for split components. Each path returns a localized blocking message rather than choosing silently.
- Not duplicated on inventory documents: original-equivalent quantities. They are recoverable from immutable requirement/request lineage, but the posted document line itself carries actual product/unit/quantity/cost.
- Assumed from existing shared infrastructure, not newly runtime-proven: actual-product layer valuation, accounting posting, return restoration costing, and production cost aggregation.
- No transaction data was backfilled. Existing requirement rows remain allocation roots; new child rows and ledger evidence are additive.
- No executed regression evidence is available because test commands were prohibited.
