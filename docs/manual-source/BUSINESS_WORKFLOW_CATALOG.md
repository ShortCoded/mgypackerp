# Business Workflow Catalog

> MgyPack ERP — Complete workflow and lifecycle reference.
> Source: pre-loaded Codex evidence + live `config/menu/*.php`.
> Date: 2026-09-20

---

## §23 Notifications

| Module | Event | Channel | Recipient | Verified |
|---|---|---|---|---|
| Purchases | Purchase Requisition submitted | In-app | Approver per branch/company scope | Menu evidence only |
| Purchases | Purchase Requisition approved / rejected | In-app | Requester | Menu evidence only |
| Purchases | Purchase Order approved | In-app | Requester / Purchasing team | Menu evidence only |
| Sales | Quotation marked sent | In-app | Sales team / Customer | Menu evidence only |
| Sales | Quotation accepted / rejected | In-app | Sales team | Menu evidence only |
| Quality | Inspection started | In-app | Quality supervisor | Menu evidence only |
| Quality | Inspection submitted | In-app | Quality reviewer | Menu evidence only |
| Quality | Inspection released / reinspect | In-app | Production supervisor | Menu evidence only |
| Production | Work order released | In-app | Production manager | Menu evidence only |
| Production | Run completed | In-app | Production supervisor | Menu evidence only |
| Production | Material request approved | In-app | Warehouse / production | Menu evidence only |
| Maintenance | Breakdown report created | In-app | Maintenance supervisor | Menu evidence only |
| Maintenance | Work order started / completed | In-app | Requester | Menu evidence only |
| HR | Payroll reviewed / approved | In-app | Finance / HR manager | Menu evidence only |
| Cash/Treasury | Voucher approved / cancelled | In-app | Finance manager | Menu evidence only |
| Cheques | State transition (deposited, collected, bounced) | In-app | Finance team | Menu evidence only |
| Fixed Assets | Asset activated / disposed | In-app | Finance manager | Menu evidence only |

> **Scope:** Notifications derived from menu action permissions. Push/email delivery not verified in this pass.

---

## §24 Tasks & Chat

| Feature | Verified | Notes |
|---|---|---|
| System tasks (todo list) | NOT VERIFIED | No menu entry found |
| Internal chat / messaging | NOT VERIFIED | No menu entry found |

---

## §27 Lifecycle Tables

### §27.1 Sales Lifecycle

| # | Stage | Document / Entity | Route | Permission | Status Transition | Next Stage |
|---|---|---|---|---|---|---|
| 1 | Master setup | Customers | `admin.sales.customers.index` | `customers.view` | — | Customer Terms |
| 2 | Master setup | Customer Terms | `admin.sales.customer-terms.index` | `customers.view` | — | Price Lists |
| 3 | Master setup | Price Lists | `admin.sales.price-lists.index` | `price_lists.view` | Draft→Reviewed→Approved | Ready for quotation |
| 4 | Sales Request | Sales Requests | `admin.sales.customer-requests.index` | `sales_requests.view` | Open | Quotation |
| 5 | Quotation | Quotations | `admin.sales.quotations.index` | `quotations.view` | Draft→Sent→Accepted/Rejected/Cancelled | Sales Order |
| 6 | Sales Order | Sales Orders | `admin.sales.sales-orders.index` | `sales_orders.view` | Draft→Approved | Delivery Note / Production Demand |
| 7 | Delivery | Issue Orders (Delivery Notes) | `admin.sales.delivery-notes.index` | `sales_deliveries.view` | Draft→Posted | Sales Invoice |
| 8 | Invoice | Sales Invoices | `admin.sales.sales-invoices.index` | `customer_invoices.view` | Draft→Posted→Closed | Customer Receipt |
| 9 | Payment | Customer Collections | `admin.sales.customer-receipts.index` | `customer_receipts.view` | Draft→Approved | — |
| 10 | Return | Sales Returns | `admin.sales.sales-returns.index` | `sales_returns.view` | Draft→Posted | Credit Note / Inventory Reversal |

### §27.2 Purchases Lifecycle

