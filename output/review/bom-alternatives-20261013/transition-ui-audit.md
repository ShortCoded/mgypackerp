# Material allocation transition and UI audit

This is a source audit of the current working tree. It records intentional transition blocks and the supported operator path; it is not browser acceptance.

## Explicitly blocked transitions

1. **Automatic run reservation after a component is split** — `ProductionCycleService::reserveRun()` throws `material_allocation_explicit_reservation_required`. Operators must reserve through an explicit material request so the material is not chosen silently.
2. **Implicit run issue after a component is split** — `ProductionCycleService::issueMaterials()` throws `material_allocation_explicit_issue_required` when no per-requirement quantities are supplied.
3. **Batch issue after a component is split** — `ProductionCycleService::issueRunBatchMaterials()` throws `material_allocation_batch_issue_unsupported`; the approved material requests must be issued separately.
4. **Output-evidence accounting on a split component** — `ProductionOutputEvidenceService::policy()` throws `material_allocation_evidence_unsupported` because the evidence policy has no allocation dimension.
5. **Creating a new allocation after output-evidence accounting is frozen** — `ProductionMaterialDemandService::allocationRequirement()` throws `material_allocation_policy_frozen`.
6. **Changing the identity or factor of an existing selected requirement** — `ProductionMaterialDemandService::assertMaterialChoice()` throws `material_choice_locked`; a selected requirement is immutable lineage.
7. **Planned quantity above the shared component cap** — create, update, restore, approval, and shortage procurement all call `assertPlannedRequestBudget()` under the company and requirement locks. The validator converts sibling actual quantities to the original unit once, applies only same-requirement reservation credit capped by that selected quantity, and rejects the combined payload with `material_request_exceeds_bom`.
8. **Request reallocation outside its narrow safe window** — reallocation requires an open run/period, a planned request in an approved/shortage/partially-issued state, an unissued positive remainder, a different frozen material, no linked purchase requisition, consistent linked reservations, a reason, edit plus approval permissions, and required idempotency.
9. **Reservation, shortage approval/procurement, or issue after product base-unit drift** — `InventoryReservationService` locks the product, requirement, and request line; requires the current product base unit to equal the frozen requirement unit before availability, including zero-stock paths; writes reservations in the frozen unit; and rechecks reservation unit/conversion fields before consumption. No implicit conversion is attempted.
10. **Additional returns reopening planned demand** — without per-return provenance, the demand ledger conservatively offsets cumulative returns against additional issues first; only the excess reduces planned issue commitment.

## Supported normal UI path

1. Open **Production Material Requests** through `admin.production.material-requests.index` and create a request through `admin.production.material-requests.create`.
2. Select a run. The form renders each frozen BOM component with the primary and approved alternatives as explicit quantity rows, including the frozen conversion factor and original-material context.
3. Submit through the idempotent `admin.production.material-requests.store` route. A single request may contain primary and alternative sibling rows; the service validates their combined original-equivalent quantity.
4. Approve through `admin.production.material-requests.approve`. Approval rechecks the shared cap, then reserves the actual product and frozen unit per request line.
5. Issue partially or fully through `admin.production.material-requests.issue`. The request-line-to-requirement link keeps the actual material explicit through reservation consumption and inventory posting.
6. If an approved line's unissued remainder must change material, use the reallocation panel in the material-request form. `admin.production.material-requests.reallocate` releases only the safe linked remainder and creates a new submitted request that must be approved normally.

Route inspection confirmed the create, store, edit, approve, issue, restore, print, and reallocate routes and their current permission/idempotency middleware. Browser transport was unavailable (`Transport closed`), so the UI path was not clicked and is not claimed as browser-accepted.

## Reporting versus operational quantities

- Operational reserve, issue, and batch eligibility continue to use the stored requirement plan and existing counters.
- Reporting projections are applied only in `ProductionReportService`, run/report controllers, exports, dashboards, and PDF views.
- Unsplit or primary-only component demand retains its stored planned total.
- Once an alternative has planned-request or planned-issued evidence, each row reports its actual selected material/unit quantity; only the component comparison attribute sums those rows in the original unit.
- Additional-only child activity does not activate planned split reporting; the root retains its stored plan and the child projects zero planned quantity.
- Inactive child allocation rows report zero instead of duplicating the full BOM quantity.
