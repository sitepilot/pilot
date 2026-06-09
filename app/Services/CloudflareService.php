<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client around the Cloudflare REST API (v4).
 *
 * All Cloudflare API logic lives here so commands stay thin and future
 * cloudflare:* commands can reuse these methods. Methods return decoded data
 * or throw a RuntimeException — they never write to the console.
 */
class CloudflareService
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    /**
     * Max page size allowed by the custom_hostnames endpoint.
     */
    private const PER_PAGE = 50;

    /**
     * Validation rules for the `cloudflare:` section of pilot.yml. Pass these to
     * {@see Config::load()} from the cf:* commands. `default_zone` is optional —
     * the command falls back to the built-in default when it is omitted.
     *
     * @var array<string, array<int, string>>
     */
    public const RULES = [
        'cloudflare' => ['required', 'array'],
        'cloudflare.token' => ['required', 'string'],
        'cloudflare.default_zone' => ['nullable', 'string'],
    ];

    private readonly ?string $token;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? config('services.cloudflare.token');
    }

    /**
     * Find a zone by its exact name (e.g. "example.com").
     *
     * @return array<string, mixed>|null The zone, or null when not found.
     */
    public function getZoneByName(string $name): ?array
    {
        $zones = $this->get('/zones', ['name' => $name]);

        return $zones[0] ?? null;
    }

    /**
     * List the custom hostnames (SSL for SaaS) configured for a zone.
     *
     * With no names, every custom hostname is returned. When names are given,
     * the Cloudflare API is queried for each (it matches exact fully qualified
     * domain names) and the results are merged and de-duplicated by id.
     *
     * @param  array<int, string>  $names
     * @return array<int, array<string, mixed>>
     */
    public function getCustomHostnames(string $zoneId, array $names = []): array
    {
        if ($names === []) {
            return $this->fetchCustomHostnames($zoneId);
        }

        $results = [];

        foreach ($names as $name) {
            foreach ($this->fetchCustomHostnames($zoneId, $name) as $hostname) {
                $results[$hostname['id']] = $hostname;
            }
        }

        return array_values($results);
    }

    /**
     * Fetch custom hostnames for a zone, following pagination until all
     * results are collected. Filters by a single hostname when given.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchCustomHostnames(string $zoneId, ?string $hostname = null): array
    {
        $hostnames = [];
        $page = 1;

        do {
            $response = $this->request("/zones/{$zoneId}/custom_hostnames", Arr::whereNotNull([
                'hostname' => $hostname,
                'page' => $page,
                'per_page' => self::PER_PAGE,
            ]));

            $hostnames = array_merge($hostnames, $response->json('result', []));

            $totalPages = (int) $response->json('result_info.total_pages', 1);
            $page++;
        } while ($page <= $totalPages);

        return $hostnames;
    }

    /**
     * Export a zone's DNS records as a BIND-format zone file.
     *
     * The Cloudflare export endpoint returns plain text (not JSON), so this
     * bypasses the JSON-asserting request() helper and returns the raw body.
     */
    public function exportDnsRecords(string $zoneId): string
    {
        $response = $this->client()->get("/zones/{$zoneId}/dns_records/export");

        if ($response->failed()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response->body();
    }

    /**
     * Issue a GET request and return the decoded `result` array.
     *
     * @param  array<string, mixed>  $query
     * @return array<int|string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->request($path, $query)->json('result', []);
    }

    /**
     * Issue a GET request, asserting both the HTTP status and Cloudflare's
     * own `success` flag, throwing with the API error messages on failure.
     *
     * @param  array<string, mixed>  $query
     */
    private function request(string $path, array $query = []): Response
    {
        $response = $this->client()->get($path, $query);

        if ($response->failed() || $response->json('success') !== true) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        if (empty($this->token)) {
            throw new RuntimeException(
                'Cloudflare API token is not configured. Set cloudflare.token in your pilot.yml.'
            );
        }

        return Http::withToken($this->token)
            ->timeout(15)
            ->connectTimeout(5)
            ->baseUrl(self::BASE_URL)
            ->acceptJson();
    }

    /**
     * Build a human-readable message from a failed Cloudflare response.
     */
    private function errorMessage(Response $response): string
    {
        $errors = collect($response->json('errors', []))
            ->pluck('message')
            ->filter()
            ->implode('; ');

        return $errors !== ''
            ? "Cloudflare API error: {$errors}"
            : "Cloudflare API request failed with status {$response->status()}.";
    }
}
