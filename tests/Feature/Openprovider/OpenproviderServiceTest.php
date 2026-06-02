<?php

use App\Services\OpenproviderService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.openprovider.username' => 'reseller',
        'services.openprovider.password' => 'secret',
    ]);
});

function fakeOpenproviderLogin(): void
{
    Http::fake([
        'api.openprovider.eu/v1beta/auth/login' => Http::response([
            'code' => 0,
            'data' => ['token' => 'session-token'],
        ]),
    ]);
}

it('throws when credentials are not configured', function () {
    config(['services.openprovider.username' => null]);

    expect(fn () => (new OpenproviderService)->findDomainByName('example.com'))
        ->toThrow(RuntimeException::class, 'Openprovider credentials are not configured');
});

it('returns the exactly matching domain by name', function () {
    fakeOpenproviderLogin();
    Http::fake([
        'api.openprovider.eu/v1beta/domains*' => Http::response([
            'code' => 0,
            'data' => ['results' => [
                ['id' => 999, 'domain' => ['name' => 'example', 'extension' => 'org']],
                ['id' => 123, 'domain' => ['name' => 'example', 'extension' => 'com']],
            ]],
        ]),
    ]);

    $domain = (new OpenproviderService)->findDomainByName('example.com');

    expect($domain)->toMatchArray(['id' => 123]);
});

it('returns null when no domain matches exactly', function () {
    fakeOpenproviderLogin();
    Http::fake([
        'api.openprovider.eu/v1beta/domains*' => Http::response([
            'code' => 0,
            'data' => ['results' => [
                ['id' => 999, 'domain' => ['name' => 'example', 'extension' => 'org']],
            ]],
        ]),
    ]);

    expect((new OpenproviderService)->findDomainByName('example.com'))->toBeNull();
});

it('logs in then sends ns_group when setting the nameserver group', function () {
    fakeOpenproviderLogin();
    Http::fake([
        'api.openprovider.eu/v1beta/domains/*' => Http::response(['code' => 0, 'data' => ['id' => 123]]),
    ]);

    (new OpenproviderService)->setNameserverGroup(123, 'sitepilot-net');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/auth/login'));
    Http::assertSent(function ($request) {
        return $request->method() === 'PUT'
            && str_contains($request->url(), '/domains/123')
            && $request['ns_group'] === 'sitepilot-net';
    });
});

it('throws with the Openprovider error message when a request fails', function () {
    fakeOpenproviderLogin();
    Http::fake([
        'api.openprovider.eu/v1beta/domains/*' => Http::response([
            'code' => 399,
            'desc' => 'Domain does not exist',
        ], 400),
    ]);

    expect(fn () => (new OpenproviderService)->setNameserverGroup(123, 'sitepilot-net'))
        ->toThrow(RuntimeException::class, 'Domain does not exist');
});

it('throws when authentication fails', function () {
    Http::fake([
        'api.openprovider.eu/v1beta/auth/login' => Http::response([
            'code' => 196,
            'desc' => 'Login credentials are incorrect',
        ], 401),
    ]);

    expect(fn () => (new OpenproviderService)->findDomainByName('example.com'))
        ->toThrow(RuntimeException::class, 'Login credentials are incorrect');
});
