# FAST EXECUTION — MGYPACK IMPLEMENTATION AND RELEASE CLOSURE

## Mandate: implement the unfinished scope, not another audit

Continue the current MgyPack ERP repository and working tree. This is an implementation assignment, not a request for another readiness review. The owner has repeatedly requested completion of the outstanding code, usable configuration, representative test data, end-to-end operation, and verification.

The supplied business brief records legitimate unfinished engineering. Do not spend another execution cycle adding one protective check and returning the same unfinished payroll, document correction, costing, and report items. A guard that rejects an unsupported operation is necessary protection; it does not implement the supported operation that the owner requested.

Your objective is to close all locally executable requirements in the attached acceptance matrix, verify the integrated result, and produce a precise release decision. Do not force a positive decision, invent approval, conceal a failure, or redefine an unfinished requirement as complete. Conversely, missing customer evidence must not become an excuse to leave an implementable engine, UI, correction workflow, or acceptance scenario unfinished.

This directive and its matrix are a proposed execution/acceptance contract. They do not claim that any code has already been changed or verified. The reference brief is a dated status report, not a live inspection or an approved financial policy.

## 1. Authority, scope, and operating rules

- Work on MgyPack only. Do not import the single-company simplifications, financial-module removals, branding, or PDF-engine decisions of Egyptian Furniture, PLUMA, Al Emran, or another repository. Preserve the actual MgyPack company, branch, financial-period, accounting, inventory, production, HR, and permissions boundaries.
- Preserve the current working tree and all existing user changes. Do not reset, restore, clean, discard changes, create a replacement application, or restart completed modules. Do not commit, push, deploy, create new branches/worktrees, or touch the live customer database without separate authorization.
- Local application development and isolated acceptance fixtures are in scope. This is not authority to approve historical balances, invent employee attendance, approve statutory rates, or apply estimated development balances to production.
- Honor the current `AGENTS.md`, approved delegation ownership, and credit-first routing. Use the already configured MCP tools and workers where available and useful. Do not rebuild or retune the Hermes/OpenCode infrastructure. Do not reactivate a disabled provider. Never claim to have used a worker, browser, MCP server, or database connection that did not actually execute.
- Reuse canonical posting, costing, payroll, inventory, production, permissions, date, Select2, PDF, and export services. Do not create a second ledger, a parallel payroll calculator used only in tests, an alternative stock engine, or a duplicate source for manufacturing expenses.
- Read context once, then inspect relevant deltas. Avoid repeated whole-repository discovery, repeated full suites after each tiny patch, unrelated dependency upgrades, cosmetic rewrites, and infrastructure detours.
- Keep all coding-agent instructions and durable execution records in English; implement the application in its existing Arabic/English conventions.

## 2. Start with a short, bounded preflight

Read the current applicable `AGENTS.md`, `PROJECT_BUSNIESS_CONTEXT.md` (exact spelling), current-state/decisions/issues records if present, the existing repository version of `docs/erp/MGYPACK_BUSINESS_AND_DOCUMENT_CYCLE_2026-09-29.md`, and this pack. The copy under `reference/` is a preserved historical reference: do not overwrite a newer repository brief with it.

Establish the current Git status, base revision, actual runtime, configured local database identity, and current test/build commands. Do not print secrets. Determine whether the inspected database is a disposable test database, a development copy of customer data, or a live database before any write. Verify the environment through actual tools, not path names alone.

Make one targeted pass through the affected services, routes, migrations, policies, tests, and report inventory. Do not treat the old test count or the brief's old status as proof of the current tree. Existing functionality that passes targeted checks should be reused, not reimplemented.

Create or update these durable records under the current repository's normal ERP documentation location, preferably `docs/erp/closure/`:

1. `MGYPACK_CLOSURE_LEDGER.md`: requirement ID, observed gap, implementation owner, dependencies, files, test/evidence references, current status, and exact next action.
2. `MGYPACK_ACCEPTANCE_EVIDENCE.md`: executed commands, exit codes, relevant assertions, browser/export artifacts, database identity, and the tested working-tree identity.
3. `MGYPACK_EXTERNAL_APPROVALS.md`: genuinely unavailable customer evidence or authority; keep this separate from engineering work.
4. `MGYPACK_RELEASE_DECISION.md`: technical, migration, customer-data, UAT, and live-release decisions separately.

These records are execution checkpoints, not the main deliverable. Move promptly into implementation. Follow the matrix IDs and add only confirmed in-scope omissions; do not expand this into an unlimited generic ERP audit.

