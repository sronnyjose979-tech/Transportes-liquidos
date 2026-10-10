<?php

namespace App\Modules\AccessAndPersonnel\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AccessAndPersonnel\Http\Requests\EmployeeRequest;
use App\Modules\AccessAndPersonnel\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ], ['integer' => 'El campo :attribute debe ser entero.', 'min' => 'El campo :attribute es demasiado pequeño.', 'max' => 'El campo :attribute supera el límite permitido.']);

        return response()->json(Employee::query()->select(['id', 'name', 'is_active', 'user_id'])
            ->orderBy('id')->paginate($data['per_page'] ?? 25))
            ->header('Cache-Control', 'no-store');
    }

    public function show(string $id): JsonResponse
    {
        return $this->respond(Employee::query()->findOrFail($id));
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        try {
            $employee = DB::transaction(function () use ($request): Employee {
                $this->authorizeWrite($request);

                return Employee::query()->create($request->validated());
            });
        } catch (QueryException $exception) {
            $this->handleConstraintViolation($exception);
        }

        $this->audit($request, $employee, 'created');

        return $this->respond($employee, 201);
    }

    public function update(EmployeeRequest $request, string $id): JsonResponse
    {
        try {
            $employee = DB::transaction(function () use ($request, $id): Employee {
                $this->authorizeWrite($request);
                $employee = Employee::query()->lockForUpdate()->findOrFail($id);
                $employee->fill($request->validated())->save();

                return $employee;
            });
        } catch (QueryException $exception) {
            $this->handleConstraintViolation($exception);
        }

        $this->audit($request, $employee, 'updated');

        return $this->respond($employee);
    }

    private function authorizeWrite(EmployeeRequest $request): void
    {
        // Account administration uses the same lock, preventing stale administrator access.
        DB::select('SELECT pg_advisory_xact_lock(873210)');
        Gate::forUser($request->user()->fresh())->authorize('manage-personnel');
    }

    private function audit(EmployeeRequest $request, Employee $employee, string $action): void
    {
        $context = [
            'actor_id' => $request->user()->getKey(),
            'target_id' => $employee->getKey(),
            'fields' => array_keys($request->validated()),
        ];
        DB::afterCommit(fn () => Log::info('administration.employee.'.$action, $context));
    }

    private function respond(Employee $employee, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $employee->only(['id', 'name', 'is_active', 'user_id'])], $status)
            ->header('Cache-Control', 'no-store');
    }

    private function handleConstraintViolation(QueryException $exception): never
    {
        if (in_array($exception->errorInfo[0] ?? null, ['23505', '23503'], true)) {
            throw ValidationException::withMessages([
                'user_id' => 'La cuenta seleccionada no existe o ya está vinculada a otro empleado.',
            ]);
        }

        throw $exception;
    }
}
