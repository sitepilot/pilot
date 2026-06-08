<?php

use Illuminate\Support\Facades\Process;

// Everything runs over SSH (no WP-CLI on the machine running the tool), so all
// faked commands are strings. WordPress is detected by a `test -f wp-config.php`
// probe on the source; `command -v wp` then decides whether to use the source's
// own wp-cli or pull a fresh wp-cli.phar; `option get siteurl` reads the URL on
// the destination.

beforeEach(function () {
    $this->fixtures = __DIR__.'/../../Fixtures';
    $this->valid = $this->fixtures.'/site-pull';
    $this->invalid = $this->fixtures.'/site-pull-invalid';
});

it('pulls a fresh wp-cli onto a source that has none, then exports, syncs, imports and rewrites', function () {
    Process::fake([
        '*command -v wp*' => Process::result(errorOutput: "wp: not found\n", exitCode: 1),
        '*config get table_prefix*' => Process::result(output: "wp_\n"),
        '*wp option get siteurl*' => Process::result(output: "https://old.example.com\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('site:pull', ['site' => 'example', '--config' => $this->valid, '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("Pulled 'example': 1.2.3.4 → 5.6.7.8")
        ->expectsOutputToContain('Imported the database');

    // A fresh wp-cli.phar is pulled onto the source.
    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'alice@1.2.3.4'")
        && str_contains($p->command, 'mkdir -p /tmp/pilot'));
    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'alice@1.2.3.4'")
        && str_contains($p->command, 'curl -fsSL -o')
        && str_contains($p->command, 'wp-cli.phar'));

    // Detection is a cheap wp-config.php test on the source; the export runs the
    // pulled binary there over PHP.
    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'alice@1.2.3.4'")
        && str_contains($p->command, 'test -f wp-config.php'));
    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'alice@1.2.3.4'")
        && str_contains($p->command, 'db export')
        && str_contains($p->command, '/dump.sql')
        && str_contains($p->command, '--allow-root'));

    // File sync + dump fetch run on the destination, pulling from the source.
    // wp-config.php is excluded so the destination keeps its own DB credentials.
    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, 'ssh -A -p 2222')
        && str_contains($p->command, 'rsync -az --delete')
        && str_contains($p->command, '--exclude=')
        && str_contains($p->command, 'wp-config.php')
        && str_contains($p->command, 'alice@1.2.3.4:/var/www/example/'));
    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, 'ssh -A -p 2222')
        && str_contains($p->command, 'alice@1.2.3.4:/tmp/pilot-')
        && str_contains($p->command, '/dump.sql'));

    // Import + search-replace run the destination's own wp.
    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'bob@5.6.7.8'")
        && str_contains($p->command, 'wp db import')
        && str_contains($p->command, '/tmp/pilot-'));
    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'bob@5.6.7.8'")
        && str_contains($p->command, 'wp search-replace')
        && str_contains($p->command, 'https://old.example.com')
        && str_contains($p->command, 'https://example.com'));

    // The database is optimized on the destination as the final DB step.
    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'bob@5.6.7.8'")
        && str_contains($p->command, 'wp db optimize'));

    // Cleanup of the temp dir on the source.
    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'alice@1.2.3.4'")
        && str_contains($p->command, 'rm -rf /tmp/pilot'));
});

it('uses the source\'s own wp-cli when it is present, without pulling one', function () {
    Process::fake([
        '*command -v wp*' => Process::result(output: "/usr/local/bin/wp\n"),
        '*wp option get siteurl*' => Process::result(output: "https://example.com\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('site:pull', ['site' => 'example', '--config' => $this->valid, '--force' => true])
        ->assertExitCode(0);

    // No fresh wp-cli is pulled (the source already has one); detection is still
    // the cheap wp-config.php test.
    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'wp-cli.phar'));
    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'alice@1.2.3.4'")
        && str_contains($p->command, 'test -f wp-config.php'));
});

