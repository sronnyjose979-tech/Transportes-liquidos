<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LocalHttpsProxyTest extends TestCase
{
    public function test_loopback_proxy_preserves_https_origin_and_port(): void
    {
        $this->registerProbe();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('http://localhost/_test/https-origin', $this->forwardedHeaders())
            ->assertOk()
            ->assertJsonPath('secure', true)
            ->assertJsonPath('origin', 'https://192.168.0.24:8443');
    }

    public function test_external_client_cannot_spoof_https_or_forwarded_host(): void
    {
        $this->registerProbe();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->getJson('http://localhost/_test/https-origin', $this->forwardedHeaders())
            ->assertOk()
            ->assertJsonPath('secure', false)
            ->assertJsonPath('origin', 'http://localhost');
    }

    private function registerProbe(): void
    {
        Route::get('/_test/https-origin', fn (Request $request) => [
            'secure' => $request->secure(),
            'origin' => $request->getSchemeAndHttpHost(),
        ]);
    }

    private function forwardedHeaders(): array
    {
        return [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => '192.168.0.24:8443',
            'X-Forwarded-Port' => '8443',
        ];
    }
}
