<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Gate;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class TransportPermissionTest extends PostgresTestCase
{
    use TransportFixtures;

    public function test_administrator_and_secretary_have_core_access_but_only_administrator_decides(): void
    {
        foreach (['administrator', 'secretary', 'driver', 'mechanic'] as $role) {
            $actor = $this->actor($role);
            foreach (['manage-transport-core', 'request-transport-authorization', 'correct-transport-record', 'record-transport-information', 'resolve-transport-warning'] as $ability) {
                $this->assertSame(in_array($role, ['administrator', 'secretary']), Gate::forUser($actor)->allows($ability));
            }
            $this->assertSame($role === 'administrator', Gate::forUser($actor)->allows('decide-transport-authorization'));
        }
    }

    public function test_inactive_anonymous_and_revoked_sessions_do_not_keep_permissions(): void
    {
        $actor = $this->actor();
        $this->assertTrue(Gate::forUser($actor)->allows('manage-transport-core'));
        $actor->roles()->detach();
        $this->assertFalse(Gate::forUser($actor)->allows('manage-transport-core'));
        $inactive = $this->actor('administrator', false);
        $this->assertFalse(Gate::forUser($inactive)->allows('manage-transport-core'));
        $active = $this->actor();
        $active->newQuery()->whereKey($active->id)->update(['is_active' => false]);
        $this->assertFalse(Gate::forUser($active)->allows('decide-transport-authorization'));
        $this->assertFalse(Gate::allows('manage-transport-core'));
    }

    public function test_core_access_does_not_expand_secretary_fleet_personnel_or_catalog_permissions(): void
    {
        $actor = $this->actor('secretary');
        $this->assertTrue(Gate::forUser($actor)->allows('correct-transport-record'));
        foreach (['manage-fleet', 'manage-personnel', 'manage-catalogs', 'manage-document-types'] as $ability) {
            $this->assertFalse(Gate::forUser($actor)->allows($ability));
        }
        $this->assertTrue(Gate::forUser($actor)->allows('manage-fleet-documents'));
    }

    public function test_database_actor_checks_reject_inactive_or_non_office_registration(): void
    {
        foreach ([$this->actor('driver'), $this->actor('mechanic'), $this->actor('secretary', false)] as $actor) {
            $this->rejected(fn () => $this->request($actor));
        }
    }
}
