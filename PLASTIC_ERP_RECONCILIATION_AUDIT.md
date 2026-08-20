# Plastic Factory ERP — Current-State Reconciliation Audit

Audit date: 2026-08-20

Mode: read-only architecture audit; no application, schema, route, menu, screen-catalog, context, or permission changes were made.

Companion matrix: `PLASTIC_ERP_SCREEN_DISPOSITION.csv`.

## 1. Executive Verdict

The current ERP UI Shell is **not a business architecture and not an implementation**. It is a broad feature/backlog inventory rendered as 524 generic metadata screens. Its controller always returns an empty dataset, its forms have no store/update/delete routes, and its metadata manufactures thousands of routes and permissions without persistence or document semantics.

The suspicion in the brief is proven:

- 524 shell resources generate 2,793 of 3,985 non-vendor routes (70.1%).
- The shell generates 6,336 of 7,094 discovered permissions (89.3%).
- 502 shell resources are menu-visible in expanded mode.
- The screen matrix classifies 166 resources as child/tab/repeater data, 42 as workflow actions, 53 as duplicates, and 9 as unsupported Plastic ERP legacy. Those 270 resources must not remain independent CRUD screens.
- Only 60 shell concepts are legitimate independent masters/transactions/workspaces as currently named; 16 more are valid responsibilities that require architectural rework. Another 109 are derived histories/inquiries, not persistence owners.

The application is a useful generic ERP foundation with real Auth, Core, Accounting, Finance, HR, customer/supplier, quotation, purchase, opening-inventory, fixed-asset, and lookup capabilities. It is **not yet a Plastic Factory manufacturing ERP**. The end-to-end Sales Order → Work Order → material reservation/issue → production/QC/output → finished goods → delivery/invoice/collection chain does not exist in current code.

The shell can safely remain only as a **non-operational requirements/backlog catalog** if it is isolated from production navigation, route generation, and permission discovery. It cannot safely remain an active ERP module surface: users would receive empty screens, roles would inherit thousands of speculative abilities, and future implementations would be forced to conform to the wrong one-concept/one-CRUD decomposition.

No shell screen, database residue table, or real screen should be deleted from this audit. Architecture approval and a separately reviewed isolation/reconciliation task must come first.

## 2. Current Code Reality

### Repository and runtime baseline

| Fact | Current evidence |
|---|---:|
| Actual root | `/mnt/Me/MB/Projects/ShortCoded/MgyPack/ERP` |
| Branch / worktree before audit | `main` / clean |
| Module directories | 10 |
| Current migration files | 153 |
| Migration rows in local database | 187 |
| Local database tables | 264 |
| Screen Catalog entries | 127: 75 `live`, 52 `placeholder` |
| ERP UI Shell resources | 524 |
| Shell resources visible in expanded menu | 502 |
| Non-vendor routes | 3,985 |
| Shell-controller routes | 2,793 |
| Shell-generated permissions | 6,336 |
| Total discovered permissions | 7,094 |
| Menu permissions | 717 unique |
| Legacy placeholder permissions | 52 |

Installed versions checked during the audit include Laravel Framework `12.61.0`, Octane `2.17.4`, Livewire `4.3.1`, Laravel Boost `2.4.8`, and Pest `3.8.6`.

### Module implementation classification

| Module/domain | Actual persisted reality | Classification |
|---|---|---|
| Auth | Login/session/lock/presence, users, roles, logs, screen visibility, committed online-seat limit | Implemented Real foundation |
| Core | Companies, branches/factories/stores, periods, product/item masters, units/conversions, product components, packaging links, archive, tasks, calendar, chat, imports, operating context, limited Open Documents | Implemented Real foundation; manufacturing master data partial |
| Accounting | Chart of Accounts, classifications, cost centers, journal-entry schema/models/service | Partially Implemented; no journal-entry UI, limited posting sources |
| Finance | Bank accounts, cashboxes, cash vouchers, cheques, transfers, opening balances and approvals | Implemented Real foundation; posting coverage incomplete |
| HR | Employees and many live lookups/controllers; broad HR schema foundations | Partially Implemented; many operational/payroll tables have no real workflows |
| Sales | Customers, credit-limit records, quotations with revisions/lines/milestones/execution schedule, project structures/models | Partially Implemented; no Sales Order, delivery, sales invoice, or collection allocation workflow |
| Purchases | Suppliers, Purchase Orders, Purchase Invoices and payment schedules | Partially Implemented; no source-linked receipt/quality/invoice chain |
| Inventory | Opening Stock, Opening Stock Pricing, Unpriced Inventory Receipt | Partially Implemented; no authoritative stock ledger/reservation/movement engine |
| Production | Production Identifier Types lookup and Production Identifier hierarchy only | Placeholder/compatibility foundation; no production transaction |
| Fixed Assets | Fixed Asset register with calculation/account setup helpers | Implemented Real register; lifecycle transactions are shell only |
| Quality | No module directory or current models/controllers/services | UI Shell Only; database residue exists |
| Maintenance | No module directory or current models/controllers/services | UI Shell Only |
| Costing | No module directory or current models/controllers/services | UI Shell Only |
| Reports | Some real customer/supplier/product/auth/accounting reports; 54 additional shell reports | Mixed real reports and UI Shell Only |

### Real transactions and boundaries proved by code

- Quotations are real documents with revision snapshots, lines, payment milestones, execution schedule lines, attachments, and sent/accepted/rejected/cancelled transitions. There is no conversion route/service to Sales Order despite a `converted` status constant.
- Purchase Orders are real header/line documents with draft/approved/closed/cancelled states and received/remaining quantity columns. No current service updates those received quantities from a Goods Receipt.
- Purchase Invoices are real header/line/payment-schedule documents and create a journal entry on approval. They have no foreign key to Purchase Order or Goods Receipt.
- Unpriced Inventory Receipts are real quantity documents with approve/close/cancel states, supplier, branch/store, and lines. They have no Purchase Order link and approval does not write a stock ledger.
- Opening Stock and Opening Stock Pricing are real opening documents. They do not establish a reusable transaction-ledger architecture for later receipts/issues/transfers/reservations.
- Finance Opening Balance approval creates a system journal entry. Cash vouchers, cheques, and transfers are real operational documents, but the full subledger/posting boundary is not uniform.
- Open Documents supports only Opening Balances, Opening Stocks, and Opening Stock Pricings. It is not yet a generic closed-document correction contract.

