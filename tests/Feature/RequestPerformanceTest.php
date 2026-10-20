<?php

namespace Tests\Feature;

use App\Http\Middleware\MeasureSystemRequests;
use App\Providers\AppServiceProvider;
use App\Support\RequestPerformance;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/** No PDO connection is opened; database events and failures are synthetic. */
class RequestPerformanceTest extends TestCase
{
    public function test_http_pipeline_keeps_guest_authorization_and_does_not_add_headers(): void
    {
        config(['performance.enabled' => true]);
        Log::spy();
        $this->getJson('/api/navigation')->assertUnauthorized()->assertHeaderMissing('X-Request-ID');
        Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context) => $message === 'performance.request'
            && $context['status'] === 401 && $context['endpoint'] === '/api/navigation');
    }

    public function test_rendered_database_error_and_summary_share_the_request_id(): void
    {
        config(['performance.enabled' => true]);
        Log::spy();
        Route::get('/api/dashboard', function (): never {
            RequestPerformance::current()->beforeQuery();
            $previous = new PDOException('sensitive detail');
            $previous->errorInfo = ['08006', 7, 'private detail'];
            throw new QueryException('test', 'select secret', ['private'], $previous);
        });
        $this->getJson('/api/dashboard')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $id = RequestPerformance::current()->correlation()['request_id'];
        Log::shouldHaveReceived('warning')->once()->with('database.operation_failed', [
            'sqlstate' => '08006', 'request_id' => $id, 'endpoint' => '/api/dashboard',
            'phase' => 'database_operation_before_first_success',
        ]);
        Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context) => $message === 'performance.request'
            && $context['request_id'] === $id && $context['sqlstate'] === '08006' && $context['status'] === 500);
    }

    public function test_disabled_and_unrelated_requests_are_untouched(): void
    {
        Log::spy();
        foreach ([[false, '/api/navigation', 'GET'], [true, '/api/session', 'GET'], [true, '/api/dashboard', 'POST']] as [$enabled, $path, $method]) {
            config(['performance.enabled' => $enabled]);
            $request = Request::create($path, $method);
            $response = new Response('unchanged', 202, ['X-Test' => 'preserved']);
            $actual = (new MeasureSystemRequests)->handle($request, fn () => $response);
            $this->assertSame($response, $actual);
            $this->assertNull($request->attributes->get(RequestPerformance::class));
        }
        Log::shouldNotHaveReceived('info');
    }

    public function test_counts_queries_without_logging_their_content_and_resets_between_requests(): void
    {
        config(['performance.enabled' => true]);
        (new AppServiceProvider($this->app))->boot();
        Log::spy();
        $connection = new Connection(null);
        Event::dispatch(new ConnectionEstablished($connection));
        $ids = [];
        foreach (['/api/navigation', '/api/dashboard'] as $path) {
            $request = Request::create($path);
            $this->app->instance('request', $request);
            $response = new Response('{"data":[]}', 200, ['Content-Type' => 'application/json']);
            $actual = (new MeasureSystemRequests)->handle($request, function () use ($connection, $response): Response {
                Event::dispatch(new QueryExecuted('select secret', ['private-value'], 12.5, $connection));

                return $response;
            });
            $this->assertSame($response, $actual);
            $ids[] = $request->attributes->get(RequestPerformance::class)->correlation()['request_id'];
        }
        $this->assertNotSame($ids[0], $ids[1]);
        Log::shouldHaveReceived('info')->twice()->withArgs(function ($message, $context): bool {
            $this->assertSame('performance.request', $message);
            $this->assertSame(1, $context['sql_completed_count']);
            $this->assertSame(12.5, $context['sql_completed_ms']);
            $this->assertNull($context['connection_establishment_ms']);
            $this->assertGreaterThanOrEqual(0, $context['duration_ms']);
            $this->assertStringNotContainsString('private-value', json_encode($context));
            $this->assertStringNotContainsString('select secret', json_encode($context));

            return true;
        });
    }

    public function test_connection_failure_is_correlated_and_rethrown_unchanged(): void
    {
        config(['performance.enabled' => true]);
        (new AppServiceProvider($this->app))->boot();
        Log::spy();
        $request = Request::create('/api/navigation');
        $this->app->instance('request', $request);
        $pdoError = new PDOException('sensitive connection detail');
        $pdoError->errorInfo = ['08006', 7, 'sensitive detail'];
        $connection = new Connection(function () use ($pdoError): never {
            throw $pdoError;
        });
        Event::dispatch(new ConnectionEstablished($connection));
        try {
            (new MeasureSystemRequests)->handle($request, function () use ($connection): never {
                $connection->select('select private_data', ['private-binding']);
                throw new \LogicException('Unreachable');
            });
            $this->fail('Expected database failure');
        } catch (QueryException $error) {
            $this->assertSame($pdoError, $error->getPrevious());
        }
        $id = $request->attributes->get(RequestPerformance::class)->correlation()['request_id'];
        Log::shouldHaveReceived('info')->once()->withArgs(function ($message, $context) use ($id): bool {
            $this->assertSame($id, $context['request_id']);
            $this->assertSame('08006', $context['sqlstate']);
            $this->assertSame('database_operation_before_first_success', $context['phase']);
            $this->assertSame(1, $context['sql_attempted_count']);
            $this->assertSame(0, $context['sql_completed_count']);
            $this->assertSame(500, $context['status']);
            $this->assertGreaterThanOrEqual(0, $context['failed_database_operation_ms']);
            $this->assertStringNotContainsString('sensitive', json_encode($context));
            $this->assertStringNotContainsString('private', json_encode($context));

            return true;
        });
    }

    public function test_logging_failure_does_not_change_the_response(): void
    {
        config(['performance.enabled' => true]);
        Log::shouldReceive('info')->once()->andThrow(new \RuntimeException('Log unavailable'));
        $response = new Response('unchanged');
        $this->assertSame($response, (new MeasureSystemRequests)->handle(Request::create('/api/dashboard'), fn () => $response));
    }
}
