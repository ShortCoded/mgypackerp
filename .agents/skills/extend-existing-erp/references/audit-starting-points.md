# ERP Audit Starting Points

## Pre-existing inventory documents (read these first)

Before launching a fresh audit scan, check whether these already exist and are current. They were produced by prior audit work and can save substantial duplicated effort.

| Document | What it covers | Caveat |
| --- | --- | --- |
| `LOCALIZATION_AUDIT.md` | Translation audit: key counts, files with most untranslated strings, missing keys, parameter mismatches, notification/locale bugs, suggested translation order. | Verify each claim against live code — the document may predate recent commits. The JSON inventory at `LOCALIZATION_AUDIT_INVENTORY.json` holds the raw position list. |
| `FINALIZATION_STATUS.md` | Wave-based system status matrix: which workflows, data-integrity, accounting, inventory, permissions, reports, and tests are verified/partial/unverified, plus release blockers and highest-risk cross-module paths. | It is a point-in-time status. A line marked VERIFIED may now be broken; a line marked PARTIAL may now be done. Re-check the underlying code for anything you are about to depend on. |

Treat these as hypotheses to confirm, not as established truth to copy into a report.

## Translation architecture

This ERP is bilingual (Arabic primary, English fallback) and uses several translation mechanisms in parallel. An audit must cover all of them, not just `resources/lang/*.php`.

### Sources that exist

- `resources/lang/ar/*.php` — structured Arabic keys (one file per domain, e.g. `inventory.php`, `production_execution.php`, `common.php`).
- `resources/lang/en/*.php` — structured English keys, same file names.
- `resources/lang/ar.json` — flat JSON Arabic keys, typically used for short UI literals and legacy strings.
- `resources/lang/en.json` — may contain very few keys; do not assume parity with `ar.json`.

### How strings are produced in code

- `__('namespace.key')` — the normal case for translated UI text.
- `__('English sentence')` — an English literal passed as a key. Works only if a matching JSON key exists; otherwise it falls back to the literal and the screen shows English even under Arabic.
- `trans()` and `@lang` — less common; cover them the same way as `__()`.
- JavaScript messages — some modules receive a `messages` object from Blade; others carry hard-coded English fallbacks inside `.js` files. Check both places.
- Status/document titles built from DB values — e.g. `str($status)->replace('_', ' ')->title()` or `__('namespace.statuses.'.$status)`. The first form does not translate; the second does. Confirm which is actually used on the screen.
- Notification text — may be translated at creation time in the requester's locale and stored as finished text, which then appears in the recipient's UI regardless of the recipient's locale. Do not assume notifications follow the reader's language.

### Audit approach

1. Extract every key referenced through `__()`, `trans()`, `@lang`, and JS message objects.
2. Build the key set from `ar/*.php`, `en/*.php`, `ar.json`, and `en.json`.
3. Compare: keys referenced but missing in both languages; keys present in one language only; keys that look like English literals being passed straight through.
4. For each missing or one-sided key, confirm it actually reaches a rendered screen — template code, loop code, and dead code all consume keys, but only rendered ones are user-visible defects.
5. For status and document-type titles, trace the exact Blade line that displays them; do not assume a complete `statuses` dictionary is wired in.

### Common false positives to discount

- Product names, customer names, account codes, document numbers, IBANs, and similar user data are not UI strings, even when they appear inside a translated template.
- Abbreviations and mixed-language fields (e.g. VAT, WIP, AM/PM in a `d/m/Y h:i A` date format) may be intentional and not require translation.
- A key that exists in `ar.json` but not in the structured PHP files is not necessarily missing — the two mechanisms coexist.

## Report coverage audit approach

For each module, build: screens → routes → controllers → services → reports → gaps. Then check each report for:

- **Filtering**: date range, entity filters, status filters, branch/company/period scoping, reset behavior.
- **Data correctness**: no duplicate rows, correct joins, correct soft-delete handling, correct historical relations, correct totals.
- **Presentation**: Arabic and English, RTL/LTR, meaningful column labels, correct date/number/currency formatting, empty-state behavior.
- **Output parity**: screen, print, Excel/CSV, and PDF (where present) must represent the same filtered dataset. Screen totals must match export totals.
- **Reconciliation**: report figures must trace back to the same source transactions the operational workflow uses — do not accept an independent formula when the module already has a canonical ledger or service.
