<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Console\BootstrapAdministrator;
use App\Modules\FleetAndDocuments\Console\ReconcileDocumentStorage;
use App\Modules\TransportAndDispatch\Policies\TransportCorePolicy;
use App\Modules\Workshop\Application\WorkshopAccess;
use App\Modules\Workshop\Models\WorkshopWorkOrder;
use App\Modules\Workshop\Policies\WorkshopWorkOrderPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(WorkshopWorkOrder::class, WorkshopWorkOrderPolicy::class);
        Gate::define('access-workshop', function (User $actor): bool {
            app(WorkshopAccess::class)->module($actor);

            return true;
        });
        Gate::define('manage-catalogs', fn (User $user): bool => $user->is_active && $user->roles()->where('name', 'administrator')->exists()
        );
        Gate::define('manage-personnel', fn (User $user): bool => $user->is_active && $user->roles()->where('name', 'administrator')->exists());
        // Provisional fleet permissions approved for this stage.
        Gate::define('manage-fleet', fn (User $user): bool => $user->is_active && $user->roles()->where('name', 'administrator')->exists());
        Gate::define('manage-document-types', fn (User $user): bool => $user->is_active && $user->roles()->where('name', 'administrator')->exists());
        Gate::define('manage-fleet-documents', fn (User $user): bool => $user->is_active && $user->roles()->whereIn('name', ['administrator', 'secretary'])->exists());
        // Persistence permissions only; driver ownership awaits assignments in 5.2B.
        foreach (['manage-transport-core', 'request-transport-authorization', 'correct-transport-record', 'record-transport-information', 'resolve-transport-warning'] as $ability) {
            Gate::define($ability, [TransportCorePolicy::class, 'manage']);
        }
        Gate::define('decide-transport-authorization', [TransportCorePolicy::class, 'decide']);
        Gate::define('manage-trip-assignments', [TransportCorePolicy::class, 'manage']);
        Gate::define('manage-trip-operations', [TransportCorePolicy::class, 'manage']);
        Gate::define('report-trip-information', [TransportCorePolicy::class, 'report']);
        Gate::define('authorize-trip-assignment-exception', [TransportCorePolicy::class, 'decide']);
        $this->commands([BootstrapAdministrator::class, ReconcileDocumentStorage::class]);
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip())
        );
    }
}
