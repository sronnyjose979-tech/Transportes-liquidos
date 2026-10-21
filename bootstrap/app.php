<?php

use App\Http\Middleware\MeasureSystemRequests;
use App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount;
use App\Modules\FleetAndDocuments\Domain\DocumentOperationFailed;
use App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors;
use App\Modules\Workshop\Http\Middleware\WorkshopApiErrors;
use App\Support\RequestPerformance;
use App\Support\SafeOperationalFailure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(MeasureSystemRequests::class);
        // Local TLS terminates at Nginx; only the loopback proxy may supply these headers.
        $middleware->trustProxies(
            at: ['127.0.0.1'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );
        $middleware->prependToPriorityList(
            AuthenticatesSessions::class,
            EnsureActiveAccount::class,
        );
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $failure) {
            $exception = SafeOperationalFailure::database($failure);
            if ($exception === null) {
                if (SafeOperationalFailure::documentary($failure)) {
                    Log::error('fleet_document.operation_failed', ['kind' => 'storage_or_runtime']);

                    return false;
                }

                return null;
            }
            // Never pass the exception, SQL, bindings or connection details to the logger.
            // Cover HTTP and console alike, including routes added in the future.
            $state = $exception->errorInfo[0] ?? null;
            $metrics = RequestPerformance::current();
            $metrics?->failed($exception);
            Log::warning('database.operation_failed', [
                'sqlstate' => is_string($state) && preg_match('/\A[A-Z0-9]{5}\z/D', $state) ? $state : 'unknown',
            ] + ($metrics?->correlation() ?? []));

            return false;
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (SafeOperationalFailure::documentary($exception)) {
                return (new DocumentOperationFailed)->render($request);
            }
            if (! $exception instanceof QueryException && SafeOperationalFailure::database($exception) !== null) {
                return response()->json(['message' => 'Server Error'], 500)->header('Cache-Control', 'private, no-store');
            }

            return null;
        });
        $exceptions->render(function (QueryException $exception, Request $request) {
            if ($request->is('api/audit*', 'api/document-alerts*', 'api/reports/*', 'api/expenses*', 'api/billing/*', 'api/workshop/*', 'api/workshop', 'api/purchasing/*', 'api/inventory/*', 'api/catalogs/spare-parts*', 'api/purchasing', 'api/inventory')) {
                return WorkshopApiErrors::databaseError($exception);
            }
            if ($request->is('api/transport/*')) {
                return TransportApiErrors::databaseError($exception);
            }

            // Preserve the generic 500 contract without Laravel's debug exception payload.
            $response = $request->is('api/*') || $request->expectsJson()
                ? response()->json(['message' => 'Server Error'], 500)
                : response('Server Error', 500)->header('Content-Type', 'text/plain; charset=UTF-8');

            return $response->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
        });
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('api/workshop/*') && $exception->validator->failed() === [] && (isset($exception->errors()['version']) || ($exception->errors()['request_key'][0] ?? '') === 'Clave reutilizada con contenido distinto.')) {
                return response()->json(['message' => 'Conflicto de versión o clave idempotente.', 'errors' => $exception->errors()], 409);
            }

            return null;
        });
        $exceptions->render(function (RuntimeException $exception, Request $request) {
            if ($request->is('api/audit*', 'api/document-alerts*', 'api/reports/*', 'api/expenses*', 'api/billing/*', 'api/workshop/*', 'api/purchasing/*', 'api/inventory/*', 'api/catalogs/spare-parts*') && ! $exception instanceof HttpExceptionInterface) {
                return response()->json(['message' => 'No se pudo confirmar la operación; conserve la clave y consulte el recibo.'], 503);
            }

            return null;
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/audit*', 'api/document-alerts*', 'api/reports/*', 'api/expenses*', 'api/billing/*', 'api/workshop/*', 'api/purchasing/*', 'api/inventory/*', 'api/catalogs/spare-parts*') && ! $exception instanceof AuthenticationException && ! $exception instanceof AuthorizationException && ! $exception instanceof ValidationException && ! $exception instanceof HttpExceptionInterface) {
                return response()->json(['message' => 'No se pudo completar la operación; consulte su estado antes de reintentar.'], $exception instanceof LogicException ? 503 : 500);
            }

            return null;
        });
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->is('api/audit*', 'api/document-alerts*', 'api/reports/*', 'api/expenses*', 'api/navigation', 'api/dashboard', 'api/billing/*', 'api/transport/my-trips*', 'api/transport/my-records', 'api/workshop/*', 'api/workshop', 'api/purchasing/*', 'api/inventory/*', 'api/catalogs/spare-parts*', 'api/purchasing', 'api/inventory')) {
                $response->headers->set('Cache-Control', 'private, no-store');
                $response->headers->set('X-Content-Type-Options', 'nosniff');
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
