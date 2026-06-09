<?php

use Illuminate\Support\Facades\Process;

// site:ssh opens an interactive login shell on a site's host. tty() is a no-op
// under Process::fake(), so the faked `ssh -t …` command string asserts cleanly.

beforeEach(function () {
    $this->fixtures = __DIR__.'/../../Fixtures';
    $this->valid = $this->fixtures.'/site';
    $this->invalid = $this->fixtures.'/site-invalid';
});

it('opens a session on the destination host by default', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('site:ssh', ['site' => 'example', '--config' => $this->valid])
        ->assertExitCode(0)
        ->expectsOutputToContain('Connecting to bob@5.6.7.8');

    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, 'ssh -t -p 2222')
        && str_contains($p->command, "'bob@5.6.7.8'")
        && ! str_contains($p->command, 'BatchMode'));
});

it('opens a session on the source host with --source', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('site:ssh', ['site' => 'example', '--config' => $this->valid, '--source' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Connecting to alice@1.2.3.4');

    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, 'ssh -t -p 22')
        && str_contains($p->command, "'alice@1.2.3.4'"));
});

it('prompts for the site when omitted', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('site:ssh', ['--config' => $this->valid])
        ->expectsChoice('Which site do you want to SSH into?', 'example', ['example', 'another-site'])
        ->assertExitCode(0);
});

it('fails on an unknown site', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('site:ssh', ['site' => 'nope', '--config' => $this->valid])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown site 'nope'")
        ->expectsOutputToContain('example');

    Process::assertNothingRan();
});

it('fails on an invalid pilot.yml', function () {
    $this->artisan('site:ssh', ['site' => 'example', '--config' => $this->invalid])
        ->assertExitCode(1)
        ->expectsOutputToContain('Invalid pilot.yml:');
});
