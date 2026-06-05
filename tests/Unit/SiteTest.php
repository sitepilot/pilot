<?php

use App\Services\Site;

it('builds from an array with derived ssh and rsync targets', function () {
    $site = Site::fromArray([
        'url' => 'https://example.com',
        'path' => 'httpdocs',
        'remote' => ['host' => '1.2.3.4', 'port' => 22, 'user' => 'test', 'path' => '/dfs/dfs/dfdf'],
    ]);

    expect($site->url)->toBe('https://example.com')
        ->and($site->path)->toBe('httpdocs')
        ->and($site->sshTarget())->toBe('test@1.2.3.4:22/dfs/dfs/dfdf')
        ->and($site->rsyncSource())->toBe('test@1.2.3.4:/dfs/dfs/dfdf/');
});

it('defaults path to httpdocs and port to 22 when omitted', function () {
    $site = Site::fromArray([
        'url' => 'https://example.com',
        'remote' => ['host' => '1.2.3.4', 'user' => 'test', 'path' => '/var/www/html'],
    ]);

    expect($site->path)->toBe('httpdocs')
        ->and($site->remotePort)->toBe(22)
        ->and($site->sshTarget())->toBe('test@1.2.3.4:22/var/www/html');
});
