<?php

namespace Tests\Feature;

use App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors;
use App\Modules\Workshop\Http\Middleware\WorkshopApiErrors;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use PDOException;
use Tests\TestCase;

class QueryExceptionSafetyTest extends TestCase
{
    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logs = new TestHandler;
        Log::swap(new Logger(new Monolog('regression', [$this->logs])));
    }

    private function failure(string $state = 'XX000'): QueryException
    {
        $previous = new PDOException('password=synthetic-secret internal-host private/storage/key SQL failure');
        $previous->errorInfo = [$state, 7, $previous->getMessage()];

        return new QueryException('private-connection', 'select * from private_table where token = ?', ['synthetic-token'], $previous);
    }

    public function test_every_http_surface_is_safe_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        foreach (['transport/trips', 'users', 'employees', 'tractor-units', 'tankers', 'documents', 'document-types', 'catalogs/customers', 'future-module', 'billing/invoices', 'workshop/orders', 'inventory/movements', 'purchasing/orders', 'expenses', 'audit', 'document-alerts', 'reports/transport'] as $path) {
            $url = '/api/'.$path.'/__query-failure';
            Route::get($url, fn () => throw $this->failure());
            $response = $this->getJson($url)->assertStatus(500);
            $this->assertSafeResponse($response->getContent());
            $this->assertSafeLogs();
        }
        Route::get('/__query-failure', fn () => throw $this->failure());
        $response = $this->get('/__query-failure')->assertStatus(500)->assertSeeText('Server Error');
        $this->assertSafeResponse($response->getContent());
        $this->assertSafeLogs();
    }

    public function test_existing_module_statuses_and_payloads_are_preserved(): void
    {
        foreach (['23505', '23514', '40001', '08006', 'XX000'] as $state) {
            foreach (['transport' => [422, 422, 409, 500, 500], 'workshop' => [409, 422, 409, 503, 500], 'billing' => [409, 422, 409, 503, 500]] as $module => $statuses) {
                $error = $this->failure($state);
                $url = '/api/'.$module.'/__query-failure-'.$state;
                Route::get($url, fn () => throw $error);
                $expected = $module === 'transport'
                    ? TransportApiErrors::databaseError($error)
                    : WorkshopApiErrors::databaseError($error);
                $response = $this->getJson($url)->assertStatus($statuses[array_search($state, ['23505', '23514', '40001', '08006', 'XX000'])]);
                $response->assertExactJson($expected->getData(true));
                $this->assertSafeResponse($response->getContent());
            }
        }
        $this->assertSafeLogs();
    }

    public function test_real_postgres_failure_is_reported_without_bindings_or_debug_details(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/users/__real-query-failure', fn () => DB::select('SELECT CAST(? AS integer)', ['synthetic-token']));
        $this->getJson('/api/users/__real-query-failure')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $this->assertSame('22P02', $this->logs->getRecords()[0]['context']['sqlstate']);
        $this->assertSafeLogs();
    }

    public function test_console_reporting_and_untrusted_sqlstate_are_safe(): void
    {
        app(ExceptionHandler::class)->report($this->failure('password=synthetic-secret'));
        $this->assertSame('unknown', $this->logs->getRecords()[0]['context']['sqlstate']);
        $this->assertSafeLogs();
    }

    private function assertSafeResponse(string $body): void
    {
        foreach (['synthetic-secret', 'synthetic-token', 'private-connection', 'private_table', 'internal-host', 'private/storage/key', 'SQLSTATE', 'Illuminate\\Database', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    private function assertSafeLogs(): void
    {
        $this->assertNotEmpty($this->logs->getRecords());
        foreach ($this->logs->getRecords() as $record) {
            $this->assertSame('database.operation_failed', $record['message']);
            $this->assertSame(['sqlstate'], array_keys($record['context']));
            $this->assertMatchesRegularExpression('/\A(?:[A-Z0-9]{5}|unknown)\z/', $record['context']['sqlstate']);
        }
        foreach (['synthetic-secret', 'synthetic-token', 'private-connection', 'private_table', 'internal-host', 'private/storage/key', 'select ', 'exception'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($this->logs->getRecords(), JSON_THROW_ON_ERROR));
        }
    }
}
