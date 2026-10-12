<?php

use App\Modules\AccessAndPersonnel\Http\Middleware\EnsureActiveAccount;
use App\Modules\TransportAndDispatch\Http\Middleware\TransportApiErrors;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (QueryException $exception, Request $request) {
            if ($request->is('api/transport/*')) {
                return TransportApiErrors::databaseError($exception);
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