## 3. Closure rules that prevent the repeated partial-fix loop

For each implementable gap, perform the following loop:

1. Reproduce the missing behavior or demonstrate the absent supported path.
2. Add a regression/acceptance test that would fail without the required behavior.
3. Implement the full path through persistence, services, authorization, UI, audit, financial/inventory effects, and reports where applicable.
4. Run focused tests and exercise the real application route or screen.
5. Record evidence and continue to the next unfinished requirement without asking for another general approval.

Do not mark a requirement complete because a menu, model, database column, label, disabled option, exception, or happy-path unit test exists. A requirement is complete only when the supported action works end to end and unsafe variants are rejected correctly.

Do not remove or hide a requested capability merely to obtain a green readiness checklist. Preserve requested scope. Distinguish an implemented but approval-gated capability from an unimplemented capability. An approval gate is legitimate only if the engine, lifecycle, permissions, audit, and acceptance evidence are actually complete.

When a real external decision is needed, record its exact missing input, the affected requirement and operation, what was implemented and verified with labeled fixtures, who must approve it, and the consequence of leaving it unresolved. Continue all independent implementation work. Do not write only “customer review required.”

## 4. Workstream HR — dated assignments and complete payroll operation

### Effective-dated employment and salary segments

Implement an authoritative assignment history carrying the relevant branch, department/cost-center dimensions, effective start/end, approval/audit information, and links to salary assignments under existing conventions. Updating the current employee card must not rewrite historical truth. The UI must collect the effective transfer date and show the history.

For existing employees, create a controlled initialization/migration path from verified available data. Do not fabricate an earlier branch history or silently assume that the employee's current branch applied to all historical periods. Ambiguous historical periods must be explicit exceptions; new dated transfers must be usable immediately.

Split payroll computation at every relevant boundary: employment start/end, branch assignment, salary version, and applicable dated policy. Reject true overlapping assignments, but support consecutive valid salary versions or branch transfers within one payroll period. The present behavior of rejecting every multi-version period is not completion.

Attendance effects must use the assignment and applicable policy at the occurrence date, not the employee's current branch. Use the existing authoritative attendance/shift timestamps and explicit date-boundary conventions. Prevent the same entitlement from being calculated or paid by both branch payrolls. Preserve branch/cost-center allocation through payslip detail, journal lines, liability, reports, and reversals.

Use an explicitly synthetic fixture for calculation checks: a 30-day month, monthly salary 9,000, and a transfer effective on day 11 must allocate 3,000 to the first branch and 6,000 to the second under a configured calendar-day policy. With a further salary change to 12,000 effective on day 21, the three segments must be 3,000, 3,000, and 4,000. These are test values only, not customer payroll or mandatory company policy. Attendance deductions and rounding need their own independent expected values.

### Wage, deduction, tax, and insurance policies

Close the required alternatives listed in the brief's wage-policy table, not only the six wage-basis labels. Track calendar/fixed-divisor/scheduled-day choices, partial weeks, paid leave and holidays, hourly rounding and overtime separation, overnight/partial shifts, and piece-work quantity/quality/rework ownership explicitly.

Implement effective-dated, versioned configuration, validation, approval, and calculation trace for the requested deduction types, overlap/precedence handling, caps, rounding, missing-punch treatment, and tax/insurance rules. Reuse canonical salary components and account mappings. The existence of `PAYROLL-TAX` and `SOCIAL-INSURANCE` components alone is not an automatic calculation engine.

Do not invent current tax bands, insurance percentages, wage bases, annualization rules, caps, or legal approval. Derive production configuration only from an approved available source. Test the generic engine using unmistakably synthetic rules. Where the approved rule requires cumulative inputs or employee-level assessment, transfers must not reset thresholds or double-apply allowances. Keep uncertain policy choices visible and separately approval-gated; do not substitute zero deductions for missing required configuration.

Posted payroll must retain its assignment, quantity/time source, rates, policy versions, component calculations, currency, and allocation snapshots. Policy changes must not silently alter posted results. Exercise review, approval, posting, partial payment, payment approval, payment reversal, remaining liability, and duplicate/concurrent-payment prevention through the existing finance workflow.

## 5. Workstream DOC — safe correction from “Open Document”

Build an explicit document-capability matrix from the actual application types. Cover the supported sales and purchase requests/orders, material requests, opening quantities/pricing, inventory receipts/issues/transfers/adjustments, supplier invoices, sales invoices, completed production runs, and received sales returns. Record the safe operation for each state rather than assuming every document can become a draft.

