FAST EXECUTION — MGYPACK PRICE PRECISION ADDENDUM

Continue the current implementation and preserve the existing working
tree. Integrate this requirement into the active task; do not restart
the general audit or discard completed work.

Read AGENTS.md and PROJECT_BUSNIESS_CONTEXT.md if not already read.
Use the existing project helpers, approved delegation, and available
MCP tools. Inspect relevant paths only.

OBJECTIVE

All applicable unit-price and unit-cost fields must accept, persist,
calculate with, transfer, and display values such as:

22
22.5
22.54545
22.54545123
0.00000001

Support 0–8 decimal places for unit prices and unit costs.
Do not reduce any existing, intentionally higher precision.

This is an end-to-end numeric integrity change, not merely an HTML
input or validation change.

SCOPE

Cover every existing price/cost-bearing path, including:
- Product prices and price lists.
- Sales quotations, requests, orders, invoices, and returns.
- Purchase quotations, requests, orders, receipts, supplier invoices,
  and returns.
- Opening-stock pricing and receipt-pricing screens.
- Inventory movement prices, cost layers, allocations, transfers,
  production costs, and valuation.
- Related imports, AJAX/API payloads, document conversions, cloning,
  snapshots, reports, PDF, Excel, and CSV.

Do not introduce editable prices into documents that intentionally
derive costs from their source. Preserve permissions and workflows.

NUMERIC POLICY

1. Separate unit prices/costs, quantities, conversion factors, percentage
   rates, exchange rates, monetary totals, and settlement amounts.
   Do not apply one decimal rule to every numeric field.

2. Unit prices/costs must accept whole numbers and up to 8 fractional
   digits. Use decimal:0,8 or the equivalent centralized project rule
   after safe input normalization, unless a field intentionally
   supports greater precision.

3. Preserve existing zero, negative-value, required/nullable, and
   maximum-value business rules. Null must not silently become zero.

4. Normalize supported Arabic/English digits and decimal separators
   consistently. Reject ambiguous or malformed values instead of
   guessing. Reject genuinely excessive precision clearly; never
   silently truncate or round an entered unit price.

5. Preserve existing quantity precision and unit divisibility rules.
   Do not reduce exchange-rate or conversion precision.

6. Preserve the approved currency, tax, accounting, and settlement
   precision policies. Do not globally force journal entries,
   inventory values, or reconciliation calculations to 2 decimals.

IMPLEMENTATION

- Build a bounded inventory of affected fields and paths. Record the
  current database precision/scale, validation, casts, input settings,
  calculation helpers, and output formatting.

- Fix all relevant layers: inputs and masks, server validation,
  model casts/mutators, database columns, services, serializers,
  document copying, imports, and reports.

- Use exact decimal storage and the project's decimal arithmetic
  abstraction. Carry authoritative decimal values as strings or
  decimal objects, not binary floating-point values.

- Ensure browser previews use compatible decimal calculations or
  server-calculated values. The server must independently validate
  inputs and calculate authoritative totals.

- Widen database columns only where needed. Preserve the existing
  integer-digit capacity when increasing fractional scale.
  Inspect dependent columns and snapshots as well.

- Make migrations safe for populated PostgreSQL databases. Do not
  use destructive rebuilds. Do not provide a narrowing rollback
  that silently destroys newly supported fractional digits.

- Do not globally replace every decimal:2, round(..., 2), or
  toFixed(2). Determine whether each occurrence represents a unit
  price, a display formatter, or a legitimate rounding boundary.

CALCULATIONS AND ROUNDING

- Multiply using the full stored unit price and quantity.
- Apply discounts, taxes, conversion, and allocation using sufficient
  intermediate precision.
- Round only at explicitly defined business boundaries. Preserve
  approved line-versus-document rounding rules.
- Make frontend totals, saved totals, posting, and exports agree.
- Allocate unavoidable rounding remainders deterministically and
  transparently. Do not hide mismatches with loose tolerances.
- Preserve the original booked values in reversals and returns.
  Partial allocations and returns must reconcile cumulatively to
  the original document, not a newly rounded price.

HISTORICAL SAFETY

Do not recalculate, reprice, or repost existing approved/posted
documents merely because precision support has changed.

Do not invent missing historical digits. Preserve existing amounts,
audit history, posting snapshots, permissions, and document locks.

DISPLAY AND EXPORT

- Display meaningful unit-price digits without unnecessary trailing
  zeroes: 22.54545000 -> 22.54545.
- Do not hide significant unit-price digits in forms, document
  details, PDFs, or exports.
- Format monetary totals separately using the currency policy.
- Opening and saving an unchanged editable record must not alter
  its price.
- Preserve price-view permissions everywhere, including exports.
- Verify exported decimal values survive supported round trips;
  prevent spreadsheet numeric coercion from silently losing digits.

REQUIRED ACCEPTANCE TESTS

A. Save and reload 22.54545 and 22.54545123 without changing their
   numeric values in every applicable price-bearing document type.

B. With no discount or tax:
   quantity 10000 × unit price 22.54545 = 225454.50000.
   For a two-decimal currency, the displayed total is 225454.50,
   never 225500.00.

C. Verify that 0.00000001 × 1000000 = 0.01, not zero.

D. Verify whole-number prices, fractional quantities where allowed,
   supported localized input, invalid input, precision boundaries,
   and large values near the supported range.

E. Verify a price survives quotation/order/receipt/invoice conversion,
   copying, save/reload, and edit-without-price-change.

F. Verify discounts, tax-inclusive/exclusive paths where supported,
   unit conversion, exchange rates, and multi-line rounding.

G. Verify partial receipts, partial invoices, returns, allocations,
   and reversals without unexplained quantity/value/ledger differences.

H. Verify screen, PDF, Excel, and CSV against the same saved values.

I. Verify existing historical totals and postings remain unchanged.

DELIVERY

Implement and verify this alongside the current work.

Report:
- The applied precision policy.
- Affected modules and database columns.
- Actual screen/document evidence using 22.54545.
- Focused test results and final current-tree regression results.
- Any remaining uncovered path, identified precisely.

Do not claim system-wide completion from one working input or one
passing unit test.

Do not commit, push, deploy, or modify the live customer database.