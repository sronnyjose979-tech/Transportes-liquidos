<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\FleetAndDocuments\Domain\DocumentValidity;
use App\Modules\FleetAndDocuments\Models\Document;
use App\Modules\FleetAndDocuments\Models\DocumentType;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\PostgresTestCase;

class FleetDocumentTest extends PostgresTestCase
{
    private function asset(string $kind = 'tractor_unit'): FleetAsset
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->roles()->attach(Role::firstOrCreate(['name' => 'administrator']));
        $data = ['code' => (string) Str::uuid()];
        if ($kind === 'tanker') {
            $data += ['has_physical_partitions' => false, 'compartments' => [['code' => 'A', 'capacity_liters' => '100', 'is_active' => true]]];
        }

        return app(FleetAdministration::class)->save($actor, $kind, $data);
    }

    private function type(bool $expiration = true, bool $tractor = true, bool $tanker = false): DocumentType
    {
        return DocumentType::create(['name' => 'DEKRA', 'tracks_expiration' => $expiration, 'required_for_tractor_units' => $tractor, 'required_for_tankers' => $tanker]);
    }

    private function document(FleetAsset $asset, DocumentType $type, ?string $expires = '2026-10-11'): Document
    {
        return Document::create(['fleet_asset_id' => $asset->id, 'document_type_id' => $type->id, 'storage_key' => 'fleet/'.Str::uuid().'.pdf', 'expires_on' => $expires, 'uploaded_by_id' => User::factory()->create()->id])->refresh();
    }

    private function rejected(callable $write, string|array $state = '23514'): void
    {
        try {
            DB::transaction($write);
            $this->fail('La escritura inválida debía rechazarse.');
        } catch (QueryException $exception) {
            $this->assertContains($exception->errorInfo[0], (array) $state);
        }
    }

    public function test_relations_registration_and_independent_renewal_history(): void
    {
        $tractor = $this->asset();
        $tanker = $this->asset('tanker');
        $type = $this->type();
        $old = $this->document($tractor, $type, '2026-10-10');
        $new = $this->document($tractor, $type, '2027-10-11');
        $this->assertTrue(Str::isUuid($new->id));
        $this->assertTrue($new->fleetAsset->is($tractor));
        $this->assertTrue($new->documentType->is($type));
        $this->assertSame($new->uploaded_by_id, $new->uploadedBy->id);
        $this->assertNotNull($new->registered_at);
        $this->assertNull($new->issued_on);
        $this->assertSame(2, $tractor->documents()->count());
        $this->assertSame(0, $tanker->documents()->count());
        $this->assertSame(2, $type->documents()->count());
        $this->assertSame($old->storage_key, $old->fresh()->storage_key);
        $this->assertNotSame($old->storage_key, $new->storage_key);
    }

    public function test_validity_boundaries_and_queries_use_costa_rica_calendar(): void
    {
        $asset = $this->asset();
        $type = $this->type();
        $at = CarbonImmutable::parse('2026-10-12 05:59:59', 'UTC');
        foreach (['2026-10-10' => DocumentValidity::Expired, '2026-10-11' => DocumentValidity::ExpiringSoon, '2026-10-26' => DocumentValidity::ExpiringSoon, '2026-10-27' => DocumentValidity::Valid] as $date => $validity) {
            $document = $this->document($asset, $type, $date);
            $this->assertSame($validity, $document->validity($at));
            $this->assertTrue(Document::withValidity($validity, $at)->whereKey($document->id)->exists());
            foreach (DocumentValidity::cases() as $other) {
                if ($other !== $validity) {
                    $this->assertFalse(Document::withValidity($other, $at)->whereKey($document->id)->exists());
                }
            }
        }
        $today = Document::where('expires_on', '2026-10-11')->firstOrFail();
        $midnight = $at->addSecond();
        $this->assertSame(DocumentValidity::Expired, $today->validity($midnight));
        $this->assertTrue(Document::withValidity(DocumentValidity::Expired, $midnight)->whereKey($today->id)->exists());
        $noExpiration = $this->document($asset, $this->type(false), null);
        $this->assertSame(DocumentValidity::NoExpiration, $noExpiration->validity($at));
        $this->assertTrue(Document::withValidity(DocumentValidity::NoExpiration, $at)->whereKey($noExpiration->id)->exists());
    }

    public function test_required_types_and_absence_do_not_select_latest_document(): void
    {
        $tractor = $this->asset();
        $tanker = $this->asset('tanker');
        $tractorType = $this->type();
        $tankerType = $this->type(true, false, true);
        $this->assertSame([$tractorType->id], DocumentType::missingFor($tractor)->pluck('id')->all());
        $this->assertSame([$tankerType->id], DocumentType::missingFor($tanker)->pluck('id')->all());
        $valid = $this->document($tractor, $tractorType, '2027-01-01');
        $expired = $this->document($tractor, $tractorType, '2020-01-01');
        $at = CarbonImmutable::parse('2026-10-11', 'America/Costa_Rica');
        $this->assertSame(0, DocumentType::missingFor($tractor)->count());
        $this->assertTrue($tractor->documents()->withValidity(DocumentValidity::Valid, $at)->whereKey($valid->id)->exists());
        $this->assertTrue($tractor->documents()->withValidity(DocumentValidity::Expired, $at)->whereKey($expired->id)->exists());
        $this->assertSame(1, DocumentType::missingFor($tanker)->count());
    }

    public function test_database_rejects_missing_expiration_and_inconsistent_dates(): void
    {
        $asset = $this->asset();
        $type = $this->type();
        $this->rejected(fn () => $this->document($asset, $type, null));
        $optional = $this->type(false);
        $this->document($asset, $optional, null);
        $this->rejected(fn () => $optional->update(['tracks_expiration' => true]));
        $this->assertFalse($optional->fresh()->tracks_expiration);
        $this->rejected(fn () => Document::create(['fleet_asset_id' => $asset->id, 'document_type_id' => $type->id, 'storage_key' => 'private/file.pdf', 'issued_on' => '2026-10-12', 'expires_on' => '2026-10-11', 'uploaded_by_id' => User::factory()->create()->id]));
    }

    public function test_database_preserves_history_and_foreign_keys_even_for_direct_sql(): void
    {
        $document = $this->document($this->asset(), $this->type());
        $this->rejected(fn () => DB::table('documents')->where('id', $document->id)->update(['storage_key' => 'replacement.pdf']));
        $this->rejected(fn () => DB::table('documents')->where('id', $document->id)->delete());
        $this->rejected(fn () => DB::statement('TRUNCATE documents'));
        foreach (['fleet_assets' => $document->fleet_asset_id, 'document_types' => $document->document_type_id, 'users' => $document->uploaded_by_id] as $table => $id) {
            // Existing fleet children use NO ACTION; document references use RESTRICT.
            $this->rejected(fn () => DB::table($table)->where('id', $id)->delete(), ['23001', '23503']);
        }
        $row = $document->getRawOriginal();
        foreach (['fleet_asset_id', 'document_type_id', 'uploaded_by_id'] as $field) {
            $invalid = $row;
            $invalid['id'] = (string) Str::uuid();
            $invalid[$field] = $field === 'uploaded_by_id' ? 9223372036854775807 : (string) Str::uuid();
            $this->rejected(fn () => DB::table('documents')->insert($invalid), '23503');
        }
        $invalid = $row;
        $invalid['id'] = (string) Str::uuid();
        $invalid['storage_key'] = ' ';
        $this->rejected(fn () => DB::table('documents')->insert($invalid));
        $this->assertSame(1, Document::count());
    }

    public function test_configuration_and_registration_serialize_and_stale_snapshots_abort(): void
    {
        $config = config('database.connections.pgsql_testing');
        $connect = fn (): PDO => new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $first = $connect();
        $second = $connect();
        $id = (string) Str::uuid();
        $statement = $first->prepare('INSERT INTO document_types VALUES (?, ?, false, true, false)');
        $statement->execute([$id, 'Concurrent test']);
        $insert = 'INSERT INTO documents (id, document_type_id, fleet_asset_id, storage_key, uploaded_by_id) VALUES (?, ?, ?, ?, ?)';
        $values = [(string) Str::uuid(), $id, (string) Str::uuid(), 'private/test.pdf', 9223372036854775807];
        try {
            $first->beginTransaction();
            $first->prepare('UPDATE document_types SET tracks_expiration = true WHERE id = ?')->execute([$id]);
            $second->beginTransaction();
            $second->exec("SET LOCAL lock_timeout = '100ms'");
            try {
                $second->prepare($insert)->execute($values);
                $this->fail('El registro debe esperar a la configuración concurrente.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
            }
            $second->rollBack();
            $first->commit();
            try {
                $second->prepare($insert)->execute($values);
                $this->fail('Tras confirmar configuración se debe exigir vencimiento.');
            } catch (PDOException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }

            $second->beginTransaction();
            $second->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $second->query('SELECT count(*) FROM document_types')->fetchColumn();
            $first->prepare('UPDATE document_types SET tracks_expiration = false WHERE id = ?')->execute([$id]);
            try {
                $second->prepare($insert)->execute($values);
                $this->fail('Un snapshot obsoleto no debe eludir la serialización.');
            } catch (PDOException $exception) {
                $this->assertSame('40001', $exception->getCode());
            }
        } finally {
            foreach ([$first, $second] as $connection) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
            }
            $first->prepare('DELETE FROM document_types WHERE id = ?')->execute([$id]);
        }
    }
}
