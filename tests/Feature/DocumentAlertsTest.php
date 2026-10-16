<?php

namespace Tests\Feature;

use App\Modules\FleetAndDocuments\Application\DocumentAlerts;
use App\Modules\FleetAndDocuments\Models\Document;
use App\Modules\FleetAndDocuments\Models\DocumentType;
use App\Modules\FleetAndDocuments\Models\FleetAsset;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Tests\Support\TransportFixtures;

class DocumentAlertsTest extends PostgresTestCase
{
    use TransportFixtures;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function document(?string $expiration, bool $tracks = true, string $kind = 'tractor_unit'): Document
    {
        $asset = FleetAsset::create(['code' => 'ALERT-'.Str::uuid(), 'type' => $kind]);
        $type = DocumentType::create(['name' => 'Permiso '.Str::uuid(), 'tracks_expiration' => $tracks, 'required_for_tractor_units' => false, 'required_for_tankers' => false]);

        return Document::create(['fleet_asset_id' => $asset->id, 'document_type_id' => $type->id, 'storage_key' => 'fleet/'.Str::uuid().'.pdf', 'expires_on' => $expiration, 'uploaded_by_id' => auth()->id()])->refresh();
    }

    public function test_permissions_for_office_only_and_revocation(): void
    {
        $this->withoutVite();
        $this->get('/alertas')->assertRedirect('/login');
        $this->getJson('/api/document-alerts')->assertUnauthorized();
        foreach (['administrator', 'secretary'] as $role) {
            $this->actingAs($this->actor($role))->get('/alertas')->assertOk();
            $this->getJson('/api/document-alerts')->assertOk();
            $this->getJson('/api/document-alerts/summary')->assertOk();
            $this->assertContains('/alertas', array_column($this->getJson('/api/navigation')->json('data.modules'), 'href'));
        }
        foreach (['driver', 'mechanic'] as $role) {
            $this->actingAs($this->actor($role))->get('/alertas')->assertForbidden();
            $this->getJson('/api/document-alerts')->assertForbidden();
            $this->postJson('/api/document-alerts/'.Str::uuid().'/review')->assertForbidden();
        }
        $user = $this->actor();
        $this->actingAs($user);
        $user->roles()->detach();
        $this->getJson('/api/document-alerts')->assertForbidden();
        $this->actingAs($this->actor('secretary', false))->getJson('/api/document-alerts/summary')->assertForbidden();
    }

    public function test_fifteen_day_limits_no_expiration_and_complete_local_day(): void
    {
        $this->actingAs($this->actor());
        CarbonImmutable::setTestNow('2026-10-16T05:59:59Z'); // Costa Rica still October 15.
        $expired = $this->document('2026-10-14');
        $today = $this->document('2026-10-15');
        $fifteen = $this->document('2026-10-30', kind: 'tanker');
        $this->document('2026-10-31');
        $this->document(null, false);
        $this->document('2026-10-10', false);
        $response = $this->getJson('/api/document-alerts')->assertOk()->assertJsonPath('meta.total', 3)->assertDontSee('storage_key');
        $rows = collect($response->json('data'))->keyBy('id');
        $this->assertSame(-1, $rows[$expired->id]['days_remaining']);
        $this->assertSame(0, $rows[$today->id]['days_remaining']);
        $this->assertSame('expiring_soon', $rows[$today->id]['status']);
        $this->assertSame(15, $rows[$fifteen->id]['days_remaining']);
        $this->getJson('/api/document-alerts/summary')->assertOk()->assertJsonPath('data.expired', 1)->assertJsonPath('data.expiring_soon', 2)->assertJsonPath('data.personnel_supported', false);
        CarbonImmutable::setTestNow('2026-10-16T06:00:00Z');
        $this->getJson('/api/document-alerts?status=expired')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_filters_and_validation_are_based_on_expiration_dates(): void
    {
        $this->actingAs($this->actor());
        CarbonImmutable::setTestNow('2026-10-16T12:00:00Z');
        $document = $this->document('2026-10-20', kind: 'tanker');
        $this->document('2026-10-15');
        $url = '/api/document-alerts?asset_type=tanker&status=expiring_soon&from=2026-10-20&to=2026-10-20&document_type_id='.$document->document_type_id.'&fleet_asset_id='.$document->fleet_asset_id;
        $this->getJson($url)->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $document->id)->assertJsonPath('data.0.original_href', '/api/documents/'.$document->id);
        $this->getJson('/api/document-alerts?from=2030-01-01')->assertOk()->assertJsonPath('meta.total', 0);
        foreach (['status=valid', 'asset_type=employee', 'attention=resolved', 'from=invalid', 'from=2026-10-20&to=2026-10-10', 'per_page=101'] as $invalid) {
            $this->getJson('/api/document-alerts?'.$invalid)->assertUnprocessable();
        }
        $this->getJson('/api/document-alerts?to=2026-10-20')->assertOk();
    }

