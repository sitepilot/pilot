<?php

use App\Services\Remote;
use App\Services\Rsync;

function remote(string $path = '/var/www/example', int $port = 22): Remote
{
    return Remote::fromArray(['host' => '1.2.3.4', 'port' => $port, 'user' => 'alice', 'path' => $path]);
}

it('builds a directory pull from the source into a local destination path', function () {
    $command = (new Rsync)->pullDir(remote(), '/var/www/dest');

    expect($command)->toContain('rsync -az --delete --no-perms --no-owner --no-group')
        ->toContain("-e 'ssh -p 22 -o StrictHostKeyChecking=accept-new -o BatchMode=yes -o ConnectTimeout=10'")
        ->toContain("'alice@1.2.3.4:/var/www/example/'")
        ->toEndWith("'/var/www/dest/'");
});

it('keeps a leading tilde unquoted in the destination path so the shell expands it', function () {
    $command = (new Rsync)->pullDir(remote(), '~/httpdocs');

    expect($command)->toContain("~/'httpdocs/'")
        ->not->toContain("'~/httpdocs/'");
});

it('adds an --exclude for each pattern, and none when there are no excludes', function () {
    expect((new Rsync)->pullDir(remote(), '/var/www/dest', ['/wp-config.php']))
        ->toContain("--exclude='/wp-config.php'")
        ->toContain('rsync -az --delete');

    expect((new Rsync)->pullDir(remote(), '/var/www/dest'))
        ->not->toContain('--exclude');
});

it('builds a single-file pull from the source', function () {
    $command = (new Rsync)->pullFile(remote(), '/tmp/pilot/dump.sql', '/tmp/pilot-1.sql');

    expect($command)->toContain('rsync -az')
        ->toContain("'alice@1.2.3.4:/tmp/pilot/dump.sql'")
        ->toEndWith("'/tmp/pilot-1.sql'");
});
