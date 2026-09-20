# Documentation Gaps — Pre-Manual Audit

> Two-track inventory: what the manual **can document now** from existing code
> evidence, and what **must be rechecked** after HR full run / runtime validation.

---

## § 37-A — MANUAL CAN DOCUMENT NOW

These areas have stable, verifiable code evidence and can be written into the
manual without further runtime checks.

### Authentication & Security

| Item | Evidence source | Status |
|------|----------------|--------|
| Login flow (login form, forgot-password, reset-password) | Auth routes, LoginRequest | ✅ Ready |
| Rate limiting (5/identifier, 20/IP, 5/account+IP) | LoginRequest class | ✅ Ready |
| Duplicate-session blocking | DuplicateSessionService | ✅ Ready |
| Inactive/blocked/deleted account blocking | LoginRequest validation | ✅ Ready |
| Lock screen (LockScreenService) | Session-based | ✅ Ready |
| Session lifetime (120 min, expire_on_close=false) | config/session.php | ✅ Ready |
| Online seat limit (OnlineSeatLimitService) | erp_seats config | ✅ Ready |
| Auth logging | Auth event listeners | ✅ Ready |

### Settings & Configuration

| Item | Evidence source | Status |
|------|----------------|--------|
| Date format / date-time format | SettingService DB key-value | ✅ Ready |
| PWA settings (20+ keys) | SettingService | ✅ Ready |
| Company print identity (11 fields) | CompanyPrintIdentityService | ✅ Ready |
| Product image_required toggle | Settings | ✅ Ready |
| Fixed assets depreciation config | Settings | ✅ Ready |
| E-invoice env configuration | Env variables | ✅ Ready |
| Session config (lifetime, expire_on_close) | config/session.php | ✅ Ready |
| erp_seats max (config-only) | config | ✅ Ready |
| erp_features visibility toggles | config/menu | ✅ Ready |
| Language defaults (ar RTL, en LTR) | config | ✅ Ready |
| Timezone (Africa/Cairo) | config | ✅ Ready |
| Mail config (SMTP/log) | config/mail.php | ✅ Ready |
| companies.max (1) | config | ✅ Ready |

### Document Numbering

| Item | Evidence source | Status |
|------|----------------|--------|
| Numbering config (document_numbers.php) | Config file, 100+ entities | ✅ Ready |
| Scopes (none/company/company_period) | Config | ✅ Ready |
| Prefix + padding pattern | Config | ✅ Ready |
| DocumentNumberService generation | Service class | ✅ Ready |

### Permission & Scope Model

| Item | Evidence source | Status |
|------|----------------|--------|
| PermissionRegistryService (dynamic, 61 aliases) | Service + config/menu | ✅ Ready |
| Role assignment (multi-role, union) | Spatie permission tables | ✅ Ready |
| OperatingContextService (company/branch/period) | Session service | ✅ Ready |
| OperatingScopeAccessService (3 flags + pivots) | Service + DB pivots | ✅ Ready |
| Admin bypass | Service logic | ✅ Ready |
| Spatie teams disabled, wildcard disabled, 24h cache | Config | ✅ Ready |
| Menu groups (13 groups) | Menu definitions | ✅ Ready |

### Factory & Entity Hierarchy

| Item | Evidence source | Status |
|------|----------------|--------|
| Branch → Halls → Stores → Machines hierarchy | DB schema, models | ✅ Ready |
| Cost centers (branch-level) | DB schema | ✅ Ready |
| storage-location REMOVED | Git history | ✅ Ready |

### Standard UI Patterns

| Item | Evidence source | Status |
|------|----------------|--------|
| Login / Lock screen screens | Routes + views | ✅ Ready |
| Dashboard widget layout | Dashboard component | ✅ Ready |
| Sidebar navigation groups | Menu config | ✅ Ready |
| RTL/LTR toggle | Language config | ✅ Ready |

---

## § 37-B — RECHECK BEFORE FINAL MANUAL

These areas require runtime verification, screenshot confirmation, or HR
full-run evidence before inclusion in the published manual.

### ⚠️ RECHECK AFTER HR RUN

