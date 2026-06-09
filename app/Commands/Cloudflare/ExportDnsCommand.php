<?php

namespace App\Commands\Cloudflare;

use App\Services\CloudflareService;
use Illuminate\Support\Facades\File;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class ExportDnsCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'cf:export
        {zone : The zone name, e.g. example.com}
        {--output= : File path to write to (defaults to <zone>.zone in the current directory)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = "Export a zone's DNS records as a BIND-format zone file";

    /**
     * Execute the console command.
     */
    public function handle(CloudflareService $cloudflare): int
    {
        $zoneName = $this->argument('zone');
        $path = $this->option('output') ?: "{$zoneName}.zone";

        try {
            $zone = $cloudflare->getZoneByName($zoneName);

            if ($zone === null) {
                error("Zone '{$zoneName}' not found.");

                return self::FAILURE;
            }

            $bind = $cloudflare->exportDnsRecords($zone['id']);
        } catch (Throwable $e) {
            error($e->getMessage());

            return self::FAILURE;
        }

        if (File::put($path, $bind) === false) {
            error("Could not write to {$path}.");

            return self::FAILURE;
        }

        info("Exported DNS for '{$zoneName}' to {$path}.");

        return self::SUCCESS;
    }
}
