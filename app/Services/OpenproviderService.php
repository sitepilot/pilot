<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client around the Openprovider REST API (v1beta).
 *
 * All Openprovider API logic lives here so commands stay thin and future
 * openprovider:* commands can reuse these methods. Methods return decoded data
 * or throw a RuntimeException — they never write to the console.
 *
 * Authentication is a two-step Bearer flow: POST /auth/login with the reseller
 * username/password returns a token, which is then sent on every other request.
 * The token is fetched lazily and memoised for the lifetime of the instance.
 */
class OpenproviderService
{
    private const BASE_URL = 'https://api.openprovider.eu/v1beta';

    /**
     * Validation rules for the `openprovider:` section of pilot.yml. Pass these
     * to {@see Config::load()} from the op:* commands. `default_ns_group` is
     * optional — the command falls back to the built-in default when omitted.
     *
     * @var array<string, array<int, string>>
     */
    public const RULES = [
        'openprovider' => ['required', 'array'],
        'openprovider.username' => ['required', 'string'],
        'openprovider.password' => ['required', 'string'],
        'openprovider.default_ns_group' => ['nullable', 'string'],
    ];

    private readonly ?string $username;

    private readonly ?string $password;

    private ?string $token = null;

    public function __construct(?string $username = null, ?string $password = null)
    {
        $this->username = $username ?? config('services.openprovider.username');
        $this->password = $password ?? config('services.openprovider.password');
    }

    /**
     * Find a domain by its exact name (e.g. "example.com").
     *
     * Openprovider's list endpoint matches by pattern, so the result is
     * filtered down to an exact name + extension match.
     *
     * @return array<string, mixed>|null The domain, or null when not found.
     */
    public function findDomainByName(string $domain): ?array
    {
        [$name, $extension] = $this->splitDomain($domain);

        $results = $this->request('get', '/domains', [
            'domain_name_pattern' => $name,
            'extension' => $extension,
        ])->json('data.results', []);

        foreach ($results as $result) {
            if (($result['domain']['name'] ?? null) === $name
                && ($result['domain']['extension'] ?? null) === $extension) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Assign a nameserver group (e.g. "sitepilot-net") to a domain, replacing
     * whatever nameservers it currently uses.
     */
    public function setNameserverGroup(string|int $domainId, string $group): void
    {
        $this->request('put', "/domains/{$domainId}", [
            'ns_group' => $group,
        ]);
    }

    /**
     * Split "example.co.uk" into ["example", "co.uk"].
     *
     * @return array{0: string, 1: string}
     */
    private function splitDomain(string $domain): array
    {
        $domain = rtrim(strtolower(trim($domain)), '.');
        $position = strpos($domain, '.');

        if ($position === false) {
            return [$domain, ''];
        }

        return [substr($domain, 0, $position), substr($domain, $position + 1)];
    }

    /**
     * Issue an authenticated request, asserting both the HTTP status and
     * Openprovider's own response code, throwing the API message on failure.
     *
     * @param  array<string, mixed>  $data  Query string for GET, JSON body otherwise.
     */
    private function request(string $method, string $path, array $data = []): Response
    {
        $response = $this->client()->{$method}($path, $data);

        if ($this->failed($response)) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->authenticate())
            ->timeout(15)
            ->connectTimeout(5)
            ->baseUrl(self::BASE_URL)
            ->acceptJson();
    }

    /**
     * Log in once and memoise the Bearer token for subsequent requests.
     */
    private function authenticate(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        if (empty($this->username) || empty($this->password)) {
            throw new RuntimeException(
                'Openprovider credentials are not configured. Set openprovider.username and openprovider.password in your pilot.yml.'
            );
        }

        $response = Http::baseUrl(self::BASE_URL)
            ->timeout(15)
            ->connectTimeout(5)
            ->acceptJson()
            ->post('/auth/login', [
                'username' => $this->username,
                'password' => $this->password,
            ]);

        $token = $response->json('data.token');

        if ($this->failed($response) || empty($token)) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $this->token = $token;
    }

    /**
     * Whether a response failed at the HTTP level or carries a non-zero
     * Openprovider status code (0 means success).
     */
    private function failed(Response $response): bool
    {
        return $response->failed() || (int) $response->json('code', 0) !== 0;
    }

    /**
     * Build a human-readable message from a failed Openprovider response.
     */
    private function errorMessage(Response $response): string
    {
        $desc = $response->json('desc');

        return ! empty($desc)
            ? "Openprovider API error: {$desc}"
            : "Openprovider API request failed with status {$response->status()}.";
    }
}
