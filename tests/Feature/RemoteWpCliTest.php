<?php

use App\Services\Remote;
use App\Services\RemoteWpCli;
use App\Services\Ssh;
use Illuminate\Support\Facades\Process;

function wpSource(string $path = '/var/www/example'): Remote
{
    return Remote::fromArray(['host' => '1.2.3.4', 'port' => 22, 'user' => 'alice', 'path' => $path]);
}

function remoteWpCli(): RemoteWpCli
{
    return new RemoteWpCli(new Ssh);
}

it('detects WordPress by the presence of wp-config.php (no WP-CLI needed)', function () {
    Process::fake(['*' => Process::result(output: '')]);

    expect(remoteWpCli()->isWordPress(wpSource()))->toBeTrue();

    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'alice@1.2.3.4'")
        && str_contains($p->command, 'test -f wp-config.php'));

    // Detection alone must not download or invoke WP-CLI.
    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'wp-cli.phar'));
});

it('reports not-WordPress when wp-config.php is absent', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    expect(remoteWpCli()->isWordPress(wpSource()))->toBeFalse();
});

it('uses the source\'s own wp-cli when it is present', function () {
    Process::fake([
        '*command -v wp*' => Process::result(output: "/usr/local/bin/wp\n"),
        '*' => Process::result(output: ''),
    ]);

    expect(remoteWpCli()->provision(wpSource()))->toBe('wp');

    Process::assertRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'mkdir -p /tmp/pilot-'));
    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'wp-cli.phar'));
});

it('pulls a fresh wp-cli into a per-run temp dir when the source has none', function () {
    Process::fake([
        '*command -v wp*' => Process::result(errorOutput: "wp: not found\n", exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    expect(remoteWpCli()->provision(wpSource()))->toStartWith('php /tmp/pilot-')->toEndWith('/wp');

    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'alice@1.2.3.4'")
        && str_contains($p->command, 'curl -fsSL -o')
        && str_contains($p->command, 'wp-cli.phar'));
});

it('reads the table prefix on the source with --allow-root', function () {
    Process::fake(['*' => Process::result(output: "wp_\n")]);

    expect(remoteWpCli()->tablePrefix(wpSource(), 'php /tmp/pilot-x/wp'))->toBe('wp_');

    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'alice@1.2.3.4'")
        && str_contains($p->command, 'config get table_prefix')
        && str_contains($p->command, '--allow-root'));
});

it('cd-s into the path and exports with --allow-root, expanding a tilde', function () {
    Process::fake(['*' => Process::result(output: '')]);

    remoteWpCli()->exportDatabase(wpSource('~/httpdocs'), 'php /tmp/pilot-x/wp');

    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'alice@1.2.3.4'")
        && str_contains($p->command, 'cd ~/')          // tilde stays bare so it expands
        && ! str_contains($p->command, "'~/")          // and is not quoted shut
        && str_contains($p->command, 'db export')
        && str_contains($p->command, '/dump.sql')
        && str_contains($p->command, '--allow-root'));
});
