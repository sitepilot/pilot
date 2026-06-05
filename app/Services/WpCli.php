<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Wraps the local `wp` (WP-CLI) binary. The remote database is exported using
 * WP-CLI's `--ssh` flag, which runs the export on the remote host (it requires
 * `wp` to be available there) and streams the dump back to local stdout.
 *
 * Import, URL lookup and search-replace all operate on the *local* WordPress
 * install at `--path`, which is why {@see validateLocalInstall()} runs first.
 *
 * Methods return data or throw RuntimeException — no console concerns.
 */
class WpCli
{
    /**
     * Confirm the local path is a configured WordPress install. `wp config path`
     * resolves wp-config.php without needing the database tables to pre-exist,
     * so a first pull into a fresh-but-configured install still passes.
     */
    public function validateLocalInstall(string $localPath): void
    {
        $this->run(
            ['wp', 'config', 'path', '--path='.$localPath],
            "No WordPress install found at {$localPath} (wp config path failed)."
        );
    }

    /**
     * Export the remote database to a local file by streaming WP-CLI's stdout to
     * disk: `wp db export - --ssh=<target> > <dumpFile>`.
     *
     * Uses the string (shell) form because of the redirect, so both arguments
     * are escaped explicitly — the string form is not auto-escaped.
     */
    public function exportRemoteDatabase(string $sshTarget, string $dumpFile): void
    {
        $command = 'wp db export - --ssh='.escapeshellarg($sshTarget).' > '.escapeshellarg($dumpFile);

        $this->run($command, 'Remote database export failed.');
    }

    /**
     * Import a SQL dump into the local database: `wp db import <dumpFile>`.
     */
    public function importDatabase(string $dumpFile, string $localPath): void
    {
        $this->run(['wp', 'db', 'import', $dumpFile, '--path='.$localPath], 'Database import failed.');
    }

    /**
     * The siteurl stored in the local database (the remote's URL, just after import).
     */
    public function currentSiteUrl(string $localPath): string
    {
        $result = $this->run(
            ['wp', 'option', 'get', 'siteurl', '--path='.$localPath],
            'Could not read the current site URL.'
        );

        return trim($result->output());
    }

    /**
     * Rewrite every occurrence of one URL with another across the database:
     * `wp search-replace <from> <to>`.
     */
    public function searchReplace(string $from, string $to, string $localPath): void
    {
        $this->run(['wp', 'search-replace', $from, $to, '--path='.$localPath], 'Search-replace failed.');
    }

    /**
     * Run a wp command and return its result, throwing the trimmed stderr (or a
     * fallback) on failure.
     *
     * @param  string|array<int, string>  $command
     */
    private function run(string|array $command, string $failureMessage): ProcessResult
    {
        $result = Process::run($command);

        if ($result->failed()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: $failureMessage);
        }

        return $result;
    }
}