| Item | What to verify | Blocker |
|------|----------------|---------|
| Employee list display & filters | Actual column set, filter behavior, pagination | HR data population |
| Payroll process run | Step-by-step flow, calculation output, approval gate | Payroll cycle execution |
| Attendance/biometric import | Import format, mapping UI, error handling | Biometric data feed |
| Shift management | Shift create/edit, assignment to employees | Shift configuration |
| HR self-service portal | Employee-facing screens, request submission | HR module runtime |
| HR report generation | Available reports, filter options, export | HR data population |

### ⚠️ RECHECK — RUNTIME / UI VERIFICATION

| Item | What to verify | Blocker |
|------|----------------|---------|
| Voucher create → approve → post → reverse cycle | Full state machine in UI | Manual walk-through |
| Journal entry posting to general ledger | GL impact verification | Accounting close test |
| Sales order → invoice → payment flow | Full cycle with amounts | Test transaction |
| Purchase order → receipt → payment flow | Full cycle with amounts | Test transaction |
| Production work order → completion → costing | End-to-end production | Test work order |
| Quality inspection → report flow | Inspection types, report output | Test inspection |
| Maintenance plan → breakdown → order → completion | Full maintenance cycle | Test plan |
| Fixed asset creation → depreciation run → report | Depreciation calculation accuracy | Test asset |
| Cheque received → status transitions | Pending → cashed / bounced | Test cheque |
| Cheque issued → status transitions | Pending → paid / bounced | Test cheque |
| Trial balance generation | Account tree accuracy, period filter | Accounting period data |
| Ledger report generation | Account detail, date range, export | Accounting period data |
| Balance sheet / income statement | Report accuracy, format | Accounting period data |
| Stock query display | Column set, store filter, quantity format | Inventory data |
| Transfer create → post | Quantity validation, store selector | Inventory data |
| Opening balance entry | Period start entry, validation | Period transition |

---

## § 37-C — MENU / RUNTIME MISMATCHES & KNOWN ISSUES

| Issue | Description | Impact | Recommendation |
|-------|-------------|--------|----------------|
| HR geography screens (hidden) | Geography sub-screens exist but are hidden in menu | Manual should note "available but not in default menu" | Document as optional |
| erp_features toggles | Feature flags may hide entire modules at config level | Screenshots may show different layouts per config | Document config-dependent visibility |
| erp.phase legacy vs expanded | Phase gates certain screens | Manual should note phase-dependent availability | Add phase note in settings chapter |
| erp_seats config-only | Not adjustable from UI | Admin may not know how to change | Document env/config change |
| companies.max=1 | Single-company enforced | Multi-company documentation may confuse | Note "currently single-company" |
| Session expire_on_close=false | Sessions persist | Users may not expect this | Document explicitly in security chapter |
| Spatie teams disabled | No team-based scoping | Teams documentation irrelevant | Skip teams section in manual |
| Wildcard permission disabled | No `*` permission | Manual should not mention wildcard | Omit wildcard from permission docs |
| Storage-location removed | Legacy entity removed | Older docs may reference it | Do not document storage-location |

---

## § 37-D — TRANSLATION & AMBIGUITY NOTES

| Area | Issue | Recommendation |
|------|-------|----------------|
| Permission aliases (61) | Some alias names may not match Arabic UI labels exactly | Cross-reference alias strings with Arabic menu labels |
| Menu group names | Config uses English keys (e.g. `core/auth`) | Manual should use Arabic labels with English key in parentheses |
| Date format patterns | `d/m/Y` vs Arabic numeral display | Document actual rendered format in screenshot |
| Currency display | Multi-currency support needs validation | Verify currency symbol rendering in Arabic context |
| Status badges | Status text may differ between English and Arabic | Verify all status labels in Arabic UI |

---

## § 37-E — CLOSING STATUS

| Item | Status |
|------|--------|
| Financial period closing flow | Needs full manual walk-through test |
| Year-end closing procedure | Verify closing → opening balance transfer |
| Period lock behavior | Confirm locked period prevents edits |
| Closing reversals | Verify reversal creates proper reversal entries |

---

## Summary Counts

| Category | Count |
|----------|-------|
| § 37-A — Can document now | 32 items |
| § 37-B — Recheck before final | 6 HR items + 16 runtime items = 22 items |
| § 37-C — Menu/runtime mismatches | 9 issues |
| § 37-D — Translation/ambiguity | 5 items |
| § 37-E — Closing status | 4 items |
| **Total gap items** | **72 items** |
| Items ready for manual | 32 |
| Items requiring recheck | 22 |
| Issues to flag | 18 |