### Database ownership drift

The live database contains 42 tables whose exact names are not referenced in current `app`, `modules`, or current migration PHP files:

`bank_voucher_lines`, `bank_vouchers`, `customer_invoice_lines`, `customer_invoices`, `customer_receipt_allocations`, `customer_receipts`, `document_payment_allocations`, `goods_receipt_lines`, `goods_receipts`, `inventory_document_lines`, `inventory_documents`, `inventory_transactions`, `operational_status_histories`, `production_component_types`, `production_identifier_type_quotation_products`, `production_material_request_lines`, `production_material_requests`, `production_order_drawings`, `production_order_lines`, `production_order_stage_histories`, `production_order_stages`, `production_order_status_histories`, `production_orders`, `production_stages`, `project_accommodation_nodes`, `project_accommodations`, `purchase_invoice_goods_receipts`, `purchase_return_lines`, `purchase_returns`, `quality_checkpoints`, `quality_inspection_results`, `quality_inspection_types`, `quality_inspections`, `quotation_due_types`, `quotation_generation_batches`, `quotation_types`, `sales_order_lines`, `sales_order_payment_schedules`, `sales_order_status_histories`, `sales_orders`, `sales_project_payment_stages`, and `sales_projects`.

Thirty-seven are empty. Five contain 36 total rows: `production_identifier_type_quotation_products` (20), `production_stages` (7), `quality_checkpoints` (7), `quality_inspection_types` (1), and `quotation_generation_batches` (1).

These are **database/schema residue**, not current implemented workflows. They must be backed up and reconciled before any cleanup or new migration naming decision. New code must not silently adopt them merely because the tables exist.

### Route integrity

There are no duplicate route names. There is one duplicate method/URI pair: `POST admin/quick-tasks` is named both `admin.quick-tasks.index` and `admin.quick-tasks.store`. This is current route drift and should be corrected in a separate focused task.

### Targeted shell verification

The current shell feature test is not green. Running it through `php artisan test --compact tests/Feature/Core/ErpUiShellTest.php` exhausts the configured 128 MB memory limit in `MenuService::normalizeItem()` before the suite can finish. Running the same file directly with a temporary 512 MB CLI limit completes with 9 passing and 3 failing tests (3,826 assertions): the shell page no longer shows the expected UI-only label, the expanded top-level navigation assertions have drifted from the current Fixed Assets/Maintenance/Reports groups, and the Fixed Assets breadcrumb assertion has drifted from the current hierarchy. This reinforces that the expanded shell is both memory-heavy and out of sync with its own acceptance contract.

During final verification, unrelated uncommitted application/test edits appeared in the shared worktree after the audit began. They were not touched by this audit; the two named deliverables remain the only files created by it.

## 3. Context Drift

Actual code and the current database are newer and more trustworthy than `CODEX_PROJECT_CONTEXT.md` for the following items.

| Context says | Actual code/database says | Trust / later correction |
|---|---|---|
| Root is `/mnt/Data/ShortCoded/Projects/EgyptianFurniture/ERP` | Root is `/mnt/Me/MB/Projects/ShortCoded/MgyPack/ERP` | Actual environment. Rewrite identity after architecture approval. |
| Implemented modules are Accounting, Auth, Core, Finance, HR only | Sales, Purchases, Inventory, Production, and FixedAssets module directories now exist with migrations/models/controllers/services | Actual code. Replace old module inventory with per-domain maturity states. |
| Sales, Purchasing, Inventory, Production, and Assets are not real modules | Customer/quotation, supplier/PO/PI, opening/receipt inventory, production identifiers, and asset register are persisted | Actual code, but do not overstate them as end-to-end workflows. |
| 778 non-vendor routes, no duplicate method/URI routes | 3,985 non-vendor routes; 2,793 are shell routes; one Quick Tasks method/URI duplicate exists | Current route list. |
| Five current menu files | 12 menu files are scanned by permission sync | Current `MenuConfigFileOrder`/dry run. |
| Opening Balances do not generate journal entries | Approval uses `OpeningBalanceApprovalService`/`JournalEntryService` and stores `journal_entry_id` | Current service code. |
| Online seat-limit work is pending/untracked | Both files are tracked, integrated in login/unlock controllers, and covered by Presence tests | Current Git and code. |
| Finance/Core/HR package and feature counts reflect the May baseline | Laravel/Livewire/Boost and domain code have advanced; database has 187 migration records vs 153 current files | Current lock/code/database. |
| No shell architecture is canonicalized | 524 shell resources, 52 compatibility aliases, 6,336 permissions, and 2,793 routes now exist | Current registry/route code; document as backlog infrastructure only. |
| Database/code alignment is not flagged | 42 domain tables have no exact current code/migration ownership | Current database plus targeted code search. Add an explicit schema-ownership warning. |

Do not rewrite the context until the screen disposition, shell isolation strategy, permission source, and orphan-table ownership decision are approved.

## 4. Business Requirements Reconciliation