| # | Stage | Document / Entity | Route | Permission | Status Transition | Next Stage |
|---|---|---|---|---|---|---|
| 1 | Master setup | Suppliers | `admin.purchases.suppliers.index` | `suppliers.view` | Active | Ready |
| 2 | Demand | Purchase Requisitions | `admin.purchases.purchase-requisitions.index` | `purchases.purchase_requisitions.view` | Draft→Submitted→Approved/Rejected/Cancelled/Close | RFQ |
| 3 | Sourcing | Supplier Quotations (Entry) | `admin.purchases.supplier-quotation-entry.index` | `purchases.supplier_quotation_entry.view` | Draft | Supplier Selection |
| 4 | Purchase Order | Purchase Orders | `admin.purchases.purchase-orders.index` | `purchase_orders.view` | Draft→Submitted→Approved→Sent/Close/Cancel | Supply Order |
| 5 | Supply | Supply Orders | `admin.purchases.supply-orders.index` | `purchases.supply_orders.view` | Draft→Issued | Goods Receipt Note |
| 6 | Goods Receipt | Goods Receipt Notes | `admin.purchases.goods-receipt-notes.index` | `purchases.goods_receipt_notes.view` | Draft→Posted→Reversed | Purchase Invoice |
| 7 | Inspection | Purchase Inspections | `admin.purchases.goods-receipt-inspection.index` | `purchases.goods_receipt_inspection.view` | Draft | Accept / Reject |
| 8 | Invoice | Purchase Invoices | `admin.purchases.purchase-invoices.index` | `purchase_invoices.view` | Draft→Approved→Closed/Cancelled/Reversed | Supplier Payment |
| 9 | Payment | Supplier Payments | `admin.purchases.supplier-payments.index` | `supplier_payments.view` | Draft→Approved→Cancelled | — |
| 10 | Return | Purchase Returns | `admin.purchases.purchase-returns.index` | `purchases.purchase_returns.view` | Draft→Posted→Reversed | Inventory Reversal |

**Requisition Origins:** Factory (production demand), Warehouse (low stock), Admin (manual).

### §27.3 Inventory Lifecycle

| # | Stage | Document / Entity | Route | Permission | Status Transition | Notes |
|---|---|---|---|---|---|---|
| 1 | Opening | Opening Stocks | `admin.inventory.opening-stocks.index` | `inventory.opening_stocks.view` | Draft→Approved | Initial qty only |
| 2 | Pricing | Opening Stock Pricing | `admin.inventory.opening-stock-pricings.index` | `inventory.opening_stock_pricings.view` | Draft | Cost valuation |
| 3 | Unpriced Receipt | Unpriced Inventory Receipts | `admin.inventory.unpriced-inventory-receipts.index` | `inventory.unpriced_inventory_receipts.view` | Draft→Approved→Closed/Cancelled | Qty without price |
| 4 | Movements | Inventory Documents | `admin.inventory.documents.index` | `inventory.documents.view` | Draft→Posted→Reversed | Receive/Issue/Return/Transfer/Adjust/Damage+Scrap |
| 5 | Stock Count | Physical Stock Counts | `admin.inventory.stock-counts.index` | `inventory.stock_counts.view` | Draft→Approved | Variance adjustment |
| 6 | Reports | Stock Balance Inquiry | `admin.inventory.stock-balances.index` | `inventory.reports.operational` | — | Operational/Financial views |
| 7 | Reports | Inventory Operational Reports | `admin.inventory.reports.index` | `inventory.reports.operational` | — | Stock card, movements, reservations |
| 8 | Reports | Inventory Valuation Comparison | `admin.inventory.reports.valuation` | `inventory.reports.financial` | — | MA / Periodic / FIFO comparison |
| 9 | Reports | Inventory Sales Valuation | `admin.inventory.sales-valuation` | `inventory.reports.operational` | — | Price list vs cost |

### §27.4 Production Lifecycle

| # | Stage | Document / Entity | Route | Permission | Status Transition | Notes |
|---|---|---|---|---|---|---|
| 1 | Routing | Production Stages | `admin.production.stages.index` | `production.stages.view` | — | Stage definitions |
| 2 | Routing | Product Production Stages | `admin.production.product-stages.index` | `production.product_stages.view` | — | BOM → stage assignment |
| 3 | Planning | Production Work Orders | `admin.production.work-orders.index` | `production.orders.view` | Draft→Planned→Released→Short-Close | Sales-driven or standalone |
| 4 | Execution | Production Runs | `admin.production.runs.index` | `production.runs.view` | Plan→Setup→Progress→QC→Receive→Complete/Cancel | Per machine/shift/BOM snapshot |
| 5 | Materials | Material Requests | `admin.production.material-requests.index` | `production.material_requests.view` | Draft→Approved→Issued | Warehouse → factory issue |
| 6 | Expenses | Expense Requests | `admin.production.expenses.index` | `production.expenses.view` | Draft→Approved→Paid→Reversed | Operational expenses |
| 7 | Reports | Production Overview | `admin.production.reports.index` | `production.reports.operational` | — | KPIs, summaries |
| 8 | Reports | Production Order Status | `admin.production.reports.orders` | `production.reports.operational` | — | Order progress |
| 9 | Reports | Production Run Performance | `admin.production.reports.runs` | `production.reports.operational` | — | Run efficiency |
| 10 | Reports | Production Material Reconciliation | `admin.production.reports.materials` | `production.reports.operational` | — | Consumption variance |

**Full Trace:** Sales Demand → Request → Order → Runs → Machine/Shift → BOM Snapshot → Reservation/Issue → Consumption/Waste → Output → Quality → FG Receipt → Cost → Closure. Remaining qty, ceilings, WIP, close/short-close tracked.

### §27.5 Quality Lifecycle

