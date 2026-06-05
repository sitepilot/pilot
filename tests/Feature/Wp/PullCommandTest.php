<?php

use Illuminate\Support\Facades\Process;

// The remote DB export runs via the shell (string) form for its redirect, so
// its faked command is a string; wp/rsync run via the array form, whose faked
// command is an array. Assertions branch on that below.

beforeEach(function () {
    $this->fixtures = __DIR__.'/../../Fixtures';
    $this->valid = $this->fixtures.'/wp-pull';
    $this->invalid = $this->fixtures.'/wp-pull-invalid';
});

it('exports, syncs, imports and rewrites the url when it differs', function () {
    Process::fake([
        "*'siteurl'*" => Process::result(output: "https://old.example.com\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('wp:pull', ['--config' => $this->valid, '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Done.');

    // Remote export: string form carrying the --ssh target.
    Process::assertRan(fn ($process) => is_string($process->command)
        && str_contains($process->command, "--ssh='test@1.2.3.4:22/dfs/dfs/dfdf'"));

    // rsync: trailing-slash source, --delete, and the port via -e "ssh -p 22".
    Process::assertRan(fn ($process) => is_array($process->command)
        && in_array('rsync', $process->command, true)
        && in_array('--delete', $process->command, true)
        && in_array('ssh -p 22', $process->command, true)
        && in_array('test@1.2.3.4:/dfs/dfs/dfdf/', $process->command, true));

    // URL differs → search-replace from the imported (old) URL to the configured one.
    Process::assertRan(fn ($process) => is_array($process->command)
        && in_array('search-replace', $process->command, true)
        && in_array('https://old.example.com', $process->command, true)
        && in_array('https://example.com', $process->command, true));
});

it('skips search-replace when the url already matches', function () {
    Process::fake([
        "*'siteurl'*" => Process::result(output: "https://example.com\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('wp:pull', ['--config' => $this->valid, '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Site URL already matches');

    Process::assertNotRan(fn ($process) => is_array($process->command)
        && in_array('search-replace', $process->command, true));
});

it('asks for confirmation and does nothing when declined', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('wp:pull', ['--config' => $this->valid])
        ->expectsConfirmation('Continue?', 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(0);

    Process::assertNotRan(fn ($process) => is_string($process->command)
        && str_contains($process->command, 'db export'));
    Process::assertNotRan(fn ($process) => is_array($process->command)
        && in_array('rsync', $process->command, true));
});

it('fails clearly when pilot.yml is missing required keys', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('wp:pull', ['--config' => $this->invalid, '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Invalid pilot.yml:')
        ->expectsOutputToContain('• The url field is required.');
});

it('fails when the local path is not a WordPress install', function () {
    Process::fake([
        "*'config' 'path'*" => Process::result(errorOutput: "Error: This does not seem to be a WordPress install.\n", exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('wp:pull', ['--config' => $this->valid, '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('WordPress install');

    Process::assertNotRan(fn ($process) => is_array($process->command)
        && in_array('rsync', $process->command, true));
});
