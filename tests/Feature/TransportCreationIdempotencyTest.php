<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Application\TransportAdministration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\PostgresTestCase;
use Tests\Support\OperationalConcurrencySchema;
use Tests\Support\TransportFixtures;

class TransportCreationIdempotencyTest extends PostgresTestCase
{
    use OperationalConcurrencySchema, TransportFixtures;

    private function payload(): array
    {
        $seed = $this->request($this->actor('secretary'));
        $this->actingAs($seed->registeredBy);

        return ['request_key' => (string) Str::uuid(), 'customer_id' => $seed->customer_id,
            'liquid_product_id' => $seed->liquid_product_id, 'route_id' => $seed->route_id,
            'requested_liters' => '100.00', 'requested_at' => '2026-10-16T08:00:00-06:00'];
    }

    public function test_requests_replay_original_snapshot_conflict_and_allow_distinct_intentions(): void
    {
        $data = $this->payload();
        $url = '/api/transport/requests';
        $original = $this->postJson($url, $data)->assertCreated()->json();
        $id = $original['data']['id'];
        $this->patchJson($url.'/'.$id, ['version' => 1, 'requested_liters' => '200.00', 'reason' => 'Corrección posterior'])->assertOk();
        $this->postJson($url, array_reverse($data, true))->assertCreated()->assertExactJson($original);
        $this->postJson($url, array_replace($data, ['requested_liters' => '100', 'requested_at' => '2026-10-16T14:00:00Z']))->assertCreated()->assertExactJson($original);
        $this->postJson($url, array_replace($data, ['requested_liters' => '101.00']))->assertConflict();
        $other = $this->postJson($url, array_replace($data, ['request_key' => (string) Str::uuid()]))->assertCreated()->json('data.id');
        $this->assertNotSame($id, $other);
        $this->assertSame(2, DB::table('transport_creation_receipts')->count());
    }

    public function test_trips_replay_without_overwriting_state_and_keys_are_actor_scoped(): void
    {
        $seed = $this->payload();
        $request = $this->postJson('/api/transport/requests', $seed)->assertCreated()->json('data.id');
        $data = ['request_key' => (string) Str::uuid(), 'transport_request_id' => $request];
        $original = $this->postJson('/api/transport/trips', $data)->assertCreated()->json();
        $this->postJson('/api/transport/trips/'.$original['data']['id'].'/cancel', ['version' => 1, 'reason' => 'Cancelación posterior'])->assertOk();
        $this->postJson('/api/transport/trips', $data)->assertCreated()->assertExactJson($original);
        $this->assertSame('cancelled', DB::table('trips')->where('id', $original['data']['id'])->value('execution_status'));
        $this->postJson('/api/transport/trips', array_replace($data, ['transport_request_id' => $this->request(auth()->user())->id]))->assertConflict();
        $this->postJson('/api/transport/requests', array_replace($seed, ['request_key' => $data['request_key']]))->assertConflict();
        $this->actingAs($this->actor('administrator'));
        $this->assertNotSame($original['data']['id'], $this->postJson('/api/transport/trips', $data)->assertCreated()->json('data.id'));
        $this->actingAs($this->actor('driver'))->postJson('/api/transport/trips', $data)->assertForbidden();
    }

    public function test_replay_rechecks_permissions_and_inactive_accounts(): void
    {
        $data = $this->payload();
        $actor = auth()->user();
        $this->postJson('/api/transport/requests', $data)->assertCreated();
        $actor->roles()->detach();
        $this->postJson('/api/transport/requests', $data)->assertForbidden();
        $actor->forceFill(['is_active' => false])->save();
        $this->postJson('/api/transport/requests', $data)->assertForbidden();
        $this->assertSame(1, DB::table('transport_creation_receipts')->count());
    }

