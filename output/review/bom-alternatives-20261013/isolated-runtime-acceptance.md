# Isolated runtime acceptance

## Target preparation

- Authorized target: `mgypack_bom_acceptance_20261013`.
- A read-only PostgreSQL catalog check immediately before creation returned absent.
- The target was created with the existing local PostgreSQL role. No role, credential, or privilege was created or changed.
- `DB_DATABASE=mgypack_bom_acceptance_20261013` was used only as a process-local override; the repository `.env` and operational connection were not modified.
- All 356 current migrations completed successfully, including the October 13 BOM-alternative and allocation-ledger migrations.
- Before the walkthrough, the exact target identity was confirmed and the guarded business tables `companies`, `users`, `production_orders`, and `inventory_transactions` were empty.

## First walkthrough attempt

The standalone application-runtime script `/tmp/mgypack_bom_acceptance_20261013.php` used normal Laravel services and wrapped all synthetic fixture and workflow writes in one outer transaction. It did not invoke a test runner.

The first attempt stopped during minimal accounting setup with PostgreSQL unique violation `23505` on `account_classifications_doc_number_unique_active`: migration state already contained `expenses` as `ACLS-00001` with `doc_number = 1`, while the synthetic raw-material classification attempted to reuse `doc_number = 1`.

This was a fixture numbering collision before any production order or inventory transaction ran, not a production-service defect. The outer transaction rolled back. A read-only post-failure check confirmed:

- `companies`: `0`
- `users`: `0`
- `accounts`: `0`
- `production_orders`: `0`
- `inventory_transactions`: `0`
- pre-existing reference row: account classification `expenses`, `ACLS-00001`, `doc_number = 1`

The operational `mgypack` invariants matched exactly before and after the attempt:

- companies: `1`
- production orders: `18`
- production runs: `37`
- inventory transactions: `507`
- inventory quantity in: `4565687.27342000`
- inventory quantity out: `670233.20542000`
- material reallocations: `0`

## Minimal correction and review gate

Only the ephemeral walkthrough script was corrected: the two synthetic account classifications now use unused document numbers `2` and `3`. No application source, migration, schema, or tracked patch file changed. The corrected script passes `php -l` and has SHA-256:

`006ceeb5f934987edc82d47bbda982264bdfbf264f566b33ecbffa79693bb29d`

The corrected harness passed independent review at the hash above. Before its one permitted rerun, an exact read-only guard confirmed classification document numbers `2` and `3`, both required classification codes, and all guarded business tables were unused.

## Independently reviewed rerun

The reviewed script was rerun exactly once with the same process-local target override. The canonical workflow advanced through mixed primary/alternative create and update, approval, partial issue, unissued reallocation, target approval/issue, actual-alternative return, journal balance checks, and canonical run costing. It then stopped at a report assertion:

- alternative issued cost: `135.00000000`
- alternative return cost: `15.00000000`
- expected alternative report actual cost: `120.00000000`
- reported alternative actual cost: `135.00000000`

The outer transaction rolled back. A read-only target check again returned zero companies, users, accounts, production orders, inventory documents, inventory transactions, and material reallocations.

The defect is in application reporting rather than the harness. `ProductionReportService::applyMaterialMetrics()` joined all material costs through `inventory_reservations`, but canonical return lines carry their frozen requirement through `source_line_type` and `source_line_id` and do not carry a reservation ID. Canonical `ProductionCostService` had already subtracted the same return correctly.

## Source correction and second review gate

`ProductionReportService` now left-joins reservations and attributes each posted material cost to the reservation requirement when present, otherwise to the canonical `ProductionMaterialRequirement` source lineage. A focused unexecuted specification requires a `90` alternative issue minus a `30` return to report `60`, matching canonical run costing.

Only `ProductionReportService.php`, the focused specification file, and review evidence changed. Scoped Pint, PHP syntax, and `git diff --check` passed. No test runner was invoked. The tracked patch is now SHA-256:

