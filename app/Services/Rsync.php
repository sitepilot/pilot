<?php

namespace App\Services;

use App\Services\Concerns\QuotesShellPaths;

/**
 * Builds `rsync` command strings to be executed *on the destination host* (the
 * one we own, where rsync is installed) via {@see Ssh::run()} with agent
 * forwarding. The destination talks straight to the source, so bulk data moves
 * source ↔ destination directly and never through the machine running the tool.
 *
 * These methods only build commands; running them is the caller's job.
 */
class Rsync
{
    use QuotesShellPaths;

    /**
     * Mirror the source directory's contents into a local path on the destination.
     * Always uses `--delete`, so the destination tree becomes an exact copy — apart
     * from any `$excludes`, which are neither transferred nor deleted (a leading
     * slash anchors a pattern to the transfer root, e.g. "/wp-config.php").
     *
     * @param  array<int, string>  $excludes
     */
    public function pullDir(Remote $source, string $destPath, array $excludes = []): string
    {
        $excludeArgs = '';

        foreach ($excludes as $pattern) {
            $excludeArgs .= '--exclude='.escapeshellarg($pattern).' ';
        }

        return sprintf(
            'rsync -az --delete --no-perms --no-owner --no-group %s-e %s %s %s',
            $excludeArgs,
            $this->remoteShell($source->port),
            escapeshellarg($source->rsyncLocation()),
            $this->shellPath(rtrim($destPath, '/').'/'),
        );
    }

    /**
     * Pull a single file from the source down to a local path on the destination.
     */
    public function pullFile(Remote $source, string $sourceFile, string $destFile): string
    {
        return sprintf(
            'rsync -az -e %s %s %s',
            $this->remoteShell($source->port),
            escapeshellarg($source->sshHost().':'.$sourceFile),
            $this->shellPath($destFile),
        );
    }

    /**
     * The `-e` remote-shell argument connecting the destination to the source.
     */
    private function remoteShell(int $port): string
    {
        return escapeshellarg('ssh -p '.$port.' '.Ssh::OPTIONS);
    }
}
