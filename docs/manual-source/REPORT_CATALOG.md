# Report Catalog

> MgyPack ERP — Complete report index with Arabic names, routes, permissions, and capabilities.
> Source: `config/menu/*.php` + pre-loaded Codex evidence.
> Date: 2026-09-20

---

## §25 Master Report Index

### §25.1 Sales Module Reports

| # | Arabic Name | English Name | Module | Route | Permission | Purpose | Filters | Key Columns | Totals | PDF | Excel | CSV | Print | Rules | Currency | Scope |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| S-01 | كشف حساب عميل | Customer Statement | Sales / Accounting | `admin.accounting.reports.customer-statement` | `reports.customer_statement.view` | Per-customer ledger with transactions and running balance | Customer, date range, account | Date, ref, description, debit, credit, balance | Debit, Credit, Balance | Yes (export) | Yes (export) | Yes (export) | No | Group by document type | Multi | Company |
| S-02 | التحليل المالي للمبيعات | Sales Financial Analysis | Sales | `admin.reports.sales.sales-orders.index?report=financial` | `reports.sales.sales_orders.view` | Revenue, cost, margin analysis | Date range, customer, product, warehouse | Revenue, COGS, gross margin, net margin | Revenue, COGS, Margin | Yes | Yes | Yes | No | Period-based comparison | Multi | Company/Branch |
| S-03 | المبيعات حسب الفترة | Sales by Period | Sales | `admin.reports.sales.sales-orders.index?report=period` | `reports.sales.sales_orders.view` | Period-over-period sales trends | Date range, period type, customer, product | Period, qty, revenue, cost | Qty, Revenue, Cost | Yes | Yes | Yes | No | Daily/weekly/monthly grouping | Multi | Company/Branch |
| S-04 | المبيعات حسب العميل | Sales by Customer | Sales | `admin.reports.sales.sales-orders.index?report=customers` | `reports.sales.sales_orders.view` | Customer ranking and contribution | Date range, customer, product | Customer, qty, revenue, % of total | Qty, Revenue, % | Yes | Yes | Yes | No | Top N, alphabetical | Multi | Company/Branch |
| S-05 | المبيعات حسب الصنف | Sales by Product | Sales | `admin.reports.sales.sales-orders.index?report=products` | `reports.sales.sales_orders.view` | Product performance analysis | Date range, product, customer, warehouse | Product, qty, revenue, cost, margin | Qty, Revenue, Cost, Margin | Yes | Yes | Yes | No | By category, by product | Multi | Company/Branch |
| S-06 | تقرير فواتير المبيعات | Sales Invoices Report | Sales | `admin.reports.sales.sales-orders.index?report=invoices` | `reports.sales.sales_orders.view` | Invoice listing and summary | Date range, customer, status | Invoice #, date, customer, total, status, paid | Total, Paid, Outstanding | Yes | Yes | Yes | No | Posted / outstanding | Multi | Company/Branch |
| S-07 | أعمار الذمم المدينة | Customer Receivables and Aging | Sales | `admin.reports.sales.sales-orders.index?report=receivables` | `reports.sales.sales_orders.view` | AR aging buckets | Date, customer, aging buckets | Customer, current, 30, 60, 90, 120+, total | Per bucket, total | Yes | Yes | Yes | No | Standard aging buckets | Multi | Company/Branch |
| S-08 | توقعات التحصيل | Collections Forecast | Sales | `admin.reports.sales.sales-orders.index?report=collections` | `reports.sales.sales_orders.view` | Expected collections by date | Date range, customer | Due date, customer, amount | Forecast total | Yes | Yes | Yes | No | Due-date based | Multi | Company/Branch |
| S-09 | تحليل مرتجعات المبيعات | Sales Returns Analysis | Sales | `admin.reports.sales.sales-orders.index?report=returns` | `reports.sales.sales_orders.view` | Return rate and reasons | Date range, product, customer | Return #, date, product, qty, value, reason | Qty, Value | Yes | Yes | Yes | No | Return rate % | Multi | Company/Branch |
| S-10 | خط أنابيب عروض الأسعار | Quotation Pipeline | Sales | `admin.reports.sales.sales-orders.index?report=quotations` | `reports.sales.sales_orders.view` | Quotation status and conversion | Date range, status, customer | Quotation #, date, customer, amount, status | Total by status | Yes | Yes | Yes | No | Draft/Sent/Accepted/Rejected | Multi | Company/Branch |
| S-11 | تنفيذ أوامر البيع | Sales Order Fulfillment | Sales | `admin.reports.sales.sales-orders.index?report=fulfillment` | `reports.sales.sales_orders.view` | Order delivery status | Date range, order, customer | Order #, ordered qty, delivered qty, remaining | Ordered, Delivered, Remaining | Yes | Yes | Yes | No | Fill rate % | Multi | Company/Branch |
| S-12 | تغطية التسعير | Sales Pricing Coverage | Sales | `admin.reports.sales.sales-orders.index?report=pricing` | `reports.sales.sales_orders.view` | Price list coverage analysis | Product, price list | Product, price list, last price, coverage | — | Yes | Yes | Yes | No | Coverage % | Multi | Company/Branch |
| S-13 | نظرة عامة على العمليات البيعية | Sales Operational Overview | Sales | `admin.reports.sales.sales-orders.index?report=operational` | `reports.sales.sales_orders.view` | High-level sales KPIs | Date range, branch | Orders, deliveries, invoices, receipts, returns counts/values | All KPIs | Yes | Yes | Yes | No | Dashboard view | Multi | Company/Branch |
| S-14 | تكلفة المبيعات | Cost of Sales | Sales | `admin.reports.sales.sales-orders.index?report=cost_of_sales` | `reports.sales.sales_orders.view` | COGS analysis per sale | Date range, product, customer | Product, qty sold, COGS, margin | COGS, Margin | Yes | Yes | Yes | No | Cost ≠ price | Multi | Company/Branch |
| S-15 | تقرير العملاء | Customers Report | Sales | `admin.reports.customers.index` | `reports.customers.view` | Customer master data listing | Status, branch, group | Customer #, name, contact, balance, status | Balances | Yes (PDF) | Yes (export) | Yes (export) | No | Active/inactive/all | Multi | Company/Branch |

