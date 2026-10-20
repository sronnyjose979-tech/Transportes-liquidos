<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Throwable;

final class RequestPerformance
{
    private readonly int $started;

    private readonly string $id;

    private int $attempted = 0;

    private int $completed = 0;

    private float $sqlMs = 0;

    private ?int $operationStarted = null;

    private ?float $failedOperationMs = null;

    private ?string $sqlstate = null;

    private ?string $failurePhase = null;

    private string $phase = 'request_pipeline';

    private bool $finished = false;

    public function __construct(private readonly string $endpoint)
    {
        $this->started = hrtime(true);
        $this->id = (string) Str::uuid();
    }

    public static function current(): ?self
    {
        if (! app()->bound('request')) {
            return null;
        }

        $metrics = app('request')->attributes->get(self::class);

        return $metrics instanceof self ? $metrics : null;
    }

    public function beforeQuery(): void
    {
        if ($this->finished) {
            return;
        }
        $this->attempted++;
        $this->operationStarted = hrtime(true);
        $this->phase = $this->completed === 0 ? 'database_operation_before_first_success' : 'database_operation_after_success';
    }

    public function queryCompleted(float $milliseconds): void
    {
        if ($this->finished) {
            return;
        }
        $this->completed++;
        $this->sqlMs += $milliseconds;
        $this->operationStarted = null;
        $this->phase = 'request_pipeline';
    }

    public function failed(Throwable $error): void
    {
        if ($error instanceof QueryException && $this->sqlstate === null) {
            $this->failurePhase = $this->phase;
            $state = $error->errorInfo[0] ?? null;
            $this->sqlstate = is_string($state) && preg_match('/\A[A-Z0-9]{5}\z/D', $state) ? $state : 'unknown';
            if ($this->operationStarted !== null) {
                $this->failedOperationMs = (hrtime(true) - $this->operationStarted) / 1e6;
            }
        }
    }

    public function correlation(): array
    {
        return ['request_id' => $this->id, 'endpoint' => $this->endpoint, 'phase' => $this->failurePhase ?? $this->phase];
    }

    public function finish(int $status): array
    {
        $this->finished = true;

        return $this->correlation() + [
            'status' => $status,
            'duration_ms' => round((hrtime(true) - $this->started) / 1e6, 3),
            'sql_attempted_count' => $this->attempted,
            'sql_completed_count' => $this->completed,
            'sql_completed_ms' => round($this->sqlMs, 3),
            'failed_database_operation_ms' => $this->failedOperationMs === null ? null : round($this->failedOperationMs, 3),
            'connection_establishment_ms' => null,
            'connection_timing' => 'unavailable_lazy_pdo',
            'sqlstate' => $this->sqlstate,
        ];
    }
}
