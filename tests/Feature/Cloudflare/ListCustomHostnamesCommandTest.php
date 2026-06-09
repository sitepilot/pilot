<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->config = __DIR__.'/../../Fixtures/cloudflare';
    $this->invalidConfig = __DIR__.'/../../Fixtures/cloudflare-invalid';
});

function fakeCloudflare(array $zones, array $hostnames): void
{
    Http::fake([
        'api.cloudflare.com/client/v4/zones/*/custom_hostnames*' => Http::response([
            'success' => true,
            'result' => $hostnames,
            'result_info' => ['total_pages' => 1],
        ]),
        'api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true,
            'result' => $zones,
        ]),
    ]);
}

it('lists custom hostnames with their origin server', function () {
    fakeCloudflare(
        zones: [['id' => 'zone-123', 'name' => 'example.com']],
        hostnames: [
            [
                'hostname' => 'shop.customer.com',
                'ssl' => ['status' => 'active'],
                'custom_origin_server' => 'origin.example.com',
            ],
        ],
    );

    $exitCode = Artisan::call('cf:hostname', ['--zone' => 'example.com', '--config' => $this->config]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('shop.customer.com')
        ->and($output)->toContain('active')
        ->and($output)->toContain('origin.example.com');
});

it('falls back to the configured default zone when no zone is given', function () {
    fakeCloudflare(
        zones: [['id' => 'zone-123', 'name' => 'sitepilot.cloud']],
        hostnames: [
            [
                'hostname' => 'shop.customer.com',
                'ssl' => ['status' => 'active'],
                'custom_origin_server' => 'origin.example.com',
            ],
        ],
    );

    $exitCode = Artisan::call('cf:hostname', ['--config' => $this->config]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('shop.customer.com');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'name=sitepilot.cloud'));
});

it('shows a placeholder when a hostname has no origin server', function () {
    fakeCloudflare(
        zones: [['id' => 'zone-123', 'name' => 'example.com']],
        hostnames: [
            ['hostname' => 'shop.customer.com', 'ssl' => ['status' => 'pending']],
        ],
    );

    $exitCode = Artisan::call('cf:hostname', ['--zone' => 'example.com', '--config' => $this->config]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('shop.customer.com')
        ->and($output)->toContain('—');
});

it('filters to the hostname and its www variant when a hostname is given', function () {
    fakeCloudflare(
        zones: [['id' => 'zone-123', 'name' => 'example.com']],
        hostnames: [
            [
                'id' => 'ch-1',
                'hostname' => 'customer.com',
                'ssl' => ['status' => 'active'],
                'custom_origin_server' => 'origin.example.com',
            ],
        ],
    );

    $exitCode = Artisan::call('cf:hostname', [
        'hostname' => 'customer.com',
        '--zone' => 'example.com',
        '--config' => $this->config,
    ]);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('customer.com');

    // Both the bare domain and its www. variant are queried.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'hostname=customer.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'hostname=www.customer.com'));
});

it('derives the bare domain when the hostname already has a www prefix', function () {
    fakeCloudflare(
        zones: [['id' => 'zone-123', 'name' => 'example.com']],
        hostnames: [['id' => 'ch-1', 'hostname' => 'www.customer.com', 'ssl' => ['status' => 'active']]],
    );

    Artisan::call('cf:hostname', [
        'hostname' => 'www.customer.com',
        '--zone' => 'example.com',
        '--config' => $this->config,
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'hostname=customer.com'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'hostname=www.customer.com'));
});

it('reports when no hostname matches the filter', function () {
    fakeCloudflare(
        zones: [['id' => 'zone-123', 'name' => 'example.com']],
        hostnames: [],
    );

    $this->artisan('cf:hostname', [
        'hostname' => 'missing.customer.com',
        '--zone' => 'example.com',
        '--config' => $this->config,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain("No custom hostname matching 'missing.customer.com' found");
});

it('reports when the zone is not found', function () {
    fakeCloudflare(zones: [], hostnames: []);

    $this->artisan('cf:hostname', ['--zone' => 'missing.com', '--config' => $this->config])
        ->assertExitCode(1)
        ->expectsOutputToContain("Zone 'missing.com' not found.");
});

it('reports when no custom hostnames are configured', function () {
    fakeCloudflare(
        zones: [['id' => 'zone-123', 'name' => 'example.com']],
        hostnames: [],
    );

    $this->artisan('cf:hostname', ['--zone' => 'example.com', '--config' => $this->config])
        ->assertExitCode(0)
        ->expectsOutputToContain('No custom hostnames configured');
});

it('fails clearly when the cloudflare config is missing the token', function () {
    $this->artisan('cf:hostname', ['--zone' => 'example.com', '--config' => $this->invalidConfig])
        ->assertExitCode(1)
        ->expectsOutputToContain('Invalid pilot.yml');
});