| Required capability | Current state | Verdict |
|---|---|---|
| Product/item classifications and units | Real Product/ItemLookup/Unit foundations and conversions | Partial reusable foundation |
| BOM/formula | Real unversioned `product_components`, including direct/percentage calculation | Partial; no effective dates, approval, version, or Work Order snapshot |
| Packaging definition | Product ↔ packaging-material pivot only | Insufficient; no quantities, hierarchy, bags/cartons rules, or snapshot |
| Machine/mold/product compatibility | Shell metadata only | Missing |
| Sales Order | Orphan empty tables plus shell; no current model/controller/service/migration | Missing |
| Sales Order split/partial acceptance/delivery | No current workflow | Missing |
| Work Order | Orphan empty production tables plus shell | Missing |
| Reservation/customer-specific stock | Shell only; no ledger/reservation tables in current code | Missing |
| Material issue/extra issue/return | Shell only | Missing |
| Progressive shift/machine output | Shell only | Missing |
| Incoming/in-process/final/stock QC | Shell plus unowned residue tables | Missing |
| Quality hold/release/reject | Shell resources incorrectly modeled as CRUDs | Missing; must be dispositions/events |
| Finished Goods Receipt | Shell only | Missing |
| Delivery | Shell only | Missing |
| Sales Invoice | Orphan empty tables plus shell | Missing |
| Customer collection/allocation | Generic cash receipt foundation only; no real customer allocation document | Partial finance infrastructure, not order-to-cash |
| Purchase Order | Real | Partial; not connected downstream |
| Goods Receipt / incoming QC | Unpriced receipt is real but not PO-linked; canonical Goods Receipt missing | Partial/incompatible |
| Purchase Invoice | Real and posts journal entry | Partial; not PO/GR-linked |
| Customer credit limits | Credit-limit records exist | Partial; no order/delivery enforcement or audited override |
| Document control/reopen | Three document types only | Partial; not a system-wide operational contract |
| Maintenance | Shell only | Missing |
| HR | Broad foundation | Real but not a blocker for manufacturing slice |
| Tax/e-invoice | No stable sales-invoice lifecycle | Correctly deferred |

The current code cannot answer the required traceability questions across the business chain. It can identify source only in selected accounting postings (`source_type`, `source_id`, `source_doc_num`), not across sales, procurement, inventory, production, quality, or delivery.

## 5. Existing Real vs Shell Architecture

### Real screen catalog

`config/erp_expanded_screens.php` contains 127 compatibility/catalog entries:

- 75 entries marked `live` resolve to real controllers. These include platform screens, 25 HR screens, 10 item-data screens, Finance documents, customer/quotation/project setup, supplier/PO/PI, three inventory opening/receipt screens, production identifier types/identifiers, and Fixed Asset Register.
- 52 entries marked `placeholder` all resolve to `ErpUiShellController@index` aliases.

The 75 count is therefore a catalog count, not 75 completed Plastic ERP business workflows.

### UI Shell mechanics

For every one of the 524 registry screens:

- `index` renders a generic Blade definition.
- `data` always returns `recordsTotal = 0`, `recordsFiltered = 0`, and `data = []`.
- create/view/edit/clone routes are GET-only visual modes.
- there are no shell store/update/delete/approve/post endpoints.
- the default blueprint assigns generic columns, fields, tabs, statuses, actions, and permissions based on profile rather than domain invariants.
- route registration skips a name collision but does not merge shell metadata with real persistence.

All 524 CSV rows therefore have current status `UI_SHELL_ONLY_EMPTY_DATASET`.

### Disposition result

| Classification | Count | Architectural meaning |
|---|---:|---|
| `KEEP_STANDALONE` | 60 | Legitimate independent target responsibility |
| `REWORK` | 16 | Valid concept; generic shell shape is wrong |
| `MERGE_INTO_PARENT` | 166 | Child/tab/repeater/configuration, not menu CRUD |
| `WORKFLOW_ACTION` | 42 | Audited transition/action, not resource CRUD |
| `HISTORY_OR_INQUIRY` | 109 | Derived/read-only; no authoritative persistence |
| `FUTURE_BACKLOG` | 69 | Plausible later capability, not confirmed immediate scope |
| `REMOVE_FROM_PLASTIC` | 9 | Unsupported project/contract legacy |
| `DUPLICATE` | 53 | Responsibility already belongs to another target |

The detailed, bilingual, machine-readable decision for every resource is in `PLASTIC_ERP_SCREEN_DISPOSITION.csv`.

## 6. UI Shell Over-Design Findings

| Module | Over-normalization proved by metadata | Correct collapse |
|---|---|---|
| Product Data | Specifications, technical properties, units, barcodes, images, documents, BOM, packaging, stock policies each became screens | Product Master tabs plus versioned BOM/Packaging definitions; compatibility matrix only where operationally required |
| Sales | Order lines/specifications/payment schedule/delivery schedule/status history/approval became CRUDs | One Sales Order workspace with child collections, history, and transitions |
| Purchases | PR/quotation/PO/receipt/invoice/return lines and approvals became CRUDs | Parent documents with child lines; approval/award/post as actions |
| Inventory | Four transfer types, adjustment-in/out, batch receipt/issue, packaging/production issues, and stock-state screens duplicate movement semantics | One source-linked movement ledger with typed documents and derived stock views |
| Production | Work Order lines/materials/operations/specifications/attachments and seven lifecycle stages became independent resources | Work Order aggregate plus execution entries and audited state machine |
| Quality | Six inspection contexts became resources; result lines became screens; hold/release/reject became CRUDs | One inspection transaction parameterized by context/type; dispositions as actions/events |
| Maintenance | Four request types, Work Order child tables, daily/weekly/monthly schedules, completion/approval/closing became screens | Typed request, Work Order aggregate, preventive calendar views, actions, history |
| Costing | Material/labor/machine/scrap/waste/packaging/delivery costs became separate documents | Cost ledger/components feeding cost-sheet and variance inquiries |
| Finance | Reconciliation lines and receipt/payment allocations became resources; close/approval concepts became screens | Parent documents with child allocations and workflow actions |
| Fixed Assets | Documents, insurance, depreciation setup, maintenance schedule, and inspection became parallel CRUDs | Fixed Asset aggregate plus lifecycle transactions; maintenance owned by Maintenance |
| HR | Employee documents/contracts/medical/insurance and approval stages became screens | Restricted Employee tabs plus HR request/payroll workflows |
| Tools/Core | Settings were fragmented into dozens of screens | Coherent settings sections and review/inquiry tools |

The strongest anti-pattern is the conversion of state transitions into nouns. `Work Order Start`, `Quality Release`, and `Maintenance Closing` are not business masters; they are authenticated, reasoned, timestamped transitions on a source document.

## 7. Plastic ERP Legacy/Furniture Leakage

The following are **Candidate Plastic ERP Legacy Leakage**. No deletion is authorized.