| # | Stage | Document / Entity | Route | Permission | Status Transition | Notes |
|---|---|---|---|---|---|---|
| 1 | Inspection | Production Quality Management | `admin.production.quality.index` | `production.quality.view` | Created→Received→Started→Reported→Submitted→Released (Normal)→Closed / Reinspect | Per-run or per-stage |
| 2 | Active View | Open Quality Inspections | `admin.production.quality.active` | `production.quality.view` | — | Dashboard of open items |
| 3 | Reports | Quality Reports | `admin.production.quality.reports.index` | `production.quality.view` | — | Pass/Fail/Scrap/Hold analysis |
| 4 | Reports | Production Quality Report | `admin.production.reports.quality` | `production.reports.operational` | — | Cross-run quality summary |

**Outcomes:** Pass → Release Normal. Fail → Reinspect or Scrap or Hold. Reinspect → Released or Closed.

### §27.6 Maintenance Lifecycle

| # | Stage | Document / Entity | Route | Permission | Status Transition | Notes |
|---|---|---|---|---|---|---|
| 1 | Plan | Preventive Maintenance Plans | `admin.maintenance.plans.index` | `maintenance.plans.view` | Created→Approved→Generated→Executed | Meter-based triggers |
| 2 | Breakdown | Breakdown Reports | `admin.maintenance.requests.index` | `maintenance.requests.view` | Created | Incident logging |
| 3 | Work Order | Maintenance Work Orders | `admin.maintenance.orders.index` | `maintenance.orders.view` | Created→Approved→Started→Paused→Completed→Closed / External | Internal or external |
| 4 | Materials | Maintenance Materials | `admin.maintenance.material-requests.index` | `maintenance.material_requests.view` | Draft→Approved→Issued→Returned | Spares, oil, parts |
| 5 | Expenses | Maintenance Expenses | `admin.maintenance.expenses.index` | `maintenance.expenses.view` | Draft→Approved→Paid→Reversed | Cost tracking |
| 6 | Reports | Maintenance Reports | `admin.maintenance.reports.index` | `maintenance.reports.view` | — | Downtime, cost, material usage |

**Linkage:** Work orders linked to asset/machine. Cost lineage: materials + expenses → work order → asset.

### §27.7 Cash / Treasury Lifecycle

| # | Stage | Document / Entity | Route | Permission | Status Transition | Notes |
|---|---|---|---|---|---|---|
| 1 | Setup | Cashboxes | `admin.finance.cashboxes.index` | `cashboxes.view` | Active | Branch-scoped |
| 2 | Receipt | Cash Receipt Vouchers | `admin.finance.cash-receipt-vouchers.index` | `cash_receipt_vouchers.view` | Draft→Approved→Cancelled | Print, doc number |
| 3 | Payment | Cash Payment Vouchers | `admin.finance.cash-payment-vouchers.index` | `cash_payment_vouchers.view` | Draft→Approved→Cancelled | Print, doc number |
| 4 | Transfer | Fund Transfers | `admin.finance.fund-transfers.index` | `fund_transfers.view` | Draft→Approved→Cancelled | Cashbox-to-cashbox / bank |
| 5 | Closing | Cashbox Statement / Count / Closing | — | — | — | Statement view, physical count, period closing |

### §27.8 Bank Lifecycle

| # | Stage | Document / Entity | Route | Permission | Status Transition | Notes |
|---|---|---|---|---|---|---|
| 1 | Setup | Bank Accounts | `admin.finance.bank-accounts.index` | `bank_accounts.view` | Active | Multi-currency |
| 2 | Transactions | Bank payments / receipts | Via supplier-payments / customer-receipts | — | — | Linked from pay/receipt screens |
| 3 | Transfers | Fund Transfers (bank legs) | `admin.finance.fund-transfers.index` | `fund_transfers.view` | Draft→Approved | Inter-account |
| 4 | Statements | Bank reconciliation | Via accounting reports | — | — | Reconciliation center |

### §27.9 Cheques Lifecycle

| # | Stage | Document / Entity | Route | Permission | Status Transition | Notes |
|---|---|---|---|---|---|---|
| 1 | Entry | Cheques | `admin.finance.cheques.index` | `cheques.view` | Created | Receipt or Payment cheque |
| 2 | Delivery | Mark Issued / Delivered | `admin.finance.cheques.index` | `cheques.mark_issued` / `cheques.mark_delivered` | Issued → Delivered | Custody transfer |
| 3 | Collection | Mark Collected | `admin.finance.cheques.index` | `cheques.mark_collected` | Delivered → Collected | — |
| 4 | Deposit | Mark Deposited | `admin.finance.cheques.index` | `cheques.mark_deposited` | Collected → Deposited | Into bank account |
| 5 | Clearing | Mark Cleared | `admin.finance.cheques.index` | `cheques.mark_cleared` | Deposited → Cleared | Bank confirmed |
| 6 | Rejection | Mark Returned / Bounce | `admin.finance.cheques.index` | `cheques.mark_returned` | Any → Returned | Bounce processing |
| 7 | Cancel | Cancel | `admin.finance.cheques.index` | `cheques.cancel` | Any → Cancelled | Reversal journal |
| 8 | Print | Cheque print | `admin.finance.cheques.index` | `cheques.print` | — | Template-based |

