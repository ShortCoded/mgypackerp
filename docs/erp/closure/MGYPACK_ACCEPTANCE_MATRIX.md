# FAST EXECUTION — MGYPACK REQUIREMENT AND ACCEPTANCE MATRIX

This is a proposed acceptance contract, not a test report and not a claim about the current source tree. It expands the supplied brief into verifiable implementation tasks. Confirm existing implementations through targeted evidence, reuse what works, and close what does not. Do not treat a dated “implemented” statement as current verification.

## Evidence and status conventions

Maintain one ledger row for each ID below. Required fields: requirement, current finding, owner, dependency, code/schema/UI changes, focused automated test, application/browser proof where applicable, quantity/value/GL proof where applicable, final verification, status, exact next action.

Use `TO_VERIFY`, `IN_PROGRESS`, `VERIFIED`, `EXTERNAL_APPROVAL`, or `BLOCKED_TECHNICAL`. An engineering task cannot become `EXTERNAL_APPROVAL` simply because its implementation has not been written. For mixed tasks, split implementation and approval into separate linked rows. `VERIFIED` requires actual evidence on the current working tree. All rows below initially require verification; no checkbox is pre-completed.

## HR and payroll

| ID | Required result | Minimum acceptance proof |
| --- | --- | --- |
| HR-01 | Effective-dated employee branch/cost-center assignments and an auditable transfer UI. | Transfer takes effect on the configured date; current-card edits cannot rewrite prior history; genuine overlaps fail; initialization does not fabricate unknown history. |
| HR-02 | Payroll segmentation across branch, salary, policy, hire, and termination boundaries. | Full-month and partial-month calculations; 9,000 salary/day-11 transfer fixture; valid sequential salary versions succeed; overlapping versions fail. |
| HR-03 | Attendance and deductions follow dated assignment/policy, including cross-midnight shifts. | Dated events use the correct branch; missing punches are not fabricated absence; approved leave/overtime applies once; reconciliation explains every deduction. |
| HR-04 | Close requested monthly/weekly/daily/hourly/shift/piece alternatives, not only wage-basis labels. | Each required configurable alternative has source quantities, rates, dates, an independent expected calculation, and a supported end-to-end path. |
| HR-05 | Configurable deduction methods, precedence, caps, and rounding. | Fixed, percentage, time-based, and tier choices behave as configured; overlapping attendance effects do not silently double-charge; totals reconcile. |
| HR-06 | Versioned tax/insurance configuration and actual automatic calculation. | Synthetic test bands/rates and boundaries; employer/employee effect as defined by the approved policy; missing required configuration blocks explicitly; no invented production rates. |
| HR-07 | Correct branch/cost-center accounting and frozen payroll sources. | Segments sum to the employee entitlement and GL postings; branch payrolls cannot duplicate entitlement; posted snapshots survive later policy/card changes. |
| HR-08 | Complete payroll review/post/pay/partial-pay/reverse cycle and reporting. | Authorized HR/accounting/finance/employee paths; liability equals unpaid net; repeated/concurrent payment requests do not overpay; screen and exports agree. |

## Document correction and reopening

| ID | Required result | Minimum acceptance proof |
| --- | --- | --- |
| DOC-01 | “Open Document” capability matrix and clear safe-operation routing for every in-scope type/state. | Each type has a working action or an explicit prerequisite/correction path; no generic status reset masquerades as completion. |
| DOC-02 | Preserve approved-history locks and cancellation/deletion invariants. | Previously approved sales orders and other protected documents cannot become freely cancelable/deletable after reopening or stale-state submission. |
| DOC-03 | Safe correction of posted inventory receipts/issues/transfers/adjustments and opening quantities/pricing. | Original layer/source matching; before/after quantity and value reconciliation; partial/dependent transactions handled through a supported plan. |
| DOC-04 | Safe supplier-invoice and purchase-receipt corrections. | Receipt, invoice, price/expense difference, payment and return relationships remain traceable; no orphaned stock or liabilities. |
| DOC-05 | Safe completed-production-run correction. | Reservations, material issues/returns, consumption/waste, quality, received finished goods, WIP, and downstream deliveries remain consistent. |
| DOC-06 | Safe correction of received sales returns and affected sales documents. | Source quantity, original cost, stock disposition, customer settlement, and reversal are respected; quantities cannot be returned twice. |
| DOC-07 | Transactional authorization, concurrency, idempotency, and audit. | Wrong company/branch/period/permission, duplicate submit, stale state, revoked permission, and injected failure tests do not leave partial financial effects. |
| DOC-08 | Dependency and closed-period handling with reviewable correction plans. | Unsafe root reversals fail with actionable dependencies; authorized correction path is demonstrable; no implicit financial-period reopen or blind cascade. |

## Inventory and production costing