The shared screen must route to the correct supported operation: ordinary draft editing, controlled reopen/revision, linked reversal and replacement, return, quantity/value correction, or a clearly explained dependency-resolution path. A dependency lock may be correct; a generic “unsupported” error with no required correction path is not completion.

Preserve approved-history locks and the new sales-order cancellation protection. An order once approved must not become freely deletable or cancelable merely because it was reopened. Do not undo those protections to make the screen appear functional.

Before executing a correction, show the original document, current lifecycle, dependent documents, quantity/value impact, intended posting period, reason, and necessary authorization. Re-read state under the existing transaction/locking conventions. Revalidate permissions and scope on repeated requests, and prevent duplicate reversals or partially posted corrections.

Retain original documents, version/source links, journals, cost-layer allocations, reservations, receipt counters, payment allocations, quality facts, and audit evidence. Reverse or correct original costs through the canonical services. Do not select unrelated stock layers or replace a posted historical cost with today's purchase price.

Handle dependencies explicitly. An unconsumed receipt may support a simple original-layer reversal; an already consumed or sold receipt requires the documented downstream correction/revaluation path. A received/sold production output must not be undone by just resetting a run's status. Sales returns must respect source quantities, original cost, quality/stock disposition, and settlement state. Supplier corrections must preserve invoice/receipt/payment and price-difference relationships.

Never introduce an automatic blanket cascade that silently reverses customer or supplier transactions. Provide an ordered, reviewable correction plan; where a multi-step correction is needed, each step must be recoverable, idempotent, auditable, and leave valid intermediate states. Respect closed-period policy and existing authorized correction-period conventions. Do not reopen a financial period or invent authority as a shortcut.

For each supported type, verify both a successful correction and appropriate denial for unsafe dependencies, missing permissions, wrong scope, duplicate requests, stale state, and closed periods. Match quantities, layers, journals, and the relevant reports before and after.

## 6. Workstream COST — finish the costing matrix without relabeling analytics

Start from the source brief's explicit distinctions. Preserve working moving-average and FIFO posting. Track every listed method separately: moving average, periodic weighted average, FIFO, last inbound cost, last approved purchase price, specific identification by batch/serial, standard cost, and LIFO. Do not silently drop a method or declare an analytical dropdown a posting policy.

Maintain a dated company/branch/warehouse scope and policy-precedence rule. Store the selected method, policy version, basis, inputs, layer references, and rounding/currency treatment for posted effects. Do not recost all warehouses through a single company-wide average when the defined scope is per warehouse.

Required implementation coverage:

- Periodic weighted average: complete the required period process, provisional/final states, deterministic period calculation, resulting adjustment/variance documents, approval, posting, and reconciliation. A report-only average is not its posting lifecycle.
- FIFO and policy transitions: preserve existing posting and implement the approved transition/reconciliation mechanism for existing stock rather than only refusing it forever. Preview the mapping/opening layers and any differences; prevent races/backdated movements during the relevant operation. Do not rewrite old posted documents or create invented historical receipt dates. Missing historical sequencing must remain visible and require an approved transition basis.
- Last inbound cost: retain its explicit analytical-reference meaning from the brief. It is not “last purchase.” Do not turn a reference valuation into historical ledger cost by changing a label.
- Last approved purchase price: implement its distinct approved purchase source, currency, eligible receipt/invoice, discount, expenses, and date rules. Any requested ledger use needs an explicitly defined policy; do not invent that business decision.
- Specific identification: complete the actual batch/serial selection, partial consumption, layer links, transfer, production consumption/receipt, return, and reversal path. Prevent selecting stock outside the permitted scope or consuming the same unit twice.
- Standard cost: complete dated approved standard versions and the required material/labor/overhead variance accounts, calculation, posting, and reconciliation. Missing account or policy configuration must fail explicitly. Do not treat a standard-price field as an implemented standard-cost lifecycle.
- LIFO: track its requested analytical algorithm and results explicitly. The brief requires review of the applicable reporting framework before accounting use. Do not assume approval or present LIFO as an approved posting policy. Do not cite an unverified accounting rule; record the actual framework decision needed.

Resolve contradictory definitions against explicit existing project decisions and approved configuration. Do not silently change formulas or infer financial authorization. An unresolved mandatory posting requirement stays visible in the release decision; “comparison only” is not a way to close a requirement that was explicitly requested for posting.

Complete the existing historical receipt-cost completion path with provenance, approval, before/after value, source-line links, and a quantity-preserving correction. If stock has been consumed, implement the required approved downstream cost/variance handling rather than corrupting source layers. Do not duplicate opening stock to fill a missing price.

