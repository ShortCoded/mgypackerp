\set ON_ERROR_STOP on

BEGIN TRANSACTION READ ONLY;

\echo 'Duplicate production material requirement tuples that would block migration 2026_10_13_005559:'

SELECT
    production_run_id,
    product_component_id,
    product_id,
    count(*) AS duplicate_count,
    array_agg(id ORDER BY id) AS requirement_ids
FROM production_material_requirements
WHERE production_run_id IS NOT NULL
  AND product_component_id IS NOT NULL
  AND product_id IS NOT NULL
GROUP BY production_run_id, product_component_id, product_id
HAVING count(*) > 1
ORDER BY production_run_id, product_component_id, product_id;

DO $preflight$
DECLARE
    duplicate_group_count bigint;
BEGIN
    SELECT count(*)
    INTO duplicate_group_count
    FROM (
        SELECT 1
        FROM production_material_requirements
        WHERE production_run_id IS NOT NULL
          AND product_component_id IS NOT NULL
          AND product_id IS NOT NULL
        GROUP BY production_run_id, product_component_id, product_id
        HAVING count(*) > 1
    ) AS duplicate_groups;

    IF duplicate_group_count > 0 THEN
        RAISE EXCEPTION
            'Migration 2026_10_13_005559 blocked: % duplicate production requirement tuple(s) require manual review.',
            duplicate_group_count;
    END IF;
END
$preflight$;

ROLLBACK;
