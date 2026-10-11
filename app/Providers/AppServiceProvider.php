<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Console\BootstrapAdministrator;
use App\Modules\FleetAndDocuments\Console\ReconcileDocumentStorage;
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
        Gate::define('manage-catalogs', fn (User $user): bool => $user->is_active && $user->roles()->where('name', 'administrator')->exists()
        );
        Gate::define('manage-personnel', fn (User $user): bool => $user->is_active && $user->roles()->where('name', 'administrator')->exists());
        // Provisional fleet permissions approved for this stage.
        Gate::define('manage-fleet', fn (User $user): bool => $user->is_active && $user->roles()->where('name', 'administrator')->exists());
        Gate::define('manage-document-types', fn (User $user): bool => $user->is_active && $user->roles()->where('name', 'administrator')->exists());
        Gate::define('manage-fleet-documents', fn (User $user): bool => $user->is_active && $user->roles()->whereIn('name', ['administrator', 'secretary'])->exists());
        $this->commands([BootstrapAdministrator::class, ReconcileDocumentStorage::class]);
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip())
        );
    }
}