it('aligns the destination table prefix to the source when they differ', function () {
    Process::fake([
        '*command -v wp*' => Process::result(errorOutput: "wp: not found\n", exitCode: 1),
        '*php /tmp/pilot-*config get table_prefix*' => Process::result(output: "src_\n"),  // source (pulled binary)
        '*config get table_prefix*' => Process::result(output: "wp_\n"),                   // destination
        '*wp option get siteurl*' => Process::result(output: "https://example.com\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('site:pull', ['site' => 'example', '--config' => $this->valid, '--force' => true])
        ->assertExitCode(0);

    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, "'bob@5.6.7.8'")
        && str_contains($p->command, 'wp config set table_prefix')
        && str_contains($p->command, 'src_'));
});

it('leaves the table prefix unchanged when source and destination match', function () {
    Process::fake([
        '*command -v wp*' => Process::result(errorOutput: "wp: not found\n", exitCode: 1),
        '*config get table_prefix*' => Process::result(output: "wp_\n"),
        '*wp option get siteurl*' => Process::result(output: "https://example.com\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('site:pull', ['site' => 'example', '--config' => $this->valid, '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Table prefix matches');

    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'wp config set table_prefix'));
});

it('only syncs files when the source is not WordPress', function () {
    Process::fake([
        // No wp-config.php on the source → not a WordPress install.
        '*test -f wp-config.php*' => Process::result(exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('site:pull', ['site' => 'example', '--config' => $this->valid, '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("Pulled 'example'")
        ->expectsOutputToContain('Synced files');

    Process::assertRan(fn ($p) => is_string($p->command)
        && str_contains($p->command, 'ssh -A -p 2222')
        && str_contains($p->command, 'rsync -az --delete'));

    // Files-only: no WP-CLI provisioning/download and no database work.
    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'wp-cli.phar'));
    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'db export'));
    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'wp db import'));
    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'wp search-replace'));
    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'wp db optimize'));
});

it('skips search-replace when the url already matches', function () {
    Process::fake([
        '*command -v wp*' => Process::result(errorOutput: "wp: not found\n", exitCode: 1),
        '*wp option get siteurl*' => Process::result(output: "https://example.com\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('site:pull', ['site' => 'example', '--config' => $this->valid, '--force' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('Site URL already matches');

    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'wp search-replace'));
});

it('prompts for the site when no key is given', function () {
    Process::fake([
        '*command -v wp*' => Process::result(errorOutput: "wp: not found\n", exitCode: 1),
        '*wp option get siteurl*' => Process::result(output: "https://example.com\n"),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('site:pull', ['--config' => $this->valid, '--force' => true])
        ->expectsChoice('Which site do you want to pull?', 'example', ['example', 'another-site'])
        ->assertExitCode(0);

    Process::assertRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'db export'));
});

it('fails and lists the sites for an unknown key', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('site:pull', ['site' => 'nope', '--config' => $this->valid, '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain("Unknown site 'nope'")
        ->expectsOutputToContain('example');

    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'db export'));
});

it('asks for confirmation and does nothing when declined', function () {
    Process::fake([
        '*command -v wp*' => Process::result(errorOutput: "wp: not found\n", exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('site:pull', ['site' => 'example', '--config' => $this->valid])
        ->expectsConfirmation('Continue?', 'no')
        ->expectsOutputToContain('Aborted.')
        ->assertExitCode(0);

    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'db export'));
    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'rsync -az --delete'));
});

it('fails clearly when pilot.yml is missing required keys', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $this->artisan('site:pull', ['site' => 'example', '--config' => $this->invalid, '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('Invalid pilot.yml:')
        ->expectsOutputToContain('The sites.example.source.host field is required.');
});

it('fails when the source is WordPress but the destination is not', function () {
    Process::fake([
        // Destination validation fails; the source's wp-config.php probe passes via '*'.
        '*bob@5.6.7.8*wp config path*' => Process::result(errorOutput: "Error: This does not seem to be a WordPress install.\n", exitCode: 1),
        '*' => Process::result(output: ''),
    ]);

    $this->artisan('site:pull', ['site' => 'example', '--config' => $this->valid, '--force' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('WordPress install');

    Process::assertNotRan(fn ($p) => is_string($p->command) && str_contains($p->command, 'rsync -az --delete'));
});
