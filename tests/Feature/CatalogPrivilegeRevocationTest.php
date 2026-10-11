<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use App\Modules\TransportAndDispatch\Http\Controllers\CustomerController;
use App\Modules\TransportAndDispatch\Models\Customer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Tests\PostgresTestCase;

class CatalogPrivilegeRevocationTest extends PostgresTestCase
{
    public function test_catalog_write_rechecks_an_actor_who_lost_privileges_after_http_authorization(): void
    {
        foreach (['deactivation', 'role-removal'] as $revocation) {
            $admin = User::factory()->create(['is_active' => true]);
            $admin->roles()->attach(Role::firstOrCreate(['name' => 'administrator']));
            $this->assertTrue($admin->can('manage-catalogs'));
            if ($revocation === 'deactivation') {
                User::whereKey($admin->id)->update(['is_active' => false]);
            } else {
                $admin->roles()->detach();
            }
            foreach (['store', 'update'] as $operation) {
                $customer = Customer::create(['name' => 'Original']);
                $before = Customer::count();
                $request = Request::create('/api/catalogs/customers', 'POST', ['name' => 'No guardar']);
                $request->setUserResolver(fn () => $admin);
                try {
                    $controller = app(CustomerController::class);
                    $operation === 'store' ? $controller->store($request) : $controller->update($request, $customer->id);
                    $this->fail('Una autorización anterior no permite escribir tras revocación.');
                } catch (AuthorizationException $exception) {
                    $this->assertSame($before, Customer::count());
                    $this->assertSame('Original', $customer->refresh()->name);
                }
            }
        }
    }
}
