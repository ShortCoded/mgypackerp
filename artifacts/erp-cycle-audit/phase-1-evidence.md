# MgyPack integrated ERP audit — phase 1 evidence

Date: 2026-09-17  
Scope completed in this phase: exact purchase-invoice arithmetic, service-item procurement eligibility, canonical trial balance, and audited financial-period close/reopen.

This is an evidence checkpoint, not a claim that the full acceptance request is complete.

## Proven architecture and gaps

| Area | Evidence from current code | Status after this phase |
|---|---|---|
| Accounting source | Official ledger reads posted `journal_entries` and `journal_entry_lines`; draft and soft-deleted entries are excluded. | Reused as the only source for the trial balance and closing checks. |
| Historical dates | Ledger uses `entry_date`, inclusive end dates, and a pre-range opening balance. | Trial balance now carries posted balances across earlier financial periods by accounting date. |
| Financial-period status | `is_closed` was directly editable in the ordinary create/edit form. No closing entry or preflight existed. | Direct status changes are rejected. Dedicated close/reopen permissions and workflows now own the transition. |
| Trial balance | No route, service, screen, PDF, or spreadsheet export existed. | Implemented from posted journal lines with opening, movement, ending, hierarchy, inactive historical accounts, zero-account option, branch/cost-center filters, drill-down, activity log, PDF, CSV, and Excel. |
| Financial statements | No real income statement, statement of financial position, changes in equity, or cash-flow implementation was found. | Still open; must not be represented as complete. |
| Costing UI | Multiple costing routes still use the generic ERP shell, whose data endpoint returns an empty dataset. | Still open and critical. |
| Period close | Ordinary status edit was the only mechanism. | Implemented journal-backed close and reversal-backed reopen; operational-document close preflights beyond draft journals remain open. |
| Cash-flow policy | No durable operating/investing/financing classification was found on accounts or posting events. | Requires an approved mapping before an official cash-flow statement can be implemented safely. |

## Implemented corrections

### Exact purchase-invoice decimals

- Replaced float arithmetic in `PurchaseInvoiceCalculationService` with BCMath decimal strings.
- Quantities remain at 8 decimals; monetary values and persisted totals remain at 4 decimals.
- Tax, line discounts, header discounts, freight tax, totals, and allocation remainders share one calculation path.
- The last line receives the declared header-discount rounding remainder.
- The accepted maximum-quantity case proves `99999999999999.99999999 × 0.0001`, less `0.0001`, produces `9999999999.9999` without float loss.

### Purchasable services

- Added the existing `service` classification to purchasable items without making it stockable.
- Existing service controls remain in force: cost center is required where applicable and goods receipt does not create inventory for a service.

### Canonical trial balance

- Source: posted, non-deleted journal lines only.
- Base-currency amount: journal-line amount multiplied by the journal exchange rate using four-decimal BCMath output.
- Opening: all posted lines before `from_date`, including earlier financial periods.
- Movement: posted lines from `from_date` through `to_date`, inclusive.
- Ending signed balance: opening + debit movement − credit movement.
- Group rows aggregate descendants for presentation; grand totals sum direct account balances once, avoiding hierarchy double-counting.
- Inactive and soft-deleted historical accounts remain visible when they carry a balance or movement.
- Accounts with no balance or movement are optional.
- Leaf accounts drill into the canonical account ledger with the same dates and dimensions.

### Financial-period close and reopen

- A period can no longer be created closed or switched between open/closed through ordinary CRUD.
- Close runs in a retryable database transaction and locks the period.
- Close is blocked while draft journals exist and verifies base-currency debit equals credit.
- Income-statement account balances are zeroed by one system-generated posted closing entry.
- The balancing result is transferred to the uniquely configured, directly postable `retained_earnings` account.
- Repeating close on a closed period is idempotent.
- Reopen first makes the period postable inside the same transaction, then creates a posted reversal linked to the closing entry.
- Reclosing after a reopen creates the next auditable closing cycle; it does not reuse or duplicate the first transfer.
- Cost-accounting reports exclude closing and closing-reversal entries so they are not treated as new operating cost.
- Legacy periods marked closed without an auditable entry cannot be reopened when income-statement balances remain; they require review.