**State Machine:** Created → Issued → Delivered → Collected → Deposited → Cleared. Any state → Returned/Cancelled.

**Marked as:** REPRESENT / BOUNCE / REVERSE-CLEARING / ENDORSEMENT — **NOT IMPLEMENTED** (menu actions not found; only deposit, collected, cleared, returned verified).

### §27.10 Fixed Assets Lifecycle

| # | Stage | Document / Entity | Route | Permission | Status Transition | Notes |
|---|---|---|---|---|---|---|
| 1 | Register | Fixed Assets Register | `admin.fixed-assets.assets.index` | `fixed_assets.view` | Draft → Recognized | Create, import, accounting configure |
| 2 | Activate | Asset activation | `admin.fixed-assets.assets.index` | `fixed_assets.activate` | Recognized → Active | Begins depreciation |
| 3 | Addition | Improvements | `admin.fixed-assets.assets.index` | `fixed_assets.improvement.post` | — | Capitalized additions |
| 4 | Custody | Custody assignment | `admin.fixed-assets.assets.index` | `fixed_assets.custody.post` | — | Employee / department link |
| 5 | Transfer | Asset transfer | `admin.fixed-assets.assets.index` | `fixed_assets.transfer` | — | Branch / location move |
| 6 | Depreciate | Depreciation run | `admin.fixed-assets.depreciation.index` | `fixed_assets.depreciation.preview` | Preview → Posted → Reversed | Periodic batch |
| 7 | Dispose | Disposal | `admin.fixed-assets.assets.index` | `fixed_assets.dispose` | Active → Disposed | Gain/loss journal |
| 8 | Reverse | Disposal / Recognition / Improvement reverse | `admin.fixed-assets.assets.index` | `fixed_assets.disposal.reverse` / `recognition.reverse` / `improvement.reverse` | — | Reversal entries |
| 9 | Movements | Asset Movements | `admin.fixed-assets.movements.index` | `fixed_assets.view` | — | Movement history |
| 10 | Reports | Fixed Asset Reports | `admin.fixed-assets.reports.index` | `fixed_assets.reports` | — | Register, depreciation, disposal |

**Account Tree Arch:** Preserved on create/transfer/dispose per account-tree rules.

### §27.11 HR / Payroll Lifecycle

| # | Stage | Document / Entity | Route | Permission | Status Transition | Notes |
|---|---|---|---|---|---|---|
| 1 | Master | Employees | `admin.hr.employees.index` | `hr.employees.view` | Active | With documents, employment data |
| 2 | Employment Data | 18 screens (Departments, Sections, Jobs, Employment Types, Grades, Hiring Statuses, Document Types, Allowances, Insurance Offices, Social Insurance Policies, Employment Tax Policies, Identifications, Nationalities, Religions, Qualifications, Universities, Faculties, Specializations, Military Services) | `admin.hr.{entity}.index` | `hr.{entity}.view` | — | Subgroup: hr_employment_data |
| 3 | Geography | Countries, Governorates, Cities, Areas | `admin.hr.{entity}.index` | `hr.{entity}.view` | — | **Hidden** from default menu |
| 4 | Attendance | Work Shifts | `admin.hr.shifts.index` | `hr.shifts.view` | — | Shift definitions |
| 5 | Attendance | Biometric Devices | `admin.hr.biometric-devices.index` | `hr.biometric_devices.view` | — | Device registration |
| 6 | Attendance | Attendance Settings | `admin.hr.attendance-settings.index` | `hr.attendance_settings.view` | — | Rules configuration |
| 7 | Attendance | Shift Assignments | `admin.hr.shift-assignments.index` | `hr.shift_assignments.view` | — | Employee ↔ shift link |
| 8 | Attendance | Employee Attendance | `admin.hr.employee-attendance.index` | `hr.employee_attendance.view` | — | Clock-in/out records, corrections |
| 9 | Requests | HR Requests | `admin.hr.hr-requests.index` | `hr.hr_requests.view` | — | Leave, overtime, etc. |
| 10 | Payroll | Payroll Preparation | `admin.hr.payroll-preparation.index` | `hr.payroll_preparation.view` | Draft → Calculated → Reviewed → Approved → Paid | Full payroll cycle |
| 11 | Self-Service | Employee Self Service | `employee.hr.self-service.index` | — | — | Employee portal |
| 12 | Reports | Payroll Report | `admin.hr.reports.payroll` | `hr.payroll_reports.view` | — | Payslips, totals |
| 13 | Reports | Payroll Payment Report | `admin.hr.reports.payments` | `hr.payroll_payment_reports.view` | — | Payment reconciliation |

