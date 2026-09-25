<?php

namespace App\Services;

use App\Console\Commands\VerifyOperationalResetBackupCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\FixedAssets\Models\FixedAsset;
use RuntimeException;
use Throwable;

class OperationalDataResetService
{
    private const POLICY_VERSION = '2026-09-25.2';

    private const OPERATIONAL_MODULES = [
        'accounting', 'finance', 'inventory', 'sales', 'purchases',
        'production', 'maintenance', 'quality', 'hr',
    ];

    public function review(): array
    {
        $this->assertPostgres();
        $columns = $this->columns();
        $this->assertManifest($columns);
        $unknownSeedTables = DB::table('seeded_reference_records')
            ->whereNotIn('table_name', array_merge($this->wiped(), $this->preserved()))
            ->distinct()->pluck('table_name')->all();
        if ($unknownSeedTables !== []) {
            throw new RuntimeException('Seed references contain unclassified tables: '.implode(', ', $unknownSeedTables));
        }
        $keys = $this->foreignKeys();
        $this->assertCycleBreaks($columns, $keys);
        $this->deleteOrder($this->wiped(), $keys, true);
        $this->deleteOrder($this->softTables($columns), $keys);
        $metrics = $this->metrics($columns);
        $fingerprints = $this->fingerprints($columns);
        $schemaFingerprint = $this->schemaFingerprint();
        $sequenceFingerprint = $this->sequenceFingerprint();
        $types = $this->transactionMorphTypes();
        $softMorphs = $this->softPurgedMorphTypes($columns, $metrics);
        $softDeletedTables = $this->softDeletedTables($columns, $metrics);
        $selective = $this->selectiveCounts($types, $softMorphs, $softDeletedTables);
        $selectiveRetained = $this->selectiveRetainedFingerprints($types, $softMorphs, $softDeletedTables);
        $blockedReferences = $this->activeReferencesToDeletedMasters($columns, $keys, $metrics);
        $unclassified = DB::table('user_notifications')
            ->whereNull(DB::raw("metadata->>'subject_type'"))
            ->whereIn('module', self::OPERATIONAL_MODULES)->count();
        $databaseIdentity = DB::selectOne('SELECT
            (SELECT oid::text FROM pg_database WHERE datname = current_database()) AS database_oid,
            inet_server_addr()::text AS server_address,
            inet_server_port()::text AS server_port,
            pg_postmaster_start_time()::text AS server_started_at');
        $policy = [
            'version' => self::POLICY_VERSION,
            'delete_tables' => $this->wiped(),
            'preserve_tables' => $this->preserved(),
            'cycle_breaks' => config('operational_reset.cycle_breaks'),
            'selective_rules' => 'transaction-morph-and-soft-purged-master-v2',
            'source_sha256' => [
                'service' => hash_file('sha256', __FILE__),
                'command' => hash_file('sha256', app_path('Console/Commands/ResetOperationalDataCommand.php')),
                'verify_command' => hash_file('sha256', app_path('Console/Commands/VerifyOperationalResetBackupCommand.php')),
                'manifest' => hash_file('sha256', config_path('operational_reset.php')),
            ],
        ];
        $policyHash = hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR));
        $manifest = [
            'database' => DB::connection()->getDatabaseName(),
            'database_instance' => hash('sha256', json_encode($databaseIdentity, JSON_THROW_ON_ERROR)),
            'policy_hash' => $policyHash,
            'columns' => $columns,
            'foreign_keys' => $keys,
            'metrics' => $metrics,
            'fingerprints' => $fingerprints,
            'schema_fingerprint' => $schemaFingerprint,
            'sequence_fingerprint' => $sequenceFingerprint,
            'transaction_morph_types' => $types,
            'soft_purged_morph_types' => $softMorphs,
            'soft_deleted_tables' => $softDeletedTables,
            'selective_counts' => $selective,
            'selective_retained_fingerprints' => $selectiveRetained,
            'blocked_master_references' => $blockedReferences,
            'unclassified_notifications' => $unclassified,
        ];

