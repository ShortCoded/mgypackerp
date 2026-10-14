# MgyPack release candidate handoff - 14 October 2026

## Candidate identity

- Repository: `https://github.com/ShortCoded/mgypackerp.git`
- Branch/base: `main` at `06a2f091b95a8996dfd641ccf78d9f9f313379cf`.
- Exact scope: 68 files - the reviewed 59-file BOM packet, eight reviewed October corrections (three update packet files and five add paths), and four browser-accepted debt-summary files.
- Delivery shape: 64 tracked files in `release-candidate-tracked.patch`; four required new files remain in the original per-file patch artifacts.
- The repository index was empty throughout preparation. Nothing was staged, committed, pushed, deployed, or published.
- The surrounding worktree contains unrelated changes. Apply only the paths in `release-candidate-manifest.tsv` to a clean checkout; do not use the dirty working tree as a release source.

## Patch artifacts

- `release-candidate-tracked.patch` - SHA-256 `15ecb48be6d6efb847c524cc54867deb56f8d86a5ec16f7f2709584bfc43aaf8`.
- `../bom-alternatives-20261013/new-product-component-alternative-model.patch`.
- `../bom-alternatives-20261013/new-product-component-alternatives-migration.patch`.
- `../bom-alternatives-20261013/new-material-identity-migration.patch`.
- `../bom-alternatives-20261013/new-material-allocation-ledger-migration.patch`.
- The tracked patch passed `git apply --check` against a clean archive of the base commit. Failure against the current dirty tree is expected and is not a release signal.

## Migration reconciliation

The configured operational database reported all three candidate migrations as already applied:

1. `2026_10_13_003118_create_product_component_alternatives_table` - batch 43.
2. `2026_10_13_003120_add_material_identity_to_production_requirements_and_requests` - batch 44.
3. `2026_10_13_005559_add_material_allocation_ledger_to_production_execution` - batch 45.

For a destination where they are pending:

1. Verify target identity and current migration status. Stop if the target is ambiguous.
2. Read-only preflight the active `semi_finished_goods_inventory` classification and the eligible classified-posting accounts. Stop unless the classification exists exactly once and every in-scope company has exactly one eligible account; do not invent or alter mappings during preflight.
3. Take a database backup and complete a restore rehearsal before changing schema.
4. Apply 003118, then 003120.
5. Run the read-only `../bom-alternatives-20261013/preflight-005559-production-requirement-duplicates.sql`. Stop on any duplicate `(run, component, product)` group.
6. Apply 005559 only after the preflight is clean.
7. Reconcile production requirements, active allocations, reservations, issue/return movements, receipt costs, and journals before opening the release to users.

The migration `down()` methods intentionally refuse rollback after alternative/allocation evidence exists. Once lineage data exists, use a reviewed forward fix or restore the approved backup; do not run a blind migration rollback.

## Deployment procedure

1. Create a clean release checkout from the base commit and apply only the manifest artifacts. Verify every manifest SHA-256 before building.
2. Do not run `composer setup`: it copies environment configuration, generates an application key, migrates, installs frontend dependencies, and builds assets as one broad action.
3. `composer.lock` and dependency declarations are outside this candidate. Use the approved production dependency-install procedure only if the target lacks the locked vendor tree.
4. Build frontend assets with the approved locked package-manager workflow, then run `pnpm run build`.
5. Apply only the missing migrations using the reconciliation sequence above.
6. Run the approved Laravel cache refresh, then `php artisan octane:reload` if Octane serves the target.
7. Confirm the new service worker activates once on each acceptance client; verify the standard work-order `/print` response directly. Treat `/pdf` routes and dynamic procurement-print routes as a separate acceptance gate until their worker policy is explicitly covered.
8. Perform focused production UAT for the dashboard filters, work-order printing, PMR lineage, semi-finished primaries, alternatives, issue/return costing, finished receipts, and authorization denials.

No CI workflow exists under `.github/workflows`; release approval and evidence collection are manual.

## Rollback procedure

- Before schema changes: restore the rehearsed backup if the target fails identity, preflight, migration, or reconciliation checks.
- Before operational lineage exists: the prior code can be redeployed only after confirming additive-schema compatibility and the migration guards permit the intended rollback.
- After alternative/allocation/issue/return/receipt evidence exists: prefer a forward correction. If restoration is required, restore the pre-release backup under the approved outage and reconciliation plan.
- PWA/browser recovery: deploy the prior service-worker source with the prior release and require clients to activate that version; do not rely on cached HTML/PDF behavior as rollback evidence.
- Runtime harness rollback evidence means complete affected-row fingerprint restoration only. PostgreSQL sequences advance and were neither reset nor claimed as restored.

## Performance-safe verification

- The repository resides on a nearly full NTFS/FUSE mount. For clean checks, project the base with `git archive` into tmpfs, apply the exact artifacts there, and omit `.env`, credentials, user uploads, logs, and operational caches.
- Reuse approved dependency caches read-only or copy only the required `vendor`/`node_modules` trees into the isolated projection. Do not copy production secrets.
- A filtered fixed-assets PDF was valid but required 102.874 seconds under the contended mount. Repeat performance acceptance in the deployment filesystem before calling the release production-ready.

## Evidence and remaining gates

- Exact source/syntax/build commands and results, protected light-mode browser acceptance, direct PDF byte validation, and transaction-wrapped runtime scenarios are documented in `ACCEPTANCE_EVIDENCE.md`.
- The client-facing guide is `../../pdf/mgypack-client-acceptance-guide-20261014.pdf` and includes the actual light-mode dashboard and PROD-00018 screenshots.
- Test-runner commands were deliberately not executed.
- The local `mgypack` database passed the read-only semi-finished account preflight. The actual deployment target, target-side repeat of that preflight, backup/restore rehearsal, production migration status, regular Chrome/Edge PDF viewer, `/pdf` and dynamic procurement-print worker-policy acceptance, production-filesystem performance, and final production UAT remain release-owner gates.

