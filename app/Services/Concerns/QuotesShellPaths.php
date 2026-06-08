<?php

namespace App\Services\Concerns;

/**
 * Helpers for building remote shell commands that operate in a configured path.
 */
trait QuotesShellPaths
{
    /**
     * Quote a filesystem path for a remote shell while still letting a leading "~"
     * (or "~user") expand. escapeshellarg() alone would quote the tilde literally,
     * so the home-relative prefix is left bare — the unquoted slash terminates the
     * tilde-prefix the shell expands — and only the remainder is escaped, e.g.
     * "~/httpdocs" → ~/'httpdocs'.
     */
    protected function shellPath(string $path): string
    {
        if (preg_match('#^(~[^/]*/)(.*)$#', $path, $matches)) {
            return $matches[1].($matches[2] !== '' ? escapeshellarg($matches[2]) : '');
        }

        return escapeshellarg($path);
    }

    /**
     * A `cd <path> && <command>` invocation, with the path tilde-safely quoted.
     * WP-CLI auto-detects the install from the working directory, and `cd` is
     * where a tilde expands (it sits at the start of the word, unlike `--path=~`).
     */
    protected function inDir(string $path, string $command): string
    {
        return 'cd '.$this->shellPath(rtrim($path, '/') ?: '/').' && '.$command;
    }
}