### §27.12 Accounting / Costing Lifecycle

| # | Stage | Document / Entity | Route | Permission | Status Transition | Notes |
|---|---|---|---|---|---|---|
| 1 | Master | Chart of Accounts | `admin.accounting.accounts.index` | `accounts.view` | Active | Hierarchical, account code control |
| 2 | Setup | Cost Centers | `admin.accounting.cost-centers.index` | `cost_centers.view` | Active | Hierarchical, print, export |
| 3 | Journal | Journal Entries | `admin.accounting.journal-entries.index` | `journal_entries.view` | Draft → Posted | Create, edit, delete, post |
| 4 | Opening | Opening Balances | `admin.finance.opening-balances.index` | `opening_balances.view` | Draft → Approved → Cancelled | Period-based |
| 5 | Period | Period Closing & Carry Forward | `admin.financial-periods.closing` | `financial_periods.view` | Open → Closed → Reopened | Carry forward balances |
| 6 | Expense | Expense Allocation | Via accounting module | — | — | Overhead allocation to cost centers |
| 7 | COGS | Cost of Goods Sold | Via sales/inventory | — | — | Cost ≠ price distinction |
| 8 | Reports | General Journal | `admin.accounting.reports.general-journal` | `reports.account_ledger.view` | — | Posted journal listing |
| 9 | Reports | Account Ledger | `admin.accounting.reports.account-ledger` | `reports.account_ledger.view` | — | General ledger per account |
| 10 | Reports | Trial Balance | `admin.accounting.reports.trial-balance` | `reports.trial_balance.view` | — | Opening / ending balances |
| 11 | Reports | Reconciliation Center | `admin.accounting.reports.reconciliation-center` | `reports.account_ledger.view` | — | Subledger vs GL |
| 12 | Reports | Financial Statements | `admin.accounting.reports.financial-statements` | `reports.financial_statements.view` | — | Income Statement, Balance Sheet, Equity Changes |
| 13 | Reports | Expense Analysis | `admin.accounting.reports.financial-analytics.expense-analysis.index` | `reports.financial_analytics.expense_analysis.view` | — | By cost center |
| 14 | Reports | Financial Ratios | `admin.accounting.reports.financial-analytics.financial-ratios.index` | `reports.financial_analytics.financial_ratios.view` | — | Liquidity, profitability, efficiency |
| 15 | Reports | Customer Statement | `admin.accounting.reports.customer-statement` | `reports.customer_statement.view` | — | Per-customer ledger |
| 16 | Reports | Supplier Statement | `admin.accounting.reports.supplier-statement` | `reports.supplier_statement.view` | — | Per-supplier ledger |

---

## §28 Validations

### §28.1 Cross-cutting Validations

| Rule | Scope | Notes |
|---|---|---|
| Closed-period block | All modules | No mutations allowed in closed financial periods |
| Reversal / cancel only | All posted documents | Never edit history; use reversal or cancellation |
| Company / branch scope | All operational documents | Scoped to company and branch |
| Financial period scope | Accounting entries | Period must be open |
| Document number uniqueness | All numbered documents | Auto-generated, configurable |
| Soft delete | All master and operational documents | Trash → Restore pattern |

### §28.2 Module-Specific Validations

| Module | Validation | Rule |
|---|---|---|
| Sales | Quotation revisions | Incremental revision tracking |
| Sales | Price list scope | Print-only ≠ operational pricing |
| Purchases | Requisition approval | Submit → Approve/Reject workflow |
| Purchases | Direct procurement override | Permission-gated bypass |
| Inventory | Quantity ≠ Valuation | Separate qty and cost tracking |
| Inventory | Stock count variance | Approve triggers adjustment journal |
| Production | BOM snapshot | Frozen at run creation; not updated later |
| Production | Material reservation | Issued before run start |
| Production | Ceiling tracking | Remaining qty and ceiling enforced |
| Quality | Inspection outcomes | Pass/Fail/Scrap/Hold/Reinspect states enforced |
| Maintenance | Internal / External | Work order type determines material/expense path |
| Fixed Assets | Account tree arch | Account hierarchy preserved across lifecycle |
| Cheques | State machine | Enforced transitions only |
| HR | Payroll cycle | Calculate → Review → Approve → Pay sequence |
| Accounting | Period close carry-forward | Balances moved to next period |

---

## §29 Audit Trail