Preserve the canonical manufacturing overhead source from the existing accounting/cost-center linkage. Do not introduce duplicate expense input or double-charge posted expenses. Preserve the project's approved allocation rules; trace production cost through materials, direct expenses, applicable labor/overhead, WIP, finished goods, and cost of sales.

## 7. Workstream DATA — create usable test data, not invented customer history

Keep three categories separate:

1. Master configuration: idempotently create verified missing standard components and required configurable mappings; never overwrite an existing approved setting or silently restore deleted data. Finance mappings and adopted policies require actual authority.
2. Acceptance fixtures: create deterministic, labeled synthetic employees, attendance, purchasing, production, quality, payment, and correction scenarios in a verified isolated database. Use the application/service paths where behavior is under test. They must never be production migrations or default customer seed data.
3. Customer historical data: preserve provenance and approval state. Prepare previews and controlled correction tools using actual sources. Do not promote estimated development values, missing biometric history, or external example spreadsheet rows into customer transactions.

Preserve these specific findings and safeguards while verifying their current state:

- `OS-00012` already contains the 81 and 73 opening units. Do not create another opening quantity. Its local `OSP-00020` value of 198,185 is an estimate until expressly approved.
- `INV-MOV-00003` has 14 lines/252 units and a local estimated value of 153,720, with `JE-00007`. Do not invent a production-run link or call this proved production cost. Preserve the explicit historical-source exception until actual evidence resolves it.
- `INV-MOV-00002` is the historical reversed receipt. The brief records repair of 209 misallocated units and a total original-linked reversal of 252. Do not blindly rerun a repair because the file says it once existed. Preview current conditions, verify exact expectations, and make reruns harmless. Its old maintenance command has documented limitations; do not apply it outside them.
- Locally created opening journals, accounts, and zero reconciliation differences establish local consistency, not customer approval of the underlying values or counterpart accounts.
- The August production workbooks are analysis references only. Do not import their rows as ERP production, inventory, labor, or cost transactions.

Before authorized changes to a non-disposable development copy, take a real backup and validate recoverability on an isolated restore. For the customer migration package, provide dry-run output, exact preconditions, expected changes, reconciliation queries, provenance, and a recovery plan. Do not hard-code local document/database IDs as universal production identities.

Create a usable estimate-approval/import path where required. The business brief permits an explicitly approved opening estimate when historical evidence is unavailable; the agent may prepare that workflow but must not impersonate the approver. Missing approval is an external gate; missing workflow is an engineering gap.

## 8. Workstream ACCEPTANCE — complete cycles and every report

### Operational cycles

Exercise real screens/routes and canonical services in an isolated customer-like environment. Cover both injection and cover/printing operations without inventing their historical production. At minimum prove:

- Sales order → remaining source quantity → production order/run → material request/reservation → partial/full issue → progress/consumption/return/waste → quality → linked finished-goods receipt → inventory costing → invoice/delivery → receipt/payment allocation. Additional sales-order quantity must expose only the new remainder; prior allocations must not duplicate.
- Purchase need/order → receipt/inspection → invoice and documented price/expense adjustment → supplier liability/payment → supported return/correction.
- Employment/dated transfer → biometric import and finalized attendance → wage/deduction calculation → payroll review/approval/posting → partial finance payment/approval → reversal → employee and GL liability reconciliation.
- Production/maintenance expense through both cash and bank paths, including payment reversal and the production-cost consequence where applicable.

Verify quantity and value identities at the proper item, base-unit, branch, warehouse, stock-status, currency, and period scope. Do not aggregate unlike units/currencies into misleading totals. Unknown cost must remain unknown, not silently zero. Full-cycle evidence must include ordinary authorized users and explicit cross-scope/permission denial, not only an administrator.

### Exhaustive bounded report inventory

Enumerate actual report endpoints from the menus/routes and their print/export handlers once. Give every report an ID and evidence row. The scope is the application's existing reports and requested outputs, not newly invented report families.

For each report verify empty state, representative populated state, relevant filters, permissions, complete-dataset totals, and consistency across screen/PDF/XLSX/CSV where those outputs are supported or required. Include production-monitoring's seven groups and document drill-downs. A 200 response or route crawl is not visual acceptance.

Render actual server-generated PDF files using the current project engine, then visually inspect the output. Verify A4 orientation, Arabic/English, RTL/LTR alignment, readable type, repeated table headers, real company branding, period/filter metadata, page numbering, totals, no unwanted QR codes, and no clipping/overlap. Inspect multipage tables and first/last page behavior. Do not substitute browser printing for the application's required PDF workflow.

