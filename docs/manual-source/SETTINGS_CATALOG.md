# Settings Catalog — MgyPack ERP

> Auto-generated reference from code evidence. Covers every admin-accessible
> configuration surface in the system.

---

## § 33 — All Settings by Area

### 33.1 General Application

| # | Setting key | Default / Source | Description | Admin UI |
|---|-------------|------------------|-------------|----------|
| 1 | `app.name` | config | Application display name shown in browser tab, emails, PDFs | Yes (env) |
| 2 | `app.timezone` | `Africa/Cairo` | Default timezone for all date/time operations | Yes (env) |
| 3 | `date_format` | `d/m/Y` | User-facing date display pattern | Yes (settings) |
| 4 | `date_time_format` | `d/m/Y h:i A` | User-facing date+time display pattern | Yes (settings) |
| 5 | `erp.phase` | `expanded` | System phase: `legacy` or `expanded`; gates module visibility | Config only |
| 6 | `erp_features.visibility-rules` | `true` | Show/hide UI elements based on permissions | Config/menu |
| 7 | `erp_features.fixed-assets-CRUD` | `true` | Enable Fixed Assets module CRUD | Config/menu |
| 8 | `erp_features.sales-CRUD` | `true` | Enable Sales module CRUD | Config/menu |
| 9 | `erp_seats` | `10` | Maximum concurrent seat licenses | Config only |
| 10 | `languages.default` | `ar` | Default application language | Config |
| 11 | `languages.available` | `en`, `ar` | Enabled UI languages | Config |

### 33.2 Printing & Company Identity

| # | Setting key | Default / Source | Description | Admin UI |
|---|-------------|------------------|-------------|----------|
| 12 | CompanyPrintIdentityService → `name` | DB | Legal company name for printouts | Yes |
| 13 | CompanyPrintIdentityService → `legal_name` | DB | Legal entity name (Arabic/English) | Yes |
| 14 | CompanyPrintIdentityService → `logo` | DB | Company logo file for headers/footers | Yes |
| 15 | CompanyPrintIdentityService → `commercial_registration` | DB | CR number for invoices/contracts | Yes |
| 16 | CompanyPrintIdentityService → `tax_number` | DB | Tax registration number | Yes |
| 17 | CompanyPrintIdentityService → `vat_number` | DB | VAT registration number | Yes |
| 18 | CompanyPrintIdentityService → `address` | DB | Registered address block | Yes |
| 19 | CompanyPrintIdentityService → `phone` | DB | Primary contact phone | Yes |
| 20 | CompanyPrintIdentityService → `email` | DB | Primary contact email | Yes |
| 21 | CompanyPrintIdentityService → `stamp` | DB | Digital stamp image for documents | Yes |
| 22 | CompanyPrintIdentityService → `signatory` | DB | Authorized signatory name | Yes |

### 33.3 E-Invoicing

| # | Setting key | Default / Source | Description | Admin UI |
|---|-------------|------------------|-------------|----------|
| 23 | e_invoice.* | Env variables | E-invoice integration configuration | Env only |

### 33.4 Product Settings

| # | Setting key | Default / Source | Description | Admin UI |
|---|-------------|------------------|-------------|----------|
| 24 | `products.image_required` | `false` | Whether product image is mandatory on creation | Settings |

### 33.5 Fixed Assets

| # | Setting key | Default / Source | Description | Admin UI |
|---|-------------|------------------|-------------|----------|
| 25 | `fixed_assets.depreciation.*` | Config | Depreciation method, rate, useful life defaults | Settings |

### 33.6 Purchase Account Codes

| # | Setting key | Default / Source | Description | Admin UI |
|---|-------------|------------------|-------------|----------|
| 26 | `purchases.account_codes.*` | Env variables | Default account codes for purchase journal entries | Env only |

### 33.7 Multi-Company

| # | Setting key | Default / Source | Description | Admin UI |
|---|-------------|------------------|-------------|----------|
| 27 | `companies.max` | `1` | Maximum number of companies allowed | Config only |

### 33.8 Localization

| # | Setting key | Default / Source | Description | Admin UI |
|---|-------------|------------------|-------------|----------|
| 28 | `locale.direction` | Per language | Text direction: LTR for `en`, RTL for `ar` | Auto from lang |
| 29 | Language default locale | `ar` | Default locale on first load | Config |

---

## § 30 — Login, Session & Security Settings

| # | Setting key / Source | Default | Description |
|---|----------------------|---------|-------------|
| 1 | LoginRequest → identifier rate limit | 5 attempts / identifier + IP | Block after 5 failed logins per credential+IP |
| 2 | LoginRequest → IP global rate limit | 20 attempts / IP | Block after 20 failed logins from any single IP |
| 3 | LoginRequest → account+IP rate limit | 5 attempts / account + IP | Block after 5 failures for same account+IP pair |
| 4 | Duplicate-session block | Enabled | Prevents same user from multiple active sessions |
| 5 | Inactive account block | Enabled | Blocks login for accounts marked inactive |
| 6 | Blocked account block | Enabled | Blocks login for accounts in blocked state |
| 7 | Deleted account block | Enabled | Blocks login for soft-deleted accounts |
| 8 | OnlineSeatLimitService | `erp_seats` (10) | Enforces maximum concurrent online users |
| 9 | LockScreenService | Session-based | Application lock screen after idle period |
| 10 | InactiveSessionService lifetime | `session.lifetime * 60` seconds | Auto-expire sessions after inactivity |
| 11 | `session.lifetime` | `120` minutes | Maximum session duration before forced re-auth |
| 12 | `session.expire_on_close` | `false` | Sessions persist across browser close |
| 13 | Auth routes | login, forgot-password, reset-password, logout, lock, profile | Authentication flow endpoints |
| 14 | Auth logging | Enabled | All auth events logged for audit trail |

