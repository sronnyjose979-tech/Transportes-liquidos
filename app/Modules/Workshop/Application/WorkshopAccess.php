<?php

namespace App\Modules\Workshop\Application;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Application\ReadAuthorizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Resource permissions for the HTTP boundary; command services revalidate on commit. */
final class WorkshopAccess
{
    public function fresh(User $actor): User
    {
        $current = ReadAuthorizationContext::forActor($actor)?->actor ?? User::query()->findOrFail($actor->id);
        if (! $current->is_active) {
            throw new AuthorizationException('Cuenta no activa.');
        }

        return $current;
    }

    public function module(User $actor): void
    {
        $this->roles($actor, ['administrator', 'secretary', 'mechanic']);
    }

    public function roles(User $actor, array $roles): void
    {
        $current = $this->fresh($actor);
        if (! $current->hasAnyRole($roles)) {
            throw new AuthorizationException('Operación de Taller no autorizada.');
        }
    }

    public function office(User $actor): void
    {
        $this->roles($actor, ['administrator', 'secretary']);
    }

    public function admin(User $actor): void
    {
        $this->roles($actor, ['administrator']);
    }

    public function isOffice(User $actor): bool
    {
        return $this->fresh($actor)->hasAnyRole(['administrator', 'secretary']);
    }

    public function orders(User $actor, Builder $query, string $column = 'id'): Builder
    {
        $this->module($actor);
        if (! $this->isOffice($actor)) {
            $query->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('workshop_mechanic_assignments as permission_assignment')->join('employees as permission_employee', 'permission_employee.id', '=', 'permission_assignment.employee_id')->whereColumn('permission_assignment.work_order_id', $column)->where('permission_employee.user_id', $actor->id));
        }

        return $query;
    }

    public function readOrder(User $actor, string $id): void
    {
        DB::table('workshop_work_orders')->where('id', $id)->firstOrFail();
        if (! $this->orders($actor, DB::table('workshop_work_orders as permission_order'), 'permission_order.id')->where('permission_order.id', $id)->exists()) {
            throw new AuthorizationException('Orden fuera del alcance autorizado.');
        }
    }

    public function writeOrder(User $actor, string $id, array $roles = ['administrator', 'mechanic']): void
    {
        $this->roles($actor, $roles);
        DB::table('workshop_work_orders')->where('id', $id)->firstOrFail();
        if (! $this->isOffice($actor) && ! DB::table('workshop_mechanic_assignments as a')->join('employees as e', 'e.id', '=', 'a.employee_id')->where('a.work_order_id', $id)->where('e.user_id', $actor->id)->where('e.is_active', true)->where('a.starts_at', '<=', now())->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('workshop_mechanic_releases as r')->whereColumn('r.assignment_id', 'a.id'))->exists()) {
            throw new AuthorizationException('Participación vigente requerida.');
        }
    }

    public function readAsset(User $actor, string $id): void
    {
        $this->module($actor);
        DB::table('fleet_assets')->where('id', $id)->firstOrFail();
        if (! $this->isOffice($actor) && ! $this->orders($actor, DB::table('workshop_work_orders as permission_order'), 'permission_order.id')->where('permission_order.fleet_asset_id', $id)->exists()) {
            throw new AuthorizationException('Activo fuera del alcance autorizado.');
        }
    }

    public function readReport(User $actor, string $id): void
    {
        $this->module($actor);
        $report = DB::table('workshop_reports')->where('id', $id)->firstOrFail();
        if ($this->isOffice($actor) || (int) $report->recorded_by_id === (int) $actor->id || (int) $report->reporter_id === (int) $actor->id) {
            return;
        }
        if (! $this->orders($actor, DB::table('workshop_work_orders as permission_order'), 'permission_order.id')->join('workshop_report_links as permission_link', 'permission_link.work_order_id', '=', 'permission_order.id')->where('permission_link.report_id', $id)->exists()) {
            throw new AuthorizationException('Reporte fuera del alcance autorizado.');
        }
    }

    public function readAdmission(User $actor, string $id): void
    {
        $admission = DB::table('workshop_admissions')->where('id', $id)->firstOrFail();
        $this->readAsset($actor, $admission->fleet_asset_id);
    }

    public function requestOrder(string $requestId): ?string
    {
        return DB::table('workshop_authorization_requests')->where('id', $requestId)->firstOrFail()->work_order_id;
    }
}
