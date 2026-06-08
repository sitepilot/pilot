<?php

namespace App\Services;

use App\Services\Concerns\QuotesShellPaths;
use Throwable;

/**
 * Runs WP-CLI on the migration source. WordPress is detected cheaply by the
 * presence of `wp-config.php` ({@see isWordPress()}) — no WP-CLI needed, so a
 * plain files-only source never triggers a download. When the database is
 * actually migrated, {@see provision()} ensures WP-CLI on the source (its own
 * `wp`, or a freshly pulled wp-cli.phar) and returns how to invoke it; the other
 * methods then run it as `cd <path> && <binary> … --allow-root`.
 *
 * The temp dir is unique per instance (per run) so concurrent migrations sharing
 * a source host don't clobber each other's binary/dump or each other's cleanup.
 */
class RemoteWpCli
{
    use QuotesShellPaths;

    private const PHAR_URL = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';

    private readonly string $dir;

    public function __construct(private readonly Ssh $ssh)
    {
        $this->dir = '/tmp/pilot-'.uniqid();
    }

    /**
     * Whether the source is a WordPress install — detected by the presence of
     * wp-config.php, which needs neither WP-CLI nor a download on the source.
     */
    public function isWordPress(Remote $source): bool
    {
        return $this->ssh->probe($source, $this->inDir($source->path, 'test -f wp-config.php')) === null;
    }

    /**
     * Ensure WP-CLI is available on the source and return how to invoke it —
     * either "wp" or "php <temp dir>/wp". The temp dir also holds the dump
     * {@see exportDatabase()} writes. Call only when migrating the database.
     */
    public function provision(Remote $source): string
    {
        $this->ssh->run($source, 'mkdir -p '.$this->dir);

        // Prefer the source's own wp-cli; the probe also catches the case where
        // it is installed but not on the non-interactive SSH PATH.
        if ($this->ssh->probe($source, 'command -v wp') === null) {
            return 'wp';
        }

        $this->ssh->run($source, $this->fetchCommand());

        return 'php '.$this->dir.'/wp';
    }

    /**
     * Export the source database to a dump file on the source (which the
     * destination then pulls — see {@see dumpPath()}).
     */
    public function exportDatabase(Remote $source, string $binary): void
    {
        $this->ssh->run($source, $this->inDir($source->path, $this->command($binary, 'db export '.escapeshellarg($this->dumpPath()))));
    }

    /**
     * The `$table_prefix` configured in the source's wp-config.php — the prefix
     * the exported tables carry.
     */
    public function tablePrefix(Remote $source, string $binary): string
    {
        return $this->ssh->output($source, $this->inDir($source->path, $this->command($binary, 'config get table_prefix')));
    }

    /**
     * Absolute path of the dump left on the source by {@see exportDatabase()}.
     */
    public function dumpPath(): string
    {
        return $this->dir.'/dump.sql';
    }

    /**
     * Best-effort removal of the (per-run) temp dir — the pulled binary and the dump.
     */
    public function cleanup(Remote $source): void
    {
        try {
            $this->ssh->run($source, 'rm -rf '.$this->dir);
        } catch (Throwable) {
            // Leaving the temp dir behind is not worth failing the run over.
        }
    }

    /**
     * A WP-CLI invocation for the source. `--allow-root` is always passed so the
     * commands work when the source's SSH user is root (WP-CLI refuses to run as
     * root otherwise); it is harmless for non-root users.
     */
    private function command(string $binary, string $args): string
    {
        return $binary.' '.$args.' --allow-root';
    }

    /**
     * Download a fresh wp-cli.phar onto the source, falling back to wget.
     */
    private function fetchCommand(): string
    {
        $url = escapeshellarg(self::PHAR_URL);
        $path = escapeshellarg($this->dir.'/wp');

        return sprintf('curl -fsSL -o %s %s || wget -qO %s %s', $path, $url, $path, $url);
    }
}
