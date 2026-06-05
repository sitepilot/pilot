<?php

namespace App\Services;

/**
 * Immutable description of a remote WordPress host and its local target, as
 * read from pilot.yml. Holds the derived strings the wp/rsync wrappers need.
 */
final readonly class Site
{
    /**
     * Validation rules for the pilot.yml keys wp:pull needs. Pass these to
     * {@see Config::load()}. `path` defaults to "httpdocs" (most migrations
     * are Plesk) and `remote.port` to 22, so both are optional here.
     *
     * @var array<string, array<int, string>>
     */
    public const RULES = [
        'url' => ['required', 'string', 'starts_with:http://,https://'],
        'path' => ['nullable', 'string'],
        'remote' => ['required', 'array'],
        'remote.host' => ['required', 'string'],
        'remote.user' => ['required', 'string'],
        'remote.path' => ['required', 'string'],
        'remote.port' => ['nullable', 'integer', 'between:1,65535'],
    ];

    public function __construct(
        public string $path,
        public string $url,
        public string $remoteHost,
        public int $remotePort,
        public string $remoteUser,
        public string $remotePath,
    ) {}

    /**
     * Build a Site from a validated pilot.yml array (see {@see RULES}),
     * applying the path/port defaults.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: (string) ($data['path'] ?? 'httpdocs'),
            url: (string) $data['url'],
            remoteHost: (string) $data['remote']['host'],
            remotePort: (int) ($data['remote']['port'] ?? 22),
            remoteUser: (string) $data['remote']['user'],
            remotePath: (string) $data['remote']['path'],
        );
    }

    /**
     * Target for WP-CLI's `--ssh=` flag, e.g. "test@1.2.3.4:22/dfs/dfs/dfdf".
     */
    public function sshTarget(): string
    {
        return "{$this->remoteUser}@{$this->remoteHost}:{$this->remotePort}{$this->remotePath}";
    }

    /**
     * rsync source, e.g. "test@1.2.3.4:/dfs/dfs/dfdf/". The trailing slash makes
     * rsync copy the directory's *contents* into the local dir rather than
     * nesting it. The port is passed separately via `-e "ssh -p <port>"`.
     */
    public function rsyncSource(): string
    {
        return "{$this->remoteUser}@{$this->remoteHost}:".rtrim($this->remotePath, '/').'/';
    }
}
