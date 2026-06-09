<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->config = __DIR__.'/../../Fixtures/openprovider';
    $this->invalidConfig = __DIR__.'/../../Fixtures/openprovider-invalid';
});

function fakeOpenprovider(array $results): void
{
    Http::fake([
        'api.openprovider.eu/v1beta/auth/login' => Http::response([
            'code' => 0,
            'data' => ['token' => 'session-token'],
        ]),
        'api.openprovider.eu/v1beta/domains/*' => Http::response(['code' => 0, 'data' => ['id' => 123]]),
        'api.openprovider.eu/v1beta/domains*' => Http::response([
            'code' => 0,
            'data' => ['results' => $results],
        ]),
    ]);
}

function exampleDomain(): array
{
    return [['id' => 123, 'domain' => ['name' => 'example', 'extension' => 'com']]];
}

it('resets the domain to the given group with --force', function () {
    fakeOpenprovider(exampleDomain());

    $this->artisan('op:reset-ns', [
        'domain' => 'example.com',
        '--config' => $this->config,
        '--group' => 'custom-group',
        '--force' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain("Reset example.com to nameserver group 'custom-group'.");

    Http::assertSent(function ($request) {
        return $request->method() === 'PUT' && $request['ns_group'] === 'custom-group';
    });
});

it('falls back to the configured default group when --group is omitted', function () {
    fakeOpenprovider(exampleDomain());

    $this->artisan('op:reset-ns', [
        'domain' => 'example.com',
        '--config' => $this->config,
        '--force' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutputToContain("nameserver group 'sitepilot-net'.");

    Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['ns_group'] === 'sitepilot-net');
});

it('asks for confirmation and aborts without a PUT when declined', function () {
    fakeOpenprovider(exampleDomain());

    $this->artisan('op:reset-ns', ['domain' => 'example.com', '--config' => $this->config])
        ->expectsConfirmation("Reset example.com to nameserver group 'sitepilot-net'?", 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(0);

    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
});

it('fails when the domain is not found', function () {
    fakeOpenprovider([]);

    $this->artisan('op:reset-ns', [
        'domain' => 'missing.com',
        '--config' => $this->config,
        '--force' => true,
    ])
        ->assertExitCode(1)
        ->expectsOutputToContain("Domain 'missing.com' not found at Openprovider.");
});

it('fails clearly when the openprovider config is missing credentials', function () {
    $this->artisan('op:reset-ns', [
        'domain' => 'example.com',
        '--config' => $this->invalidConfig,
        '--force' => true,
    ])
        ->assertExitCode(1)
        ->expectsOutputToContain('Invalid pilot.yml');
});