Preserve original Falcon components, shared date inputs/formatting, and AJAX search for large selectors. Use the existing project conventions instead of introducing a new UI shell. Do not borrow PLUMA branding or assume another project's PDF library.

Verify that filters and permissions apply equally to display, print, and export. Test more than one page of results and a realistic larger dataset. Measure representative latency, queries, export behavior, and memory; use existing approved performance thresholds. Where none exist, disclose measurements and limitations rather than inventing an acceptance number or claiming unmeasured performance.

## 9. Integration and final verification gate

Implement dependent schema/service foundations before their consumers. Parallelize only when the current ownership/routing permits and file/DB ownership is genuinely separate. Shared posting/costing contracts need one owner. Run focused checks after each workstream; then run a clean final integrated gate on the actual final source tree.

The final gate must cover:

- Migrations on an isolated restored customer-like database and a fresh test schema where applicable; repeatable configuration and a credible recovery rehearsal.
- Full relevant automated test suite, negative financial/permission cases, concurrency/idempotency coverage, and an explicit disposition of skipped tests.
- PHP syntax checks as appropriate, Pint, Blade compilation (`view:cache` where supported), `git diff --check`, and the actual production asset build (`pnpm run build` if still the project's command).
- Current permission/menu synchronization checks, posting-account validation, stock/layer/WIP/GL reconciliation, payroll liability/payment matching, and source-document traceability.
- The operational browser cycles, all-report evidence matrix, generated PDF inspection, and export comparisons.

Record the base revision plus a working-tree fingerprint that includes uncommitted and relevant untracked source/migration/test files, actual commands, outcomes, timestamps, database identity, and artifacts. Do not print secrets or real employee details into shared logs. A HEAD hash alone is not enough when changes are uncommitted.

Any subsequent code/configuration/schema/template/dependency/test change invalidates affected results. Rerun those checks, and rerun the full suite before making a new whole-tree full-suite claim. Do not attribute the brief's 2,318-test result to today's changed tree.

Do not skip, delete, weaken, or change expected results merely to make failing tests green. Fix the behavior or establish and document the legitimate approved expectation. Explain each skipped test and its release relevance.

## 10. Execution discipline and stopping behavior

Continue through the in-scope implementation workstreams in the current execution. Do not pause after each small repair for “shall I continue?” Do not end with an old list of engineering gaps that you could still implement with available tools. Produce functional closures, not increasingly detailed descriptions of missing functionality.

Do not claim you can work beyond a real session/tool limit. If an actual interruption prevents completion, persist the exact completed IDs, current diff, verified commands, pending IDs, observed failure, and the next executable action. Report the interruption honestly; do not label an interrupted implementation technically ready. On resume, read the checkpoint and continue without repeating completed discovery or retesting unchanged scope unnecessarily.

A permission restriction, unavailable external document, missing accountant approval, or unavailable live environment is not evidence that a local algorithm cannot be implemented and verified with fixtures. Work around only the verification/input limitation, never bypass the financial/security control. Complete everything independent of the missing external fact.

## 11. Final handoff: evidence and separate decisions

Produce these separate decisions, each backed by the requirement/evidence ledger:

- `ENGINEERING_READY`: YES only when all mandatory in-scope engineering requirements are implemented and the final integrated gate passes. A missing engine, report verification, correction path, or required migration means NO. A disabled placeholder is not YES.
- `MIGRATION_REHEARSAL`: PASS/FAIL with the exact tested database state, steps, repeatability, reconciliation, and recovery evidence.
- `CUSTOMER_DATA_APPROVAL`: APPROVED/PENDING/REJECTED, based only on real documented approval.
- `CUSTOMER_UAT`: ACCEPTED/PENDING/FAILED, based only on actual customer acceptance, not an agent signing for the customer.
- `LIVE_RELEASE_DECISION`: GO/NO-GO. Never call the whole system “prod ready” when a mandatory release gate is unapproved or failed. Do not deploy as part of this task.

The final response must include the closed matrix IDs, the exact remaining IDs if any, changed-file summary, final-tree test/build results, real end-to-end/report evidence locations, and narrowly defined external approvals. Do not bury unfinished engineering under “data only.” Do not count a green suite as coverage of unimplemented workflows.

Start now with the bounded preflight, then implement the unresolved requirements. The expected improvement over the prior six responses is completed, demonstrated functionality across the remaining workstreams—not a more confident wording of the release decision.