| ID | Required result | Minimum acceptance proof |
| --- | --- | --- |
| COST-01 | Preserve moving-average and FIFO posting with dated scope and snapshots. | Receipt/issue/return/reversal and multi-warehouse tests prove chosen method, layer trace, policy precedence, and value reconciliation. |
| COST-02 | Periodic weighted-average lifecycle rather than report-only simulation. | Period inputs, provisional/final process, adjustments, approval, GL effects and reconciliation are implemented and independently checked. |
| COST-03 | Controlled method transition with existing balances. | Preview, explicit transition basis, layer/source mapping, dated activation, any approved differences, and repeatability; historical posted documents are not overwritten. |
| COST-04 | Distinct last-inbound and last-approved-purchase reference calculations. | Transfer/return/production can affect the former but cannot masquerade as purchase in the latter; sources, currency, date and expense definitions are visible. No unapproved ledger policy is implied. |
| COST-05 | Specific identification by batch/serial across relevant inventory/production paths. | Selected layers/units match consumption, transfer and return; quantities and costs remain exact; duplicate consumption/cross-scope selection fails. |
| COST-06 | Standard-cost version, approval, variance and accounting lifecycle. | Standard materials/labor/overhead and actual differences reconcile; dated versions and account mappings are enforced; a price field alone does not pass. |
| COST-07 | Explicit LIFO algorithm/disposition without fabricated accounting approval. | Requested analysis has deterministic layer tests; accounting use remains tied to a real reporting-framework decision. Do not silently remove an explicitly required posting requirement. |
| COST-08 | Quantity-preserving historical cost completion and necessary downstream adjustments. | Cost source, approval, original receipt lines, consumed-stock treatment, open/closed-period handling and GL/stock differences are traceable. |
| COST-09 | Canonical manufacturing cost and expense allocation without duplicate sources. | Existing accounting-linked overhead and approved allocation rules flow once into WIP/finished goods/COGS; cash and bank direct expenses reconcile and reverse correctly. |

## Data and operational acceptance

| ID | Required result | Minimum acceptance proof |
| --- | --- | --- |
| DATA-01 | Safe master-data/configuration initialization. | Creates only verified missing components; repeat run is harmless; existing approved or soft-deleted records are not overwritten/revived silently. |
| DATA-02 | Isolated, labeled, deterministic acceptance data for all incomplete cycles. | Actual application paths are exercised with synthetic employees, attendance and transactions; none enter production migrations or customer records. |
| DATA-03 | Preserve the exact historical exceptions and avoid duplicate stock. | `OS-00012`, `OSP-00020`, `INV-MOV-00003`, `JE-00007` and the estimated/source-unknown flags are checked in the current database, not assumed; original quantities remain unchanged by pricing. |
| DATA-04 | Current-state-safe `INV-MOV-00002` repair/reconciliation procedure. | Dry run, exact preconditions, original-linked 252-unit validation when applicable, no foreign-layer allocations, and a no-op repeat; historical command limitations are respected. |
| DATA-05 | Customer-source import/estimate-approval path and external approval register. | Agent does not invent attendance/production history or approve estimates; required approver, source and exact affected operation are named. |
| DATA-06 | Backup/restore rehearsal and controlled customer migration package. | Recoverable isolated restore, expected row/value effects, dry run/apply controls, idempotency and reconciliation; no blind migration of local estimates/IDs. |
| CYCLE-01 | Sales → production → quality → receipt → inventory valuation → delivery/invoice → collection. | Full linked documents for representative injection and cover/printing runs, quantity/cost/WIP/GL proof, plus new-quantity-only source allocation after an order amendment. |
| CYCLE-02 | Purchase → inspection/receipt → invoice/expense difference → payment → return/correction. | Stock, supplier liability, original-cost return and accounting agree; ordinary authorized roles perform the steps. |
| CYCLE-03 | Production and maintenance expenses through cash and bank. | Correct cash-voucher versus bank-journal behavior, statement linkage, approved payment/reversal and production-cost treatment. |

## Reports, visual outputs, and release verification

| ID | Required result | Minimum acceptance proof |
| --- | --- | --- |
| REPORT-01 | Complete bounded inventory of existing report endpoints and outputs. | Every actual report has a coverage/evidence row; an HTTP-only crawl is not marked visually verified. |
| REPORT-02 | Identical authorized scope and totals across screen/PDF/XLSX/CSV. | Empty/populated/multipage cases, actual filters, dates, branch/company permissions, full-dataset totals and missing-cost exceptions. |
| REPORT-03 | Visual A4 PDF acceptance using the actual application renderer. | Readable Arabic/English, RTL/LTR, real branding, headers, totals, page numbers and no QR/clipping/overlap; actual generated files are inspected. |
| REPORT-04 | All seven production-monitoring groups and drill-downs. | Values come only from ERP sources; date-window versus lifetime-run semantics remain explicit; exports preserve groups/filters and safe cross-branch read access. |
| REPORT-05 | Representative data-volume and interactive UI acceptance. | More-than-one-page exports/totals, measured performance, working AJAX/date components, scrolling, permissions, and documented measurement limits. |
| RELEASE-01 | Fresh/restored-clone migration, configuration repeatability and recovery validation. | Actual run evidence on the named non-production databases, including historical-data preconditions and a credible restore/recovery rehearsal. |
| RELEASE-02 | Clean final-tree tests, syntax/style/templates and production asset build. | Actual commands/exits/counts; skipped-test disposition; no attribution of prior-tree test results; base revision plus working-tree fingerprint. |
| RELEASE-03 | Financial/inventory/payroll reconciliation and security checks. | Stock layers/movements/GL and payroll liabilities/payments match at the correct dimensions; source links, permission sync and concurrency protections are evidenced. |
| RELEASE-04 | Honest separate technical, data, UAT and live-release decisions. | No engineering gap hidden as “data only”; no local consistency called customer approval; no “prod ready” with an unapproved mandatory release gate. |

## Brief traceability

These references point to sections of the supplied document, not to current code evidence:

- HR: section 7 and the explicit dated-transfer gap in the 30 September review.
- Document corrections: section 4, the “Open Document” status row, and approval/cancellation audit addenda.
- Cost: section 4's policy matrix, transition restrictions, purchase-cost definition and historical-cost treatment.
- Data: sections 7–9, the specific document table, and the historical repair/estimate disclosures.
- Cycles: sections 2–4, 7 and 9.
- Reports: sections 5–6, the report status rows and visual-verification addendum.

The implementation directive adds engineering evidence requirements such as independent expected fixtures, explicit state matrices and current-tree fingerprints. These are proposed acceptance controls, not previously verified business facts.