1. **Repository/context identity** — the canonical context still points to `EgyptianFurniture`, while the working repository is now `MgyPack/ERP` and the business target is Plastic Factory ERP.
2. **Real project quotation structures** — persisted `project_structures`, `project_structure_models`, quotation `project_name`, execution schedule phases, milestones, and project quotation type reflect a project/contract sales model. Standard product quotations are reusable; project structures require a keep/remove business decision.
3. **Shell project/contract allocation** — `production_project_allocation`, `production_contract_identifier_allocation`, sales contracts/milestones/schedules/terms, contract profitability, and contract-status report are unsupported by the confirmed Plastic Factory flows. They are marked `REMOVE_FROM_PLASTIC`.
4. **Orphan project tables** — `project_accommodations`, `project_accommodation_nodes`, `sales_projects`, and `sales_project_payment_stages` remain in the database without current code ownership.
5. **Production identifiers** — a generic hierarchy is implemented, while shell aliases point to contract identifier allocation. It may be reusable only if the business defines a Plastic-specific traceability purpose; it is not a Work Order foundation.
6. **Station terminology residue** — the branch type was migrated from `station` to `factory`, but request payloads and methods still use `station_halls` names.
7. **Refrigerator/cold-capacity structures** — `branch_refrigerators` and capacities are real Core structures but not supported by the supplied Plastic Factory requirements. Treat as candidate legacy, not confirmed removal.
8. **Product decals and some quotation execution fields** — potentially reusable for printed/customer-specific packaging, but not confirmed as standalone masters. Keep as candidate product fields until business validation.

Generic ERP functions such as companies, branches, users, roles, finance, accounting, archive, tasks, calendar, chat, suppliers, customers, and fixed assets are reusable infrastructure and are not legacy merely because they predate the Plastic conversion.

## 8. Canonical Target Module Map

| Target module | Responsibility | Current reuse |
|---|---|---|
| Platform/Auth | Identity, roles, audit, screen visibility, sessions | Strong real foundation |
| Core/Organization | Companies, branches, factories, stores, periods, document identity, settings | Strong/partial real foundation |
| Product & Manufacturing Master | Items, classifications, UOM, conversions, versioned BOM/formula, packaging, machine/mold/product compatibility | Product/UOM/components real; versions/compatibility missing |
| Sales | Customers/agreements, quotations, Sales Orders, returns, delivery, invoices, credit enforcement | Customer/quotation partial only |
| Purchasing | Suppliers, requisitions/RFQ, Purchase Orders, Goods Receipts, purchase invoices/returns | Supplier/PO/PI partial |
| Inventory | Warehouses/locations, immutable movements, reservations, lot/status position, counts, transfers, adjustments | Opening/receipt documents only |
| Production | Plans, Work Orders, requirements, execution entries, outputs, external manufacturing | Identifier lookup only |
| Quality | Plans/specifications, inspections/results/evidence, disposition, NCR/CAPA | Missing |
| Maintenance | Machines/molds operational lifecycle, requests, Work Orders, PM plans, spares, downtime/history | Missing; asset register reusable |
| Delivery/Logistics | Delivery notes/schedules/confirmation and stock issue coordination | Missing; keep under Sales initially unless complexity proves a module |
| Finance/Treasury | Receipts/payments, allocations, cash/bank/cheques/transfers | Strong partial foundation |
| Accounting | Journals/posting, ledgers, periods, statements | COA/cost center/journal service partial |
| Costing | Cost ledger, rates, allocation, product/WO cost and variances | Missing |
| Fixed Assets | Asset register and accounting lifecycle | Register real |
| HR | Workforce, attendance, payroll | Broad partial foundation; later dependency |
| Reports | Read models/inquiries over authoritative modules | Mixed real/shell |

## 9. Canonical Target Screen Map

The primary navigation should expose aggregates and workspaces, not their child tables or lifecycle verbs. Report leaves may be accessible through report hubs without owning persistence.

### Master Data

| Module / screen | Responsibility, children, actions, results | Current |
|---|---|---|
| Core / Companies | Legal/operating company and policy scope | Real |
| Core / Branches, Factories & Stores | Branch/factory/warehouse hierarchy; locations as children | Partial real |
| Core / Financial Periods | Date/status/posting scope | Real |
| Product / Products & Materials | One item master; technical, packaging, quality, inventory-policy, attachment tabs | Partial real |
| Product / Units & Conversions | UOM and controlled conversion graph | Partial real |
| Product / BOM & Formula Versions | Components, percentages, packaging, effective dates, approval; result is immutable version snapshot source | Rework/missing |
| Manufacturing / Machines | Machine capability, factory/location, status, maintenance link | Missing |
| Manufacturing / Molds | Mold products/cavities/status/location/lifecycle | Missing |
| Manufacturing / Compatibility Matrix | Machine ↔ mold ↔ product validity | Missing |
| Manufacturing / Work Centers, Lines & Shifts | Capacity and execution assignment | Partial lookup only |
| Sales / Customers & Commercial Agreement | Contacts, addresses, credit, terms, customer-specific materials/specifications | Customer/credit partial |
| Purchasing / Suppliers & Commercial Agreement | Contacts, terms, catalog/quality history | Supplier partial |
| Quality / Inspection Setup | Types, characteristics, methods, sampling, evidence rules | Missing |
| Maintenance / Maintenance Setup | Priorities, failures, teams, PM rules | Missing |
| Accounting / Chart of Accounts & Cost Centers | Financial classification and posting targets | Real |
| Finance / Banks, Bank Accounts & Cashboxes | Treasury masters | Real |
| Fixed Assets / Asset Register | Asset identity/location/depreciation basis | Real |

### Transactions