| Module | Audit Mechanism | Scope |
|---|---|---|
| All modules | Created / updated / deleted timestamps | Eloquent timestamps on all models |
| All modules | Soft delete (trash + restore) | User-identifiable via trash views |
| Sales | Quotation revisions | Full revision history per quotation |
| Sales | Price list reviews + approvals | Reviewer / approver tracking |
| Purchases | Requisition submit / approve / reject | Workflow state transitions logged |
| Purchases | Purchase order approval chain | Submit → Approve → Reject |
| Production | Work order plan / release / short-close | State transition audit |
| Production | Run lifecycle (plan → complete) | Per-step state tracking |
| Quality | Inspection start / submit / release / reinspect | Full inspection trail |
| Maintenance | Plan approve / generate / execute | Plan lifecycle audit |
| Maintenance | Work order approve → start → complete → close | Full work order trail |
| Cash/Treasury | Voucher approve / cancel | Approval chain |
| Cheques | State transitions | All deposit/collection/clearing/return logged |
| Fixed Assets | Recognize / activate / improve / custody / transfer / dispose | Full lifecycle |
| Fixed Assets | Depreciation preview → post → reverse | Depreciation batch tracking |
| HR | Payroll calculate → review → approve → pay | Full payroll cycle |
| Accounting | Journal create → post → reverse | Posting and reversal audit |
| Accounting | Period close / reopen | Period lifecycle |

---

## §32 Business Cycles

### §32.1 Sales Cycle

**Entry Point:** Customer inquiry → Sales Request or direct Quotation.

**Flow:**
1. **Customer Setup:** Create customer record with terms, credit limits, and price list assignment.
2. **Sales Request (optional):** Log customer inquiry. Convert to Quotation when ready.
3. **Quotation:** Prepare with price list pricing, terms, items. Track revisions. Mark Sent → Accept/Reject/Cancel.
4. **Sales Order:** On acceptance, convert to Sales Order. Production demand generated if items are manufactured.
5. **Delivery Note (Issue Order):** Pick and dispatch from warehouse. Posts inventory issue.
6. **Sales Invoice:** Invoice posted after delivery. Credit notes available for corrections.
7. **Customer Receipt:** Record payment (cash/bank/cheque). Allocate to invoices.
8. **Sales Return:** Return goods. Posts inventory receipt and credit note.

**Reports:** 13+ sales reports (financial analysis, period, customer, product, invoices, receivables, aging, collections, returns, quotations, fulfillment, pricing, operational, cost of sales). Customer statement shared with Accounting.

**Invariants:**
- Invoice quantity ≤ delivery quantity ≤ order quantity.
- COGS ≠ selling price (cost calculated via inventory valuation, not price list).
- Price list print-only ≠ operational pricing.
- Closed-period block on all mutations.

### §32.2 Purchases Cycle

**Entry Point:** Demand signal (factory / warehouse low stock / admin manual).

**Flow:**
1. **Purchase Requisition:** Origin-tracked (factory, warehouse, admin). Submit → Approve/Reject workflow. Branch/company scoped.
2. **RFQ (implied):** Create supplier quotations from requisition lines. (RFQ generation mechanism via menu: `report_rfq_quotation_status`.)
3. **Supplier Quotation Entry:** Record supplier prices per item. View price comparison.
4. **Supplier Selection:** Select best supplier per item (price, lead time, quality).
5. **Purchase Order:** Create PO from selection. Submit → Approve → Send → Close/Cancel.
6. **Supply Order:** Supplier dispatch tracking. Issue → Goods receipt.
7. **Goods Receipt Note:** Receive goods into warehouse. Post → inventory qty update. Reverse available.
8. **Purchase Inspection:** Quality check on received goods. Accept / Reject.
9. **Purchase Invoice:** Match to GRN. Approve → post accounting. Close / Cancel / Reverse.
10. **Supplier Payment:** Record payment. Approve → post accounting.
11. **Purchase Return:** Return defective goods. Post → inventory reversal.

**Reports:** 30+ procurement reports (ledger, requirements, requests, RFQ status, PO status, delivery schedule, ordered vs received, invoices, returns, by supplier/item/category/warehouse/period, price history, aging, GRNI, production-linked).

**Invariants:**
- Requisition → PO traceability maintained.
- GRN qty = received qty; invoice qty ≤ received qty.
- Branch/admin scoping per track evidence.
- Closed-period block on all mutations.

### §32.3 Inventory Cycle

**Entry Point:** Opening stock setup or ongoing operations.

**Flow:**
1. **Opening Stock:** Initial qty entry → approve.
2. **Opening Stock Pricing:** Assign cost to opening qty.
3. **Unpriced Inventory Receipts:** Qty-only receipts (no price) → approve → close/cancel.
4. **Inventory Documents:** Central movement screen. Operations:
   - **Receive:** Inbound from purchase, production, or transfer.
   - **Issue:** Outbound to sales, production, or transfer.
   - **Return:** Return from any destination.
   - **Transfer:** Inter-warehouse movement.
   - **Adjust:** Manual qty adjustment (with reason).
   - **Damage / Scrap:** Write-off damaged goods.
   - **Reverse:** Reverse any posted document.
5. **Physical Stock Counts:** Create count → enter physical qty → approve → variance adjustment.
6. **Stock Balance Inquiry:** Real-time operational and financial views.
7. **Inventory Reports:** Stock card, movements, reservations.
8. **Valuation Comparison:** Moving Average / Periodic Average / FIFO.
9. **Sales Valuation:** Price list vs cost comparison.