    public function test_review_is_persistent_idempotent_immutable_and_does_not_change_source(): void
    {
        $admin = $this->actor();
        $this->actingAs($admin);
        CarbonImmutable::setTestNow('2026-10-16T12:00:00Z');
        $document = $this->document('2026-10-16');
        $before = $document->getAttributes();
        $url = '/api/document-alerts/'.$document->id.'/review';
        $review = $this->postJson($url)->assertOk()->json('data');
        $this->actingAs($this->actor('secretary'))->postJson($url)->assertOk()->assertJsonPath('data.id', $review['id'])->assertJsonPath('data.reviewed_by_id', $admin->id);
        $this->getJson('/api/document-alerts?attention=reviewed')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.review.by.name', $admin->name);
        $this->getJson('/api/document-alerts?attention=unreviewed')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson($url, ['reviewed_by_id' => $admin->id])->assertUnprocessable();
        $this->assertEquals($before, $document->fresh()->getAttributes());
        $this->assertDatabaseCount('document_alert_reviews', 1);
        $this->rejected(fn () => DB::table('document_alert_reviews')->delete());
        $this->rejected(fn () => DB::table('document_alert_reviews')->update(['reviewed_by_id' => auth()->id()]));
        CarbonImmutable::setTestNow('2026-10-17T12:00:00Z');
        $this->getJson('/api/document-alerts')->assertOk()->assertJsonPath('data.0.status', 'expired')->assertJsonPath('data.0.review.validity_at_review', 'expiring_soon');
    }

    public function test_renewals_and_type_changes_respect_historical_configuration(): void
    {
        $this->actingAs($this->actor());
        CarbonImmutable::setTestNow('2026-10-16T12:00:00Z');
        $old = $this->document('2026-10-15');
        $this->postJson('/api/document-alerts/'.$old->id.'/review')->assertOk();
        $new = Document::create(['fleet_asset_id' => $old->fleet_asset_id, 'document_type_id' => $old->document_type_id, 'storage_key' => 'fleet/new.pdf', 'expires_on' => '2026-10-20', 'uploaded_by_id' => auth()->id()]);
        DocumentType::whereKey($old->document_type_id)->update(['tracks_expiration' => false, 'name' => 'Configuración futura']);
        $this->getJson('/api/document-alerts')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $new->id)->assertJsonPath('data.0.review', null);
        $this->postJson('/api/document-alerts/'.$new->id.'/review')->assertOk();
        Document::create(['fleet_asset_id' => $old->fleet_asset_id, 'document_type_id' => $old->document_type_id, 'storage_key' => 'fleet/no-expiration.pdf', 'expires_on' => null, 'uploaded_by_id' => auth()->id()]);
        $this->getJson('/api/document-alerts')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('document_alert_reviews', 2);
        $this->postJson('/api/document-alerts/'.$old->id.'/review')->assertOk();
        $valid = $this->document('2026-12-01');
        $this->postJson('/api/document-alerts/'.$valid->id.'/review')->assertConflict();
        $other = Document::create(['fleet_asset_id' => $valid->fleet_asset_id, 'document_type_id' => $valid->document_type_id, 'storage_key' => 'fleet/replacement.pdf', 'expires_on' => '2026-10-15', 'uploaded_by_id' => auth()->id()]);
        $this->postJson('/api/document-alerts/'.$valid->id.'/review')->assertConflict();
        $this->assertTrue($other->current()->whereKey($other->id)->exists());
    }

    public function test_scheduled_check_is_repeatable_read_only_and_uses_local_timezone(): void
    {
        $this->actingAs($this->actor());
        CarbonImmutable::setTestNow('2026-10-16T12:00:00Z');
        $this->document('2026-10-15');
        $first = app(DocumentAlerts::class)->summary();
        $this->artisan('documents:check-expirations')->assertSuccessful();
        $this->artisan('documents:check-expirations')->assertSuccessful();
        $this->assertSame($first, app(DocumentAlerts::class)->summary());
        $this->assertDatabaseCount('document_alert_reviews', 0);
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'documents:check-expirations'));
        $this->assertCount(1, $events);
        $this->assertSame('America/Costa_Rica', $events->first()->timezone);
        $this->assertSame('0 6 * * *', $events->first()->expression);
    }
}
