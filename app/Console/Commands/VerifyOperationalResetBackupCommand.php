<?php

namespace App\Console\Commands;

use App\Services\OperationalDataResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class VerifyOperationalResetBackupCommand extends Command
{
    protected $signature = 'erp:verify-operational-reset-backup
        {--restored-database= : Existing isolated database restored from the backup}
        {--backup-file= : PostgreSQL custom-format backup that was restored}
        {--backup-sha256= : Expected SHA-256 digest of the backup}
        {--proof-file= : Absolute path for the signed verification proof}';

    protected $description = 'Compare a restored backup with the quiesced source before an operational reset.';

    public function handle(OperationalDataResetService $reset): int
    {
        try {
            if (! app()->isDownForMaintenance() || config('app.maintenance.driver') !== 'file') {
                throw new RuntimeException('Enter file-based maintenance mode before verifying the backup.');
            }

            $restoredDatabase = trim((string) $this->option('restored-database'));
            $backupFile = trim((string) $this->option('backup-file'));
            $digest = strtolower(trim((string) $this->option('backup-sha256')));
            $proofFile = trim((string) $this->option('proof-file'));

            if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $restoredDatabase) !== 1
                || preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1
                || ! $this->isAbsolutePath($proofFile) || ! is_dir(dirname($proofFile))) {
                throw new RuntimeException('Provide a restored database, backup digest, and absolute proof file path.');
            }

            $source = $reset->review();
            if ($restoredDatabase === $source['database']) {
                throw new RuntimeException('The restored database must differ from the source database.');
            }

            $maintenanceFile = app()->storagePath('framework/down');
            if (! is_file($backupFile) || ! is_readable($backupFile) || filesize($backupFile) === 0
                || ! hash_equals($digest, hash_file('sha256', $backupFile))
                || ! is_file($maintenanceFile) || filemtime($backupFile) < filemtime($maintenanceFile)) {
                throw new RuntimeException('The backup must be readable and created after entering maintenance mode.');
            }

            $archiveCheck = new Process(['pg_restore', '--list', $backupFile]);
            $archiveCheck->setTimeout(60);
            $archiveCheck->mustRun();
            preg_match('/^;\s*dbname:\s*(.+)$/m', $archiveCheck->getOutput(), $matches);
            if (trim($matches[1] ?? '') !== $source['database']) {
                throw new RuntimeException('The backup archive identifies a different source database.');
            }

            $originalConnection = DB::getDefaultConnection();
            $verifyConfig = array_replace(DB::connection()->getConfig(), ['database' => $restoredDatabase]);
            unset($verifyConfig['url'], $verifyConfig['name']);
            config(['database.connections.operational_reset_verify' => $verifyConfig]);

            try {
                DB::setDefaultConnection('operational_reset_verify');
                $restored = $reset->review();
            } finally {
                DB::setDefaultConnection($originalConnection);
                DB::disconnect('operational_reset_verify');
            }

            foreach (['metrics', 'fingerprints', 'selective_rows', 'blocked_master_references',
                'delete_rows', 'purge_soft_deleted', 'unclassified_operational_notifications',
                'policy_hash', 'schema_fingerprint', 'sequence_fingerprint'] as $field) {
                if ($source[$field] !== $restored[$field]) {
                    if ($field === 'schema_fingerprint') {
                        throw new RuntimeException($this->schemaDifference($reset, $originalConnection));
                    }
                    throw new RuntimeException("Restored {$field} differs from the source snapshot.");
                }
            }

            $proof = [
                'format_version' => 2,
                'source_database' => $source['database'],
                'database_instance' => $source['database_instance'],
                'review_token' => $source['review_token'],
                'policy_hash' => $source['policy_hash'],
                'backup_file' => realpath($backupFile),
                'backup_sha256' => $digest,
                'restored_database' => $restoredDatabase,
                'snapshot_sha256' => hash('sha256', json_encode([
                    $source['metrics'], $source['fingerprints'], $source['selective_rows'],
                    $source['schema_fingerprint'], $source['sequence_fingerprint'],
                ], JSON_THROW_ON_ERROR)),
                'table_count' => count($source['fingerprints']),
                'verified_at' => now()->toIso8601String(),
            ];
            $proof['signature'] = self::proofSignature($proof);
            $temporary = tempnam(dirname($proofFile), 'reset-proof-');
            if ($temporary === false) {
                throw new RuntimeException('Cannot create the proof file.');
            }
            file_put_contents($temporary, json_encode($proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            chmod($temporary, 0600);
            if (! rename($temporary, $proofFile)) {
                throw new RuntimeException('Cannot save the proof file.');
            }

            $this->line(json_encode([
                'proof_file' => $proofFile,
                'review_token' => $proof['review_token'],
                'policy_hash' => $proof['policy_hash'],
                'backup_sha256' => $digest,
                'verified_tables' => $proof['table_count'],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->info('Restored backup matches the source snapshot.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Backup verification refused: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    public static function proofSignature(array $proof): string
    {
        unset($proof['signature']);

        return hash_hmac('sha256', json_encode($proof, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function schemaDifference(OperationalDataResetService $reset, string $originalConnection): string
    {
        $source = $reset->schemaCatalog();

        try {
            DB::setDefaultConnection('operational_reset_verify');
            $restored = $reset->schemaCatalog();
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::disconnect('operational_reset_verify');
        }

        foreach ($source as $section => $sourceRows) {
            $restoredRows = $restored[$section] ?? [];
            if (json_encode($sourceRows, JSON_THROW_ON_ERROR) === json_encode($restoredRows, JSON_THROW_ON_ERROR)) {
                continue;
            }

            $rowCount = max(count($sourceRows), count($restoredRows));
            for ($index = 0; $index < $rowCount; $index++) {
                $sourceRow = json_encode($sourceRows[$index] ?? null, JSON_THROW_ON_ERROR);
                $restoredRow = json_encode($restoredRows[$index] ?? null, JSON_THROW_ON_ERROR);
                if ($sourceRow !== $restoredRow) {
                    return "Restored schema {$section} differs at row {$index}; source: "
                        .mb_substr($sourceRow, 0, 500).' restored: '.mb_substr($restoredRow, 0, 500);
                }
            }
        }

        return 'Restored schema fingerprint differs from the source snapshot.';
    }
}
