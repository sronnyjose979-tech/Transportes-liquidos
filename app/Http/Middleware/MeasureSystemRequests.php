<?php

namespace App\Http\Middleware;

use App\Support\RequestPerformance;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class MeasureSystemRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('performance.enabled') || ! $request->isMethod('GET') || ! $request->is('api/navigation', 'api/dashboard')) {
            return $next($request);
        }

        $metrics = new RequestPerformance('/'.$request->path());
        $request->attributes->set(RequestPerformance::class, $metrics);
        $status = 500;
        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (Throwable $error) {
            $status = $error instanceof HttpExceptionInterface ? $error->getStatusCode() : 500;
            $metrics->failed($error);
            throw $error;
        } finally {
            $context = $metrics->finish($status);
            // Diagnostics must never replace the original response or exception.
            try {
                Log::info('performance.request', $context);
            } catch (Throwable) {
                // A failing log destination must not break the request.
            }
        }
    }
}
