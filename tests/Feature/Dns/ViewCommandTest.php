<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

// dig is invoked with an array command, so Process::fake matches against the
// shell-quoted form: 'dig' '+short' 'NS' 'example.com' '@1.1.1.1'.

it('shows the current apex, www, MX and NS records', function () {
    Process::fake([
        "*'NS'*" => Process::result(output: "ns1.example.com.\n"),
        "*'MX'*" => Process::result(output: "10 mail.example.com.\n"),
        "*'CNAME' 'www.example.com'*" => Process::result(output: "cdn.example.net.\n"),
        "*'A' 'example.com'*" => Process::result(output: "93.184.216.34\n"),
        '*' => Process::result(output: ''),
    ]);

    $code = Artisan::call('dns:view', ['domain' => 'example.com', '--resolver' => '1.1.1.1']);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('Current DNS records for example.com')
        ->and($output)->toContain('Apex (A): 93.184.216.34')
        ->and($output)->toContain('www (CNAME): cdn.example.net')
        ->and($output)->toContain('Mail servers: 10 mail.example.com')
        ->and($output)->toContain('Nameservers: ns1.example.com');
});

it('with --watch, reports the new records when one changes', function () {
    Process::fake([
        "*'NS'*" => Process::sequence()
            ->push(Process::result(output: "ns1.old.com.\n"))   // baseline NS
            ->push(Process::result(output: "ns1.new.com.\n")),  // round 2 NS (changed)
        '*' => Process::result(output: ''),
    ]);

    $code = Artisan::call('dns:view', [
        'domain' => 'example.com',
        '--resolver' => '1.1.1.1',
        '--watch' => true,
        '--interval' => 1,
    ]);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('Nameservers: ns1.old.com')      // baseline
        ->and($output)->toContain('New DNS records for example.com')
        ->and($output)->toContain('Nameservers: ns1.new.com');     // changed
});

it('does not wait for a change without --watch', function () {
    // A single constant result for every query: nothing ever changes, so if the
    // command waited it would hang. It must return immediately instead.
    Process::fake(['*' => Process::result(output: "ns1.example.com.\n")]);

    $code = Artisan::call('dns:view', ['domain' => 'example.com', '--resolver' => '1.1.1.1']);

    expect($code)->toBe(0)
        ->and(Artisan::output())->not->toContain('New DNS records');
});

it('traces the authoritative delegation when no resolver is given', function () {
    Process::fake(['*' => Process::result(output: "example.com.\t172800\tIN\tNS\tns1.example.com.\n")]);

    Artisan::call('dns:view', ['domain' => 'example.com']);

    Process::assertRan(fn ($process) => str_contains(implode(' ', $process->command), '+trace'));
});

it('fails with a clear message when the lookup errors', function () {
    Process::fake(['*' => Process::result(errorOutput: 'dig: network unreachable', exitCode: 9)]);

    $code = Artisan::call('dns:view', ['domain' => 'example.com']);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('dig: network unreachable');
});

it('shows "(none)" when no records are found', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $code = Artisan::call('dns:view', ['domain' => 'example.com', '--resolver' => '1.1.1.1']);
    $output = Artisan::output();

    expect($code)->toBe(0)
        ->and($output)->toContain('Apex: (none)')
        ->and($output)->toContain('www: (none)')
        ->and($output)->toContain('Mail servers: (none)')
        ->and($output)->toContain('Nameservers: (none)');
});
