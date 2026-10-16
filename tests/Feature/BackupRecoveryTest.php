<?php

namespace Tests\Feature;

use App\Modules\BackupRecovery\Application\BackupArchive;
use App\Modules\Expenses\Application\ExpenseAdministration;
use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\FleetAndDocuments\Models\Document;
use App\Modules\FleetAndDocuments\Models\DocumentType;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;
use Tests\Support\WorkshopSchema;

class BackupRecoveryTest extends PostgresTestCase
{
    use TransportFixtures, WorkshopSchema;

    private string $root;

    private array $targets = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareWorkshopSchema();
        $this->root = getenv('TEST_DB_HOST').'/backup-case-'.Str::uuid();
        mkdir($this->root, 0700);
        foreach (['fleet', 'private/workshop/bytes', 'private/workshop/locks'] as $folder) {
            mkdir($this->root.'/'.$folder, 0700, true);
        }
        file_put_contents($this->root.'/key', base64_encode(random_bytes(32)));
        chmod($this->root.'/key', 0600);
        config(['backups.key_file' => $this->root.'/key', 'backups.destination' => $this->root.'/sets',
            'backups.pg_bin' => getenv('POSTGRES_TEST_BIN'), 'backups.restore_connection' => 'pgsql_testing',
            'backups.retention_days' => null, 'filesystems.disks.fleet_documents.root' => $this->root.'/fleet',
            'filesystems.disks.local.root' => $this->root.'/private', 'workshop.storage_root' => $this->root.'/private/workshop']);
        $this->mock(MaintenanceMode::class)->shouldReceive('active')->andReturn(true);
        file_put_contents($this->root.'/private/workshop/locks/test.lock', 'not recoverable process ownership');
        file_put_contents($this->root.'/private/workshop/bytes/photo.png', 'synthetic workshop document');
        file_put_contents($this->root.'/private/empty.txt', '');
        file_put_contents($this->root.'/fleet/chunk.bin', str_repeat('test', 262144)); // Exact stream chunk boundary.
    }

    protected function tearDown(): void
    {
        foreach ($this->targets as $target) {
            // Only brand-new synthetic databases created by this test, on the verified isolated server.
            DB::connection()->statement('DROP DATABASE IF EXISTS "'.$target.'" WITH (FORCE)');
        }
        $this->removeWorkshopSchema();
        if (isset($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    private function create(): string
    {
        return config('backups.destination').'/'.app(BackupArchive::class)->create('pgsql_testing', config('backups.destination'), true);
    }

    private function target(): array
    {
        $name = 'restore_'.str_replace('-', '', (string) Str::uuid());
        $this->targets[] = $name;
        $config = DB::connection()->getConfig();
        $files = $this->root.'/recovered-'.$name;

        return [$name, $files, $config['host'].':'.$config['port'].'/'.$name.'|'.$files];
    }

    private function assertRejected(callable $action, string $code): void
    {
        try {
            $action();
            $this->fail('Expected protected operation rejection');
        } catch (RuntimeException $error) {
            $this->assertSame($code, $error->getMessage());
        }
    }

    public function test_encrypted_real_dump_complete_restore_database_documents_and_manifest(): void
    {
        $admin = $this->actor();
        $asset = app(FleetAdministration::class)->save($admin, 'tractor_unit', ['code' => 'BACKUP-SYNTHETIC']);
        $type = DocumentType::create(['name' => 'Documento sintético', 'tracks_expiration' => false, 'required_for_tractor_units' => false, 'required_for_tankers' => false]);
        file_put_contents($this->root.'/fleet/synthetic.pdf', '%PDF synthetic backup only');
        $document = Document::create(['fleet_asset_id' => $asset->id, 'document_type_id' => $type->id, 'storage_key' => 'synthetic.pdf', 'uploaded_by_id' => $admin->id]);
        $expenses = app(ExpenseAdministration::class);
        $expense = $expenses->create($admin, (string) Str::uuid(), ['concept' => 'Gasto sintético', 'category' => 'Prueba', 'amount' => '1.01', 'currency' => 'CRC', 'occurred_at' => now()->toIso8601String()]);
        $receipt = (string) Str::uuid();
        $receiptKey = 'expenses/files/'.$receipt.'.pdf';
        mkdir($this->root.'/fleet/expenses/files', 0700, true);
        $receiptBytes = '%PDF synthetic receipt';
        file_put_contents($this->root.'/fleet/'.$receiptKey, $receiptBytes);
        $data = ['sha256' => hash('sha256', $receiptBytes), 'mime_type' => 'application/pdf', 'size_bytes' => strlen($receiptBytes), 'extension' => 'pdf'];
        $expenses->receipt($admin, $expense['expense_id'], (string) Str::uuid(), $data, function () use ($receipt, $receiptKey, $expense, $admin, $data): array {
            DB::table('expense_receipts')->insert(['id' => $receipt, 'expense_id' => $expense['expense_id'], 'storage_key' => $receiptKey, 'uploaded_by_id' => $admin->id, ...array_intersect_key($data, array_flip(['sha256', 'mime_type', 'size_bytes']))]);

            return ['expense_id' => $expense['expense_id'], 'receipt_id' => $receipt];
        });
        $this->artisan('backups:run', ['action' => 'create', '--connection' => 'pgsql_testing', '--quiesced' => true])->assertExitCode(0);
        $sets = glob(config('backups.destination').'/*/manifest.json');
        $this->assertCount(1, $sets);
        $directory = dirname($sets[0]);
        $this->artisan('backups:run', ['action' => 'verify', '--archive' => $directory])->assertExitCode(0);
        $archive = app(BackupArchive::class);
        $manifest = $archive->verify($directory);
        $this->assertSame('America/Costa_Rica', $manifest['timezone']);
        $this->assertStringStartsWith('18.', $manifest['postgres']);
        $this->assertSame('local/workshop', $manifest['storage']['workshop']);
        $this->assertCount(6, $manifest['entries']); // DB, three private fixtures, one document and one receipt.
        $this->assertFalse(file_exists($directory.'/database.tmp'));
        $this->assertStringNotContainsString('BACKUP-SYNTHETIC', file_get_contents($directory.'/000000.enc'));
        $this->assertStringNotContainsString('password', file_get_contents($directory.'/manifest.json'));
        [$target, $files, $confirmation] = $this->target();
        $this->assertSame($manifest['id'], $archive->restore($directory, 'pgsql_testing', $target, $files, $confirmation, true));
        $this->assertFileExists($files.'/RESTORE_COMPLETE.json');
        $this->assertFileDoesNotExist($files.'/database.dump');
        $this->assertSame('%PDF synthetic backup only', file_get_contents($files.'/private/fleet_documents/synthetic.pdf'));
        $this->assertSame($receiptBytes, file_get_contents($files.'/private/fleet_documents/'.$receiptKey));
        $this->assertSame('', file_get_contents($files.'/private/local/empty.txt'));
        $this->assertSame(hash_file('sha256', $this->root.'/fleet/chunk.bin'), hash_file('sha256', $files.'/private/fleet_documents/chunk.bin'));
        $this->assertFileDoesNotExist($files.'/private/local/workshop/locks/test.lock');
        $config = DB::connection()->getConfig();
        $pdo = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$target, $config['username']);
        $path = $pdo->prepare("SELECT set_config('search_path', ?, false)");
        $path->execute([$manifest['search_path']]);
        $query = $pdo->prepare('SELECT storage_key FROM documents WHERE id=?');
        $query->execute([$document->id]);
        $this->assertSame('synthetic.pdf', $query->fetchColumn());
        $pdo = null;
        $this->assertRejected(fn () => $archive->restore($directory, 'pgsql_testing', $target, $files, $confirmation, true), 'restore_files_exist');
        $newFiles = $this->root.'/second-destination';
        $confirmation = $config['host'].':'.$config['port'].'/'.$target.'|'.$newFiles;
        $this->assertRejected(fn () => $archive->restore($directory, 'pgsql_testing', $target, $newFiles, $confirmation, true), 'restore_database_exists_or_transaction_active');
        $this->assertFileDoesNotExist($newFiles);
        file_put_contents($this->root.'/fleet/'.$receiptKey, 'corrupt source receipt');
        $this->assertRejected(fn () => $this->create(), 'private_reference_integrity_failed');
        $this->assertCount(1, glob(config('backups.destination').'/*/manifest.json'));
    }

    public function test_corruption_and_missing_payload_rejected_before_creating_restore_targets(): void
    {
        $directory = $this->create();
        $archive = app(BackupArchive::class);
        file_put_contents($directory.'/000000.enc', 'corrupt', FILE_APPEND);
        [$target, $files, $confirm] = $this->target();
        $this->assertRejected(fn () => $archive->restore($directory, 'pgsql_testing', $target, $files, $confirm, true), 'ciphertext_integrity_failed');
        $this->assertFileDoesNotExist($files);
        $this->assertNull(DB::selectOne('SELECT 1 FROM pg_database WHERE datname=?', [$target]));
        $directory = $this->create();
        file_put_contents($directory.'/manifest.json', 'tampered', FILE_APPEND);
        $this->assertRejected(fn () => $archive->verify($directory), 'manifest_authentication_failed');
        $directory = $this->create();
        unlink($directory.'/000000.enc');
        $this->assertRejected(fn () => $archive->verify($directory), 'ciphertext_integrity_failed');
    }

    public function test_destinations_confirmation_symlinks_and_mandatory_quiescence_are_protected(): void
    {
        $archive = app(BackupArchive::class);
        $this->assertRejected(fn () => $archive->create('pgsql_testing', $this->root.'/sets', false), 'maintenance_and_stopped_writers_required');
        foreach ([base_path().'/backups', $this->root.'/fleet/nested', '/tmp/../unsafe', '/'] as $unsafe) {
            $this->assertRejected(fn () => $archive->create('pgsql_testing', $unsafe, true), str_contains($unsafe, '..') || $unsafe === '/' ? 'unsafe_path' : (str_starts_with($unsafe, base_path()) ? 'unsafe_destination' : 'storage_destination_overlap'));
        }
        symlink($this->root.'/fleet', $this->root.'/link');
        $this->assertRejected(fn () => $archive->create('pgsql_testing', $this->root.'/link/sets', true), 'unsafe_path');
        $directory = $this->create();
        [$target, $files] = $this->target();
        $this->assertRejected(fn () => $archive->restore($directory, 'pgsql_testing', $target, $files, 'wrong destination', true), 'destination_confirmation_required');
        $this->assertRejected(fn () => $archive->restore($directory, 'pgsql_testing', 'transporte_db', $files, '', true), 'isolated_restore_required');
        $this->assertRejected(fn () => $archive->restore($directory, 'pgsql_testing', $target, $files, '', false), 'isolated_restore_required');
        $this->assertFileDoesNotExist($files);
        config(['filesystems.disks.local.root' => base_path()]);
        $this->assertRejected(fn () => $this->create(), 'unsafe_storage');
    }

    public function test_missing_registered_document_aborts_backup_without_publishing_partial_set(): void
    {
        $admin = $this->actor();
        $asset = app(FleetAdministration::class)->save($admin, 'tractor_unit', ['code' => 'MISSING-SYNTHETIC']);
        $type = DocumentType::create(['name' => 'Faltante sintético', 'tracks_expiration' => false, 'required_for_tractor_units' => false, 'required_for_tankers' => false]);
        Document::create(['fleet_asset_id' => $asset->id, 'document_type_id' => $type->id, 'storage_key' => 'missing.pdf', 'uploaded_by_id' => $admin->id]);
        $this->assertRejected(fn () => $this->create(), 'private_reference_integrity_failed');
        $this->assertSame(['.', '..', '.backup.lock'], scandir(config('backups.destination')));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_failed_postgres_restore_preserves_quarantined_files_without_overwriting_database(): void
    {
        $directory = $this->create();
        mkdir($this->root.'/fake-bin', 0700);
        file_put_contents($this->root.'/fake-bin/pg_restore', "#!/bin/sh\nif [ \"\$1\" = '--version' ]; then echo 'pg_restore (PostgreSQL) 18.3'; exit 0; fi\necho 'password=must-never-be-logged' >&2\nexit 42\n");
        chmod($this->root.'/fake-bin/pg_restore', 0700);
        config(['backups.pg_bin' => $this->root.'/fake-bin']);
        [$target, $files, $confirmation] = $this->target();
        Log::spy();
        $this->artisan('backups:run', ['action' => 'restore', '--connection' => 'pgsql_testing', '--archive' => $directory, '--database' => $target, '--files' => $files, '--confirm' => $confirmation, '--isolated' => true])->expectsOutputToContain('isolated_restore_partial_failure')->assertExitCode(1);
        $this->assertFileExists($files.'/RESTORE_FAILED');
        $this->assertFileDoesNotExist($files.'/RESTORE_COMPLETE.json');
        $this->assertNotNull(DB::selectOne('SELECT 1 FROM pg_database WHERE datname=?', [$target]));
        Log::shouldHaveReceived('warning')->once()->with('backup.operation_failed', ['operation' => 'restore', 'code' => 'isolated_restore_partial_failure']);
        Log::shouldNotHaveReceived('error');
    }

    public function test_retention_is_opt_in_and_only_removes_authenticated_complete_sets(): void
    {
        $directory = $this->create();
        $archive = app(BackupArchive::class);
        $this->assertRejected(fn () => $archive->prune(config('backups.destination')), 'retention_policy_required');
        $this->assertDirectoryExists($directory);
        config(['backups.retention_days' => 2]);
        $this->assertSame(0, $archive->prune(config('backups.destination')));
        $this->travel(3)->days();
        $this->assertSame(1, $archive->prune(config('backups.destination')));
        $this->assertDirectoryDoesNotExist($directory);
        $this->travelBack();
    }

    public function test_scheduler_is_opt_in_and_preserves_expiration_schedule(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertCount(1, array_filter($events, fn ($event) => str_contains($event->command ?? '', 'documents:check-expirations')));
        $this->assertCount(0, array_filter($events, fn ($event) => str_contains($event->command ?? '', 'backups:run')));
        config(['backups.schedule_time' => '02:30', 'backups.writers_stopped' => true]);
        require base_path('routes/console.php');
        $backups = array_values(array_filter(app(Schedule::class)->events(), fn ($event) => str_contains($event->command ?? '', 'backups:run')));
        $this->assertCount(1, $backups);
        $this->assertSame('30 2 * * *', $backups[0]->expression);
        $this->assertSame('America/Costa_Rica', $backups[0]->timezone);
        $this->assertTrue($backups[0]->evenInMaintenanceMode);
        $this->assertTrue($backups[0]->withoutOverlapping);
        $this->assertStringContainsString('--quiesced', $backups[0]->command);
    }

    public function test_existing_transaction_and_failed_dump_are_not_misreported_as_complete(): void
    {
        DB::beginTransaction();
        $this->assertRejected(fn () => $this->create(), 'root_transaction_required');
        $this->assertSame(1, DB::transactionLevel());
        DB::rollBack();
        mkdir($this->root.'/bad-bin', 0700);
        file_put_contents($this->root.'/bad-bin/pg_dump', "#!/bin/sh\nif [ \"\$1\" = '--version' ]; then echo 'pg_dump (PostgreSQL) 18.3'; exit 0; fi\nexit 42\n");
        chmod($this->root.'/bad-bin/pg_dump', 0700);
        config(['backups.pg_bin' => $this->root.'/bad-bin']);
        $this->assertRejected(fn () => $this->create(), 'postgres_process_failed');
        $this->assertSame(['.', '..', '.backup.lock'], scandir(config('backups.destination')));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_authenticated_manifest_traversal_and_wrong_key_are_rejected(): void
    {
        $directory = $this->create();
        $archive = app(BackupArchive::class);
        $manifest = $archive->verify($directory);
        $manifest['entries'][0]['path'] = 'private/../../outside';
        $json = json_encode($manifest, JSON_THROW_ON_ERROR);
        file_put_contents($directory.'/manifest.json', $json);
        $key = base64_decode(file_get_contents($this->root.'/key'));
        file_put_contents($directory.'/manifest.hmac', hash_hmac('sha256', 'transport-backup-v1:'.$json, $key));
        $this->assertRejected(fn () => $archive->verify($directory), 'invalid_manifest_entry');
        $directory = $this->create();
        file_put_contents($this->root.'/key', base64_encode(random_bytes(32)));
        $this->assertRejected(fn () => $archive->verify($directory), 'manifest_authentication_failed');
    }

    public function test_live_application_and_concurrent_backups_are_refused(): void
    {
        mkdir(config('backups.destination'), 0700);
        $lock = fopen(config('backups.destination').'/.backup.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->assertRejected(fn () => $this->create(), 'backup_already_running');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->mock(MaintenanceMode::class)->shouldReceive('active')->andReturn(false);
        $this->assertRejected(fn () => $this->create(), 'maintenance_and_stopped_writers_required');
    }
}