No production period or user data was closed or reopened during this work; all mutations were in isolated tests.

## Automated evidence actually run

| Command/scope | Result |
|---|---|
| Accounting journal, ledger, trial-balance suite | 14 passed, 217 assertions before close tests were added. |
| Trial balance focused test including CSV and PDF | 1 passed, 22 assertions. |
| Period close/reopen plus permission discovery | 2 passed, 67 assertions. |
| Financial-period CRUD/context and cost-accounting regression set | 37 passed, 328 assertions. |
| Combined precision, accounting, procurement, costing, production/inventory, and fixed-asset purchase set | 71 passed, 2,026 assertions. |
| Procurement cycle | 20 passed, 775 assertions. |
| Manufacturing/inventory cycle | 23 passed, 731 assertions. |
| Cost dimensions and hierarchy | 8 passed, 89 assertions. |
| Browser-independent JavaScript tests | 41 passed, 3 skipped, 0 failed (44 total). |
| Full PHP suite with 512 MB | 1,787 passed, 126 failed, 2 skipped; 33,104 assertions. Failures pre-existed this focused work and include current unrelated Auth/Core/PWA/UI changes. |
| Default 128 MB full PHP suite | Stopped by cumulative memory exhaustion in spreadsheet export; targeted accounting export tests pass at the default limit. |
| `git diff --check` | Passed for the integrated working tree at the checkpoint. |
| Laravel Pint, scoped PHP files | Passed; formatting fixes were applied. |

The current `NavigationAuditTest` is not green: a full run produced 14 failures involving route-count expectations, route binding, unexpected Human Resources visibility, and an existing fixed-asset breadcrumb mismatch. The file was restored unchanged after diagnosis; its expected counts were not rewritten to conceal unrelated navigation work.

## OpenCode delegation record

- OpenCode CLI 1.18.27 was verified.
- The configured Laravel Boost MCP connected successfully.
- A safe no-tool prompt worked with the free `opencode/big-pickle` model.
- A protected shell probe was rejected, confirming approvals were not disabled.
- The bounded purchase-decimal assignment is recorded in `opencode-purchase-invoice-decimal-task.md`.
- The implementation run produced no output for roughly eight minutes and was terminated with exit 130. OpenCode wrote no files. Codex implemented and verified the bounded change directly, as required by the fallback rule.

## Not yet accepted

The following requested outcomes remain unproved and must not be described as ready:

1. Official income statement, statement of financial position, changes in equity, and direct/indirect cash-flow statements.
2. The supplied 10,000-unit factory reference scenario and all stated reference totals.
3. Inventory-method scenarios for moving average, periodic weighted average, and FIFO as separate controlled runs.
4. Full overhead allocation by approved machine/labor hours, material-value fallback, zero/partial bases, and normal-capacity under-absorption.
5. WIP and finished-goods reconciliation through close, including late cost after sale.
6. Payroll-to-ledger and payroll-to-cash reconciliation.
7. A complete cost-center drill path showing original cost, applied cost, and unapplied remainder.
8. Operational-document preflight across every module before period close.
9. Large-data query-count, memory, concurrency, and lock-contention evidence.
10. Browser/device visual QA for the new report and close/reopen controls.
11. A clean full regression suite; the dirty working tree contains unrelated active changes and known failures.

## Deployment and rollback notes for this phase

- No dependency or database migration was added.
- Run the existing permission seeder/synchronization so `reports.trial_balance.*` and `financial_periods.close/reopen` reach intended roles.
- Clear application/menu caches after deployment.
- Verify a non-production company: trial balance screen, all three exports, close with a draft journal (must block), close after posting/cancelling drafts, reopen, and reclose.
- Before code rollback, reopen any period closed by the new workflow through the application so its closing entry is reversed. Never delete closing journals to roll back code.
- Preserve the unrelated dirty-worktree changes listed by `git status`; they are outside this phase.
