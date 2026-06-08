<?php

namespace App\Services;

/**
 * Immutable connection info for one host (a migration's source or destination),
 * as read from a `sites.*.source` / `sites.*.destination` block in pilot.yml.
 * Holds the derived strings the wp/rsync/ssh wrappers need.
 */
final readonly class Remote
{
    public function __construct(
        public string $host,
        public int $port,
        public string $user,
        public string $path,
    ) {}

    /**
     * Build a Remote from a validated `source`/`destination` array, applying the
     * port default (22).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            host: (string) $data['host'],
            port: (int) ($data['port'] ?? 22),
            user: (string) $data['user'],
            path: (string) $data['path'],
        );
    }

    /**
     * The bare "user@host" used by `ssh`/`rsync` (the port is passed separately
     * via `-p`/`-e "ssh -p <port>"`).
     */
    public function sshHost(): string
    {
        return "{$this->user}@{$this->host}";
    }

    /**
     * rsync location for this host, e.g. "alice@a.example.com:/var/www/site/".
     * The trailing slash makes rsync copy the directory's *contents* into the
     * destination rather than nesting it.
     */
    public function rsyncLocation(): string
    {
        return $this->sshHost().':'.rtrim($this->path, '/').'/';
    }
}
