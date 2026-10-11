<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Infrastructure\PrivateDocumentStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\PostgresTestCase;

class FleetDocumentConcurrencyTest extends PostgresTestCase
{
    private ?\Closure $cleanupSchema = null;

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            // Parent releases the primary connection's locks before independent cleanup.
            if ($this->cleanupSchema !== null) {
                ($this->cleanupSchema)();
            }
        }
    }

    public function test_concurrent_registrations_serialize_and_rollback_preserves_current_reference(): void
    {
        $config = config('database.connections.pgsql_testing');
        $connect = fn (): PDO => new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $first = $connect();
        $second = $connect();
        $schema = 'document_concurrency_'.str_replace('-', '', (string) Str::uuid());
        $tables = ['users', 'fleet_assets', 'document_types', 'documents', 'document_configuration_snapshots', 'current_fleet_documents'];
        $first->exec('CREATE SCHEMA '.$schema);
        try {
            $first->exec('SET search_path TO '.$schema.', public');
            $second->exec('SET search_path TO '.$schema.', public');
            foreach ($tables as $table) {
                $first->exec('CREATE TABLE '.$schema.'.'.$table.' (LIKE public.'.$table.' INCLUDING ALL)');
            }
            // Exercise exact installed document constraints/triggers, not a mock or alternate algorithm.
            foreach (['documents', 'document_configuration_snapshots', 'current_fleet_documents'] as $table) {
                $constraints = $first->query("SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid = 'public.$table'::regclass AND contype = 'f'")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($constraints as $constraint) {
                    $definition = str_replace('public.', $schema.'.', $constraint['definition']);
                    // pg_get_constraintdef omits visible schema qualifiers; search_path resolves these to the isolated copies.
                    $first->exec('ALTER TABLE '.$schema.'.'.$table.' ADD CONSTRAINT '.$constraint['conname'].' '.$definition);
                }
                $triggers = $first->query("SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgrelid = 'public.$table'::regclass AND NOT tgisinternal")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($triggers as $trigger) {
                    $first->exec(str_replace(' ON public.'.$table.' ', ' ON '.$schema.'.'.$table.' ', $trigger));
                }
            }
            $asset = (string) Str::uuid();
            $type = (string) Str::uuid();
            $actor = 900001;
            $first->prepare('INSERT INTO users (id, name, email, password) VALUES (?, ?, ?, ?)')->execute([$actor, 'Synthetic test', 'concurrent@example.test', 'unused']);
            $first->prepare("INSERT INTO fleet_assets (id, code, type, service_status) VALUES (?, ?, 'tractor_unit', 'available')")->execute([$asset, 'Synthetic test']);
            $first->prepare('INSERT INTO document_types VALUES (?, ?, true, true, false)')->execute([$type, 'Concurrent documentation']);
            $insert = 'INSERT INTO documents (id, document_type_id, fleet_asset_id, storage_key, expires_on, uploaded_by_id, registered_at) VALUES (?, ?, ?, ?, ?, ?, ?)';
            $values = fn (string $id, string $registered): array => [$id, $type, $asset, 'files/'.$id.'.pdf', '2027-01-01', $actor, $registered];
            Storage::fake('fleet_documents');
            $storage = app(PrivateDocumentStorage::class);
            $disk = $storage->disk();
            DB::statement('SET LOCAL search_path TO '.$schema.', public');
            $stage = function (PDO $connection, string $id) use ($disk): void {
                $key = 'files/'.$id.'.pdf';
                $disk->put($key, 'Synthetic file');
                $disk->put('.pending/'.$id.'.json', json_encode(['document_id' => $id, 'key' => $key, 'backend_pid' => (int) $connection->query('SELECT pg_backend_pid()')->fetchColumn(), 'pending_commit' => true]));
            };
            $old = (string) Str::uuid();
            $new = (string) Str::uuid();
            $first->beginTransaction();
            $first->prepare($insert)->execute($values($old, '2026-10-11'));
            $stage($first, $old);
            $this->assertSame(0, $storage->reconcile());
            $disk->assertExists('files/'.$old.'.pdf');
            $second->beginTransaction();
            $second->exec("SET LOCAL lock_timeout = '100ms'");
            try {
                $second->prepare($insert)->execute($values($new, '2000-01-01'));
                $this->fail('La renovación concurrente debe esperar a la primera transacción.');
            } catch (PDOException $e) {
                $this->assertSame('55P03', $e->getCode());
                $second->rollBack();
            }
            $this->assertSame(0, (int) $second->query('SELECT count(*) FROM current_fleet_documents')->fetchColumn());
            $first->commit();
            $this->assertSame(1, $storage->reconcile());
            $disk->assertExists('files/'.$old.'.pdf');
            $second->beginTransaction();
            $second->prepare($insert)->execute($values($new, '2000-01-01'));
            $stage($second, $new);
            $this->assertSame(0, $storage->reconcile());
            $disk->assertExists('files/'.$new.'.pdf');
            // While renewal is uncommitted, readers still see the previous current document.
            $this->assertSame($old, $first->query('SELECT document_id FROM current_fleet_documents')->fetchColumn());
            $second->commit();
            $this->assertSame(1, $storage->reconcile());
            $disk->assertExists('files/'.$new.'.pdf');
            $this->assertSame(1, (int) $first->query('SELECT count(*) FROM current_fleet_documents')->fetchColumn());
            $this->assertSame($new, $first->query('SELECT document_id FROM current_fleet_documents')->fetchColumn());
            $this->assertSame($old, $first->query('SELECT id FROM documents ORDER BY registered_at DESC LIMIT 1')->fetchColumn());
            $this->assertSame(2, (int) $first->query('SELECT count(*) FROM document_configuration_snapshots')->fetchColumn());
            $failed = (string) Str::uuid();
            $first->beginTransaction();
            $first->prepare($insert)->execute($values($failed, '2026-10-12'));
            $stage($first, $failed);
            $this->assertSame(0, $storage->reconcile());
            $first->rollBack();
            $this->assertSame(1, $storage->reconcile());
            $disk->assertMissing('files/'.$failed.'.pdf');
            $disk->assertExists('files/'.$old.'.pdf');
            $disk->assertExists('files/'.$new.'.pdf');
            $this->assertSame($new, $second->query('SELECT document_id FROM current_fleet_documents')->fetchColumn());
            $this->assertSame(2, (int) $second->query('SELECT count(*) FROM documents')->fetchColumn());
            $this->assertSame(2, (int) $second->query('SELECT count(*) FROM document_configuration_snapshots')->fetchColumn());
        } finally {
            foreach ([$first, $second] as $connection) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                $connection->exec('SET search_path TO public');
            }
            DB::statement('SET LOCAL search_path TO public');
            // Only the explicitly created synthetic schema in the verified temporary test database.
            $this->cleanupSchema = fn () => $first->exec('DROP SCHEMA '.$schema.' CASCADE');
        }
    }

    public function test_migration_refuses_existing_documents_without_inventing_history(): void
    {
        $schema = 'legacy_document_'.str_replace('-', '', (string) Str::uuid());
        DB::statement('CREATE SCHEMA '.$schema);
        try {
            DB::statement('SET LOCAL search_path TO '.$schema.', public');
            DB::statement('CREATE TABLE documents (id uuid PRIMARY KEY)');
            DB::statement('CREATE TABLE document_types (id uuid PRIMARY KEY)');
            DB::table('documents')->insert(['id' => (string) Str::uuid()]);
            $migration = require database_path('migrations/2026_10_11_000050_track_current_fleet_documents.php');
            try {
                $migration->up();
                $this->fail('La migración debe detenerse ante historial no verificable.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('no se inferirán', $e->getMessage());
                $this->assertSame(1, DB::table('documents')->count());
                $this->assertSame(0, DB::table('information_schema.tables')->where('table_schema', $schema)->where('table_name', 'current_fleet_documents')->count());
            }
        } finally {
            DB::statement('SET LOCAL search_path TO public');
            DB::statement('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
}
