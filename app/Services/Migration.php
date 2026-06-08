<?php

namespace App\Services;

/**
 * Immutable description of a single site's migration. A `sites:` entry describes
 * the site itself — i.e. the destination it is migrated *to* (its url and host
 * connection live directly under the key) — plus a nested `source:` block for the
 * host it is pulled *from*.
 */
final readonly class Migration
{
    /**
     * Validation rules for the whole `sites:` map. Pass these to
     * {@see Config::load()}; the wildcards validate every keyed entry. The site's
     * own connection sits directly under the key; `source.*` describes the host it
     * is pulled from. `url` is the site's URL (the WordPress search-replace
     * target). `*.port` defaults to 22, so it is optional.
     *
     * @var array<string, array<int, string>>
     */
    public const RULES = [
        'sites' => ['required', 'array'],
        'sites.*.url' => ['required', 'string', 'starts_with:http://,https://'],
        'sites.*.host' => ['required', 'string'],
        'sites.*.user' => ['required', 'string'],
        'sites.*.path' => ['required', 'string'],
        'sites.*.port' => ['nullable', 'integer', 'between:1,65535'],
        'sites.*.source' => ['required', 'array'],
        'sites.*.source.host' => ['required', 'string'],
        'sites.*.source.user' => ['required', 'string'],
        'sites.*.source.path' => ['required', 'string'],
        'sites.*.source.port' => ['nullable', 'integer', 'between:1,65535'],
    ];

    public function __construct(
        public Remote $source,
        public Remote $destination,
        public string $url,
    ) {}

    /**
     * Build a Migration from a single validated `sites` entry: the entry itself is
     * the destination (Remote reads the host/port/user/path keys and ignores the
     * rest), with the target URL alongside and the origin under `source`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            source: Remote::fromArray($data['source']),
            destination: Remote::fromArray($data),
            url: (string) $data['url'],
        );
    }
}
