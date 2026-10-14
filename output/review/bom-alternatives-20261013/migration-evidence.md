# Local migration evidence

## Before state

No saved before-migration schema snapshot was captured before the two additive migrations were applied. Because the packet request prohibits Git and destructive cleanup, the migrations were not rolled back merely to manufacture before-state evidence. Both migrations also contain guards intended to prevent evidence-losing rollback.

The only supportable before-state statement is structural: both migration files are new, and their `up()` methods create the alternative master table and add the original/actual lineage columns described below. This is source evidence, not a recorded database-before snapshot.

## Current database state

Read-only Laravel schema inspection returned:

- Migration `2026_10_13_003118_create_product_component_alternatives_table`: present in migration batch 43.
- Migration `2026_10_13_003120_add_material_identity_to_production_requirements_and_requests`: present in migration batch 44.
- Migration `2026_10_13_005559_add_material_allocation_ledger_to_production_execution`: present in migration batch 45.
- Table `product_component_alternatives`: present.
- `production_material_requirements`: all of `original_product_id`, `original_unit_id`, `original_planned_quantity`, and `material_conversion_factor` are present.
- `production_material_request_lines`: all of `original_product_id`, `original_unit_id`, `original_requested_quantity`, and `material_conversion_factor` are present.
- `production_material_substitutions`: both `conversion_factor` and `replacement_quantity` are present.
- `production_material_requirements`: `allocation_root_requirement_id` and the unique run/component/actual-product allocation constraint are present.
- `production_material_request_lines`: all three cumulative reallocation quantity columns are present.
- Table `production_material_request_reallocations`: present with immutable source/target requirement, request, product, unit, factor, quantity, reservation-release, reason, actor, and timestamp evidence.

## Current evidence population

A read-only data query returned zero for each of the following:

- Active BOM alternative definitions.
- Released order lines with non-empty frozen alternatives.
- Material requirements with a conversion factor other than 1.
- Material request lines with a conversion factor other than 1.
- Substitution proposals with a non-null replacement quantity.
- Material allocation child requirements.
- Material request lines with a non-zero reallocated quantity.
- Material reallocation ledger rows.
- Run/component groups split across more than one requirement row.

Therefore the local schema is applied, but the database contains no real alternative-material transaction lineage that can be used for runtime acceptance.

## Authorized fresh target

The authorized name for a future synthetic-only acceptance target is `mgypack_bom_acceptance_20261013`. The default sandbox denied loopback socket creation, but the supported one-time local read-only approval path succeeded: the current connection identified operational `mgypack`, and an exact PostgreSQL catalog query returned `target_exists: false` for the authorized target. No database was created, migrated, or seeded. Operational `mgypack` and every historical acceptance database remain untouched.
