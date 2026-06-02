<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Looks up authoritative DNS records (NS, MX, …) for a domain via `dig`.
 *
 * By default it traces the delegation from the root, so a change is seen the
 * moment it is live at the authoritative chain — without waiting for any
 * recursive resolver's cache TTL to expire. This mirrors how a fresh recursive
 * resolution (e.g. Let's Encrypt's validators) sees DNS.
 *
 * Returns data or throws — no console concerns.
 */
class DnsLookup
{
    /**
     * Resolve the records of a given type (e.g. "NS", "MX") for a domain.
     *
     * With no resolver, the authoritative delegation is traced from the root.
     * When a resolver is given (e.g. "1.1.1.1"), that recursive resolver is
     * queried instead.
     *
     * @return array<int, string> Sorted, de-duplicated, lower-cased record values.
     */
    public function records(string $domain, string $type, ?string $resolver = null): array
    {
        $type = strtoupper($type);

        $command = $resolver !== null
            ? ['dig', '+short', $type, $domain, '@'.$resolver]
            : ['dig', '+trace', '+nodnssec', $type, $domain];

        $result = Process::run($command);

        if ($result->failed()) {
            throw new RuntimeException(
                trim($result->errorOutput()) ?: "DNS lookup for '{$domain}' failed."
            );
        }

        return $this->parseRecords($result->output(), $domain, $type);
    }

    /**
     * Extract the domain's records of the given type from dig output, handling
     * both the `+short` form (rdata only, one record per line) and the full
     * record form produced by `+trace` (name TTL CLASS TYPE rdata).
     *
     * @return array<int, string>
     */
    private function parseRecords(string $output, string $domain, string $type): array
    {
        $domain = rtrim(strtolower($domain), '.');
        $records = [];

        foreach (preg_split('/\R/', trim($output)) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }

            $fields = preg_split('/\s+/', $line);

            // Full record form: only keep records of this type owned by the
            // queried domain (skips the root "." and TLD delegations, plus any
            // other record types emitted along a +trace).
            if (count($fields) >= 5 && strtoupper($fields[3]) === $type) {
                if (rtrim(strtolower($fields[0]), '.') === $domain) {
                    $records[] = $this->formatRdata(array_slice($fields, 4), $type);
                }

                continue;
            }

            // `+short` output: the line is the rdata of the queried type — one
            // field for most types, two for MX (preference + host).
            if (count($fields) === ($type === 'MX' ? 2 : 1)) {
                $records[] = $this->formatRdata($fields, $type);
            }
        }

        $records = array_values(array_unique($records));
        $this->sortRecords($records, $type);

        return $records;
    }

    /**
     * Normalise a record's rdata fields into a display string: a bare,
     * lower-cased host for most types, or "<preference> <host>" for MX.
     *
     * @param  array<int, string>  $rdata
     */
    private function formatRdata(array $rdata, string $type): string
    {
        if ($type === 'MX' && count($rdata) >= 2) {
            return $rdata[0].' '.rtrim(strtolower($rdata[1]), '.');
        }

        return rtrim(strtolower($rdata[0]), '.');
    }

    /**
     * Sort records in place — by preference (numeric) then host for MX,
     * alphabetically otherwise.
     *
     * @param  array<int, string>  $records
     */
    private function sortRecords(array &$records, string $type): void
    {
        if ($type === 'MX') {
            usort($records, function (string $a, string $b): int {
                [$prefA, $hostA] = explode(' ', $a, 2);
                [$prefB, $hostB] = explode(' ', $b, 2);

                return [(int) $prefA, $hostA] <=> [(int) $prefB, $hostB];
            });

            return;
        }

        sort($records);
    }
}
