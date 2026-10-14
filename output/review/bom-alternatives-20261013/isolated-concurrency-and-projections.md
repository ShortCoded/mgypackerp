# Isolated concurrency and locale projection evidence

## Scope and target

- Exact database: `mgypack_bom_acceptance_20261013`.
- New scenario label: `SYNTHETIC-BOM-CONCURRENCY-20261013`.
- The retained successful canonical walkthrough was not rerun or modified.
- Existing retained records were protected by a selected-row SHA-256 fingerprint before and after the new scenario.
- Operational `mgypack` was queried read-only before and after.

Initial temporary harness hashes:

- concurrency worker: `1b18f018e4d7472924abd06a429fb29adc4cb5516cda372c30ddd8666764ff34`
- concurrency orchestrator: `6bb0e23484da1cfe2270fd28e01962c31a1b5e349dcb2b8dc8c34700a6a7794f`
- corrected read-only locale projection with guarded PDF output: `552670aa44423e206a2ee8aaa351a7cb79c4f820eb2700a7f0b1eba8ca30f72b`
- multi-position reproduction: `5cc8fa4e5103cd0534b5346db9634848d7a208fa46beff4111734de3dac6a1fa`

All four passed `php -l`.

Approved follow-up harness hashes:

- corrected multi-position approval and issue: `24fbab9f6ad8462bf25b10c3974c78aeada88a6cb9bb95c5fd106bc82f65d884`
- operational null-dimension compatibility audit: `abd24992dcb98aac175a50de4c61972cd170ff10301d44f28270e646d10fd39b`

Both follow-up harnesses passed `php -l`. No test runner was invoked.

## Bounded two-process request race

The new order `PROD-00003` and run `PROD-00003-R001` use one component with fixed capacity `100.00000000` in the original unit:

- primary conversion factor: `1`
- alternative conversion factor: `2`
- primary stock: `500`
- alternative stock: `1000`

Two separate PHP processes booted Laravel independently, opened separate process-local PostgreSQL connections, verified the exact target, authenticated the existing synthetic user, checked its existing `production.material_requests.create` permission, reached a filesystem readiness barrier, and were released together:

- primary worker requested `60`, equal to `60` original
- alternative worker requested `120`, equal to `60` original

Result:

- primary worker committed `PMR-00004`, line `12`, requested/original `60/60`
- alternative worker was rejected with `material_request_exceeds_bom`
- only one competing request persisted; no `120`-original combined commitment was stored

A normal sequential request then filled the remaining capacity through the opposite material:

- `PMR-00005`, line `13`
- alternative requested quantity `80`
- original-equivalent quantity `40`

The resulting planned total is exactly `100` original units.

## Bounded two-process approval race

Two new independent processes reached a second readiness barrier and concurrently approved `PMR-00004` and `PMR-00005` using the existing `production.material_requests.approve` permission.

Both became `approved` with zero shortage. Read-only lineage after completion:

| Request | Line | Product | Actual reserved | Factor | Original equivalent | Active reservations for line |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| `PMR-00004` | `12` | `10` | `60.00000000` | `1.00000000` | `60.00000000` | `1` |
| `PMR-00005` | `13` | `11` | `80.00000000` | `2.00000000` | `40.00000000` | `1` |

Verification:

- requested original-equivalent total: `100.00000000`
- reserved original-equivalent total: `100.00000000`
- active reservation IDs: `7`, `8`
- each request line had one active reservation because its material occupied one stock position in this scenario
- multiple reservations for one request line are valid when they represent distinct stock positions; reservation count alone is not evidence of duplication
- retained canonical-record fingerprint before: `1d32ba59ed7765565553c64f9387fdf07b019346066a4d68eb779ff4112580c4`
- retained canonical-record fingerprint after: `1d32ba59ed7765565553c64f9387fdf07b019346066a4d68eb779ff4112580c4`

The new scenario was executed once and retained. It was not rerun.

## Multi-position false-shortage reproduction

The new retained scenario `SYNTHETIC-BOM-MULTIPOS-20261013` used order `PROD-00004`, run `e0360e1d-a3cd-4ad2-baec-6dd5a5df4ae0`, request `PMR-00006`, line `14`, and requirement `10`. One raw material had exactly two positive positions, in FIFO order:

| Position | Location | Batch | Quantity | Unit cost |
| --- | --- | --- | ---: | ---: |
| older | `null` | `null` | `40.00000000` | `2.00000000` |
| exact | `1` | `SYNTHETIC-BOM-MULTIPOS-20261013-STOCK-LOT` | `60.00000000` | `4.00000000` |

Approval of the `100.00000000` request reproduced the defect. The first `40` slice was reserved inside the approval transaction. Exact availability for the second position then also subtracted that null/null reservation and exposed only `20`, leaving a false `40` shortage inside `reserveForProductionAcrossPositions()`. The service raised the localized `The production reservation exceeds available stock.` domain error.

The failed approval rolled back completely:

- request status remained `submitted`
- line approved, reserved, and shortage quantities remained `0.00000000`
- requirement reserved quantity remained `0.00000000`
- no reservation ID survived
- pre-existing isolated-record fingerprint remained `c4d45c5b50aca442c45390f1c6bd41e6df5fb812e5d3026eda607f25c5807041` before and after

After independent source approval, the retained submitted request was rerun once. In exact-dimension mode, `InventoryAvailabilityService` subtracts only reservations whose location and batch exactly match the physical position. Aggregate availability retains its prior exact-or-null behavior.

Corrected approval and issue evidence:

- availability before approval: aggregate `100`, exact null/null `40`, exact location/batch `60`
- request `PMR-00006` became `approved` with approved/reserved/shortage `100 / 100 / 0`
- requirement `10` reserved counter became `100`
- reservation `11`: null/null, quantity `40`
- reservation `12`: location `1`, batch `SYNTHETIC-BOM-MULTIPOS-20261013-STOCK-LOT`, quantity `60`
- issue document `INV-MOV-00004` issued `70` as `40 + 30`
- issue line `9`: reservation `11`, request line `14`, requirement `10`, unit cost `3.20000000`, total cost `128.00000000`
- issue line `10`: reservation `12`, request line `14`, requirement `10`, unit cost `4.00000000`, total cost `120.00000000`
- document material cost: `248.00000000`
- request became `partially_issued`; request-line and requirement issued counters became `70`
- reservation `11` became consumed with zero remainder
- reservation `12` remained active with `30` consumed and `30` remaining
- aggregate, null/null, and exact location/batch availability all became `0` after subtracting their matching active reservations
- the prior-evidence fingerprint remained `db61779f514c5629310e44ed5d31bf4b9d0d35c97688fedf1956342a07b5b3ff` before and after

The released BOM snapshot contains no approved alternative. Reallocation and automatic release of the retained `30` were therefore not attempted; neither is a safe supported continuation for this fixture.

## Concurrency-only isolated count delta

Expected additive changes from the bounded concurrency scenario only, before the later multi-position scenario:

| Table | Before | After |
| --- | ---: | ---: |
| products | 5 | 8 |
| product components | 2 | 3 |
| component alternatives | 2 | 3 |
| production orders | 2 | 3 |
| production runs | 2 | 3 |
| material requirements | 4 | 6 |
| material requests | 3 | 5 |
| material request lines | 4 | 6 |
| inventory reservations | 3 | 5 |
| inventory transactions | 10 | 12 |

Companies and users remained `1` each.

## Final isolated totals after corrected multi-position issue

| Table | Final total |
| --- | ---: |
| companies | 1 |
| users | 1 |
| products | 10 |
| product components | 4 |
| component alternatives | 3 |
| warehouse locations | 1 |
| production orders | 4 |
| production runs | 4 |
| material requirements | 7 |
| material requests | 6 |
| material request lines | 7 |
| material reallocations | 1 |
| inventory reservations | 7 |
| inventory documents | 4 |
| inventory document lines | 6 |
| inventory transactions | 18 |
| receipt layers | 10 |
| layer allocations | 6 |
| journal entries | 4 |
| journal entry lines | 8 |

## Arabic and English read-only projections

The retained canonical records `PROD-00001` and `PROD-00001-R001` were projected through the normal PDF and Excel render services in memory. The synthetic user lacked every corresponding route right:

- `production.orders.print`: `false`
- `production.runs.print`: `false`
- `production.reports.materials.view`: `false`
- `production.reports.materials.print`: `false`
- `production.reports.materials.export`: `false`

No permission was granted and no protected route was invoked. This is render/export-service evidence only, not route authorization acceptance.

| Locale | Direction | Order PDF | Run materials PDF | Materials report PDF | XLSX | Worksheet RTL |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| Arabic | `rtl` | 51,445 bytes | 55,633 bytes | 70,928 bytes | 7,257 bytes | `true` |
| English | `ltr` | 52,485 bytes | 52,842 bytes | 51,046 bytes | 7,145 bytes | `false` |

All PDFs began `%PDF-1.4`; both XLSX payloads began `504b0304`. The normal HTML projections behind each PDF contained `SYNTHETIC-BOM-ALTERNATIVE-1`.

The localized materials workbooks contained the retained alternative row:

- run/order: `PROD-00001-R001` / `PROD-00001`
- planned/reserved/issued/returned: `90 / 90 / 45 / 5`
- Arabic sheet title: `ترصيد خامات الإنتاج`, RTL enabled
- English sheet title: `Production Material Reconciliat`, RTL disabled

The first projection harness attempt stopped on an omitted shared `$reportTitle` variable. The second completed all render assertions but stopped at the final count check because a local BOM variable shadowed the count closure. Both were read-only harness defects. After those two local corrections, the hash recorded above passed, with identical isolated counts before and after.

All six current PDF payloads were saved under `visual-fidelity/`, validated by `pdfinfo` as one-page A4 landscape mPDF documents, rasterized at 144 DPI with Poppler, and inspected at original PNG resolution. None was blank. Arabic and English order, run-material, and aggregate-material pages all had visible company identity, titles, table borders, values, page footer, and the retained primary/alternative material data. No clipping or overlapping text obscured the tabular evidence.

## Operational invariants

The read-only operational baseline and post-concurrency check remained identical:

- companies: `1`
- production orders: `18`
- production runs: `37`
- inventory transactions: `507`
- quantity in: `4565687.27342000`
- quantity out: `670233.20542000`
- material reallocations: `0`

The additional compatibility audit executed inside an explicit PostgreSQL read-only transaction. It inspected all active operational reservations whose location or batch is null:

- active rows inspected: `9`, all with positive remainder
- exact tuples: `4`
- total remaining quantity: `3597.36840000`
- supported by stock at the exact null/null tuple: `4` groups
- wildcard-looking groups: `0`
- unsupported groups: `0`

| Store | Product | Reservation IDs | Type | Remaining | Exact available-status stock | Other-position available stock | Result |
| ---: | ---: | --- | --- | ---: | ---: | ---: | --- |
| 1 | 982 | `55, 56` | sales | `80.00000000` | `165.00000000` | `0.00000000` | supported exact tuple |
| 3 | 907 | `53, 54` | sales | `28.00000000` | `57.00000000` | `0.00000000` | supported exact tuple |
| 4 | 42 | `1, 3, 4` | production | `3425.10000000` | `23043.12100000` | `0.00000000` | supported exact tuple |
| 4 | 58 | `2, 5` | production | `64.26840000` | `165.12338000` | `0.00000000` | supported exact tuple |

All four groups had both location and batch null and were fully supported without borrowing from another stock position. The five production reservation rows have no material-request-line ID, but their exact-tuple stock coverage is sufficient; no lineage was normalized or mutated.

## Remaining boundaries

- Browser inventory remained empty earlier, and the latest browser-control documentation/reset calls both failed because the transport was closed; no UI or route-level acceptance is claimed.
- The bounded two-process race and corrected multi-position approval/issue are runtime-proven. Repeated stress, multi-host concurrency, and deadlock/retry variation remain unproven.
- PDF/XLSX rendering and locale projection are proven below the route layer. The complete report/export permission-policy matrix remains unproven because the synthetic user lacks those rights and no rights were added.
- Focused Pest specifications remain unexecuted by instruction.
- No operational write, permission grant, database reset, publication, Git commit, or push occurred.