**Invariants:**
- Inventory qty ≠ valuation (quantity tracked separately from cost).
- COGS calculated via inventory valuation method, not price list.
- Posted documents create accounting journals.
- Reversal available for all posted documents.
- Closed-period block on all mutations.

### §32.4 Production Cycle

**Entry Point:** Sales order production demand or standalone work order.

**Flow:**
1. **Production Stages:** Define routing stages (cutting, molding, assembly, etc.).
2. **Product Production Stages:** Assign stages + BOM to products.
3. **Work Order:** Create from sales demand or manually. Plan → Release → Execute.
4. **Production Run:** Per machine/shift scheduling.
   - **Plan:** Assign run parameters.
   - **Setup:** Machine preparation.
   - **Progress:** Track production progress.
   - **Material Reservation:** Reserve raw materials from inventory.
   - **Material Issue:** Issue materials to production.
   - **Account Materials:** Record consumption and waste.
   - **Labor:** Record labor hours/costs.
   - **QC:** Quality checkpoint.
   - **Receive:** Finished goods receipt to warehouse.
   - **Complete:** Close run.
5. **Material Requests:** Warehouse → factory material issue.
6. **Expense Requests:** Operational expense tracking (approve → pay → reverse).
7. **Costing:** BOM snapshot → material cost + labor + overhead → FG cost.
8. **Closure:** Work order close / short-close. Remaining qty, WIP, ceilings tracked.

**Reports:** Overview, order status, run performance, material reconciliation, quality, finished goods receipts.

**Invariants:**
- BOM snapshot frozen at run creation.
- Material reservation before issue.
- Remaining qty and ceilings enforced.
- WIP tracked during production.
- Cost ≠ selling price.

### §32.5 Quality Cycle

**Entry Point:** Production run stage completion or standalone inspection.

**Flow:**
1. **Create Inspection:** Link to production run / stage.
2. **Receive:** Inspector receives inspection assignment.
3. **Start:** Begin inspection work.
4. **Report:** Record measurements, pass/fail/scrap/hold.
5. **Submit:** Submit inspection results for review.
6. **Review:** Quality supervisor reviews results.
7. **Release Normal:** If pass → release to next stage or FG receipt.
8. **Reinspect:** If fail → schedule re-inspection.
9. **Close:** Final disposition.

**Outcomes:** Pass → Release Normal. Fail → Reinspect / Scrap / Hold. Reinspect → Released or Closed.

**Reports:** Quality reports (pass/fail/scrap/hold analysis), Production quality report (cross-run).

### §32.6 Maintenance Cycle

**Entry Point:** Preventive schedule or breakdown incident.

**Flow:**
1. **Preventive Plan:** Create plan with meter-based triggers. Approve → Generate work orders.
2. **Breakdown Report:** Log incident. Link to asset/machine.
3. **Work Order:** Created from plan or breakdown.
   - **Approve:** Supervisor approval.
   - **Start:** Begin maintenance work.
   - **Pause:** Temporary hold.
   - **External:** Outsource to third party.
   - **Complete:** Finish work.
   - **Close:** Final closure.
4. **Materials:** Request spares/parts. Approve → Issue → Return unused.
5. **Expenses:** Record costs. Approve → Pay → Reverse if needed.
6. **Reports:** Downtime, cost analysis, material usage.

**Invariants:**
- Work orders linked to asset/machine.
- Cost lineage: materials + expenses → work order → asset.
- Internal vs external determines material/expense path.

### §32.7 Cash / Treasury Cycle

**Entry Point:** Cash transaction need.

**Flow:**
1. **Cashbox Setup:** Create cashbox. Branch-scoped.
2. **Cash Receipt Voucher:** Record cash received. Approve → cancel available.
3. **Cash Payment Voucher:** Record cash paid. Approve → cancel available.
4. **Fund Transfer:** Move funds between cashboxes or to/from bank. Approve → cancel.
5. **Statement / Count / Closing:** Period-end reconciliation.

**Invariants:**
- Approval required before posting.
- Cancel available (not edit).
- Branch scoping enforced.

### §32.8 Bank Cycle

**Entry Point:** Bank transaction need.

**Flow:**
1. **Bank Account Setup:** Create account with bank/branch details. Multi-currency.
2. **Payments / Receipts:** Linked from supplier-payments and customer-receipts screens.
3. **Fund Transfers:** Inter-account transfers. Approve.
4. **Reconciliation:** Via accounting reconciliation center.

### §32.9 Cheques Cycle

**Entry Point:** Cheque received (receipt) or issued (payment).

**Flow:**
1. **Create Cheque:** Enter cheque details (type: receipt/payment/guarantee).
2. **Issue / Deliver:** Custody transfer.
3. **Collect:** Receive funds.
4. **Deposit:** Into bank account.
5. **Clear:** Bank confirms.
6. **Return (Bounce):** Rejection processing.
7. **Cancel:** Reverse all effects.

