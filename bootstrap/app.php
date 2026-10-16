<?php

use App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount;
use App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors;
use App\Modules\Workshop\Http\Middleware\WorkshopApiErrors;
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
        $middleware->prependToPriorityList(
            AuthenticatesSessions::class,
            EnsureActiveAccount::class,
        );
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (QueryException $exception) {
            if (request()->is('api/reports/*')) {
                Log::warning('reports.database_query_rejected', ['sqlstate' => $exception->errorInfo[0] ?? null]);

                return false;
            }
            if (request()->is('api/billing/*', 'api/expenses*')) {
                Log::warning(request()->is('api/expenses*') ? 'expenses.database_operation_rejected' : 'billing.database_operation_rejected', ['sqlstate' => $exception->errorInfo[0] ?? null]);

                return false;
            }
            if (request()->is('api/workshop/*', 'api/purchasing/*', 'api/inventory/*', 'api/catalogs/spare-parts*')) {
                // Do not log SQL bindings containing private documents or economic data.
                Log::warning(request()->is('api/inventory/*', 'api/catalogs/spare-parts*') ? 'inventory.database_operation_rejected' : (request()->is('api/purchasing/*') ? 'purchasing.database_operation_rejected' : 'workshop.database_operation_rejected'), ['sqlstate' => $exception->errorInfo[0] ?? null]);

                return false;
            }

            return null;
        });
        $exceptions->render(function (QueryException $exception, Request $request) {
            if ($request->is('api/reports/*', 'api/expenses*', 'api/billing/*', 'api/workshop/*', 'api/workshop', 'api/purchasing/*', 'api/inventory/*', 'api/catalogs/spare-parts*', 'api/purchasing', 'api/inventory')) {
                return WorkshopApiErrors::databaseError($exception);
            }
            if ($request->is('api/transport/*')) {
                return TransportApiErrors::databaseError($exception);
            }

            return null;
        });
        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('api/workshop/*') && $exception->validator->failed() === [] && (isset($exception->errors()['version']) || ($exception->errors()['request_key'][0] ?? '') === 'Clave reutilizada con contenido distinto.')) {
                return response()->json(['message' => 'Conflicto de versión o clave idempotente.', 'errors' => $exception->errors()], 409);
            }

            return null;
        });
        $exceptions->render(function (RuntimeException $exception, Request $request) {
            if ($request->is('api/reports/*', 'api/expenses*', 'api/billing/*', 'api/workshop/*', 'api/purchasing/*', 'api/inventory/*', 'api/catalogs/spare-parts*') && ! $exception instanceof HttpExceptionInterface) {
                return response()->json(['message' => 'No se pudo confirmar la operación; conserve la clave y consulte el recibo.'], 503);
            }

            return null;
        });
        $exceptions->render(function (Throwable $exception, Request $request) {
            if ($request->is('api/reports/*', 'api/expenses*', 'api/billing/*', 'api/workshop/*', 'api/purchasing/*', 'api/inventory/*', 'api/catalogs/spare-parts*') && ! $exception instanceof AuthenticationException && ! $exception instanceof AuthorizationException && ! $exception instanceof ValidationException && ! $exception instanceof HttpExceptionInterface) {
                return response()->json(['message' => 'No se pudo completar la operación; consulte su estado antes de reintentar.'], $exception instanceof LogicException ? 503 : 500);
            }

            return null;
        });
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->is('api/reports/*', 'api/expenses*', 'api/navigation', 'api/dashboard', 'api/billing/*', 'api/transport/my-trips*', 'api/transport/my-records', 'api/workshop/*', 'api/workshop', 'api/purchasing/*', 'api/inventory/*', 'api/catalogs/spare-parts*', 'api/purchasing', 'api/inventory')) {
                $response->headers->set('Cache-Control', 'private, no-store');
                $response->headers->set('X-Content-Type-Options', 'nosniff');
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
