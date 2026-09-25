<?php

namespace App\Console\Commands;

use App\Services\OperationalDataResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class ResetOperationalDataCommand extends Command
{
    protected $signature = 'erp:reset-operational-data
        {--simulate : Run the reset in a transaction and roll it back (non-production only)}
        {--apply : Commit the reviewed reset}
        {--review-token= : Exact SHA-256 token from the latest preview}
        {--policy-hash= : Exact SHA-256 policy hash from the same preview}
        {--database= : Exact expected database name}
        {--operator= : Name or identifier of the person authorizing the reset}
        {--backup-file= : Existing, readable pg_dump custom-format backup file}
        {--backup-sha256= : Expected SHA-256 digest of the backup file}
        {--restore-proof-file= : Proof from erp:verify-operational-reset-backup}';

    protected $description = 'Preview or reset operational ERP data while preserving master codings, files, and chats.';

    public function handle(OperationalDataResetService $reset): int
    {
        try {
            if ($this->option('apply') && $this->option('simulate')) {
                $this->error('Choose --apply or --simulate, not both.');

                return self::FAILURE;
            }

            if ($this->option('simulate') && app()->environment('production')) {
                $this->error('Simulation is restricted to non-production environments.');

                return self::FAILURE;
            }

            if ($this->option('apply')) {
                if (! app()->isDownForMaintenance()) {
                    $this->error('Put Laravel in maintenance mode and stop Octane, queue workers, and the scheduler before applying.');

                    return self::FAILURE;
                }

                if (config('app.maintenance.driver') !== 'file') {
                    $this->error('Apply requires Laravel file-based maintenance mode so its guard cannot be cleared with database cache.');

                    return self::FAILURE;
                }

                $database = trim((string) $this->option('database'));
                $operator = trim((string) $this->option('operator'));
                $token = trim((string) $this->option('review-token'));
                $policyHash = trim((string) $this->option('policy-hash'));
                $backup = trim((string) $this->option('backup-file'));
                $digest = strtolower(trim((string) $this->option('backup-sha256')));
                $proofFile = trim((string) $this->option('restore-proof-file'));

                if ($database === '' || $operator === '' || $token === '' || $policyHash === ''
                    || $backup === '' || $digest === '' || $proofFile === ''
                    || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
                    || preg_match('/\A[a-f0-9]{64}\z/', $policyHash) !== 1
                    || preg_match('/\A[a-f0-9]{64}\z/', $digest) !== 1) {
                    $this->error('Apply requires --database, --operator, --review-token, --policy-hash, --backup-file, --backup-sha256, and --restore-proof-file.');

                    return self::FAILURE;
                }

                $review = $reset->review();

                if ($database !== $review['database'] || ! hash_equals($review['review_token'], $token)
                    || ! hash_equals($review['policy_hash'], $policyHash)) {
                    $this->error('Database name, policy hash, or review token differs from the current preview.');

                    return self::FAILURE;
                }

                if (! is_file($backup) || ! is_readable($backup) || filesize($backup) === 0
                    || ! hash_equals($digest, hash_file('sha256', $backup))) {
                    $this->error('Backup file is missing, empty, unreadable, or does not match its reviewed SHA-256.');

                    return self::FAILURE;
                }

                $maintenanceFile = app()->storagePath('framework/down');
                if (! is_file($maintenanceFile) || filemtime($backup) < filemtime($maintenanceFile)) {
                    $this->error('Create a fresh backup after entering maintenance mode, then retry with its digest.');

                    return self::FAILURE;
                }

                $archiveCheck = new Process(['pg_restore', '--list', $backup]);
                $archiveCheck->setTimeout(60);
                $archiveCheck->mustRun();
                preg_match('/^;\s*dbname:\s*(.+)$/m', $archiveCheck->getOutput(), $matches);

                if (trim($matches[1] ?? '') !== $database) {
                    $this->error('The PostgreSQL backup does not identify the reviewed database.');

                    return self::FAILURE;
                }

                if (! is_file($proofFile) || ! is_readable($proofFile)
                    || filemtime($proofFile) < filemtime($backup)) {
                    $this->error('The restored-backup proof is missing or older than its backup.');

                    return self::FAILURE;
                }

                $proof = json_decode((string) file_get_contents($proofFile), true, 512, JSON_THROW_ON_ERROR);
                $signature = $proof['signature'] ?? '';
                $snapshotHash = hash('sha256', json_encode([
                    $review['metrics'], $review['fingerprints'], $review['selective_rows'],
                    $review['schema_fingerprint'], $review['sequence_fingerprint'],
                ], JSON_THROW_ON_ERROR));
                if (! is_array($proof) || ! is_string($signature)
                    || ! hash_equals(VerifyOperationalResetBackupCommand::proofSignature($proof), $signature)
                    || ($proof['format_version'] ?? null) !== 2
                    || ($proof['source_database'] ?? null) !== $database
                    || ($proof['database_instance'] ?? null) !== $review['database_instance']
                    || ($proof['review_token'] ?? null) !== $token
                    || ($proof['policy_hash'] ?? null) !== $policyHash
                    || ($proof['backup_file'] ?? null) !== realpath($backup)
                    || ($proof['backup_sha256'] ?? null) !== $digest
                    || ($proof['snapshot_sha256'] ?? null) !== $snapshotHash
                    || ($proof['table_count'] ?? null) !== count($review['fingerprints'])) {
                    $this->error('The restored-backup proof does not match this database, policy, backup, and review.');

                    return self::FAILURE;
                }

                $otherConnections = (int) DB::selectOne("SELECT COUNT(*) AS total FROM pg_stat_activity
                    WHERE datname = current_database() AND pid <> pg_backend_pid()
                        AND backend_type = 'client backend'")->total;
                if ($otherConnections > 0) {
                    $this->error("{$otherConnections} other client database connections remain. Stop application workers and retry.");

                    return self::FAILURE;
                }

                if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $database) !== 1) {
                    $this->error('Database name cannot be safely used for the PostgreSQL connection gate.');

                    return self::FAILURE;
                }

                $adminConfig = array_replace(DB::connection()->getConfig(), ['database' => 'postgres']);
                unset($adminConfig['url'], $adminConfig['name']);
                config(['database.connections.operational_reset_admin' => $adminConfig]);
                $admin = DB::connection('operational_reset_admin');
                $identifier = '"'.$database.'"';
                $gateClosed = false;
                $committed = false;

                try {
                    $admin->statement('ALTER DATABASE '.$identifier.' WITH ALLOW_CONNECTIONS false');
                    $gateClosed = true;
                    $remainingConnections = (int) DB::selectOne("SELECT COUNT(*) AS total FROM pg_stat_activity
                        WHERE datname = current_database() AND pid <> pg_backend_pid()
                            AND backend_type = 'client backend'")->total;
                    if ($remainingConnections > 0) {
                        throw new RuntimeException("{$remainingConnections} other database connections reached the target before the write gate closed.");
                    }

                    $result = $reset->run($token, true, $operator, $digest, $backup, $proofFile);
                    $committed = true;
                } finally {
                    try {
                        if ($gateClosed && ! $committed) {
                            $admin->statement('ALTER DATABASE '.$identifier.' WITH ALLOW_CONNECTIONS true');
                        }
                    } catch (Throwable $gateError) {
                        throw new RuntimeException(
                            "The reset may have committed, but the connection gate for {$database} could not be reopened. Re-enable it from the postgres maintenance database.",
                            previous: $gateError,
                        );
                    } finally {
                        DB::disconnect('operational_reset_admin');
                    }
                }

                $this->line($this->render($result));
                $this->info('Operational reset committed and verified.');
                $this->warn('Database connections remain closed. Keep Octane, queues, and scheduler stopped; reopen the database explicitly from the postgres maintenance database after final checks.');

                return self::SUCCESS;
            }

            $review = $reset->review();
            unset($review['metrics'], $review['fingerprints'], $review['selective_retained_fingerprints']);
            $this->line($this->render($review));

            if ($this->option('simulate')) {
                $this->line($this->render($reset->run($review['review_token'], false)));
                $this->info('Simulation passed; all writes were rolled back.');
            } else {
                $this->info('Read-only preview complete. No data changed.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Operational reset refused: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function render(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