    public function test_key_validation_and_receipt_failure_roll_back_creation(): void
    {
        $data = $this->payload();
        $this->postJson('/api/transport/trips', ['transport_request_id' => $this->request()->id])->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->postJson('/api/transport/requests', array_replace($data, ['request_key' => 'invalid']))->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $count = DB::table('transport_requests')->count();
        DB::unprepared("CREATE FUNCTION pg_temp.reject_creation_receipt() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Synthetic receipt failure' USING ERRCODE='23514'; END; $$; CREATE TRIGGER reject_creation_receipt BEFORE INSERT ON transport_creation_receipts FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_creation_receipt();");
        $this->postJson('/api/transport/requests', $data)->assertUnprocessable();
        $this->assertSame($count, DB::table('transport_requests')->count());
        $this->assertSame(0, DB::table('transport_creation_receipts')->count());
        DB::unprepared('DROP TRIGGER reject_creation_receipt ON transport_creation_receipts');
        $this->postJson('/api/transport/requests', $data)->assertCreated();
    }

    public function test_concurrent_retries_wait_for_commit_and_recover_the_same_creation(): void
    {
        $this->withOperationalSchema(function (): void {
            foreach (['request', 'trip'] as $kind) {
                $requestData = $this->payload();
                $actor = auth()->user();
                $data = $kind === 'request' ? $requestData : ['request_key' => (string) Str::uuid(), 'transport_request_id' => $this->request($actor)->id];
                if (DB::transactionLevel() > 0) {
                    DB::commit();
                }
                DB::beginTransaction();
                $method = $kind === 'request' ? 'createRequest' : 'createTrip';
                $original = app(TransportAdministration::class)->$method($actor, $data);
                $code = <<<'CODE'
                    require 'vendor/autoload.php';
                    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
                    if (getenv('TEST_DB_ISOLATED') !== '1' || $input['connection']['database'] !== 'transporte_test' || !str_starts_with($input['connection']['host'], '/tmp/transporte-tests-')) exit(2);
                    $app = require 'bootstrap/app.php';
                    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
                    config(['database.connections' => ['pgsql_testing' => $input['connection']], 'database.default' => 'pgsql_testing']);
                    Illuminate\Support\Facades\DB::statement('SET search_path TO '.$input['schema'].',public');
                    Illuminate\Support\Facades\DB::statement("SET application_name='transport_creation_retry'");
                    $actor = App\Models\User::findOrFail($input['actor']);
                    $result = app(App\Modules\TransportAndDispatch\Application\TransportAdministration::class)->{$input['method']}($actor, $input['data']);
                    echo json_encode(['id' => $result->id]);
                    CODE;
                $worker = new Process([PHP_BINARY, '-r', $code], base_path());
                $worker->setInput(json_encode(['connection' => config('database.connections.pgsql_testing'), 'schema' => DB::selectOne('SELECT current_schema() AS name')->name, 'actor' => $actor->id, 'method' => $method, 'data' => $data], JSON_THROW_ON_ERROR));
                $worker->setTimeout(10);
                $worker->start();
                try {
                    $waiting = false;
                    for ($i = 0; $i < 150; $i++) {
                        DB::select('SELECT pg_stat_clear_snapshot()');
                        $waiting = (bool) DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name='transport_creation_retry' AND wait_event='advisory') AS waiting")->waiting;
                        if ($waiting || ! $worker->isRunning()) {
                            break;
                        }
                        usleep(20000);
                    }
                    $this->assertTrue($waiting, 'Concurrent creation must wait for the uncommitted operation. '.$worker->getErrorOutput().$worker->getOutput());
                    DB::commit();
                    $worker->wait();
                    $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                    $this->assertSame($original->id, json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['id']);
                    $this->assertSame(1, DB::table('transport_creation_receipts')->where('request_key', $data['request_key'])->count());
                } finally {
                    if (DB::transactionLevel() > 0) {
                        DB::rollBack();
                    }
                    if ($worker->isRunning()) {
                        $worker->stop();
                    }
                }
            }
        });
    }

    public function test_receipts_cannot_be_changed(): void
    {
        $this->postJson('/api/transport/requests', $this->payload())->assertCreated();
        $this->expectException(QueryException::class);
        DB::table('transport_creation_receipts')->update(['fingerprint' => str_repeat('0', 64)]);
    }
}