        return [
            'database' => $manifest['database'],
            'database_instance' => $manifest['database_instance'],
            'policy_hash' => $policyHash,
            'review_token' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)),
            'delete_tables' => count($this->wiped()),
            'preserve_tables' => count($this->preserved()),
            'delete_rows' => array_filter(array_combine(
                $this->wiped(),
                array_map(fn (string $table): int => $metrics[$table]['total'], $this->wiped()),
            )),
            'purge_soft_deleted' => array_filter(array_combine(
                $this->softTables($columns),
                array_map(fn (string $table): int => $metrics[$table]['soft'], $this->softTables($columns)),
            )),
            'selective_rows' => $selective,
            'blocked_master_references' => $blockedReferences,
            'unclassified_operational_notifications' => $unclassified,
            'metrics' => $metrics,
            'fingerprints' => $fingerprints,
            'schema_fingerprint' => $schemaFingerprint,
            'sequence_fingerprint' => $sequenceFingerprint,
            'selective_retained_fingerprints' => $selectiveRetained,
        ];
    }

    public function run(
        string $reviewToken,
        bool $commit,
        ?string $operator = null,
        ?string $backupDigest = null,
        ?string $backupFile = null,
        ?string $proofFile = null,
    ): array {
        if ($commit && (! app()->isDownForMaintenance()
            || config('app.maintenance.driver') !== 'file'
            || trim((string) $operator) === ''
            || preg_match('/\A[a-f0-9]{64}\z/', (string) $backupDigest) !== 1
            || ! is_file((string) $backupFile) || ! is_file((string) $proofFile))) {
            throw new RuntimeException('A committed reset requires maintenance mode, an operator, a backup, and its restore proof.');
        }

        DB::beginTransaction();

        try {
            DB::statement("SET LOCAL lock_timeout = '10s'");
            DB::statement('LOCK TABLE '.implode(', ', array_map(
                fn (string $table): string => '"'.$table.'"',
                array_merge($this->wiped(), $this->preserved()),
            )).' IN ACCESS EXCLUSIVE MODE');
            $review = $this->review();

            if (! hash_equals($review['review_token'], $reviewToken)) {
                throw new RuntimeException('The database changed since review; run the preview again.');
            }

            if ($commit) {
                $this->assertCommitAuthorization($review, $backupDigest, $backupFile, $proofFile);
            }

            if ($review['unclassified_operational_notifications'] > 0) {
                throw new RuntimeException('Operational notifications without a document subject need classification.');
            }

            if ($review['blocked_master_references'] !== []) {
                throw new RuntimeException('Active master data references soft-deleted master records. Repair the listed links before reset.');
            }

            $columns = $this->columns();
            $keys = $this->foreignKeys();
            $types = $this->transactionMorphTypes();
            $softMorphs = $this->softPurgedMorphTypes($columns, $review['metrics']);
            $softDeletedTables = $this->softDeletedTables($columns, $review['metrics']);
            $activeBefore = array_combine(
                $this->preserved(),
                array_map(fn (string $table): int => $review['metrics'][$table]['active'], $this->preserved()),
            );
            $fingerprintsBefore = $review['fingerprints'];
            $expectedDrops = $this->activeSelectiveCounts($types, $softMorphs, $softDeletedTables);

            foreach (config('operational_reset.cycle_breaks') as $table => $break) {
                DB::table($table)->whereNotNull($break['column'])->update([$break['column'] => null]);
            }

            $this->deleteSelective($types, $softMorphs, $softDeletedTables);

            foreach ($this->deleteOrder($this->wiped(), $keys, true) as $table) {
                DB::table($table)->delete();
            }

            foreach ($this->deleteOrder($this->softTables($columns), $keys) as $table) {
                DB::table($table)->whereNotNull('deleted_at')->delete();
            }

            foreach ($this->wiped() as $table) {
                if (DB::table($table)->exists()) {
                    throw new RuntimeException("Reset verification failed: {$table} is not empty.");
                }
            }

            foreach ($this->preserved() as $table) {
                $remaining = DB::table($table)->count();
                $expected = $activeBefore[$table] - ($expectedDrops[$table] ?? 0);

                if ($remaining !== $expected) {
                    throw new RuntimeException("Protected {$table} rows changed unexpectedly: {$expected} expected, {$remaining} found.");
                }

                $actual = $this->tableFingerprint($table, isset($columns[$table]['deleted_at']));
                $expectedFingerprint = $review['selective_retained_fingerprints'][$table]
                    ?? $fingerprintsBefore[$table]['active'];
                if (! hash_equals($expectedFingerprint, $actual)) {
                    throw new RuntimeException("Protected {$table} content changed unexpectedly.");
                }
            }

            $result = [
                'database' => $review['database'],
                'database_instance' => $review['database_instance'],
                'policy_hash' => $review['policy_hash'],
                'deleted_operational_rows' => array_sum($review['delete_rows']),
                'purged_soft_deleted_master_rows' => array_sum($review['purge_soft_deleted']),
                'deleted_selective_rows' => $review['selective_rows'],
                'committed' => $commit,
            ];

            if ($commit) {
                DB::table('activity_log')->insert([
                    'log_name' => 'operational_reset',
                    'description' => 'ERP operational data reset completed',
                    'event' => 'reset',
                    'module' => 'core',
                    'action' => 'reset',
                    'status' => 'success',
                    'properties' => json_encode([
                        'operator' => $operator,
                        'executing_user' => function_exists('posix_geteuid')
                            ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : get_current_user(),
                        'executing_host' => gethostname() ?: null,
                        'backup_sha256' => $backupDigest,
                        'review_token' => $reviewToken,
                        'result' => $result,
                        'deleted_rows_by_table' => $review['delete_rows'],
                        'soft_deleted_rows_by_table' => $review['purge_soft_deleted'],
                        'preserved_active_fingerprints' => array_map(
                            fn (array $fingerprint): string => $fingerprint['active'],
                            array_intersect_key($review['fingerprints'], array_flip($this->preserved())),
                        ),
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $commit ? DB::commit() : DB::rollBack();

            return $result;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    private function selectiveCounts(array $types, array $softMorphs, array $softDeletedTables): array
    {
        return [
            'archive_file_usages' => $this->archiveUsages($types, $softMorphs)->count(),
            'user_notifications' => $this->notifications($types, $softMorphs)->count(),
            'activity_log' => $this->activityLogs($types, $softMorphs)->count(),
            'seeded_reference_records' => $this->seededReferences($softDeletedTables)->count(),
        ];
    }

    private function assertCommitAuthorization(array $review, string $backupDigest, string $backupFile, string $proofFile): void
    {
        $allowsConnections = DB::selectOne('SELECT datallowconn AS allowed FROM pg_database
            WHERE datname = current_database()')->allowed;
        if ($allowsConnections) {
            throw new RuntimeException('The PostgreSQL connection gate is open; committed reset refused.');
        }

        $maintenanceFile = app()->storagePath('framework/down');
        if (! is_file($maintenanceFile)
            || filemtime($backupFile) < filemtime($maintenanceFile)
            || ! hash_equals($backupDigest, hash_file('sha256', $backupFile))
            || filemtime($proofFile) < filemtime($backupFile)) {
            throw new RuntimeException('The backup or restored-backup proof is stale or has changed.');
        }

        $proof = json_decode((string) file_get_contents($proofFile), true, 512, JSON_THROW_ON_ERROR);
        $snapshotHash = hash('sha256', json_encode([
            $review['metrics'], $review['fingerprints'], $review['selective_rows'],
            $review['schema_fingerprint'], $review['sequence_fingerprint'],
        ], JSON_THROW_ON_ERROR));
        if (! is_array($proof) || ! is_string($proof['signature'] ?? null)
            || ! hash_equals(VerifyOperationalResetBackupCommand::proofSignature($proof), $proof['signature'])
            || ($proof['format_version'] ?? null) !== 2
            || ($proof['source_database'] ?? null) !== $review['database']
            || ($proof['database_instance'] ?? null) !== $review['database_instance']
            || ($proof['review_token'] ?? null) !== $review['review_token']
            || ($proof['policy_hash'] ?? null) !== $review['policy_hash']
            || ($proof['backup_file'] ?? null) !== realpath($backupFile)
            || ($proof['backup_sha256'] ?? null) !== $backupDigest
            || ($proof['snapshot_sha256'] ?? null) !== $snapshotHash) {
            throw new RuntimeException('Restored-backup proof does not authorize this exact reset.');
        }
    }

    private function activeSelectiveCounts(array $types, array $softMorphs, array $softDeletedTables): array
    {
        $counts = $this->selectiveCounts($types, $softMorphs, $softDeletedTables);
        $counts['archive_file_usages'] = $this->archiveUsages($types, $softMorphs)->whereNull('deleted_at')->count();

        return $counts;
    }

    private function deleteSelective(array $types, array $softMorphs, array $softDeletedTables): void
    {
        $this->archiveUsages($types, $softMorphs)->delete();
        $this->notifications($types, $softMorphs)->delete();
        $this->activityLogs($types, $softMorphs)->delete();
        $this->seededReferences($softDeletedTables)->delete();
    }

    private function selectiveRetainedFingerprints(array $types, array $softMorphs, array $softDeletedTables): array
    {
        $selectors = [
            'archive_file_usages' => $this->archiveUsages($types, $softMorphs),
            'user_notifications' => $this->notifications($types, $softMorphs),
            'activity_log' => $this->activityLogs($types, $softMorphs),
            'seeded_reference_records' => $this->seededReferences($softDeletedTables),
        ];
        $fingerprints = [];

        foreach ($selectors as $table => $selector) {
            $retained = DB::table($table)
                ->whereNotIn('id', (clone $selector)->select('id'));
            if ($table === 'archive_file_usages') {
                $retained->whereNull('deleted_at');
            }

            $fingerprints[$table] = $this->queryFingerprint($retained);
        }

        return $fingerprints;
    }

    private function queryFingerprint(Builder $query): string
    {
        $rowQuery = 'SELECT md5(to_jsonb(row_data)::text) AS row_hash FROM ('.$query->toSql().') AS row_data';
        $result = DB::selectOne('SELECT md5(COALESCE(string_agg(row_hash, \'\' ORDER BY row_hash), \'\')) AS fingerprint
            FROM ('.$rowQuery.') AS hashes', $query->getBindings());

        return (string) $result->fingerprint;
    }

    private function archiveUsages(array $types, array $softMorphs): Builder
    {
        return DB::table('archive_file_usages')->where(
            function (Builder $query) use ($types, $softMorphs): void {
                $query->whereIn('usable_type', $types)
                    ->orWhereIn('company_attachable_type', $types);
                foreach ($softMorphs as $type => $table) {
                    $ids = DB::table($table)->whereNotNull('deleted_at')->select('id');
                    $query->orWhere(fn (Builder $usage): Builder => $usage
                        ->where('usable_type', $type)->whereIn('usable_id', $ids))
                        ->orWhere(fn (Builder $usage): Builder => $usage
                            ->where('company_attachable_type', $type)
                            ->whereIn('company_attachable_id', $ids));
                }
            },
        );
    }

    private function notifications(array $types, array $softMorphs): Builder
    {
        return DB::table('user_notifications')->where(function (Builder $query) use ($types, $softMorphs): void {
            $query->whereIn(DB::raw("metadata->>'subject_type'"), $types)
                ->orWhere(function (Builder $assetNotifications): void {
                    $assetNotifications->where(DB::raw("metadata->>'subject_type'"), (new FixedAsset)->getMorphClass())
                        ->whereIn('type', ['assets.disposed', 'assets.sold', 'assets.written_off']);
                });
            foreach ($softMorphs as $type => $table) {
                $query->orWhere(fn (Builder $notification): Builder => $notification
                    ->where(DB::raw("metadata->>'subject_type'"), $type)
                    ->whereRaw('EXISTS (SELECT 1 FROM "'.$table.'" AS deleted_master
                        WHERE deleted_master."id"::text = "user_notifications"."metadata"->>\'subject_id\'
                        AND deleted_master."deleted_at" IS NOT NULL)'));
            }
        });
    }

    private function activityLogs(array $types, array $softMorphs): Builder
    {
        return DB::table('activity_log')->where(function (Builder $query) use ($types, $softMorphs): void {
            $query->whereIn('subject_type', $types);
            foreach ($softMorphs as $type => $table) {
                $query->orWhere(fn (Builder $log): Builder => $log->where('subject_type', $type)
                    ->whereIn('subject_id', DB::table($table)->whereNotNull('deleted_at')->select('id')));
            }
        });
    }

    private function seededReferences(array $softDeletedTables): Builder
    {
        return DB::table('seeded_reference_records')->where(function (Builder $query) use ($softDeletedTables): void {
            $query->whereIn('table_name', $this->wiped());
            foreach ($softDeletedTables as $table) {
                $query->orWhere(fn (Builder $seed): Builder => $seed->where('table_name', $table)
                    ->whereIn('record_id', DB::table($table)->whereNotNull('deleted_at')->select('id')));
            }
        });
    }

    private function softDeletedTables(array $columns, array $metrics): array
    {
        return array_values(array_filter($this->softTables($columns),
            fn (string $table): bool => $metrics[$table]['soft'] > 0));
    }

    private function transactionMorphTypes(): array
    {
        $types = [];

        foreach ($this->morphTypeTables() as $type => $table) {
            if (in_array($table, $this->wiped(), true)) {
                $types[] = $type;
            }
        }

        sort($types);

        return $types;
    }

    private function softPurgedMorphTypes(array $columns, array $metrics): array
    {
        $types = [];

        foreach ($this->morphTypeTables() as $type => $table) {
            if (in_array($table, $this->preserved(), true)
                && isset($columns[$table]['deleted_at']) && $metrics[$table]['soft'] > 0) {
                $types[$type] = $table;
            }
        }

        return $types;
    }

    private function morphTypeTables(): array
    {
        $types = array_unique(array_merge(
            DB::table('archive_file_usages')->whereNotNull('usable_type')->distinct()->pluck('usable_type')->all(),
            DB::table('archive_file_usages')->whereNotNull('company_attachable_type')->distinct()->pluck('company_attachable_type')->all(),
            DB::table('user_notifications')->selectRaw("metadata->>'subject_type' AS subject_type")
                ->whereRaw("metadata->>'subject_type' IS NOT NULL")
                ->distinct()->pluck('subject_type')->all(),
            DB::table('activity_log')->whereNotNull('subject_type')->distinct()->pluck('subject_type')->all(),
        ));
        $tables = [];

        foreach ($types as $type) {
            $class = Relation::getMorphedModel($type) ?? $type;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                throw new RuntimeException("Unknown polymorphic subject {$type}; reset refused.");
            }

            $table = (new $class)->getTable();

            if (! in_array($table, $this->wiped(), true)
                && ! in_array($table, $this->preserved(), true)) {
                throw new RuntimeException("Unclassified polymorphic subject table {$table}; reset refused.");
            }

            $tables[$type] = $table;
        }

        ksort($tables);

        return $tables;
    }

    private function metrics(array $columns): array
    {
        $metrics = [];

        foreach ($columns as $table => $tableColumns) {
            $soft = isset($tableColumns['deleted_at']) ? 'COUNT(*) FILTER (WHERE "deleted_at" IS NOT NULL)' : '0';
            $id = isset($tableColumns['id']) ? 'MAX("id"::text)' : 'NULL';
            $updated = isset($tableColumns['updated_at']) ? 'MAX("updated_at"::text)' : 'NULL';
            $row = DB::selectOne(
                'SELECT COUNT(*) AS total, '.$soft.' AS soft, '.$id.' AS highest_id, '.
                $updated.' AS latest_update FROM "'.$table.'"',
            );
            $total = (int) $row->total;
            $softCount = (int) $row->soft;
            $metrics[$table] = [
                'total' => $total,
                'active' => $total - $softCount,
                'soft' => $softCount,
                'highest_id' => $row->highest_id,
                'latest_update' => $row->latest_update,
            ];
        }

        return $metrics;
    }

    private function fingerprints(array $columns): array
    {
        $fingerprints = [];

        foreach ($columns as $table => $tableColumns) {
            $fingerprints[$table] = [
                'all' => $this->tableFingerprint($table),
                'active' => isset($tableColumns['deleted_at'])
                    ? $this->tableFingerprint($table, true)
                    : $this->tableFingerprint($table),
            ];
        }

        return $fingerprints;
    }

    private function schemaFingerprint(): string
    {
        $catalogQueries = [
            'relations' => <<<'SQL'
                SELECT c.relname, c.relkind, c.relpersistence, c.relrowsecurity,
                    c.relforcerowsecurity, c.reloptions::text AS options,
                    CASE WHEN c.relkind IN ('v', 'm') THEN pg_get_viewdef(c.oid, true) END AS view_definition
                FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
                ORDER BY c.relname
                SQL,
            'columns' => <<<'SQL'
                SELECT c.relname, a.attnum, a.attname, format_type(a.atttypid, a.atttypmod) AS data_type,
                    a.attnotnull, a.attidentity, a.attgenerated, a.attcollation::regcollation::text AS collation,
                    pg_get_expr(d.adbin, d.adrelid) AS default_expression
                FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                    JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
                    LEFT JOIN pg_attrdef d ON d.adrelid = c.oid AND d.adnum = a.attnum
                WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p', 'v', 'm', 'f')
                ORDER BY c.relname, a.attnum
                SQL,
            'constraints' => <<<'SQL'
                SELECT c.relname, k.conname, k.contype, k.condeferrable, k.condeferred,
                    pg_get_constraintdef(k.oid, true) AS definition
                FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid
                    JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = current_schema()
                ORDER BY c.relname, k.conname
                SQL,
            'indexes' => <<<'SQL'
                SELECT t.relname AS table_name, i.relname AS index_name, pg_get_indexdef(i.oid) AS definition
                FROM pg_index x JOIN pg_class t ON t.oid = x.indrelid
                    JOIN pg_class i ON i.oid = x.indexrelid
                    JOIN pg_namespace n ON n.oid = t.relnamespace
                WHERE n.nspname = current_schema()
                ORDER BY t.relname, i.relname
                SQL,
            'triggers' => <<<'SQL'
                SELECT c.relname, t.tgname, t.tgenabled, pg_get_triggerdef(t.oid, true) AS definition
                FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
                    JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = current_schema() AND NOT t.tgisinternal
                ORDER BY c.relname, t.tgname
                SQL,
            'functions' => <<<'SQL'
                SELECT p.proname, pg_get_function_identity_arguments(p.oid) AS arguments,
                    pg_get_functiondef(p.oid) AS definition
                FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = current_schema() AND p.prokind IN ('f', 'p')
                ORDER BY p.proname, arguments
                SQL,
            'enums' => <<<'SQL'
                SELECT t.typname, e.enumsortorder, e.enumlabel
                FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
                    JOIN pg_namespace n ON n.oid = t.typnamespace
                WHERE n.nspname = current_schema()
                ORDER BY t.typname, e.enumsortorder
                SQL,
            'policies' => <<<'SQL'
                SELECT schemaname, tablename, policyname, permissive, roles, cmd, qual, with_check
                FROM pg_policies WHERE schemaname = current_schema()
                ORDER BY tablename, policyname
                SQL,
        ];
        $catalog = [];

        foreach ($catalogQueries as $name => $query) {
            $catalog[$name] = DB::select($query);
        }

        return hash('sha256', json_encode($catalog, JSON_THROW_ON_ERROR));
    }

    private function sequenceFingerprint(): string
    {
        $sequences = DB::select('SELECT schemaname, sequencename, sequenceowner, data_type,
            start_value, min_value, max_value, increment_by, cycle, cache_size
            FROM pg_sequences WHERE schemaname = current_schema() ORDER BY sequencename');
        $snapshot = [];

        foreach ($sequences as $sequence) {
            $schema = str_replace('"', '""', $sequence->schemaname);
            $name = str_replace('"', '""', $sequence->sequencename);
            $state = DB::selectOne('SELECT last_value, is_called FROM "'.$schema.'"."'.$name.'"');
            $snapshot[] = ['definition' => $sequence, 'last_value' => $state->last_value, 'is_called' => $state->is_called];
        }

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    private function tableFingerprint(string $table, bool $activeOnly = false): string
    {
        $where = $activeOnly ? ' WHERE "deleted_at" IS NULL' : '';
        $result = DB::selectOne('SELECT md5(COALESCE(string_agg(row_hash, \'\' ORDER BY row_hash), \'\')) AS fingerprint
            FROM (SELECT md5(to_jsonb(row_data)::text) AS row_hash FROM "'.$table.'" AS row_data'.$where.') AS hashes');

        return (string) $result->fingerprint;
    }

    private function activeReferencesToDeletedMasters(array $columns, array $keys, array $metrics): array
    {
        $blocked = [];
        $preserved = array_fill_keys($this->preserved(), true);
        $wiped = array_fill_keys($this->wiped(), true);

        foreach ($keys as $key) {
            $child = $key['child'];
            $parent = $key['parent'];

            if (! isset($preserved[$child]) || (! isset($preserved[$parent]) && ! isset($wiped[$parent]))) {
                continue;
            }

            if (isset($preserved[$parent]) && (! isset($columns[$parent]['deleted_at']) || $metrics[$parent]['soft'] === 0)) {
                continue;
            }

            if (! preg_match('/FOREIGN KEY \\(([^)]+)\\) REFERENCES [^(]+\\(([^)]+)\\)/', $key['definition'], $matches)
                || str_contains($matches[1], ',') || str_contains($matches[2], ',')) {
                throw new RuntimeException("Cannot inspect foreign key {$key['name']}; reset refused.");
            }

            $childColumn = trim($matches[1], ' "');
            $parentColumn = trim($matches[2], ' "');

            if (! preg_match('/\\A[a-z_][a-z0-9_]*\\z/', $childColumn)
                || ! preg_match('/\\A[a-z_][a-z0-9_]*\\z/', $parentColumn)) {
                throw new RuntimeException("Unexpected identifier in foreign key {$key['name']}; reset refused.");
            }

            $childScope = isset($columns[$child]['deleted_at']) ? ' AND child."deleted_at" IS NULL' : '';
            $parentScope = isset($preserved[$parent]) ? ' AND parent."deleted_at" IS NOT NULL' : '';
            $from = ' FROM "'.$child.'" AS child
                JOIN "'.$parent.'" AS parent ON child."'.$childColumn.'" = parent."'.$parentColumn.'"
                WHERE 1 = 1'.$childScope.$parentScope;
            $count = (int) DB::selectOne('SELECT COUNT(*) AS total'.$from)->total;

            if ($count > 0) {
                $blocked[$key['name']] = [
                    'reference' => "{$child}.{$childColumn} -> {$parent}.{$parentColumn}",
                    'active_rows' => $count,
                    'deleted_parent_ids' => isset($columns[$parent]['id'])
                        ? array_map(fn (object $row): string => (string) $row->id,
                            DB::select('SELECT DISTINCT parent."id" AS id'.$from.' ORDER BY id LIMIT 20')) : [],
                    'sample_active_child_ids' => isset($columns[$child]['id'])
                        ? array_map(fn (object $row): string => (string) $row->id,
                            DB::select('SELECT child."id" AS id'.$from.' ORDER BY id LIMIT 20')) : [],
                ];
            }
        }

        return $blocked;
    }

    private function columns(): array
    {
        $columns = [];
        foreach (DB::select("SELECT table_name, column_name, is_nullable
            FROM information_schema.columns WHERE table_schema = 'public'
            ORDER BY table_name, ordinal_position") as $row) {
            $columns[$row->table_name][$row->column_name] = $row->is_nullable === 'YES';
        }

        return $columns;
    }

    private function foreignKeys(): array
    {
        return array_map(
            fn (object $row): array => [
                'child' => $row->child,
                'parent' => $row->parent,
                'name' => $row->name,
                'action' => $row->action,
                'definition' => $row->definition,
            ],
            DB::select("SELECT child.relname AS child, parent.relname AS parent,
                    fk.conname AS name, fk.confdeltype AS action, pg_get_constraintdef(fk.oid) AS definition
                FROM pg_constraint AS fk
                JOIN pg_class AS child ON child.oid = fk.conrelid
                JOIN pg_class AS parent ON parent.oid = fk.confrelid
                JOIN pg_namespace AS child_schema ON child_schema.oid = child.relnamespace
                JOIN pg_namespace AS parent_schema ON parent_schema.oid = parent.relnamespace
                WHERE fk.contype = 'f' AND child_schema.nspname = 'public'
                    AND parent_schema.nspname = 'public'
                ORDER BY child.relname, parent.relname, fk.conname"),
        );
    }

    private function deleteOrder(array $tables, array $keys, bool $breakCycles = false): array
    {
        $degrees = array_fill_keys($tables, 0);
        $children = [];
        $breaks = config('operational_reset.cycle_breaks');

        foreach ($keys as $key) {
            if (! isset($degrees[$key['child']], $degrees[$key['parent']])
                || $key['child'] === $key['parent']
                || ! in_array($key['action'], ['a', 'r'], true)
                || $breakCycles && isset($breaks[$key['child']])
                    && $key['name'] === $breaks[$key['child']]['constraint']) {
                continue;
            }

            $children[$key['child']][] = $key['parent'];
            $degrees[$key['parent']]++;
        }

        $ready = array_keys(array_filter($degrees, fn (int $value): bool => $value === 0));
        sort($ready);
        $order = [];

        while ($ready !== []) {
            $table = array_shift($ready);
            $order[] = $table;
            foreach ($children[$table] ?? [] as $parent) {
                if (--$degrees[$parent] === 0) {
                    $ready[] = $parent;
                }
            }
            sort($ready);
        }

        if (count($order) !== count($tables)) {
            throw new RuntimeException('Restrictive foreign-key cycle; schema review required.');
        }

        return $order;
    }

    private function assertCycleBreaks(array $columns, array $keys): void
    {
        foreach (config('operational_reset.cycle_breaks') as $table => $break) {
            $hasKey = false;
            foreach ($keys as $key) {
                $hasKey = $hasKey || $key['child'] === $table
                    && $key['name'] === $break['constraint']
                    && in_array($key['action'], ['a', 'r'], true);
            }
            if (($columns[$table][$break['column']] ?? false) !== true || ! $hasKey) {
                throw new RuntimeException("Cycle break {$table}.{$break['column']} changed; reset refused.");
            }
        }
    }

    private function assertManifest(array $columns): void
    {
        $classified = array_merge($this->wiped(), $this->preserved());
        $unknown = array_diff(array_keys($columns), $classified);
        $missing = array_diff($classified, array_keys($columns));
        if (count($classified) !== count(array_unique($classified)) || $unknown !== [] || $missing !== []) {
            throw new RuntimeException('Reset manifest mismatch. Unknown: '.implode(', ', $unknown).
                '; missing: '.implode(', ', $missing).'.');
        }
    }

    private function assertPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Operational reset is verified for PostgreSQL only.');
        }
    }

    private function softTables(array $columns): array
    {
        return array_values(array_filter(
            $this->preserved(),
            fn (string $table): bool => isset($columns[$table]['deleted_at']),
        ));
    }

    private function wiped(): array
    {
        return config('operational_reset.delete_tables');
    }

    private function preserved(): array
    {
        return config('operational_reset.preserve_tables');
    }
}
