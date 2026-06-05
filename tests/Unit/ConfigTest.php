<?php

use App\Services\Config;
use App\Services\Site;

beforeEach(function () {
    $this->fixtures = __DIR__.'/../Fixtures';
});

it('loads and returns the parsed pilot.yml when valid', function () {
    $data = (new Config)->load($this->fixtures.'/wp-pull', Site::RULES);

    expect($data)->toMatchArray(['url' => 'https://example.com', 'path' => 'httpdocs'])
        ->and($data['remote']['host'])->toBe('1.2.3.4');
});

it('throws when pilot.yml is missing', function () {
    expect(fn () => (new Config)->load($this->fixtures.'/does-not-exist', Site::RULES))
        ->toThrow(RuntimeException::class, 'No pilot.yml found');
});

it('reports all missing required keys at once', function () {
    $message = null;

    try {
        (new Config)->load($this->fixtures.'/wp-pull-invalid', Site::RULES);
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
    }

    expect($message)
        ->toContain('Invalid pilot.yml')
        ->toContain('The url field is required')
        ->toContain('The remote.host field is required')
        ->toContain('The remote.path field is required');
});

it('rejects a url without an http(s) scheme', function () {
    expect(fn () => (new Config)->load($this->fixtures.'/wp-pull-badurl', Site::RULES))
        ->toThrow(RuntimeException::class, 'must start with');
});

it('validates against caller-supplied rules so other commands can reuse load()', function () {
    // A different command could validate its own keys against the same file.
    expect(fn () => (new Config)->load($this->fixtures.'/wp-pull', ['token' => ['required']]))
        ->toThrow(RuntimeException::class, 'The token field is required');
});
