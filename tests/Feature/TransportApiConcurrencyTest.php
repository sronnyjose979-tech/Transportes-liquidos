<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PDO;
use Tests\PostgresTestCase;
use Tests\Support\OperationalConcurrencySchema;
use Tests\Support\TransportFixtures;

class TransportApiConcurrencyTest extends PostgresTestCase
{
    use OperationalConcurrencySchema, TransportFixtures;

    public function test_real_postgres_lock_conflict_returns_safe_409_and_retry_reads_current_state(): void
    {
        $this->withOperationalSchema(function (PDO $other): void {
            $trip = $this->trip($this->request());
            $this->ensureAssignments($trip);
            $this->actingAs($trip->registeredBy);
            DB::commit();
            $other->beginTransaction();
            $other->query('SELECT pg_advisory_xact_lock(873210)');
            DB::statement("SET lock_timeout='100ms'");
            $url = '/api/transport/trips/'.$trip->id.'/start';
            $response = $this->postJson($url, ['version' => 1, 'reason' => 'Inicio concurrente'])->assertConflict();
            $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
            $this->assertStringNotContainsString('873210', $response->getContent());
            $other->rollBack();
            DB::statement("SET lock_timeout='0'");
            $this->getJson('/api/transport/trips/'.$trip->id)->assertOk()->assertJsonPath('data.execution_status', 'pending')->assertJsonPath('data.version', 1);
            $this->postJson($url, ['version' => 1, 'reason' => 'Confirmar tras consulta'])->assertOk()->assertJsonPath('data.execution_status', 'in_progress');
            $this->postJson($url, ['version' => 1, 'reason' => 'Respuesta previa perdida'])->assertConflict();
            $this->assertSame(1, $trip->changes()->count());
        });
    }
}
