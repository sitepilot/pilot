<?php

use App\Services\CloudflareService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.cloudflare.token' => 'test-token']);
});

it('throws when making a request with no token configured', function () {
    config(['services.cloudflare.token' => null]);

    expect(fn () => (new CloudflareService)->getZoneByName('example.com'))
        ->toThrow(RuntimeException::class, 'Cloudflare API token is not configured');
});

it('returns the matching zone by name', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [['id' => 'zone-123', 'name' => 'example.com']],
        ]),
    ]);

    $zone = (new CloudflareService)->getZoneByName('example.com');

    expect($zone)->toMatchArray(['id' => 'zone-123', 'name' => 'example.com']);
});

it('returns null when the zone does not exist', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => [],
        ]),
    ]);

    expect((new CloudflareService)->getZoneByName('missing.com'))->toBeNull();
});

it('follows pagination when listing custom hostnames', function () {
    $page1 = array_map(fn ($i) => ['hostname' => "host{$i}.com"], range(1, 50));
    $page2 = [['hostname' => 'host51.com']];

    Http::fakeSequence('api.cloudflare.com/client/v4/zones/zone-123/custom_hostnames*')
        ->push(['success' => true, 'result' => $page1, 'result_info' => ['total_pages' => 2]])
        ->push(['success' => true, 'result' => $page2, 'result_info' => ['total_pages' => 2]]);

    $hostnames = (new CloudflareService)->getCustomHostnames('zone-123');

    expect($hostnames)->toHaveCount(51)
        ->and($hostnames[50]['hostname'])->toBe('host51.com');
});

it('queries each requested name and merges results de-duplicated by id', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-123/custom_hostnames*' => Http::response([
            'success' => true,
            'result' => [['id' => 'ch-1', 'hostname' => 'example.com']],
            'result_info' => ['total_pages' => 1],
        ]),
    ]);

    // Both names return the same record; it should appear only once.
    $hostnames = (new CloudflareService)->getCustomHostnames('zone-123', ['example.com', 'www.example.com']);

    expect($hostnames)->toHaveCount(1)
        ->and($hostnames[0]['id'])->toBe('ch-1');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'hostname=example.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'hostname=www.example.com'));
});

it('returns the raw BIND body when exporting DNS records', function () {
    $bind = "\$ORIGIN example.com.\nwww 1 IN A 192.0.2.1\n";

    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-123/dns_records/export' => Http::response($bind),
    ]);

    expect((new CloudflareService)->exportDnsRecords('zone-123'))->toBe($bind);
});

it('throws with the Cloudflare error message when the DNS export fails', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-123/dns_records/export' => Http::response([
            'success' => false,
            'errors' => [['code' => 1001, 'message' => 'Invalid zone identifier']],
        ], 400),
    ]);

    expect(fn () => (new CloudflareService)->exportDnsRecords('zone-123'))
        ->toThrow(RuntimeException::class, 'Invalid zone identifier');
});

it('throws with the Cloudflare error message on a failed request', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => false,
            'errors' => [['code' => 9109, 'message' => 'Invalid access token']],
        ], 403),
    ]);

    expect(fn () => (new CloudflareService)->getZoneByName('example.com'))
        ->toThrow(RuntimeException::class, 'Invalid access token');
});
