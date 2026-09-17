# OpenCode implementation slice: exact purchase-invoice decimals

You are the single scoped implementation worker. Do not delegate and do not use tools. Read only the attached files and return a unified diff patch; do not claim files were edited.

## Proven problem

- `PurchaseInvoiceCalculationService` performs invoice money, tax, discount, header-discount allocation, and `netAmountsByLine` with PHP floats. This violates the ERP requirement for exact decimal financial arithmetic and loses cents at accepted maximum values.
- Current database migrations, model casts, and request validation use 8 decimal places for quotation and purchase-invoice quantities. `DocumentCalculationPrecisionTest` still expects 4 decimals for two quantities, so those assertions are stale; do not reduce production quantity precision.

## Allowed files only

1. `modules/Purchases/Services/PurchaseInvoiceCalculationService.php`
2. `tests/Unit/DocumentCalculationPrecisionTest.php`

Explicit exclusions: no migrations, models, requests, other services, dependencies, configs, docs, or unrelated cleanup.

## Existing patterns and invariants

- Follow `QuotationCalculationService` and `SalesAmountService`'s BCMath string approach, but do not add a Purchases-to-Sales dependency.
- `NumericFormatService::normalizeToScale` is the input-normalization contract.
- Quantities use 8 decimals. Monetary values, rates, and persisted derived totals use 4 decimals.
- Preserve public method availability unless impossible; keep the existing `calculate` return keys and `netAmountsByLine` keys.
- Existing ordinary examples such as quantity 2, unit price 10, fixed header discount 2, freight 5, and 14% tax must remain: line tax 2.5200, line total 20.5200, freight tax 0.7000, total tax 3.2200, invoice total 26.2200.
- Header-discount allocation must assign any rounding remainder to the last line so allocated shares equal the header discount exactly.
- Never use float conversion, float arithmetic, `number_format` for arithmetic, or epsilon comparisons in calculation/net-allocation paths. BCMath is installed.
- Clamp negative discounts, rates, freight, and taxable bases consistently with current behavior; cap percentage discounts/rates at 100 where current behavior does.
- Round persisted 4-decimal amounts half-up, using higher intermediate precision; do not accidentally truncate meaningful fifth decimals.
- Do not change tenancy or permissions.

## Acceptance criteria

- Update stale quotation and purchase-invoice quantity expectations from 4 to 8 decimal places.
- Add a focused purchase-invoice assertion using quantity `99999999999999.99999999`, unit price `0.0001`, fixed line discount `0.0001`, and zero tax, proving subtotal `10000000000.0000`, discount `0.0001`, and total `9999999999.9999` (using the exact equivalent result keys).
- Existing `DocumentCalculationPrecisionTest` and relevant procurement calculation examples pass.
- Return the unified diff, files changed, recommended tests, and any unverified edge.