`e60ef18e802ee9462d03aebb9ec6e1dfc23db46bec85a5ba4aa27230c85dc9cb`

Operational `mgypack` invariants matched exactly before and after the reviewed failing rerun: one company, 18 production orders, 37 production runs, 507 inventory transactions, quantity in `4565687.27342000`, quantity out `670233.20542000`, and zero material reallocations. The migrated acceptance target remained empty pending independent source review.

## Reviewed successful walkthrough

Independent source review passed the corrected tracked patch at SHA-256:

`e60ef18e802ee9462d03aebb9ec6e1dfc23db46bec85a5ba4aa27230c85dc9cb`

Immediately before execution, read-only guards confirmed:

- current database: `mgypack_bom_acceptance_20261013`
- migrations: `356`
- companies, users, accounts, production orders, inventory documents, inventory transactions, and material reallocations: all `0`
- classification document numbers `2` and `3`: unused
- classification `doc_num` values `SYNTHETIC-CLASS-RAW` and `SYNTHETIC-CLASS-WIP`: unused
- classification codes `raw_material_inventory` and `work_in_process_inventory`: unused
- walkthrough script SHA-256: `006ceeb5f934987edc82d47bbda982264bdfbf264f566b33ecbffa79693bb29d`

The reviewed script then ran exactly once and passed. Its retained canonical evidence includes:

- mixed planned allocation updated to primary `50` plus alternative `75`, exactly `100` original-equivalent units
- over-cap request rejected with `material_request_exceeds_bom`
- partial issue: primary `20` costing `40`, alternative `30` costing `90`
- unissued reallocation: source `10`, original equivalent `10`, target alternative `15`
- target alternative issue: `15` costing `45`
- canonical alternative return: `5` costing `15`
- alternative report actual cost: issued `135` minus returned `15` = `120`
- canonical total direct-material cost and report run cost: primary `40` plus alternative net `120` = `160`
- reported plans: primary `40`, alternative `90`, component original-equivalent total `100`
- issue, target-issue, and return journals balanced at `130/130`, `45/45`, and `15/15`
- zero-stock frozen-unit drift rejected with the request still `submitted` and zero reservations created
- final stock: primary available/staging `480/20`; alternative available/staging `460/40`

The isolated target retains one synthetic company, one user, five products, two production orders, two production runs, three material requests, three inventory documents, ten inventory transactions, three journal entries, and one material reallocation for review. The script was not rerun afterward.

## Canonical return lineage

A read-only query against the retained target confirms:

| Document | Type | Product | Quantity | Cost | Reservation | Frozen source type | Frozen source ID |
| --- | --- | ---: | ---: | ---: | ---: | --- | ---: |
| `INV-MOV-00001` | production material issue | `6` | `30.00000000` | `90.00000000` | `5` | `Modules\Production\Models\ProductionMaterialRequirement` | `4` |
| `INV-MOV-00002` | production material issue | `6` | `15.00000000` | `45.00000000` | `6` | `Modules\Production\Models\ProductionMaterialRequirement` | `4` |
| `INV-MOV-00003` | production material return | `6` | `5.00000000` | `15.00000000` | `null` | `Modules\Production\Models\ProductionMaterialRequirement` | `4` |

This directly exercises the corrected fallback: the return has no reservation but shares frozen requirement source ID `4` with both alternative issues. The focused `90 - 30 = 60` regression remains intentionally unexecuted because test-runner commands are prohibited; the retained canonical scenario proves the same attribution behavior at `135 - 15 = 120`.

## Operational isolation after success

The read-only operational baseline and post-run check were identical:

- database: `mgypack`
- companies: `1`
- production orders: `18`
- production runs: `37`
- inventory transactions: `507`
- quantity in: `4565687.27342000`
- quantity out: `670233.20542000`
- material reallocations: `0`

No test runner, operational write, Git commit, push, credential change, role change, or PLUMA access occurred.
