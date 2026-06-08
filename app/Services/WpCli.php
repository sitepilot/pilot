<?php

namespace App\Services;

use App\Services\Concerns\QuotesShellPaths;

/**
 * Drives the WP-CLI installed on the *destination* host — the server that hosts
 * the site, where `wp` is available. Every command runs there over SSH
 * (`ssh <destination> 'cd <path> && wp …'`); nothing needs WP-CLI on the machine
 * running the tool. The *source* may have no WP-CLI, so it is handled separately
 * by {@see RemoteWpCli}.
 *
 * Methods return data or throw RuntimeException — no console concerns.
 */
class WpCli
{
    use QuotesShellPaths;

    public function __construct(private readonly Ssh $ssh) {}

    /**
     * Confirm the destination is a configured WordPress install, throwing (with
     * WP-CLI's own message) when it is not.
     */
    public function validateInstall(Remote $remote): void
    {
        $this->ssh->run($remote, $this->inDir($remote->path, 'wp config path'));
    }

    /**
     * Import a SQL dump that already lives on the destination into its database.
     */
    public function importDatabase(Remote $remote, string $dumpFile): void
    {
        $this->ssh->run($remote, $this->inDir($remote->path, 'wp db import '.escapeshellarg($dumpFile)));
    }

    /**
     * The siteurl stored in the destination database (the source's URL, just after import).
     */
    public function currentSiteUrl(Remote $remote): string
    {
        return $this->ssh->output($remote, $this->inDir($remote->path, 'wp option get siteurl'));
    }

    /**
     * The `$table_prefix` configured in the destination's wp-config.php.
     */
    public function tablePrefix(Remote $remote): string
    {
        return $this->ssh->output($remote, $this->inDir($remote->path, 'wp config get table_prefix'));
    }

    /**
     * Set `$table_prefix` in the destination's wp-config.php — used to adopt the
     * source's prefix so WordPress finds the imported tables.
     */
    public function setTablePrefix(Remote $remote, string $prefix): void
    {
        $this->ssh->run($remote, $this->inDir($remote->path, 'wp config set table_prefix '.escapeshellarg($prefix)));
    }

    /**
     * Rewrite every occurrence of one URL with another across the destination database.
     */
    public function searchReplace(Remote $remote, string $from, string $to): void
    {
        $this->ssh->run($remote, $this->inDir($remote->path, 'wp search-replace '.escapeshellarg($from).' '.escapeshellarg($to)));
    }
}
