<?php

namespace App\Commands\Dns;

use App\Services\DnsLookup;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;

class ViewCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'dns:view
        {domain : The domain to look up}
        {--resolver= : Query this recursive resolver (e.g. 1.1.1.1) instead of tracing the authoritative delegation}
        {--watch : Keep watching and report the new records when one changes}
        {--interval=5 : Seconds between checks in --watch mode}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = "Show a domain's NS, MX, apex and www records (optionally watching until they change)";

    /**
     * The records shown, in display order. Each entry has a human label, the
     * host prefix to query ('' = the apex domain), the record type(s) to try in
     * order (the first non-empty result wins, so www shows its CNAME or, failing
     * that, its A record), and whether to append the matched type to the label.
     */
    private const RECORDS = [
        'apex' => ['label' => 'Apex', 'host' => '', 'types' => ['A'], 'showType' => true],
        'www' => ['label' => 'www', 'host' => 'www', 'types' => ['CNAME', 'A'], 'showType' => true],
        'mx' => ['label' => 'Mail servers', 'host' => '', 'types' => ['MX'], 'showType' => false],
        'ns' => ['label' => 'Nameservers', 'host' => '', 'types' => ['NS'], 'showType' => false],
    ];

    /**
     * Execute the console command.
     */
    public function handle(DnsLookup $lookup): int
    {
        $domain = $this->argument('domain');
        $resolver = $this->option('resolver');
        $interval = max(1, (int) $this->option('interval'));

        try {
            $baseline = $this->lookupAll($lookup, $domain, $resolver);
        } catch (Throwable $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        $this->overview("Current DNS records for {$domain}", $baseline);

        if (! $this->option('watch')) {
            return self::SUCCESS;
        }

        $records = $this->awaitChange($lookup, $domain, $resolver, $interval, $baseline);

        $this->overview("New DNS records for {$domain}", $records, success: true);

        return self::SUCCESS;
    }

    /**
     * Poll until any record changes and return the new record set, showing a
     * spinner while waiting in an interactive terminal (a plain wait otherwise).
     *
     * @return array<string, array{type: ?string, values: array<int, string>}>
     */
    private function awaitChange(DnsLookup $lookup, string $domain, ?string $resolver, int $interval, array $baseline): array
    {
        $loop = function () use ($lookup, $domain, $resolver, $interval, $baseline): array {
            $previous = $baseline;

            while (true) {
                sleep($interval);

                try {
                    $records = $this->lookupAll($lookup, $domain, $resolver);
                } catch (Throwable $e) {
                    continue;
                }

                if ($records !== $previous) {
                    return $records;
                }

                $previous = $records;
            }
        };

        $message = "Waiting for a DNS change to {$domain}…";

        if ($this->supportsLiveOutput()) {
            return spin($loop, $message);
        }

        info($message);

        return $loop();
    }

    /**
     * Look up every shown record, keyed by its RECORDS key. For an entry with
     * several types, the first type that returns anything wins; the matched type
     * is kept so the label can show it.
     *
     * @return array<string, array{type: ?string, values: array<int, string>}>
     */
    private function lookupAll(DnsLookup $lookup, string $domain, ?string $resolver): array
    {
        $records = [];

        foreach (self::RECORDS as $key => $spec) {
            $name = $spec['host'] === '' ? $domain : $spec['host'].'.'.$domain;
            $matched = null;
            $values = [];

            foreach ($spec['types'] as $type) {
                $values = $lookup->records($name, $type, $resolver);

                if ($values !== []) {
                    $matched = $type;

                    break;
                }
            }

            $records[$key] = ['type' => $matched, 'values' => $values];
        }

        return $records;
    }

    /**
     * Render a record set as an overview: a pretty table in an interactive
     * terminal, plain labelled lines otherwise (pipes, tests). When $success is
     * true the title is shown as a green info line with no leading blank line.
     *
     * @param  array<string, array{type: ?string, values: array<int, string>}>  $records
     */
    private function overview(string $title, array $records, bool $success = false): void
    {
        if (! $success) {
            $this->newLine();
        }

        $success
            ? info($title)
            : $this->line($this->supportsLiveOutput() ? " <options=bold>{$title}</>" : "{$title}:");

        if ($this->supportsLiveOutput()) {
            $rows = [];

            foreach (self::RECORDS as $key => $spec) {
                $values = $records[$key]['values'] === [] ? ['(none)'] : $records[$key]['values'];
                $label = $this->label($spec, $records[$key]['type']);
                $first = true;

                foreach ($values as $value) {
                    $rows[] = [$first ? $label : '', $value];
                    $first = false;
                }
            }

            table(['Record', 'Value'], $rows);

            return;
        }

        foreach (self::RECORDS as $key => $spec) {
            $this->line('  '.$this->label($spec, $records[$key]['type']).': '.$this->formatList($records[$key]['values']));
        }
    }

    /**
     * The display label for a record, appending the matched type (e.g. "Apex (A)",
     * "www (CNAME)") when the entry opts in and a type was found.
     *
     * @param  array{label: string, showType: bool, ...}  $spec
     */
    private function label(array $spec, ?string $type): string
    {
        return $spec['showType'] && $type !== null
            ? "{$spec['label']} ({$type})"
            : $spec['label'];
    }

    /**
     * Whether the current output supports the interactive Prompts UI. False under
     * the buffered test harness and for piped/non-decorated output.
     */
    private function supportsLiveOutput(): bool
    {
        return $this->output->getOutput() instanceof ConsoleOutputInterface
            && $this->output->isDecorated();
    }

    /**
     * Render a record list for display, or "(none)" when empty.
     *
     * @param  array<int, string>  $records
     */
    private function formatList(array $records): string
    {
        return $records === [] ? '(none)' : implode(', ', $records);
    }
}
