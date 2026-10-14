<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\AccessAndPersonnel\Models\Role;
use App\Modules\FleetAndDocuments\Application\FleetAdministration;
use App\Modules\FleetAndDocuments\Models\Document;
use App\Modules\FleetAndDocuments\Models\DocumentType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\PostgresTestCase;

class FleetDocumentWebTest extends PostgresTestCase
{
    private function account(string $role = 'administrator', bool $active = true): User
    {
        $user = User::factory()->create(['is_active' => $active]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    public function test_web_shell_is_authorized_without_private_data_and_is_not_cached(): void
    {
        $this->withoutVite();
        $this->actingAs($this->account());
        $response = $this->get('/documentacion')->assertOk()->assertViewIs('fleet-documents')
            ->assertSee('lang="es"', false)->assertSee('fleet-documents-app')->assertSee('viewport');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertDontSee('storage_key')->assertDontSee('uploaded_by');
    }

    public function test_documental_asset_lookup_is_paginated_minimal_and_does_not_grant_fleet_writes(): void
    {
        $admin = $this->account();
        $service = app(FleetAdministration::class);
        $service->save($admin, 'tractor_unit', ['code' => 'CAB-01']);
        $service->save($admin, 'tanker', ['code' => 'CIS-01', 'has_physical_partitions' => false,
            'compartments' => [['code' => 'A', 'capacity_liters' => '1000', 'is_active' => true]]]);
        foreach ([$admin, $this->account('secretary')] as $actor) {
            $this->actingAs($actor);
            $response = $this->getJson('/api/fleet-assets?per_page=1')->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)
                ->assertJsonPath('data.0.code', 'CAB-01')->assertJsonPath('data.0.type', 'tractor_unit');
            $this->assertSame(['id', 'code', 'type'], array_keys($response->json('data.0')));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->getJson('/api/fleet-assets?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.code', 'CIS-01');
            $this->getJson('/api/fleet-assets?per_page=101')->assertUnprocessable();
            $this->getJson('/api/fleet-assets?page=0')->assertUnprocessable();
        }
        $this->getJson('/api/tractor-units')->assertForbidden();
        $this->postJson('/api/tractor-units', ['code' => 'FORBIDDEN'])->assertForbidden();
        $this->postJson('/api/tankers', [])->assertForbidden();
        $this->postJson('/api/fleet-assets', [])->assertStatus(405);
    }

    public function test_documental_asset_lookup_rejects_other_roles_inactive_and_anonymous_accounts(): void
    {
        $this->getJson('/api/fleet-assets')->assertUnauthorized();
        foreach (['driver', 'mechanic'] as $role) {
            $this->actingAs($this->account($role))->getJson('/api/fleet-assets')->assertForbidden();
        }
        $this->actingAs($this->account('secretary', false))->getJson('/api/fleet-assets')->assertForbidden();
        $secretary = $this->account('secretary');
        $this->actingAs($secretary)->getJson('/api/fleet-assets')->assertOk();
        $secretary->roles()->detach();
        $this->getJson('/api/fleet-assets')->assertForbidden();
    }

    public function test_document_responses_expose_only_author_id_and_display_name_including_history(): void
    {
        Storage::fake('fleet_documents');
        $admin = $this->account();
        $asset = app(FleetAdministration::class)->save($admin, 'tractor_unit', ['code' => 'CAB-AUTHOR']);
        $type = DocumentType::create(['name' => 'Permiso', 'tracks_expiration' => false,
            'required_for_tractor_units' => true, 'required_for_tankers' => false]);
        $author = $this->account('secretary');
        $this->actingAs($author);
        $file = new UploadedFile(__DIR__.'/../Fixtures/fleet-documents/valid.pdf', 'permiso.pdf', null, null, true);
        $created = $this->postJson('/api/fleet-assets/'.$asset->id.'/documents', ['file' => $file, 'document_type_id' => $type->id])
            ->assertCreated()->assertJsonPath('data.uploaded_by', ['id' => (string) $author->id, 'name' => $author->name]);
        $id = $created->json('data.id');
        $this->postJson('/api/fleet-assets/'.$asset->id.'/documents/'.$id.'/renew', ['file' => $file])->assertCreated();
        foreach (['/api/documents', '/api/fleet-assets/'.$asset->id.'/documents', '/api/fleet-assets/'.$asset->id.'/documents/history'] as $url) {
            $response = $this->getJson($url)->assertOk();
            foreach ($response->json('data') as $document) {
                $this->assertSame(['id' => (string) $author->id, 'name' => $author->name], $document['uploaded_by']);
                $this->assertArrayNotHasKey('storage_key', $document);
            }
            $response->assertDontSee($author->email)->assertDontSee($author->password);
        }
        $this->getJson('/api/documents/'.$id)->assertOk()->assertJsonPath('data.is_current', false)
            ->assertJsonPath('data.uploaded_by', ['id' => (string) $author->id, 'name' => $author->name])
            ->assertDontSee(Document::findOrFail($id)->storage_key);
    }
}
