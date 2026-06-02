<?php

namespace App\Commands\Cloudflare;

use App\Services\CloudflareService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class ListCustomHostnamesCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'cf:hostname
        {hostname? : Filter to a specific custom hostname}
        {--zone= : The zone name, e.g. example.com (defaults to the configured zone)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'List Cloudflare custom hostnames and their origin server for a zone';

    /**
     * Execute the console command.
     */
    public function handle(CloudflareService $cloudflare): int
    {
        $zoneName = $this->option('zone') ?: config('services.cloudflare.default_zone');
        $filter = $this->argument('hostname');

        try {
            $zone = $cloudflare->getZoneByName($zoneName);

            if ($zone === null) {
                $this->error("Zone '{$zoneName}' not found.");

                return self::FAILURE;
            }

            $names = $filter !== null ? $this->hostnameVariants($filter) : [];
            $hostnames = $cloudflare->getCustomHostnames($zone['id'], $names);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($hostnames === []) {
            $this->info($filter !== null
                ? "No custom hostname matching '{$filter}' found for '{$zoneName}'."
                : "No custom hostnames configured for '{$zoneName}'.");

            return self::SUCCESS;
        }

        $rows = array_map(fn (array $hostname): array => [
            $hostname['hostname'] ?? '—',
            Arr::get($hostname, 'ssl.status', '—'),
            $hostname['custom_origin_server'] ?? '—',
        ], $hostnames);

        $this->table(['Hostname', 'SSL Status', 'Origin Server'], $rows);

        return self::SUCCESS;
    }

    /**
     * Expand a hostname filter into the bare domain and its "www." variant,
     * so searching for "example.com" also matches "www.example.com".
     *
     * @return array<int, string>
     */
    private function hostnameVariants(string $hostname): array
    {
        $bare = Str::startsWith($hostname, 'www.') ? Str::after($hostname, 'www.') : $hostname;

        return array_values(array_unique([$bare, "www.{$bare}"]));
    }
}
