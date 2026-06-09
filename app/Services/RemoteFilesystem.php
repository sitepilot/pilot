<?php

namespace App\Services;

use App\Services\Concerns\QuotesShellPaths;

/**
 * Builds shell command strings for filesystem housekeeping on a remote host,
 * to be executed via {@see Ssh::run()}. Like {@see Rsync}, these methods only
 * build commands; running them is the caller's job.
 */
class RemoteFilesystem
{
    use QuotesShellPaths;

    /**
     * Normalize a tree to the web-standard permissions: directories 755, files
     * 644. Idempotent. `-exec … {} +` batches into a few chmod calls, and
     * shellPath() keeps a leading "~" expandable.
     */
    public function normalizePermissions(string $path): string
    {
        $path = $this->shellPath($path);

        return "find {$path} -type d -exec chmod 755 {} + && find {$path} -type f -exec chmod 644 {} +";
    }
}
