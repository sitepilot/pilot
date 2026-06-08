<?php

use App\Services\Remote;

it('builds from an array with derived ssh and rsync targets', function () {
    $remote = Remote::fromArray([
        'host' => '1.2.3.4',
        'port' => 2222,
        'user' => 'alice',
        'path' => '/var/www/site',
    ]);

    expect($remote->sshHost())->toBe('alice@1.2.3.4')
        ->and($remote->rsyncLocation())->toBe('alice@1.2.3.4:/var/www/site/');
});

it('defaults the port to 22 when omitted', function () {
    $remote = Remote::fromArray([
        'host' => '1.2.3.4',
        'user' => 'alice',
        'path' => '/var/www/site',
    ]);

    expect($remote->port)->toBe(22)
        ->and($remote->sshHost())->toBe('alice@1.2.3.4');
});
