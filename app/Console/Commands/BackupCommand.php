<?php

namespace App\Console\Commands;

use App\Modules\BackupRecovery\Application\BackupArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BackupCommand extends Command
{
    protected $signature = 'backups:run {action : create, verify, restore or prune}
        {--connection= : PostgreSQL connection name; required for restore}
        {--destination= : Absolute private backup directory}
        {--archive= : Complete backup set directory}
        {--database= : New restore_* database, never an existing database}
        {--files= : New isolated recovery directory}
        {--confirm= : Exact host:port/database|absolute-files-directory}
        {--quiesced : Confirm all HTTP, upload, worker, reconciler and SQL writers are drained}
        {--isolated : Confirm recovery connection points to an isolated server}';

    protected $description = 'Create, verify or restore encrypted PostgreSQL18/private-file backup sets';

    public function handle(BackupArchive $archives): int
    {
        $action = (string) $this->argument('action');
        try {
            $connection = (string) ($this->option('connection') ?: config('database.default'));
            $destination = (string) ($this->option('destination') ?: config('backups.destination'));
            $result = match ($action) {
                'create' => $archives->create($connection, $destination, (bool) $this->option('quiesced')),
                'verify' => $archives->verify((string) $this->option('archive'))['id'],
                'restore' => $archives->restore((string) $this->option('archive'), (string) $this->option('connection'), (string) $this->option('database'), (string) $this->option('files'), (string) $this->option('confirm'), (bool) $this->option('isolated')),
                'prune' => $archives->prune($destination),
                default => throw new \RuntimeException('invalid_backup_action'),
            };
            Log::info('backup.operation_completed', ['operation' => $action, 'result' => $result]);
            $this->info('Backup operation completed: '.$result);
            if ($action === 'create' && config('backups.retention_days') !== null) {
                $removed = $archives->prune($destination);
                Log::info('backup.retention_completed', ['removed' => $removed]);
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            // Do not log previous exceptions, process command lines, SQL, credentials or destinations.
            $code = preg_match('/\A[a-z_]{3,80}\z/D', $error->getMessage()) ? $error->getMessage() : 'backup_operation_failed';
            Log::warning('backup.operation_failed', ['operation' => in_array($action, ['create', 'verify', 'restore', 'prune'], true) ? $action : 'invalid', 'code' => $code]);
            $this->error('Backup operation failed: '.$code.'. Isolated partial recovery targets must not be connected to the application.');

            return self::FAILURE;
        }
    }
}
