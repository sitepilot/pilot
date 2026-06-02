<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.cloudflare.token' => 'test-token']);
    $this->outputPath = sys_get_temp_dir().'/cf-export-test-'.getmypid().'.zone';
});

afterEach(function () {
    if (File::exists($this->outputPath)) {
        File::delete($this->outputPath);
    }
});

function fakeZoneAndExport(array $zones, string $bind): void
{
    Http::fake([
        'api.cloudflare.com/client/v4/zones/*/dns_records/export' => Http::response($bind),
        'api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => $zones,
        ]),
    ]);
}

it('exports the zone DNS to a BIND file and prints the path', function () {
    $bind = "\$ORIGIN example.com.\n@ 1 IN SOA ns.example.com. ...\nwww 1 IN A 192.0.2.1\n";

    fakeZoneAndExport(
        zones: [['id' => 'zone-123', 'name' => 'example.com']],
        bind: $bind,
    );

    $exitCode = Artisan::call('cf:export', [
        'zone' => 'example.com',
        '--output' => $this->outputPath,
    ]);

    expect($exitCode)->toBe(0)
        ->and(File::exists($this->outputPath))->toBeTrue()
        ->and(File::get($this->outputPath))->toBe($bind)
        ->and(Artisan::output())->toContain($this->outputPath);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'dns_records/export'));
});

it('reports when the zone is not found and writes nothing', function () {
    fakeZoneAndExport(zones: [], bind: '');

    $this->artisan('cf:export', [
        'zone' => 'missing.com',
        '--output' => $this->outputPath,
    ])
        ->assertExitCode(1)
        ->expectsOutputToContain("Zone 'missing.com' not found.");

    expect(File::exists($this->outputPath))->toBeFalse();
});

it('fails clearly when no API token is configured', function () {
    config(['services.cloudflare.token' => null]);

    $this->artisan('cf:export', [
        'zone' => 'example.com',
        '--output' => $this->outputPath,
    ])
        ->assertExitCode(1)
        ->expectsOutputToContain('Cloudflare API token is not configured');
});