| Module / screen | Responsibility, sources, children/actions, results | Current |
|---|---|---|
| Sales / Quotations | Customer offer with revisions/lines/terms; accept/reject; may source Sales Order later | Real but project leakage needs rework |
| Sales / Sales Orders | Source quotation/manual request; lines, specs, schedules, terms, audit; approve/partially accept/cancel; results Work Orders/Deliveries | Missing |
| Purchasing / Purchase Requisitions | Need lines and approvals; result RFQ/PO | Missing |
| Purchasing / RFQs & Supplier Quotations | Supplier responses and comparison; award action results PO | Missing |
| Purchasing / Purchase Orders | Supplier/order lines/schedule; approve/change/close; result Goods Receipts | Real but disconnected |
| Purchasing / Goods Receipts | PO or approved direct receipt; lines/lots; submit to QC; result held/accepted/rejected inventory movement | Missing; unpriced receipt is not sufficient |
| Purchasing / Purchase Invoices | Source accepted receipts/PO; lines/schedules; approve/post; result AP journal/payment obligation | Real but disconnected |
| Purchasing / Purchase Returns | Source receipt/quality decision; issue stock; result supplier debit/credit | Missing |
| Inventory / Reservations | Source SO/WO and customer allocation; reserve/release/shortage | Missing |
| Inventory / Material Issues & Returns | Source WO/request; normal/extra issue and unused return; result immutable movements | Missing |
| Inventory / Transfers & Adjustments | Controlled source/destination/count/reason; result movements | Missing |
| Inventory / Stock Counts | Plan, sheets, entries, differences; approval results adjustment | Missing |
| Production / Work Orders | Sales Order/plan source; snapshots, assignments, requirements, execution, status, downstream graph | Missing |
| Production / Production Output | WO/shift/machine entries, good/scrap/waste/rework quantities; result QC/FG receipt | Missing |
| Inventory / Finished Goods Receipts | Accepted output, lot/status/store; result on-hand/held stock | Missing |
| Quality / Inspections | Incoming/in-process/final/stock/return context; samples/results/photos; disposition | Missing |
| Quality / NCR & CAPA | Source inspection/complaint; findings/actions/follow-up | Missing |
| Sales / Deliveries | SO schedule and available stock; partial delivery; result sales issue/customer confirmation | Missing |
| Sales / Sales Invoices | Source delivered quantities; result AR/tax/posting | Missing |
| Finance / Customer Receipts & Allocations | Source customer/payment; allocation to invoices | Generic cash voucher only |
| Sales / Sales Returns | Source delivery/invoice; route to QC; result disposition/movement/credit | Missing |
| Maintenance / Maintenance Requests | Typed request for machine/mold/tool | Missing |
| Maintenance / Maintenance Work Orders | Source request/PM; tasks/labor/spares/downtime/external work; complete/approve/close | Missing |
| Finance / Cash/Bank Vouchers, Cheques, Transfers | Treasury transactions and status transitions | Cash vouchers/cheques/transfers real; bank vouchers missing |
| Accounting / Journal Inquiry | Review system entries by source; manual journal UI only if separately approved | Service/schema only |

### Planning and Execution Workspaces

| Module / screen | Responsibility | Current |
|---|---|---|
| Production / Production Plan | Demand/SO planning by day/week/month | Missing |
| Production / MRP & Capacity Planning | Requirements, availability, machine/mold capacity, shortage proposals | Missing |
| Production / Work Order Planning | Split/link SO demand; select machine/mold/date/shift; release | Missing |
| Production / Shop-Floor Execution | Progressive shift/machine entries, downtime, mold events, actual consumption/output | Missing |
| Quality / Line Inspection Queue | Due hourly checks and evidence capture | Missing |
| Warehouse / Issue & Receipt Queue | Authorized source documents; quantity visibility separated from costs | Missing |
| Maintenance / PM Calendar | Generated due work and maintenance queue | Missing |

### Inquiries, Reports, Settings, and Tools

| Screen/hub | Responsibility | Persistence |
|---|---|---|
| Inventory Position & Stock Card | On-hand/reserved/available/status by item/store/lot/source | Read model only |
| Document Traceability | Upstream/downstream document graph and remaining quantities | Read model only |
| Production & Quality Inquiry | WO progress, requirements vs actual, QC, scrap/downtime | Read model only |
| Sales/Purchase/Finance/Cost/Maintenance Report Hubs | Domain reports over authoritative transactions | Read model only |
| ERP Settings | Consolidated organization, numbering, policies, integration, print/label tabs | Settings persistence, not dozens of CRUDs |
| Open Documents | Authorized correction workflow with reason, expiry, auto-close, and audit | Extend current real tool |
| File Manager / Import / Calendar / Board / Chat | Generic tools | Real reusable infrastructure |
| Permission & Menu Review | Read-only architecture/role diff before controlled sync | Missing but recommended |

## 10. Canonical Document Graph

Legend: `[D]` business document, `[A]` workflow action, `[M]` inventory movement, `[J]` accounting consequence, `[I]` history/inquiry.

### Order-to-Cash

```text
[D] Quotation (optional)
  -> [D] Sales Order
       -> [D] Work Order(s) / reservation requests
       -> [D] Delivery schedule
       -> [D] Delivery Note(s)
            -> [A] Confirm/ship
            -> [M] Sales delivery issue
            -> [D] Sales Invoice(s)
                 -> [J] AR / revenue / tax posting
                 -> [D] Customer Receipt
                      -> allocation child rows
                      -> [J] cash/bank and AR settlement
All nodes -> [I] source/downstream graph, requested/executed/remaining
```

### Procure-to-Stock

```text
[D] Purchase Requisition
  -> [D] RFQ -> [D] Supplier Quotations -> [A] Award
  -> [D] Purchase Order
       -> [D] Goods Receipt(s)
            -> [D] Incoming Quality Inspection
                 -> [A] accept / hold / reject
                 -> [M] receipt into released / quarantine / rejected status
            -> [D] Purchase Invoice
                 -> [J] inventory/expense, AP, tax
                 -> [D] Supplier Payment + allocation
                      -> [J] cash/bank and AP settlement
```

### Production

```text
[D] Sales Order and/or [D] Production Plan
  -> [D] Work Order
       -> immutable BOM/formula + packaging snapshots
       -> [D] Reservation
       -> [D] Material Request
            -> [D] Material Issue / Extra Issue -> [M] store to WIP
            -> [D] Material Return -> [M] WIP to store
       -> [A] approve -> release -> start -> pause/resume -> complete -> close
       -> execution child entries by shift/machine/mold
       -> [D] In-process QC
       -> [D] Production Output
            -> good / semi-finished / scrap / waste / rework children
            -> [D] Final QC
            -> [D] Finished Goods Receipt -> [M] WIP to FG status/store
       -> [I] planned/issued/consumed/produced/received/remaining
```

### Returns