---

## § 19 — Document Numbering Settings

All numbering governed by `config/document_numbers.php` with 100+ entity entries.

| # | Setting | Scope options | Pattern | Notes |
|---|---------|---------------|---------|-------|
| 1 | Per-entity numbering config | `none` / `company` / `company_period` | prefix + padding | Defined in `document_numbers.php` |
| 2 | Prefix | Entity-specific | e.g. `INV-`, `JV-`, `PO-` | Configurable per entity |
| 3 | Padding | Entity-specific | e.g. 5, 6, 7 digits | Zero-padded sequence |
| 4 | Reset strategy | Per scope | Annual (period) / cumulative (company) / none | Tied to scope setting |
| 5 | DocumentNumberService | Runtime | Generates next sequence number | Validates uniqueness |

**Scope meanings:**
- `none` — global sequential, never resets
- `company` — resets each financial year per company
- `company_period` — resets each financial period within a company

---

## § 35 — Screenshot & Documentation Plan

### Priority Order for Screenshots

| Priority | Screen / Area | Screenshots needed | Key states | Annotation notes | Reuse from |
|----------|---------------|--------------------|------------|------------------|------------|
| P1 | Login / Lock Screen | Y | Empty form, error state, locked state | Highlight rate limit messages, lock screen timer | — |
| P1 | Dashboard | Y | Default view, filtered by period | Annotate widget zones, quick-actions bar | — |
| P1 | Global UI Shell | Y | Sidebar collapsed/expanded, RTL/LTR, language switch | Annotate sidebar nav groups, breadcrumbs, user menu | — |
| P2 | Company / Branch Master | Y | Create form, list view, edit modal | Annotate required fields, parent-child link | — |
| P2 | Product Master | Y | Create (image required/disabled), list, variants | Annotate image upload zone, category tree | — |
| P2 | Customer / Supplier Master | Y | Create form, list, balance display | Annotate account-code auto-fill, credit limit field | — |
| P2 | Price List | Y | Active list, edit mode, print preview | Annotate currency column, effective-date range | — |
| P3 | Sales Order → Invoice cycle | Y | Draft, approved, posted, reversed | Annotate status badges, approve/post buttons, line totals | — |
| P3 | Purchase Order → Receipt cycle | Y | Draft, approved, posted, received | Annotate goods-receipt link, account-code default | — |
| P3 | Inventory (Stock / Transfers) | Y | Stock query, transfer create, transfer post | Annotate store selector, quantity validation | — |
| P4 | Production (Order / Machine) | Y | Work order create, machine assignment, completion | Annotate bill-of-materials display, cost-center link | Production screen |
| P4 | Quality Inspection | Y | Inspection create, pass/fail, report | Annotate inspection-type dropdown, linked work order | — |
| P4 | Maintenance (Plan / Breakdown) | Y | Plan schedule, breakdown log, order track | Annotate frequency picker, linked asset | — |
| P5 | Voucher (Journal Entry) | Y | Create, approve, post, reverse | Annotate debit/credit grid, account autocomplete, balance check | — |
| P5 | General Journal | Y | List, detail view, filter by type | Annotate type badges, period indicator | — |
| P5 | Fixed Asset Register | Y | Create asset, movement, depreciation run | Annotate useful-life field, depreciation method selector | — |
| P6 | Employee / HR (list) | Y | Employee list, detail, org-chart | Annotate status badges, department tree | — |
| P6 | Payroll | Y | Process run, payslip view, approval | Annotate calculation breakdown, approval workflow | — |
| P7 | Reports (Trial Balance / Ledger / Statements) | Y | Generate, filter, export | Annotate date-range picker, account-tree filter, export button | — |
| P7 | Cheques (Received / Issued) | Y | Register cheque, status transitions | Annotate status badges (pending/cashed/bounced), due-date | — |
| P8 | User Management | Y | Create user, assign roles, scope access | Annotate role multi-select, scope tree picker | — |
| P8 | Settings Page | Y | All sections, save confirmation | Annotate section tabs, required-field markers | — |
| — | Calendar / Boards / Chat | N | — | — | — |
| — | File Manager | N | — | — | — |

**Total screenshots planned: 24**
**Screens needing multiple states: 8** (login, dashboard, voucher, general journal, payroll, cheques, user management, settings)

---

## Footer Counts

| Category | Count |
|----------|-------|
| § 33 — Application Settings | 29 |
| § 30 — Login / Session / Security | 14 |
| § 19 — Numbering Settings | 5 (config surface) + 100+ entities |
| § 35 — Screenshots Planned | 24 screens |
| **Grand total documented settings** | **48 distinct setting surfaces** |
