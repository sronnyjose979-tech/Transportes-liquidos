<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Models\LiquidProduct;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationApplication;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationDecision;
use App\Modules\TransportAndDispatch\Models\TransportAuthorizationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class TransportAuthorizationIntegrityTest extends PostgresTestCase
{
    use TransportFixtures;

    public function test_intent_decision_application_relationships_and_single_use(): void
    {
        $request = $this->request($this->actor('secretary'));
        [$intent, $decision, $change, $application] = $this->productChange($request);
        $this->checkConstraints();
        $this->assertTrue($intent->decision->is($decision));
        $this->assertTrue($decision->authorizationRequest->is($intent));
        $this->assertTrue($decision->application->is($application));
        $this->assertTrue($application->change->is($change));
        $this->assertTrue($application->decision->is($decision));
        $this->assertSame('secretary', $application->appliedBy->roles->first()->name);
        $this->rejected(fn () => TransportAuthorizationApplication::create(['decision_id' => $decision->id, 'change_id' => $change->id, 'applied_by_id' => $request->registered_by_id]), '23505');
        $this->rejected(fn () => DB::table('transport_authorization_requests')->where('id', $intent->id)->update(['content' => '{}']));
        $this->rejected(fn () => DB::table('transport_authorization_decisions')->where('id', $decision->id)->delete());
        $this->rejected(fn () => DB::table('transport_authorization_applications')->where('id', $application->id)->delete());
    }

    public function test_only_active_administrator_decides_and_each_intent_has_one_decision(): void
    {
        $request = $this->request();
        $product = LiquidProduct::create(['name' => 'Otro', 'is_active' => true]);
        $intent = $this->intent($request, $product);
        foreach ([$this->actor('secretary'), $this->actor('driver'), $this->actor('administrator', false)] as $actor) {
            $this->rejected(fn () => TransportAuthorizationDecision::create(['authorization_request_id' => $intent->id, 'decision' => 'approved', 'decided_by_id' => $actor->id, 'reason' => 'No permitido']));
        }
        $decision = TransportAuthorizationDecision::create(['authorization_request_id' => $intent->id, 'decision' => 'rejected', 'decided_by_id' => $request->registered_by_id, 'reason' => 'Rechazo']);
        $this->rejected(fn () => TransportAuthorizationDecision::create(['authorization_request_id' => $intent->id, 'decision' => 'approved', 'decided_by_id' => $request->registered_by_id, 'reason' => 'Otra']), '23505');
        $this->assertSame('rejected', $intent->decision->decision);
    }

    public function test_missing_rejected_or_wrong_content_approval_cannot_cover_product_change(): void
    {
        $request = $this->request();
        $product = LiquidProduct::create(['name' => 'Otro', 'is_active' => true]);
        $this->rejected(fn () => $this->change($request, ['liquid_product_id' => $product->id]));
        $request->refresh();
        $intent = $this->intent($request, $product);
        $decision = TransportAuthorizationDecision::create(['authorization_request_id' => $intent->id, 'decision' => 'rejected', 'decided_by_id' => $request->registered_by_id, 'reason' => 'Rechazo']);
        $this->rejected(function () use ($request, $product, $decision): void {
            $change = $this->change($request, ['liquid_product_id' => $product->id]);
            TransportAuthorizationApplication::create(['decision_id' => $decision->id, 'change_id' => $change->id, 'applied_by_id' => $request->registered_by_id]);
        });
        $this->assertSame(1, $request->fresh()->version);
    }

    public function test_target_xor_version_and_affected_trip_reviews(): void
    {
        $request = $this->request();
        $trip = $this->trip($request);
        $product = LiquidProduct::create(['name' => 'Otro', 'is_active' => true]);
        $this->rejected(fn () => TransportAuthorizationRequest::create(['transport_request_id' => $request->id, 'trip_id' => $trip->id,
            'type' => 'product_change', 'content' => [], 'target_version' => 1, 'requested_by_id' => $request->registered_by_id, 'reason' => 'Dos destinos']));
        $this->rejected(fn () => TransportAuthorizationRequest::create(['transport_request_id' => $request->id,
            'type' => 'product_change', 'content' => ['before' => [], 'after' => []], 'target_version' => 2, 'requested_by_id' => $request->registered_by_id, 'reason' => 'Versión obsoleta']));
        $this->rejected(function () use ($request): void {
            [$intent, $decision, $change, $application] = $this->productChange($request);
            // Review history cannot be removed to bypass the affected-trip condition.
            DB::table('trip_product_reviews')->where('request_change_id', $change->id)->delete();
        });
        $request->refresh()->unsetRelation('trips');
        $this->productChange($request);
        $this->checkConstraints();
        $this->assertSame(1, $trip->productReviews()->count());
    }

    public function test_approved_intent_for_other_target_or_price_is_not_applied(): void
    {
        $request = $this->request();
        $other = $this->request();
        $product = LiquidProduct::create(['name' => 'Otro', 'is_active' => true]);
        $intent = $this->intent($other, $product);
        $decision = TransportAuthorizationDecision::create(['authorization_request_id' => $intent->id, 'decision' => 'approved', 'decided_by_id' => $other->registered_by_id, 'reason' => 'Otro destino']);
        $this->rejected(function () use ($request, $product, $decision): void {
            $change = $this->change($request, ['liquid_product_id' => $product->id]);
            TransportAuthorizationApplication::create(['decision_id' => $decision->id, 'change_id' => $change->id, 'applied_by_id' => $request->registered_by_id]);
        });
    }

    public function test_wrong_approved_payload_and_missing_reviews_are_rejected_atomically(): void
    {
        $request = $this->request();
        $trip = $this->trip($request);
        $product = LiquidProduct::create(['name' => 'Otro', 'is_active' => true]);
        $intent = $this->intent($request, $product);
        $decision = TransportAuthorizationDecision::create(['authorization_request_id' => $intent->id, 'decision' => 'approved', 'decided_by_id' => $request->registered_by_id, 'reason' => 'Cambio']);
        $this->rejected(function () use ($request, $product, $decision): void {
            $change = $this->change($request, ['liquid_product_id' => $product->id]);
            TransportAuthorizationApplication::create(['decision_id' => $decision->id, 'change_id' => $change->id, 'applied_by_id' => $request->registered_by_id]);
            // No review for the affected trip: deferred validation rejects the whole operation.
        });
        $request->refresh();
        $different = LiquidProduct::create(['name' => 'No autorizado', 'is_active' => true]);
        $this->rejected(function () use ($request, $different, $decision): void {
            $change = $this->change($request, ['liquid_product_id' => $different->id]);
            TransportAuthorizationApplication::create(['decision_id' => $decision->id, 'change_id' => $change->id, 'applied_by_id' => $request->registered_by_id]);
        });
        $this->assertSame(1, $request->fresh()->version);
        $this->assertSame(0, $trip->productReviews()->count());
        $this->assertSame(0, TransportAuthorizationApplication::count());
    }

    public function test_price_authorization_can_be_recorded_without_implementing_price_application(): void
    {
        $request = $this->request();
        $trip = $this->trip($request);
        $intent = TransportAuthorizationRequest::create(['trip_id' => $trip->id, 'type' => 'price_change',
            'content' => ['request' => 'Evaluación comercial'], 'target_version' => 1, 'requested_by_id' => $request->registered_by_id, 'reason' => 'Solicitud']);
        $decision = TransportAuthorizationDecision::create(['authorization_request_id' => $intent->id, 'decision' => 'approved', 'decided_by_id' => $request->registered_by_id, 'reason' => 'Decisión registrada']);
        $this->rejected(function () use ($trip, $decision): void {
            $change = $this->change($trip, ['scheduled_start_at' => '2026-10-12']);
            TransportAuthorizationApplication::create(['decision_id' => $decision->id, 'change_id' => $change->id, 'applied_by_id' => $trip->registered_by_id]);
        });
        $this->assertNull($decision->application);
        $this->assertSame(1, $trip->fresh()->version);
    }

    public function test_concurrent_application_waits_then_cannot_consume_approval_twice(): void
    {
        $config = config('database.connections.pgsql_testing');
        $connection = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'], $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $schema = 'transport_auth_'.str_replace('-', '', (string) Str::uuid());
        $tables = ['users', 'roles', 'user_roles', 'customers', 'liquid_products', 'operation_sites', 'routes', 'transport_requests', 'trips',
            'transport_authorization_requests', 'transport_authorization_decisions', 'transport_changes', 'trip_product_reviews', 'trip_events', 'trip_warnings', 'trip_warning_resolutions', 'transport_authorization_applications'];
        $connection->exec('CREATE SCHEMA '.$schema);
        try {
            $connection->exec('SET search_path TO '.$schema.',public');
            foreach ($tables as $table) {
                $connection->exec('CREATE TABLE '.$schema.'.'.$table.' (LIKE public.'.$table.' INCLUDING ALL)');
            }
            foreach ($tables as $table) {
                $constraints = $connection->query("SELECT conname,pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid='public.$table'::regclass AND contype='f'")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($constraints as $constraint) {
                    $connection->exec('ALTER TABLE '.$schema.'.'.$table.' ADD CONSTRAINT '.$constraint['conname'].' '.str_replace('public.', $schema.'.', $constraint['definition']));
                }
                $triggers = $connection->query("SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgrelid='public.$table'::regclass AND NOT tgisinternal")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($triggers as $trigger) {
                    $connection->exec(str_replace(' ON public.'.$table.' ', ' ON '.$schema.'.'.$table.' ', $trigger));
                }
            }
            DB::statement('SET LOCAL search_path TO '.$schema.',public');
            $request = $this->request();
            DB::commit(); // Only synthetic schema fixtures become visible to the second connection.
            DB::beginTransaction();
            DB::statement('SET LOCAL search_path TO '.$schema.',public');
            [$intent, $decision, $change, $application] = $this->productChange($request);
            $insert = 'INSERT INTO transport_authorization_applications(id,decision_id,change_id,applied_by_id) VALUES (?,?,?,?)';
            $values = [(string) Str::uuid(), $decision->id, $change->id, $request->registered_by_id];
            $connection->beginTransaction();
            $connection->exec("SET LOCAL lock_timeout='100ms'");
            try {
                $connection->prepare($insert)->execute($values);
                $this->fail('La segunda aplicación debe esperar a la transacción activa.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
                $connection->rollBack();
            }
            $this->assertSame(0, (int) $connection->query('SELECT count(*) FROM transport_authorization_applications')->fetchColumn());
            DB::commit();
            $connection->beginTransaction();
            try {
                $connection->prepare($insert)->execute($values);
                $this->fail('Una aprobación confirmada no puede consumirse otra vez.');
            } catch (PDOException $exception) {
                $this->assertSame('23505', $exception->getCode());
                $connection->rollBack();
            }
            $this->assertSame(1, (int) $connection->query('SELECT count(*) FROM transport_authorization_applications')->fetchColumn());
            $this->assertSame(2, (int) $connection->query('SELECT version FROM transport_requests')->fetchColumn());
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::statement('SET search_path TO public');
            $connection->exec('SET search_path TO public');
            $connection->exec('DROP SCHEMA '.$schema.' CASCADE');
        }
    }
}
