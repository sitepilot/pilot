<?php

use App\Services\Config;
use App\Services\Migration;

beforeEach(function () {
    $this->fixtures = __DIR__.'/../Fixtures';
});

it('loads and returns the parsed pilot.yml when valid', function () {
    $data = (new Config)->load($this->fixtures.'/site-pull', Migration::RULES);

    expect($data['sites'])->toHaveKeys(['example', 'another-site'])
        ->and($data['sites']['example']['url'])->toBe('https://example.com')
        ->and($data['sites']['example']['host'])->toBe('5.6.7.8')
        ->and($data['sites']['example']['source']['host'])->toBe('1.2.3.4');
});

it('throws when pilot.yml is missing', function () {
    expect(fn () => (new Config)->load($this->fixtures.'/does-not-exist', Migration::RULES))
        ->toThrow(RuntimeException::class, 'No pilot.yml found');
});

it('reports all missing required keys at once', function () {
    $message = null;

    try {
        (new Config)->load($this->fixtures.'/site-pull-invalid', Migration::RULES);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)
        ->toContain('Invalid pilot.yml')
        ->toContain('The sites.example.host field is required')
        ->toContain('The sites.example.path field is required')
        ->toContain('The sites.example.source.host field is required');
});

it('rejects a url without an http(s) scheme', function () {
    expect(fn () => (new Config)->load($this->fixtures.'/site-pull-badurl', Migration::RULES))
        ->toThrow(RuntimeException::class, 'must start with');
});

it('validates against caller-supplied rules so other commands can reuse load()', function () {
    // A different command could validate its own keys against the same file.
    expect(fn () => (new Config)->load($this->fixtures.'/site-pull', ['token' => ['required']]))
        ->toThrow(RuntimeException::class, 'The token field is required');
});
