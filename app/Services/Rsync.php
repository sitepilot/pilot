<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Wraps `rsync` for pulling a remote directory down to a local one.
 *
 * Always mirrors with `--delete`: local files absent on the remote are removed,
 * so the local tree becomes an exact copy of the remote. Returns nothing or
 * throws RuntimeException — no console concerns.
 */
class Rsync
{
    /**
     * Pull the remote directory's contents into the local destination.
     *
     * @param  string  $source  Remote source ending in '/' (see {@see Site::rsyncSource()}).
     * @param  string  $localDest  Local destination directory.
     * @param  int  $port  SSH port, passed via `-e "ssh -p <port>"`.
     */
    public function pull(string $source, string $localDest, int $port): void
    {
        $dest = rtrim($localDest, '/').'/';

        $command = [
            'rsync', '-az', '--delete',
            // Avoid chown/chmod failures pulling to a dev machine whose user
            // differs from the remote file owner.
            '--no-perms', '--no-owner', '--no-group',
            '-e', 'ssh -p '.$port,
            $source, $dest,
        ];

        $result = Process::run($command);

        if ($result->failed()) {
            throw new RuntimeException(
                trim($result->errorOutput()) ?: 'rsync failed.'
            );
        }
    }
}