```text
[D] Sales Return request (source Delivery/Invoice)
  -> [M] receipt into quarantine/return staging
  -> [D] Quality Inspection
       -> [A] disposition: restock / hold / sort / rework / scrap / reject
       -> [M] status/location movement matching the decision
       -> [D/J] credit/financial consequence where approved
```

### Maintenance

```text
[D] Maintenance Request or PM-generated due item
  -> [D] Maintenance Work Order
       -> task/labor/checklist/spare/downtime children
       -> [M] spare issue / return
       -> mold/machine lifecycle events
       -> [A] complete -> inspect/approve -> close
       -> [J] cost posting when costing/accounting boundary is enabled
       -> [I] asset/mold history and reliability reports
```

## 11. Sales Architecture

- Keep Customers and standard Quotations as reusable real foundations.
- Rework quotation project fields into an optional, explicitly approved extension; do not make project structures mandatory for normal plastic product sales.
- Sales Order must be a new aggregate, not an invoice alias. It needs lines, ordered/accepted/cancelled/delivered/invoiced quantities, customer/product/packaging/spec snapshots, delivery/payment schedules, credit decision, and a downstream document graph.
- Partial acceptance, split Work Orders, multiple deliveries, and multiple invoices must be modeled through allocations/quantities, not status names alone.
- Customer credit policy belongs to Customer Commercial Agreement. A blocked order/delivery requires an audited override action with permission and reason.
- Delivery owns fulfillment quantities; Sales Invoice consumes eligible delivered quantities. Direct invoice without delivery must be an explicit policy, not the default path.
- Customer receipt belongs to Finance/Treasury; allocation rows link receipt amounts to sales invoices. Sales should expose the result but not duplicate the receipt resource.

## 12. Purchasing Architecture

- Preserve real Suppliers, Purchase Orders, and Purchase Invoices, but do not call the cycle complete.
- Add Purchase Requisition/RFQ only to the level the business approves; RFQ is optional, not a mandatory obstacle to every PO.
- Canonical Goods Receipt must link PO/PO lines (or an approved direct-receipt reason), track received/accepted/rejected/remaining quantities, lot/status/store, and hide commercial prices from warehouse roles.
- Incoming Quality Inspection must be a Quality transaction sourced by the receipt, not a purchase child CRUD or a manual status flag.
- Purchase Invoice lines must allocate to accepted Goods Receipt/PO quantities. The current direct product-line invoice can remain only as a controlled exception policy.
- The current `received_quantity` fields on Purchase Order lines are dormant; update them from receipt allocations, never through PO editing.
- Supplier payments/allocations belong to Finance. Purchase screens should show linked settlement state without duplicating the payment document.

## 13. Inventory Architecture

Current inventory cannot calculate authoritative on-hand, reserved, available, quarantine, released, damaged, scrap, or customer-specific balances. Approving current opening/receipt documents changes document status but does not create a stock ledger.

The missing foundation is:

1. immutable inventory transaction header and movement lines;
2. company/factory/store/location and product/UOM dimensions;
3. optional lot/batch and stock-status dimensions;
4. source document type/public reference and source line reference;
5. movement type/direction and base quantity with conversion snapshot;
6. reservation ledger/allocation tied to SO/WO/customer;
7. reversal/correction links rather than silent mutation;
8. cost visibility separated from quantity authorization;
9. read models for on-hand, reserved, available, and status stock.

Opening Stock and Unpriced Receipt should eventually post through the same movement engine. Product `reorder_point` is planning data, not an authoritative quantity. No product quantity column should be introduced as the stock source of truth.

Lot strategy is a blocker: resin, masterbatch, printed customer material, WIP, and finished products may have different lot requirements. A lot should be generated/received through a transaction, not maintained as an independent arbitrary CRUD master.

## 14. Production / Work Order Architecture

### Recommended Work Order aggregate

**Header/snapshots**

- public document identity; company, factory, planning date, priority, source Sales Order/line or Production Plan;
- finished/semi-finished product, target quantity and UOM;
- selected machine, mold, work center/line, shift plan;
- compatibility decision/snapshot;
- approved BOM/formula version snapshot and packaging-definition snapshot;
- planned start/end, actual start/end, status, close/cancel reason;
- requested, reserved, issued, consumed, returned, produced, FG-received, scrap/waste/rework, and remaining derived quantities.

**Child records/tabs**

- source allocations (one SO line may feed WOs; a WO may have approved demand allocations);
- material/packaging requirements snapshot;
- operations/routing snapshot only where needed;
- reservation and shortage view;
- material request/issue/extra issue/return links;
- shift/machine production entries;
- output entries with good/scrap/waste/rework quantities;
- QC inspections/results/evidence;
- downtime/mold-change/employee/checklist events;
- attachments, comments, status history, audit, downstream documents.

**Workflow actions**

Draft → approve → release → start → pause/resume → complete → close, with cancel where allowed. Exact transition guards must be approved; lifecycle actions in the shell are not resources.

**Separate masters**

Products, BOM/formula versions, packaging definitions, machines, molds, compatibility, work centers/lines, shifts/calendars, inspection plans, reason codes.

**Separate transactions/reports**

Reservations, Material Issue/Return, Production Output, QC Inspection, Finished Goods Receipt, and read-only requirements/progress/variance/history reports.

The current Production Identifier hierarchy is not a substitute for Work Order identity. Retain it only after a Plastic-specific use is confirmed.

## 15. Quality Architecture

- Keep setup as Inspection Types, characteristics/methods, and versioned Inspection Plans; sampling is plan detail.
- Use one Quality Inspection aggregate with context: incoming receipt, in-process WO/shift/hour, final output, existing stock, sales return, supplier complaint, or customer complaint.
- Results, samples, actual values, defects, photos, and signatures are children. Server timestamps, uploader identity, file hash/metadata, and audit must be authoritative.
- `Quality Hold`, `Quality Release`, and `Quality Rejection` are disposition actions/events. They update stock status only through controlled inventory movements/status allocations.
- NCR/CAPA is a separate aggregate when a failed inspection/complaint requires investigation and actions; NCR lines/corrective/preventive/follow-up are children.
- Customer/Supplier Quality Complaint should link the Sales/Purchase complaint to Inspection/NCR rather than create parallel complaint truth.
- Quality history and inspection-result reports are read models.

