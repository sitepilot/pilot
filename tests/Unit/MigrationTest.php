<?php

use App\Services\Migration;
use App\Services\Remote;

it('builds source and destination remotes from a single sites entry', function () {
    $migration = Migration::fromArray([
        'url' => 'https://example.com',
        'host' => '5.6.7.8',
        'port' => 2222,
        'user' => 'bob',
        'path' => '/var/www/site',
        'source' => ['host' => '1.2.3.4', 'port' => 22, 'user' => 'alice', 'path' => '/var/www/site'],
    ]);

    expect($migration->source)->toBeInstanceOf(Remote::class)
        ->and($migration->destination)->toBeInstanceOf(Remote::class)
        ->and($migration->url)->toBe('https://example.com')
        ->and($migration->source->sshHost())->toBe('alice@1.2.3.4')
        ->and($migration->source->port)->toBe(22)
        ->and($migration->destination->sshHost())->toBe('bob@5.6.7.8')
        ->and($migration->destination->port)->toBe(2222);
});
