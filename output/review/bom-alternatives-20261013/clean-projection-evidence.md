# Clean 59-file projection evidence

## Projection construction

- Base commit: `06a2f091b95a8996dfd641ccf78d9f9f313379cf`.
- Temporary projection: `/tmp/mgypack-bom-59-projection-20261013-QllU0I/candidate`.
- Construction: two independent `git archive HEAD` extractions, then the five packet patches applied only to the candidate extraction.
- Runtime-only dependency symlinks to the original `vendor` and `node_modules` directories were added after source closure verification. They are not source files or manifest members.
- No source file from the shared working tree was copied directly into the projection.

Closure verification before dependency symlinks:

- changed source paths: `59`
- expected tracked paths: `55`
- expected required untracked paths: `4`
- unexpected changed or added paths: `0`
- missing `HEAD` paths: `0`
- candidate-versus-authoritative-manifest hash mismatches: `0`

The authoritative manifest is `changed-files.tsv`, SHA-256 `4672e9b1011bfb66fe149d372aebf36c59eb3d4ecd725e3710b31409626a1e35`.

## Source and application boot checks

All checks ran inside the candidate projection without the repository `.env` and without a test runner:

- PHP lint: `45` PHP source files, `0` failures.
- Laravel application boot: Laravel `12.61.0` on PHP `8.5.11`, environment `local`, debug disabled.
- Production route registration: `199` routes listed successfully, including work-order print, material request reallocation/reconciliation, run-material print, and report print/export routes.
- Blade compilation: `php artisan view:cache` passed.
- Frontend production build: Vite `7.3.2`, `54` modules transformed, `public/build/manifest.json`, CSS, and JS emitted successfully.

The first `pnpm run build` invocation reported that dependency synchronization could not lock the read-only symlinked dependency store, but Vite still completed successfully. The build was repeated with dependency verification explicitly disabled for this immutable projection; it completed cleanly. The only remaining build warning was stale Browserslist metadata, not a compilation error.

## Migration duplicate preflight

`preflight-005559-production-requirement-duplicates.sql` is an explicit read-only transaction. It:

1. lists duplicate non-null `(production_run_id, product_component_id, product_id)` groups and their requirement IDs;
2. raises a blocking PostgreSQL exception when any group exists;
3. performs no cleanup, merge, normalization, or data mutation.

Operational validation result:

- database identity: `mgypack`
- transaction mode: `read only`
- duplicate groups: `0`
- preflight SHA-256: `ad4062f99206716a33bec46ea23ddfc80fb39053abe59e3f8e9963a96c51f9a7`

This validation does not apply or reverse migration `2026_10_13_005559`; it only proves the current operational rows do not conflict with that migration's three-column unique constraint.

## Boundaries

- No test command, staging, commit, push, deployment, operational write, permission grant, or automatic duplicate cleanup occurred.
- Browser and protected route-policy acceptance remain outside this projection evidence.