## 16. Maintenance Architecture

- Machine and Mold are canonical operational masters shared by Production and Maintenance; do not maintain duplicate registers.
- Spare Parts are Products/items with inventory behavior. Tool records may be assets or controlled items depending on capitalization/traceability.
- Maintenance Request is one typed document: preventive, corrective, breakdown, or emergency.
- Maintenance Work Order owns tasks, labor, checklist, spares, downtime, external-service details, attachments, completion evidence, and cost links.
- PM Plan owns interval/meter rules; daily/weekly/monthly schedules are views of generated due work.
- Completion/approval/closing are actions. External maintenance is a Work Order execution mode, not a separate parallel lifecycle.
- Mold lifecycle events—requested, moved, mounted, running, removed, inspected, cleaned, dried, preserved, repaired, stored—belong to the Mold/Work Order event history. Only movements that transfer custody/location or consume inventory require separate resulting transactions.
- Machine/mold/tool inspection and meter readings are Work Order/asset events or a consolidated maintenance inspection transaction, not three independent CRUD stacks.

## 17. Costing / Accounting Boundary

- Inventory quantity truth must exist before production costing.
- Costing consumes immutable inventory movements, labor/time entries, machine/downtime rates, external services, scrap/waste disposition, and overhead rules.
- Product standard cost should be versioned and composed of material/labor/overhead components. The individual shell cost screens are components or inquiries, not separate authoritative documents.
- Work Order estimated/actual cost and variance are derived from snapshots and actual ledgers. They should not be manually editable cost-sheet truth.
- Accounting posting must occur at explicit business boundaries: accepted/posted receipt, supplier invoice approval, FG receipt/cost recognition policy, delivery/invoice, receipt/payment, inventory adjustment, and cost close as approved.
- The existing JournalEntry service proves source-linked system entries for Opening Balance and Purchase Invoice. Extend a single posting contract later; do not let each module hand-roll journals.
- Cost visibility must be permission-separated from warehouse quantity operations.

## 18. Permissions Reconciliation

### Actual sources

`PermissionRegistryService::all()` merges:

1. permissions extracted from 12 `config/menu/*.php` files;
2. 6,336 shell-generated permissions;
3. 52 legacy placeholder permissions;
4. canonical mappings for selected legacy names.

`config/permissions.php` is empty/comment-only. `PermissionSeeder` creates discovered permissions, keeps stale database permissions, and syncs all discovered permissions to admin. `erp:permissions:sync` can prune, but also calls `syncPermissions` for admin during normal execution unless `--skip-admin-sync` is used.

### Measured risk

- Discovered unique permissions: 7,094.
- Current matching database permissions: 708.
- Permissions a real sync would create: 6,386.
- Stale database permissions: 172.
- Shell permissions dominate the registry and encode 524 speculative CRUD surfaces.
- Six route abilities are missing from the registry: `quick_tasks.change_status`, `quick_tasks.create`, `quick_tasks.delete`, `quick_tasks.restore`, `quick_tasks.update`, and `quick_tasks.view`.
- The dry run warns that normal sync without `--skip-admin-sync` would remove 172 permissions from admin even without `--prune`.

### Safe next permission step after screen approval

1. Export/back up `permissions`, role/user permission pivots, and current admin grants.
2. Approve the canonical screen/action matrix and decide whether shell permissions are excluded from operational discovery.
3. Add every approved runtime route ability—starting with the six Quick Tasks abilities—to an explicit source of truth and add a route-vs-registry test.
4. Generate a reviewed permission diff by module/resource/action.
5. Run only a dry run with stale/created/admin diff output and `--skip-admin-sync`.
6. Create missing approved permissions without prune and without admin sync.
7. Reconcile admin/role assignments explicitly after review.
8. Prune only in a later authorized migration/task with backup, mapping, and rollback. Do not run `--prune` during shell reconciliation.

## 19. Golden Scenario A Validation — Injection

### Current system

The scenario fails after the quotation/product master:

- Product and an unversioned component formula can be entered.
- Packaging can only be related, not quantity-defined/snapshotted.
- No Sales Order, Work Order, compatibility, reservation, issue, progressive execution, hourly QC, output, FG receipt, delivery, invoice, or collection allocation workflow exists.
- The system cannot record shift 1 = 10 cartons and shift 2 = 20 cartons against a Work Order, derive completed = 30 and remaining = 70, or trace partial FG receipts.

### Recommended architecture

The target passes when the SO line allocates 100 cartons to one or more WOs; each WO snapshots product/BOM/packaging/machine/mold; material documents post movements; shift output children accumulate 10 + 20; QC links by WO/shift/time; FG receipts consume accepted output; the SO graph shows produced/received/delivered/invoiced/remaining quantities.

## 20. Golden Scenario B Validation — Cover / Assembly

### Current system

The scenario fails despite Product Components supporting several component products:

- No versioned 7-in-1 BOM/packaging snapshot.
- No customer/order-specific reservation dimension.
- No issue/extra issue/unused return movement.
- No actual-vs-standard consumption or progressive output.

### Recommended architecture

The target is manufacturing-style neutral: the Work Order snapshots the assembly formula and packaging, reservation allocations carry customer/SO ownership, issue/extra issue/return documents post movements, actual consumption derives from movements, output/QC/FG receipt remain the same document types used by injection. Machine/mold fields can be nullable or governed by routing/work-center requirements for assembly WOs; they must not define the Work Order identity.

## 21. Architectural Blockers

The following decisions must be resolved before transactional implementation:

