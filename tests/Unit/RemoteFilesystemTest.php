<?php

use App\Services\RemoteFilesystem;

it('builds a chmod command normalizing directories to 755 and files to 644', function () {
    $command = (new RemoteFilesystem)->normalizePermissions('/var/www/example');

    expect($command)->toContain("find '/var/www/example' -type d -exec chmod 755 {} +")
        ->toContain("find '/var/www/example' -type f -exec chmod 644 {} +");
});

it('keeps a leading tilde unquoted so the shell expands it', function () {
    $command = (new RemoteFilesystem)->normalizePermissions('~/httpdocs');

    expect($command)->toContain("find ~/'httpdocs' -type d")
        ->not->toContain("'~/httpdocs'");
});
