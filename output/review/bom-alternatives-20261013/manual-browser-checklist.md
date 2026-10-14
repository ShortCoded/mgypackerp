# Manual isolated browser checklist

## Current blocker

The supported computer-use inventory returned `apps: []` and `browsers: []`. The same empty inventory remained after the supported session reset. There is no browser provider or tab to bind, so no UI acceptance is claimed.

The retained synthetic user is `SYNTHETIC-BOM-ACCEPTANCE-USER-1` (`synthetic_bom_acceptance_operator`, ID `3`). Its password was randomized and not retained, and it has only these permissions:

- `production.material_requests.create`
- `production.material_requests.view`
- `production.material_requests.edit`
- `production.material_requests.approve`

Product editing, work-order/run viewing and printing, material-request printing, and production reports require additional permissions that were deliberately not granted. Browser acceptance therefore requires a separately authorized isolated QA user/session. Do not reset credentials, copy an operational session, or broaden permissions merely to satisfy this checklist.

## Safe isolated process

Do not use the operational server on port `8001`. Launch a separate process with a process-local database override, for example:

```bash
APP_URL=http://127.0.0.1:8013 DB_DATABASE=mgypack_bom_acceptance_20261013 php artisan serve --host=127.0.0.1 --port=8013
```

Use `http://127.0.0.1:8013` as the base URL only after confirming the port is free and the process reports the exact isolated database. Do not modify `.env` or operational configuration.

## Retained record identifiers

| Record | Identifier |
| --- | --- |
| Company / branch / period / store | IDs `3` / `3` / `3` / `3` |
| Finished product | ID `4`, `SYNTHETIC-BOM-FG-1` |
| Primary material | ID `5`, `SYNTHETIC-BOM-PRIMARY-1` |
| Alternative material | ID `6`, `SYNTHETIC-BOM-ALTERNATIVE-1` |
| Component / alternative definition | IDs `2` / `2`, factor `1.50000000` |
| Production order | ID `2`, `PROD-00001` |
| Production run | ID `2`, public ID `13555155-e884-4a79-8e04-70ba533cad55`, `PROD-00001-R001` |
| Source material request | ID `3`, `PMR-00001`, `partially_issued` |
| Reallocation target request | ID `4`, `PMR-00002`, `issued` |
| Unit-drift request | ID `5`, `PMR-00003`, `submitted` |
| Reallocation ledger | ID `2`, source `10`, original equivalent `10`, target `15` |
| Inventory movements | `INV-MOV-00001`, `INV-MOV-00002`, `INV-MOV-00003` |

## Acceptance steps

1. **Nested BOM alternatives — desktop and mobile**
   - Open `/admin/products/SYNTHETIC-BOM-FG-1/edit` with an isolated user authorized for `products.edit`.
   - Confirm the component details contain the nested alternatives control, primary material `SYNTHETIC-BOM-PRIMARY-1`, alternative `SYNTHETIC-BOM-ALTERNATIVE-1`, and factor `1.50000000`.
   - At a desktop viewport, confirm alternative product, factor, notes, and remove action share one row. At `991px` or narrower, confirm the row stacks into one column with no page-level horizontal overflow.
   - Add and remove an unsaved alternative row to verify client-side indexing and validation placement. Do not submit the form.

2. **Request quantities and reallocation evidence**
   - Open `/admin/production/material-requests/PMR-00001`.
   - Confirm the primary row shows requested `50`, original `50`, reserved `50`, issued `20`, and reallocated `10`.
   - Confirm the alternative row shows requested `75`, original `50`, reserved `75`, and issued `30`.
   - Confirm the reallocation history links `PMR-00001` to `PMR-00002` and displays source `10`, original equivalent `10`, and target `15`.
   - Open `/admin/production/material-requests/PMR-00002` and confirm the alternative row is issued at requested/original/issued `15/10/15`.
   - Open `/admin/production/material-requests/PMR-00003` and confirm the zero-stock unit-drift request remains `submitted`, requested/original `10/10`, with zero reserved and issued.

3. **Run and reports**
   - Open `/admin/production/runs/13555155-e884-4a79-8e04-70ba533cad55` with `production.runs.view`.
   - Confirm primary and alternative material rows retain distinct products and units, split planned quantities `40` and `90`, and one component original-equivalent plan of `100`.
   - Open `/admin/production/reports/operations?production_run_id=2` and `/admin/production/reports/operations/materials?production_run_id=2` with their respective report permissions.
   - Confirm primary actual cost `40`, alternative actual cost `120`, and run direct-material cost `160`. This is a focused visual check only; it does not prove every report/export or permission-policy branch.

4. **Arabic and English**
   - Visit `/lang/ar`, then repeat the product, request, run, and report pages. Confirm RTL layout, translated alternative/reallocation labels, correct alignment, and LTR numeric values.
   - Visit `/lang/en` and repeat the same pages. Confirm English labels and LTR layout without stale Arabic content.

5. **Responsive layout**
   - Check at approximately `1440×900`, `768×1024`, and `390×844`.
   - Confirm nested alternative controls stack below `992px`, request tables remain within their responsive scroll region, action buttons wrap without overlap, and no content is clipped in either locale.

6. **Print preview**
   - Open `/admin/production/work-orders/PROD-00001/print` with `production.orders.print`. Confirm a populated order/formula document, the primary BOM row, and the approved alternative with factor `1.5`; the page must not be blank.
   - Open `/admin/production/material-requests/PMR-00001/print` with `production.material_requests.print` and `/admin/production/runs/13555155-e884-4a79-8e04-70ba533cad55/materials/print` with `production.runs.print`.
   - Confirm primary/alternative quantities and original-equivalent context are visible in both Arabic and English print previews.

## Unproven boundaries

- No browser/provider was available, so none of the steps above has been clicked or visually accepted.
- A bounded two-process request/approval race passed in the separate `SYNTHETIC-BOM-CONCURRENCY-20261013` scenario. The reviewed multi-position correction also passed one guarded approval/issue rerun. Repeated stress, multi-host concurrency, and deadlock/retry variation remain unproven.
- Runtime acceptance covers the retained canonical material-cost report path, not the complete production report/export permission policy matrix.
- Focused Pest specifications remain unexecuted because test-runner commands were prohibited.
- No publication, Git commit, push, or operational-data mutation is authorized by this checklist.

