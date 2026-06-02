<?php

use App\Services\DnsLookup;
use Illuminate\Support\Facades\Process;

function traceOutput(): string
{
    return <<<'OUT'
.			518400	IN	NS	a.root-servers.net.
;; Received 239 bytes from 192.168.1.1#53(192.168.1.1) in 4 ms

com.			172800	IN	NS	a.gtld-servers.net.
;; Received 1170 bytes from 198.41.0.4#53(a.root-servers.net) in 20 ms

example.com.		172800	IN	NS	NS2.EXAMPLE.COM.
example.com.		172800	IN	NS	ns1.example.com.
example.com.		172800	IN	NS	ns1.example.com.
;; Received 100 bytes from 192.5.6.30#53(a.gtld-servers.net) in 30 ms
OUT;
}

it('traces the authoritative delegation by default', function () {
    Process::fake(['*' => Process::result(output: traceOutput())]);

    $nameservers = (new DnsLookup)->records('example.com', 'NS');

    // Only the domain's own NS records, lower-cased, de-duplicated and sorted.
    expect($nameservers)->toBe(['ns1.example.com', 'ns2.example.com']);

    Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), '+trace'));
});

it('queries the given recursive resolver with +short', function () {
    Process::fake(['*' => Process::result(output: "ns2.example.com.\nns1.example.com.\n")]);

    $nameservers = (new DnsLookup)->records('example.com', 'NS', '1.1.1.1');

    expect($nameservers)->toBe(['ns1.example.com', 'ns2.example.com']);

    Process::assertRan(function ($process) {
        $command = implode(' ', $process->command);

        return str_contains($command, '+short') && str_contains($command, '@1.1.1.1');
    });
});

it('parses MX records with their preference, sorted by preference', function () {
    Process::fake(['*' => Process::result(output: "20 mx2.example.com.\n10 mx1.example.com.\n")]);

    $mx = (new DnsLookup)->records('example.com', 'MX', '1.1.1.1');

    expect($mx)->toBe(['10 mx1.example.com', '20 mx2.example.com']);

    Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), 'MX'));
});

it('throws when dig fails', function () {
    Process::fake(['*' => Process::result(errorOutput: "dig: couldn't connect", exitCode: 9)]);

    expect(fn () => (new DnsLookup)->records('example.com', 'NS'))
        ->toThrow(RuntimeException::class, "dig: couldn't connect");
});

it('returns an empty list when there are no records', function () {
    Process::fake(['*' => Process::result(output: '')]);

    expect((new DnsLookup)->records('example.com', 'NS'))->toBe([]);
});