**State Machine:**

```
Created → Issued → Delivered → Collected → Deposited → Cleared
    ↓         ↓          ↓           ↓           ↓
    └─→ Cancelled    └─→ Returned (Bounce) at any point
```

**Verified Actions:** Create, edit, delete, mark_deposited, mark_collected, mark_returned, mark_issued, mark_delivered, mark_cleared, cancel, print.

**NOT IMPLEMENTED (menu actions not found):** Represent, Reverse-Clearing, Endorsement.

### §32.10 Fixed Assets Cycle

**Entry Point:** Asset acquisition or import.

**Flow:**
1. **Register:** Create or import asset. Accounting configure (depreciation method, useful life, accounts).
2. **Recognize:** Post initial recognition journal.
3. **Activate:** Begin depreciation schedule.
4. **Additions (Improvement):** Capitalized improvements. Post → journal.
5. **Custody:** Assign to employee / department.
6. **Transfer:** Move between branches / locations.
7. **Depreciate:** Periodic batch. Preview → Post → Reverse if needed.
8. **Dispose:** Gain/loss disposal journal.
9. **Reverse:** Reverse disposal, recognition, or improvement entries.
10. **Movements:** Full movement history.
11. **Reports:** Register, depreciation, disposal reports.

**Invariants:**
- Account tree hierarchy preserved.
- Depreciation calculated per configured method.
- Disposal generates gain/loss.
- All lifecycle events create accounting journals.

### §32.11 HR / Payroll Cycle

**Entry Point:** Employee hire or data update.

**Flow:**
1. **Employee Setup:** Create employee record with personal data, documents, employment details.
2. **Employment Data:** 18 reference screens (departments, sections, jobs, grades, etc.).
3. **Shifts:** Define work shifts.
4. **Biometric Devices:** Register attendance devices.
5. **Attendance Settings:** Configure rules.
6. **Shift Assignments:** Assign employees to shifts.
7. **Employee Attendance:** Clock-in/out records. Corrections available.
8. **HR Requests:** Leave, overtime, etc. Manage via admin.
9. **Payroll Preparation:** Calculate → Review → Approve → Create Payment → Reconcile.
10. **Employee Self Service:** Employee portal for requests.
11. **Reports:** Payroll report, payslips, payment report.

**Hidden Screens:** Countries, Governorates, Cities, Areas (geography data, present but hidden from default menu).

**Invariants:**
- Payroll cycle: Calculate → Review → Approve → Pay (sequential).
- Company/branch scoping on all HR data.

### §32.12 Accounting / Costing Cycle

**Entry Point:** Chart of accounts setup or daily journal posting.

**Flow:**
1. **Chart of Accounts:** Hierarchical account tree. Create, edit, clone, delete, export. Account code control.
2. **Cost Centers:** Hierarchical cost center tree. Print, export.
3. **Journal Entries:** Create → Post. Edit/delete before posting. Reverse posted entries.
4. **Opening Balances:** Period-start balances. Approve → cancel.
5. **Period Closing:** Close period → carry forward balances → reopen if needed.
6. **Expense Allocation:** Overhead allocation to cost centers.
7. **COGS:** Cost of goods sold calculation (via inventory valuation).
8. **Reports:**
   - General Journal: Posted journal listing.
   - Account Ledger: General ledger per account.
   - Trial Balance: Opening/ending balances.
   - Reconciliation Center: Subledger vs GL comparison.
   - Financial Statements: Income Statement, Balance Sheet, Equity Changes.
   - Expense Analysis: By cost center.
   - Financial Ratios: Liquidity, profitability, efficiency.
   - Customer Statement: Per-customer ledger.
   - Supplier Statement: Per-supplier ledger.

**Financial Statements Implementation:**

| Statement | Status | Notes |
|---|---|---|
| Income Statement | IMPLEMENTED | Via `admin.accounting.reports.financial-statements` |
| Balance Sheet | IMPLEMENTED | Via `admin.accounting.reports.financial-statements` |
| Statement of Equity Changes | IMPLEMENTED | Via `admin.accounting.reports.financial-statements` |
| Cash Flow Statement | **NOT IMPLEMENTED** | Not present in menu or pre-loaded evidence |

**Invariants:**
- Debit = Credit enforced on all journal entries.
- Period must be open for posting.
- Reversal creates counter-entries (never edit posted data).
- Company/branch/period scoping on all entries.

---

## Footer — Workflow Counts

| Category | Count |
|---|---|
| Business Cycles (§32) | 12 |
| Lifecycle Tables (§27) | 12 (one per cycle) |
| Lifecycle Stages (§27 total rows) | 112 |
| Validation Rules (§28) | 20 |
| Audit Trail Items (§29) | 18 |
| Notification Events (§23) | 17 |
| Tasks/Chat Features (§24) | 0 verified |

---

*End of Business Workflow Catalog.*
