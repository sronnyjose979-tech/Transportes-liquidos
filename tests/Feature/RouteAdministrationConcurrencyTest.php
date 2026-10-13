<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Application\RouteAdministration;
use App\Modules\TransportAndDispatch\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\PostgresTestCase;
use Tests\Support\OperationalConcurrencySchema;
use Tests\Support\TransportFixtures;

class RouteAdministrationConcurrencyTest extends PostgresTestCase
{
    use OperationalConcurrencySchema;
    use TransportFixtures;

    public function test_route_edit_serializes_with_first_request_and_cannot_change_referenced_route_after_commit(): void
    {
        $this->withOperationalSchema(function (PDO $other): void {
            $seed = $this->request();
            $attributes = $seed->route->only(['name', 'origin_site_id', 'destination_site_id']);
            $route = Route::create($attributes);
            DB::commit();
            DB::beginTransaction();
            $service = app(RouteAdministration::class);
            $updated = $service->save($seed->registeredBy, array_replace($attributes, ['name' => 'Editada antes del uso']) + ['expected' => $attributes], $route->id);
            $sql = 'INSERT INTO transport_requests(id,customer_id,liquid_product_id,route_id,requested_liters,registered_by_id,requested_at) VALUES (?,?,?,?,100,?,clock_timestamp())';
            $values = [(string) Str::uuid(), $seed->customer_id, $seed->liquid_product_id, $route->id, $seed->registered_by_id];
            $other->beginTransaction();
            $other->exec("SET LOCAL lock_timeout='100ms'");
            try {
                $other->prepare($sql)->execute($values);
                $this->fail('La primera solicitud debe esperar a la edición de ruta.');
            } catch (PDOException $exception) {
                $this->assertSame('55P03', $exception->getCode());
                $other->rollBack();
            }
            DB::commit();
            $other->prepare($sql)->execute($values);
            $this->assertSame('Editada antes del uso', $other->query("SELECT name FROM routes WHERE id='".$route->id."'")->fetchColumn());
            $before = $updated->only(['name', 'origin_site_id', 'destination_site_id']);
            try {
                $service->save($seed->registeredBy, array_replace($before, ['name' => 'Cambio ya histórico']) + ['expected' => $before], $route->id);
                $this->fail('Después del primer uso la ruta no puede editarse.');
            } catch (ConflictHttpException $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            $this->assertSame($before, $route->fresh()->only(['name', 'origin_site_id', 'destination_site_id']));
        });
    }
}