1. **Schema ownership:** preserve/reuse/migrate/archive the 42 unowned tables and 36 residue rows; never build silently on them.
2. **Shell runtime role:** backlog catalog only versus operational surface. Recommendation: catalog only, excluded from runtime routes/menu/permissions.
3. **Shell acceptance/performance:** the focused shell feature suite exceeds 128 MB through the Artisan runner and has three contract failures even at 512 MB.
4. **Permission source:** approved screens/actions plus runtime-only abilities; exact safe admin/prune procedure.
5. **Operating scope:** company, branch, factory, store/location, and financial-period requirements for each document and movement.
6. **Warehouse model:** reconcile Branch `warehouse/factory`, `branch_stores`, halls/locations, and candidate refrigerator legacy.
7. **Inventory ledger:** immutable movement/reversal design, negative-stock policy, UOM/base quantity, source-line identity.
8. **Reservation:** allocation granularity for customer/SO/WO and available-stock calculation.
9. **Lot/batch and stock status:** which item classes require lots; quarantine/released/scrap states and transfers between them.
10. **Product/BOM/packaging versioning:** effective dates, approval, formula percentages, nested/semi-finished behavior, Work Order snapshot.
11. **Work Order identity/lifecycle:** source allocation, split rules, manufacture style, closure guards, partial output/FG receipts.
12. **Machine/mold ownership:** canonical module/master and compatibility rules; mold lifecycle events.
13. **Quality disposition:** inspection contexts, evidence, inventory status effects, NCR/CAPA boundary.
14. **Document correction:** generalized lock/open/edit/auto-close contract, reasons, expiry, audit; current tool covers only three documents.
15. **Purchasing source chain:** direct-receipt/direct-invoice exceptions and PO/GR/QC/PI allocations.
16. **Sales credit/delivery/invoice policy:** enforcement points and audited override.
17. **Posting boundary:** which approvals post, reversal model, period locks, subledger-to-GL contract.
18. **Project/contract legacy:** retain only explicitly approved generic capability; remove from Plastic navigation otherwise.

## 22. Recommended Vertical Implementation Order

1. Approve/freeze this screen, document, module, and permission architecture.
2. Isolate the UI Shell as a requirements catalog and reconcile the canonical context/catalog/permission registry—without deleting metadata or pruning permissions.
3. Decide and document ownership/migration strategy for the 42 residue tables.
4. Finalize operating scope and factory/store/location model.
5. Complete only the manufacturing masters needed by transactions: Product, UOM, versioned BOM/formula, packaging, machine, mold, compatibility, work centers/shifts.
6. Implement the inventory movement/reservation/status foundation and route current opening/receipt documents through it.
7. Connect the existing Purchase Order to canonical Goods Receipt → incoming QC → inventory → Purchase Invoice; preserve price visibility controls.
8. Implement Sales Order with credit policy, schedules, source/downstream allocations, and partial quantities.
9. Implement Sales Order/Plan → Work Order planning and snapshotting.
10. Implement material reservation/request/issue/extra issue/return.
11. Implement production execution, progressive output, downtime/scrap/waste/rework.
12. Implement in-process/final Quality and disposition.
13. Implement Finished Goods Receipt, Delivery, Sales Invoice, and Customer Receipt allocation.
14. Implement Sales/Purchase Returns and quarantine/disposition.
15. Implement Maintenance Request/Work Order/PM/mold lifecycle.
16. Implement costing and extend the controlled accounting posting contract.
17. Complete HR/attendance/payroll only as required for labor/time/payroll dependencies.
18. Implement Egyptian tax/e-invoice after Sales Invoice lifecycle stability.
19. Add advanced reporting, performance validation, reconciliation, migration rehearsal, and go-live controls.

This order moves schema-residue and scope decisions ahead of coding, and places the inventory ledger before connecting the already-real Purchase Order/Invoice. That prevents a second set of disconnected quantity documents.

## 23. Exact Next Task After This Audit

**Next task: Plastic ERP Architecture Freeze and UI Shell Isolation — no business workflow implementation.**

Its reviewed scope should be:

1. approve/amend `PLASTIC_ERP_SCREEN_DISPOSITION.csv`;
2. define the canonical module/screen/document/action registry from this report;
3. keep the 524 definitions accessible as a developer/admin requirements catalog, but remove them from operational menu, route, and permission generation without deleting their metadata;
4. produce a schema-ownership map and preservation plan for the 42 residue tables;
5. reconcile the 75 real catalog entries and generic infrastructure with the approved target map;
6. repair the permission source contract and Quick Tasks gap using dry-run/no-prune safeguards;
7. then rewrite `CODEX_PROJECT_CONTEXT.md` and the Screen Catalog to the approved reality.

Do not combine that task with Work Order, inventory ledger, or other manufacturing implementation.

## 24. Files/Commands Inspected

### Files/families

- `CODEX_PROJECT_CONTEXT.md` and project `AGENTS.md`.
- `.opencode/skills/laravel-erp-review/SKILL.md`.
- `modules/*` directory/file inventory, focused on Routes, Models, Services, Controllers, Migrations, and relevant tests.
- `modules/Core/Services/ErpUi/{ErpUiScreenRegistry,ErpUiScreenDefinition,ErpUiScreenBlueprints}.php`.
- `modules/Core/Http/Controllers/ErpUiShellController.php`, shell middleware/routes/views.
- `config/erp_ui_screens/*.php`, `config/erp_expanded_screens.php`, `config/erp_ui_screen_aliases.php`.
- `config/menu/*.php`, `MenuService`, `MenuConfigFileOrder`.
- `PermissionRegistryService`, `PermissionSeeder`, `SyncErpPermissionsCommand` and permission/shell tests.
- Key Product/BOM/packaging, Customer/Quotation, Supplier/PO/PI, Inventory opening/receipt, Production Identifier, Finance/Journal, Open Documents, operating-context files.
- Current database migration status and schema table inventory.

### Read-only/safe commands

- `git status --short`, `git branch --show-current`, `git ls-files`.
- Targeted `rg --files`, `rg -n`, `sed`, and file counts; vendor/assets/archives/backups were excluded.
- `php artisan route:list --except-vendor --json` and targeted route filters.
- `php artisan migrate:status`.
- Read-only Tinker registry, menu, route-middleware, schema/table, and row-count queries.
- `php artisan erp:permissions:sync --dry-run --skip-admin-sync` only; no sync and no prune.
- Targeted package-version reads with `composer show`.
- Targeted `ErpUiShellTest.php`: the Artisan runner exhausted 128 MB; direct Pest at 512 MB completed with 9 passed and 3 failed tests.

No migrations, seeders, dependency changes, browser actions, permission writes/prune, application refactors, or full test suite were run.
