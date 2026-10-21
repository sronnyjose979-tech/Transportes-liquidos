<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Domain\DocumentOperationFailed;
use App\Modules\FleetAndDocuments\Infrastructure\PrivateDocumentStorage;
use App\Modules\FleetAndDocuments\Models\Document;
use App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors;
use App\Modules\Workshop\Http\Middleware\WorkshopApiErrors;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use PDOException;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    public function test_nested_document_database_failure_is_safe_in_logs_and_debug_http(): void
    {
        config(['app.debug' => true]);
        $failure = new \RuntimeException('synthetic-token', previous: new DocumentOperationFailed('synthetic-secret', previous: $this->failure('08006')));
        Route::get('/api/documents/__nested-failure', fn () => throw $failure);
        $response = $this->getJson('/api/documents/__nested-failure')->assertStatus(503);
        $this->assertSafeResponse($response->getContent());
        $this->assertSame('08006', $this->logs->getRecords()[0]['context']['sqlstate']);
        $this->assertSafeLogs();
    }

    public function test_nested_storage_failure_is_reported_without_private_messages(): void
    {
        $failure = new \RuntimeException('synthetic-token', previous: new DocumentOperationFailed('synthetic-secret', previous: new \RuntimeException('password=synthetic-secret private/storage/key')));
        app(ExceptionHandler::class)->report($failure);
        $records = $this->logs->getRecords();
        $this->assertSame('fleet_document.operation_failed', $records[0]['message']);
        $this->assertSame(['kind' => 'storage_or_runtime'], $records[0]['context']);
        $this->assertSafeResponse(json_encode($records, JSON_THROW_ON_ERROR));
    }

    public function test_storage_adapter_errors_are_sanitized_for_download_and_console_reconciliation(): void
    {
        config(['app.debug' => true]);
        Storage::shouldReceive('disk')->andThrow(new \RuntimeException('synthetic-token password=synthetic-secret private/storage/key'));
        Route::get('/api/documents/__storage-failure', fn () => app(PrivateDocumentStorage::class)->download(
            (new Document)->forceFill(['storage_key' => 'files/10000000-0000-4000-8000-000000000001.pdf'])
        ));
        $response = $this->getJson('/api/documents/__storage-failure')->assertStatus(503);
        $this->assertSafeResponse($response->getContent());
        try {
            app(PrivateDocumentStorage::class)->reconcile();
            $this->fail('Must report failed reconciliation.');
        } catch (DocumentOperationFailed $exception) {
            app(ExceptionHandler::class)->report($exception);
        }
        $this->assertCount(2, $this->logs->getRecords());
        foreach ($this->logs->getRecords() as $record) {
            $this->assertSame('fleet_document.operation_failed', $record['message']);
        }
        $this->assertSafeResponse(json_encode($this->logs->getRecords(), JSON_THROW_ON_ERROR));
    }

    public function test_reconciliation_command_fails_visibly_without_printing_private_causes(): void
    {
        $this->mock(PrivateDocumentStorage::class)->shouldReceive('reconcile')->once()
            ->andThrow(new \RuntimeException('password=synthetic-secret synthetic-token private/storage/key'));
        $this->artisan('fleet-documents:reconcile-storage')
            ->expectsOutputToContain('No se pudo reconciliar el almacenamiento documental.')
            ->doesntExpectOutputToContain('synthetic-secret')
            ->doesntExpectOutputToContain('synthetic-token')
            ->assertExitCode(1);
        $this->assertSame('fleet_document.operation_failed', $this->logs->getRecords()[0]['message']);
        $this->assertSafeResponse(json_encode($this->logs->getRecords(), JSON_THROW_ON_ERROR));
    }

    public function test_deferred_download_failure_never_reports_private_adapter_causes(): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->once()->andReturn(true);
        $disk->shouldReceive('download')->once()->andReturn(new StreamedResponse(
            fn () => throw new \RuntimeException('synthetic-token password=synthetic-secret private/storage/key')
        ));
        Storage::shouldReceive('disk')->andReturn($disk);
        $response = app(PrivateDocumentStorage::class)->download((new Document)->forceFill([
            'id' => '10000000-0000-4000-8000-000000000001', 'storage_key' => 'files/10000000-0000-4000-8000-000000000001.pdf',
        ]));
        try {
            $response->sendContent();
            $this->fail('Streaming failure must remain visible.');
        } catch (DocumentOperationFailed $exception) {
            app(ExceptionHandler::class)->report($exception);
        }
        $this->assertSame('fleet_document.operation_failed', $this->logs->getRecords()[0]['message']);
        $this->assertSafeResponse(json_encode($this->logs->getRecords(), JSON_THROW_ON_ERROR));
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