### §25.2 Purchases Module Reports

| # | Arabic Name | English Name | Module | Route | Permission | Purpose | Filters | Key Columns | Totals | PDF | Excel | CSV | Print | Rules | Currency | Scope |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| P-01 | كشف حساب مورد | Supplier Statement | Purchases / Accounting | `admin.accounting.reports.supplier-statement` | `reports.supplier_statement.view` | Per-supplier ledger | Supplier, date range, account | Date, ref, description, debit, credit, balance | Debit, Credit, Balance | Yes (export) | Yes (export) | Yes (export) | No | Group by document type | Multi | Company |
| P-02 | تقرير الموردين | Suppliers Report | Purchases | `admin.reports.suppliers.index` | `reports.suppliers.view` | Supplier master data listing | Status, branch | Supplier #, name, contact, balance, status | Balances | Yes (PDF) | Yes (export) | Yes (export) | No | Active/inactive/all | Multi | Company/Branch |
| P-03 | دفتر المشتريات | Purchase Ledger | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=purchase_ledger` | `reports.purchases.view` | Complete purchase transaction ledger | Date range, supplier, product | Date, doc type, doc #, supplier, product, qty, amount, cost | Qty, Amount, Cost | No | Yes (export) | Yes (export) | No | All purchase documents | Multi | Company/Branch |
| P-04 | متطلبات الشراء المفتوحة | Open Purchase Requirements | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=open_requirements` | `reports.purchases.view` | Outstanding requisition lines | Warehouse, product, status | Requisition #, product, requested qty, ordered qty, remaining | Requested, Ordered, Remaining | No | Yes (export) | Yes (export) | No | Unfulfilled only | Multi | Branch |
| P-05 | تقرير طلبات الشراء | Purchase Requests Report | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=purchase_requests` | `reports.purchases.view` | Requisition listing and status | Date range, status, origin, warehouse | Requisition #, date, origin, status, items, total | Total | No | Yes (export) | Yes (export) | No | All statuses | Multi | Branch |
| P-06 | طلبات الشراء المعلقة | Pending Purchase Requests | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=pending_purchase_requests` | `reports.purchases.view` | Requisitions awaiting approval | Origin, warehouse, submitter | Requisition #, date, origin, submitted by, age | Count | No | Yes (export) | Yes (export) | No | Submitted not approved | Multi | Branch |
| P-07 | مقارنة بين الطلب والشراء | Requested vs Ordered | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=requested_vs_ordered` | `reports.purchases.view` | Requisition-to-PO comparison | Date range, product, warehouse | Product, requested qty, ordered qty, variance | Requested, Ordered, Variance | No | Yes (export) | Yes (export) | No | Variance % | Multi | Branch |
| P-08 | حالة عروض الأسعار | RFQ / Quotation Status | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=rfq_quotation_status` | `reports.purchases.view` | RFQ and supplier quotation status | Date range, status, supplier | RFQ #, supplier, quotation #, status, amount | Amount | No | Yes (export) | Yes (export) | No | Open/closed | Multi | Branch |
| P-09 | إجراءات التوريد المعلقة | Pending RFQ / Quotation / Selection Actions | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=pending_sourcing_actions` | `reports.purchases.view` | Outstanding sourcing tasks | Age, supplier, product | Requisition #, product, age, pending action | Count | No | Yes (export) | Yes (export) | No | Action required | Multi | Branch |
| P-10 | أوامر الشراء المفتوحة | Open Purchase Orders | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=open_purchase_orders` | `reports.purchases.view` | Outstanding POs | Supplier, date range, warehouse | PO #, date, supplier, amount, status, received % | Amount, Received % | No | Yes (export) | Yes (export) | No | Not fully received | Multi | Branch |
| P-11 | حالة أوامر الشراء | Purchase Order Status | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=purchase_order_status` | `reports.purchases.view` | Full PO status overview | Date range, status, supplier | PO #, date, supplier, status, amount, received, invoiced | Amount, Received, Invoiced | No | Yes (export) | Yes (export) | No | All statuses | Multi | Branch |
| P-12 | أوامر الاستلام الجزئي | Partially Received Orders | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=partially_received_orders` | `reports.purchases.view` | POs partially received | Supplier, overdue only | PO #, supplier, ordered, received, remaining, due date | Ordered, Received, Remaining | No | Yes (export) | Yes (export) | No | Remaining > 0 | Multi | Branch |
| P-13 | تأخر التوريد | Overdue PO Deliveries | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=overdue_po_deliveries` | `reports.purchases.view` | POs past due date | Supplier, overdue days | PO #, supplier, due date, days overdue, amount | Amount, Overdue days | No | Yes (export) | Yes (export) | No | Due date < today | Multi | Branch |
| P-14 | تقرير أوامر التوريد | Supply Orders Report | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=supply_orders` | `reports.purchases.view` | Supply order listing | Date range, supplier, status | Supply #, date, supplier, PO #, status, amount | Amount | No | Yes (export) | Yes (export) | No | All statuses | Multi | Branch |
| P-15 | جدول التسليم | Delivery Schedule | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=delivery_schedule` | `reports.purchases.view` | Expected deliveries by date | Date range, supplier, warehouse | Date, supplier, PO #, product, qty, warehouse | Qty | No | Yes (export) | Yes (export) | No | Forward-looking | Multi | Branch |
| P-16 | مقارنة بين الأمر والاستلام | Ordered vs Received | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=ordered_vs_received` | `reports.purchases.view` | Order receipt comparison | Date range, product, supplier | Product, ordered qty, received qty, variance % | Ordered, Received, Variance | No | Yes (export) | Yes (export) | No | Fill rate % | Multi | Branch |
| P-17 | توصيلات الموردين | Supplier Deliveries | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=supplier_deliveries` | `reports.purchases.view` | Delivery history | Date range, supplier | Date, supplier, GRN #, items, amount | Amount | No | Yes (export) | Yes (export) | No | Posted GRNs only | Multi | Branch |
| P-18 | نتائج فحص المشتريات | Purchase Inspection Results | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=receipt_quality_status` | `reports.purchases.view` | Inspection pass/fail summary | Date range, supplier, result | Inspection #, GRN #, supplier, result, items | Pass, Fail, Scrap | No | Yes (export) | Yes (export) | No | All results | Multi | Branch |
| P-19 | فحوصات الاستلام المعلقة | Accepted Inspections Pending Receipt | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=incoming_qc_pending` | `reports.purchases.view` | Inspections accepted but not received | Supplier, product | Inspection #, supplier, product, qty, accepted date | Qty | No | Yes (export) | Yes (export) | No | Pending receipt | Multi | Branch |
| P-20 | رفض فحص المشتريات | Purchase Inspection Rejections | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=qc_rejection` | `reports.purchases.view` | Rejected items analysis | Date range, supplier, product | Inspection #, supplier, product, rejected qty, reason | Rejected qty | No | Yes (export) | Yes (export) | No | Rejections only | Multi | Branch |
| P-21 | إيصالات الشراء | Purchase Receipts | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=purchase_receipts` | `reports.purchases.view` | GRN listing | Date range, supplier, warehouse | GRN #, date, supplier, items, amount, status | Amount | No | Yes (export) | Yes (export) | No | Posted GRNs | Multi | Branch |
| P-22 | تقرير فواتير المشتريات | Purchase Invoices Report | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=purchase_invoices` | `reports.purchases.view` | Invoice listing and summary | Date range, supplier, status | Invoice #, date, supplier, amount, status, paid | Amount, Paid, Outstanding | No | Yes (export) | Yes (export) | No | All statuses | Multi | Branch |
| P-23 | مقارنة بين الاستلام والفاتورة | Received vs Invoiced | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=received_vs_invoiced` | `reports.purchases.view` | GRN-to-invoice matching | Date range, supplier | Supplier, received qty/value, invoiced qty/value, variance | Received, Invoiced, Variance | No | Yes (export) | Yes (export) | No | Price variance | Multi | Branch |
| P-24 | تقرير مرتجعات المشتريات | Purchase Returns Report | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=returns` | `reports.purchases.view` | Return listing and analysis | Date range, supplier, product | Return #, date, supplier, product, qty, value | Qty, Value | No | Yes (export) | Yes (export) | No | Posted returns | Multi | Branch |
| P-25 | المشتريات حسب المورد | Purchases by Supplier | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=purchases_by_supplier` | `reports.purchases.view` | Supplier ranking | Date range, supplier | Supplier, qty, amount, % of total | Qty, Amount, % | No | Yes (export) | Yes (export) | No | Top N | Multi | Company/Branch |
| P-26 | المشتريات حسب الصنف | Purchases by Item | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=purchases_by_product` | `reports.purchases.view` | Product purchase analysis | Date range, product, category | Product, qty, amount, avg price | Qty, Amount, Avg Price | No | Yes (export) | Yes (export) | No | By product/category | Multi | Company/Branch |
| P-27 | المشتريات حسب التصنيف | Purchases by Category | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=purchases_by_category` | `reports.purchases.view` | Category-level analysis | Date range, category | Category, qty, amount, % | Qty, Amount, % | No | Yes (export) | Yes (export) | No | Grouped by category | Multi | Company/Branch |
| P-28 | المشتريات حسب المستودع | Purchases by Warehouse | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=purchases_by_warehouse` | `reports.purchases.view` | Warehouse-level analysis | Date range, warehouse | Warehouse, qty, amount | Qty, Amount | No | Yes (export) | Yes (export) | No | Per warehouse | Multi | Company/Branch |
| P-29 | المشتريات حسب الفترة | Purchases by Period | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=purchases_by_period` | `reports.purchases.view` | Period trends | Date range, period type | Period, qty, amount | Qty, Amount | No | Yes (export) | Yes (export) | No | Daily/weekly/monthly | Multi | Company/Branch |
| P-30 | سجل أسعار المورد / الصنف | Supplier / Item Price History | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=price_history` | `reports.purchases.view` | Price trend analysis | Product, supplier, date range | Date, supplier, product, unit price, qty | — | No | Yes (export) | Yes (export) | No | Price over time | Multi | Company |
| P-31 | أرصدة الموردين | Supplier Outstanding | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=outstanding_supplier_invoices` | `reports.purchases.view` | Outstanding payables | Supplier, aging buckets | Supplier, current, 30, 60, 90, 120+, total | Per bucket, total | No | Yes (export) | Yes (export) | No | Standard aging | Multi | Company/Branch |
| P-32 | أقساط الموردين المستحقة | Due Supplier Installments | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=due_supplier_installments` | `reports.purchases.view` | Upcoming payment due dates | Date range, supplier | Due date, supplier, amount | Total | No | Yes (export) | Yes (export) | No | Due-date based | Multi | Company/Branch |
| P-33 | أعمار ذمم الموردين | Supplier Aging | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=supplier_aging` | `reports.purchases.view` | AP aging analysis | Date, supplier, aging buckets | Supplier, current, 30, 60, 90, 120+, total | Per bucket, total | No | Yes (export) | Yes (export) | No | Standard aging | Multi | Company/Branch |
| P-34 | مدفوعات الموردين القادمة | Upcoming Supplier Payments | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=upcoming_supplier_payments` | `reports.purchases.view` | Cash flow forecast for AP | Date range, supplier | Date, supplier, amount | Total by period | No | Yes (export) | Yes (export) | No | Forward-looking | Multi | Company/Branch |
| P-35 | مستلم غير مفوتر | Goods Received Not Invoiced (GRNI) | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=goods_received_not_invoiced` | `reports.purchases.view` | GRN without matching invoice | Date range, supplier, warehouse | GRN #, date, supplier, received qty/value, invoiced qty/value | Received, Invoiced, Variance | No | Yes (export) | Yes (export) | No | Uninvoiced only | Multi | Branch |
| P-36 | تحليل المشتريات المرتبطة بالإنتاج | Production-linked Procurement Analysis | Purchases | `admin.purchases.procurement-cycle-report.index?report_type=production_analysis` | `reports.purchases.view` | Procurement linked to production demand | Date range, product, work order | Product, work order, PO #, qty, cost | Qty, Cost | No | Yes (export) | Yes (export) | No | Production linkage | Multi | Branch |

### §25.3 Inventory Module Reports

| # | Arabic Name | English Name | Module | Route | Permission | Purpose | Filters | Key Columns | Totals | PDF | Excel | CSV | Print | Rules | Currency | Scope |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| I-01 | استعلام الأرصدة | Stock Balance Inquiry | Inventory | `admin.inventory.stock-balances.index` | `inventory.reports.operational` | Real-time stock levels | Warehouse, product, category, status | Product, warehouse, on-hand, reserved, available, cost | Qty, Cost value | No | Yes (export) | Yes (export) | No | Operational + Financial views | Multi | Company/Warehouse |
| I-02 | تقارير المخزون التشغيلية | Inventory Operational Reports | Inventory | `admin.inventory.reports.index` | `inventory.reports.operational` | Stock card, movements, reservations | Warehouse, product, date range | Product, document type, in, out, balance, date | In, Out, Balance | No | Yes (export) | Yes (export) | No | Stock card per product | Multi | Warehouse |
| I-03 | مقارنة تقييم المخزون | Inventory Valuation Comparison | Inventory | `admin.inventory.reports.valuation` | `inventory.reports.financial` | MA vs Periodic vs FIFO comparison | Product, warehouse, date | Product, MA value, Periodic value, FIFO value, variance | Per method, variance | No | No | No | No | Financial access only | Single | Company |
| I-04 | تقييم المخزون بسعر البيع | Inventory Sales Valuation | Inventory | `admin.inventory.sales-valuation` | `inventory.reports.operational` | Price list vs cost comparison | Product, price list, warehouse | Product, cost, price list price, margin, stock value | Cost value, Sales value, Margin | No | Yes (export) | Yes (export) | No | Margin analysis | Multi | Company/Warehouse |
| I-05 | تقرير استلام الإنتاج التام | Finished Goods Receipts Report | Production / Inventory | `admin.production.reports.receipts` | `production.reports.operational` | FG receipt listing | Date range, product, work order | Date, work order, product, qty, warehouse | Qty | No | No | No | No | Production receipts | Multi | Warehouse |
| I-06 | تقرير بيانات المنتجات والخامات | Products and Materials Data Report | Reports | `admin.reports.products-data.index` | `reports.products_data.view` | Product master data + BOM | Category, type, status | Product #, name, category, UOM, BOM, components, cost | — | Yes (PDF) | Yes (export) | Yes (export) | No | Master data | Single | Company |

### §25.4 Production Module Reports

| # | Arabic Name | English Name | Module | Route | Permission | Purpose | Filters | Key Columns | Totals | PDF | Excel | CSV | Print | Rules | Currency | Scope |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| PR-01 | ملخص الإنتاج | Production Reports Overview | Production | `admin.production.reports.index` | `production.reports.operational` | High-level production KPIs | Date range, product, stage | KPI values, counts, rates | All KPIs | No | No | No | No | Dashboard | N/A | Company/Branch |
| PR-02 | موقف أوامر الإنتاج | Production Order Status Report | Production | `admin.production.reports.orders` | `production.reports.operational` | Work order progress | Date range, status, product | WO #, product, planned qty, produced qty, status, remaining | Planned, Produced, Remaining | No | No | No | No | Per work order | N/A | Branch |
| PR-03 | أداء التشغيلات | Production Run Performance Report | Production | `admin.production.reports.runs` | `production.reports.operational` | Run efficiency analysis | Date range, machine, product | Run #, WO #, machine, shift, planned qty, actual qty, time, efficiency | Planned, Actual, Efficiency % | No | No | No | No | Per run | N/A | Branch |
| PR-04 | تقرير تسوية الخامات | Production Material Reconciliation Report | Production | `admin.production.reports.materials` | `production.reports.operational` | Material consumption variance | Date range, product, material | Material, BOM qty, issued qty, consumed qty, waste, variance | BOM, Issued, Consumed, Waste, Variance | No | No | No | No | Variance % | N/A | Branch |

### §25.5 Quality Module Reports

| # | Arabic Name | English Name | Module | Route | Permission | Purpose | Filters | Key Columns | Totals | PDF | Excel | CSV | Print | Rules | Currency | Scope |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Q-01 | تقارير الجودة | Quality Reports | Quality | `admin.production.quality.reports.index` | `production.quality.view` | Inspection results analysis | Date range, result, product, stage | Inspection #, run #, product, stage, result, pass qty, fail qty, scrap | Pass, Fail, Scrap | No | No | No | No | Pass rate % | N/A | Branch |
| Q-02 | تقرير جودة الإنتاج | Production Quality Report | Quality / Production | `admin.production.reports.quality` | `production.reports.operational` | Cross-run quality summary | Date range, product, run | Run #, product, inspections, pass rate, issues | Inspection count, Pass rate % | No | No | No | No | Per run | N/A | Branch |

### §25.6 Maintenance Module Reports

| # | Arabic Name | English Name | Module | Route | Permission | Purpose | Filters | Key Columns | Totals | PDF | Excel | CSV | Print | Rules | Currency | Scope |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| M-01 | تقارير الصيانة | Maintenance Reports | Maintenance | `admin.maintenance.reports.index` | `maintenance.reports.view` | Downtime, cost, material usage | Date range, machine, type, status | Machine, downtime hours, materials cost, labor cost, total cost | Downtime, Material Cost, Labor Cost, Total | No | Yes (export) | Yes (export) | No | Internal/external breakdown | Single | Company/Branch |

### §25.7 Accounting Module Reports

| # | Arabic Name | English Name | Module | Route | Permission | Purpose | Filters | Key Columns | Totals | PDF | Excel | CSV | Print | Rules | Currency | Scope |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| A-01 | اليومية العامة | General Journal | Accounting | `admin.accounting.reports.general-journal` | `reports.account_ledger.view` | Posted journal listing | Date range, account, cost center, reference | Date, JE #, account, description, debit, credit | Debit, Credit | No | Yes (export) | Yes (export) | No | Posted entries only | Multi | Company |
| A-02 | كشف الأستاذ | Account Ledger | Accounting | `admin.accounting.reports.account-ledger` | `reports.account_ledger.view` | General ledger per account | Account, date range, cost center | Date, JE #, description, debit, credit, balance | Debit, Credit, Balance | No | Yes (export) | Yes (export) | No | Running balance | Multi | Company |
| A-03 | ميزان المراجعة | Trial Balance | Accounting | `admin.accounting.reports.trial-balance` | `reports.trial_balance.view` | Opening/ending balances | Date, account range, cost center | Account code, account name, opening, debit, credit, ending | Opening, Debit, Credit, Ending | No | Yes (export) | Yes (export) | No | Debit = Credit enforced | Multi | Company |
| A-04 | مركز المطابقة | Reconciliation Center | Accounting | `admin.accounting.reports.reconciliation-center` | `reports.account_ledger.view` | Subledger vs GL comparison | Account, date range | Account, GL balance, subledger balance, variance | GL, Subledger, Variance | No | Yes (export) | Yes (export) | No | Variance highlight | Multi | Company |
| A-05 | القوائم المالية | Financial Statements | Accounting | `admin.accounting.reports.financial-statements` | `reports.financial_statements.view` | Income Statement, Balance Sheet, Equity Changes | Date range, comparison period, cost center | Account, classification, current period, prior period, variance | Per classification, grand total | No | Yes (export) | Yes (export) | No | Three statements | Multi | Company |
| A-06 | تحليل المصروفات | Expense Analysis | Accounting | `admin.accounting.reports.financial-analytics.expense-analysis.index` | `reports.financial_analytics.expense_analysis.view` | Cost center expense breakdown | Date range, cost center, account | Cost center, account, amount, % of total | Per CC, Grand total | No | Yes (export) | Yes (export) | No | Hierarchical CC | Multi | Company |
| A-07 | النسب المالية | Financial Ratios | Accounting | `admin.accounting.reports.financial-analytics.financial-ratios.index` | `reports.financial_analytics.financial_ratios.view` | Liquidity, profitability, efficiency ratios | Date range, comparison period | Ratio name, current value, prior value, change % | — | No | Yes (export) | Yes (export) | No | Computed from statements | N/A | Company |
| A-08 | كشف حساب عميل | Customer Statement | Accounting / Sales | `admin.accounting.reports.customer-statement` | `reports.customer_statement.view` | Per-customer ledger | Customer, date range, account | Date, ref, description, debit, credit, balance | Debit, Credit, Balance | Yes (export) | Yes (export) | Yes (export) | No | Shared with Sales | Multi | Company |
| A-09 | كشف حساب مورد | Supplier Statement | Accounting / Purchases | `admin.accounting.reports.supplier-statement` | `reports.supplier_statement.view` | Per-supplier ledger | Supplier, date range, account | Date, ref, description, debit, credit, balance | Debit, Credit, Balance | Yes (export) | Yes (export) | Yes (export) | No | Shared with Purchases | Multi | Company |

### §25.8 HR / Payroll Module Reports

| # | Arabic Name | English Name | Module | Route | Permission | Purpose | Filters | Key Columns | Totals | PDF | Excel | CSV | Print | Rules | Currency | Scope |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| H-01 | تقرير الرواتب | Payroll Report | HR | `admin.hr.reports.payroll` | `hr.payroll_reports.view` | Payslip listing and payroll summary | Payroll run, department, employee | Employee, department, basic, allowances, deductions, net | Basic, Allowances, Deductions, Net | Yes (payslip) | Yes (export) | Yes (export) | No | Per payroll run | Single | Company/Branch |
| H-02 | تقرير مدفوعات الرواتب | Payroll Payment Report | HR | `admin.hr.reports.payments` | `hr.payroll_payment_reports.view` | Payment reconciliation | Payroll run, date range, payment method | Employee, amount paid, payment method, date, status | Total paid | No | Yes (export) | Yes (export) | No | Paid vs pending | Single | Company/Branch |

### §25.9 Fixed Assets Module Reports

| # | Arabic Name | English Name | Module | Route | Permission | Purpose | Filters | Key Columns | Totals | PDF | Excel | CSV | Print | Rules | Currency | Scope |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| FA-01 | تقارير الأصول الثابتة | Fixed Asset Reports | Fixed Assets | `admin.fixed-assets.reports.index` | `fixed_assets.reports` | Register, depreciation, disposal reports | Date range, category, status, branch | Asset #, name, category, cost, accumulated dep., book value, status | Cost, Accumulated Dep., Book Value | No | Yes (export) | Yes (export) | No | Multiple report types | Single | Company/Branch |

### §25.10 Finance Module Reports

Reports accessible through finance screens (not separate report routes):

| # | Arabic Name | English Name | Module | Route | Permission | Purpose | Notes |
|---|---|---|---|---|---|---|---|
| F-01 | كشف الخزنة | Cashbox Statement | Finance | Via cashbox detail screen | `cashboxes.view` | Cashbox transaction listing | Statement view within cashbox |
| F-02 | عد الخزنة | Cashbox Count | Finance | Via cashbox detail screen | `cashboxes.view` | Physical count reconciliation | Count vs system balance |
| F-03 | إقفال الخزنة | Cashbox Closing | Finance | Via cashbox detail screen | `cashboxes.view` | Period-end closing | Balance carry forward |

---

## §26 Print & Export Rules

| Rule | Scope | Notes |
|---|---|---|
| PDF generation | Sales reports (S-01 to S-15), Customers (S-15), Suppliers (P-02), Products (I-06), Payroll (H-01) | Available via `pdf` action in menu |
| Excel export | All reports with `export` action | Via `export` action in menu |
| CSV export | All reports with `export` action | Via `export` action in menu |
| Print (browser) | Price lists, purchase orders, goods receipt notes, purchase invoices, production work orders, production runs, material requests, quality inspections, maintenance orders, cash vouchers, fund transfers, cheques, fixed assets | Via `print` action in screen menus |
| Price list print-only | Price lists (`price_lists.print`) | Print/export only; NOT operational pricing |
| Cheque print | Cheques (`cheques.print`) | Template-based cheque printing |
| Financial statement export | Financial statements (`financial-statements`) | Via `export` action |
| Currency display | All financial reports | Multi-currency support; base + transaction currency |
| Scope enforcement | All reports | Company and/or branch scoping per module rules |
| Closed-period data | All accounting reports | Reports include closed-period data; mutations blocked |
| PDF canonical for price lists | Price lists | PDF is the canonical printed format |

---

## Footer — Report Counts

| Module | Count |
|---|---|
| Sales (§25.1) | 15 |
| Purchases (§25.2) | 36 |
| Inventory (§25.3) | 6 |
| Production (§25.4) | 4 |
| Quality (§25.5) | 2 |
| Maintenance (§25.6) | 1 |
| Accounting (§25.7) | 9 |
| HR / Payroll (§25.8) | 2 |
| Fixed Assets (§25.9) | 1 |
| Finance (§25.10) | 3 |
| **Total report rows** | **79** |

---

*End of Report Catalog.*
