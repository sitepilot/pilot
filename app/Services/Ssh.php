<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Runs a command on a remote host over SSH.
 *
 * `BatchMode=yes` and `StrictHostKeyChecking=accept-new` keep these calls
 * non-interactive: they fail fast instead of hanging on a password or host-key
 * prompt. With $forwardAgent the local SSH agent is forwarded (`-A`) so the
 * remote host can authenticate onward to a third host using the operator's key —
 * this is how the destination reaches the source for the direct file/database
 * transfers, without proxying the data through the machine running the tool.
 *
 * Returns data or throws RuntimeException — no console concerns.
 */
class Ssh
{
    /**
     * Non-interactive SSH options shared by these commands and the rsync transfers
     * ({@see Rsync::remoteShell()}): fail fast instead of prompting, accept new
     * host keys, and cap the connect wait so an unreachable host errors promptly.
     */
    public const OPTIONS = '-o StrictHostKeyChecking=accept-new -o BatchMode=yes -o ConnectTimeout=10';

    /**
     * Interactive SSH options: like {@see OPTIONS} but without `BatchMode=yes`, so
     * password and host-key prompts work when a human is at the terminal.
     */
    public const INTERACTIVE_OPTIONS = '-o StrictHostKeyChecking=accept-new -o ConnectTimeout=10';

    /**
     * Open an interactive login shell on the host, attaching the operator's
     * terminal. Unlike {@see run()}/{@see output()}/{@see probe()} this drops
     * BatchMode so prompts work, forces a PTY (`ssh -t`), runs no remote command,
     * and blocks until the session ends. Returns ssh's exit code.
     */
    public function interactive(Remote $remote): int
    {
        $process = Process::forever();

        // A TTY is what makes the session interactive, but enabling it without one
        // available (e.g. under test) throws, so only attach it when present.
        if (SymfonyProcess::isTtySupported()) {
            $process->tty();
        }

        return $process->run($this->wrapInteractive($remote))->exitCode();
    }

    /**
     * Run a command on the host, throwing the trimmed stderr (or a fallback) on a
     * non-zero exit.
     */
    public function run(Remote $remote, string $command, bool $forwardAgent = false): void
    {
        $result = Process::run($this->wrap($remote, $command, $forwardAgent));

        $this->throwIfFailed($remote, $result);
    }

    /**
     * Run a command on the host and return its trimmed stdout, throwing on failure.
     */
    public function output(Remote $remote, string $command): string
    {
        $result = Process::run($this->wrap($remote, $command));

        $this->throwIfFailed($remote, $result);

        return trim($result->output());
    }

    /**
     * Run a command on the host as a probe: null when it succeeds, or the trimmed
     * stderr (or a fallback) when it fails — without throwing.
     */
    public function probe(Remote $remote, string $command): ?string
    {
        $result = Process::run($this->wrap($remote, $command));

        if ($result->successful()) {
            return null;
        }

        return trim($result->errorOutput()) ?: 'command failed';
    }

    /**
     * Throw the command's trimmed stderr (or a host-named fallback) when it exited
     * non-zero, so {@see run()} and {@see output()} surface failures identically.
     */
    private function throwIfFailed(Remote $remote, ProcessResult $result): void
    {
        if ($result->failed()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: "SSH command failed on {$remote->sshHost()}.");
        }
    }

    private function wrap(Remote $remote, string $command, bool $forwardAgent = false): string
    {
        return sprintf(
            'ssh %s-p %d %s %s %s',
            $forwardAgent ? '-A ' : '',
            $remote->port,
            self::OPTIONS,
            escapeshellarg($remote->sshHost()),
            escapeshellarg($command),
        );
    }

    private function wrapInteractive(Remote $remote): string
    {
        return sprintf(
            'ssh -t -p %d %s %s',
            $remote->port,
            self::INTERACTIVE_OPTIONS,
            escapeshellarg($remote->sshHost()),
        );
    }
}
