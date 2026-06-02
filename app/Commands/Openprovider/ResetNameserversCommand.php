<?php

namespace App\Commands\Openprovider;

use App\Services\OpenproviderService;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class ResetNameserversCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'op:reset-ns
        {domain : The domain to reset, e.g. example.com}
        {--group= : Nameserver group to apply (defaults to the configured group)}
        {--force : Skip the confirmation prompt}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = "Reset a domain's nameservers to an Openprovider nameserver group";

    /**
     * Execute the console command.
     */
    public function handle(OpenproviderService $openprovider): int
    {
        $domainName = $this->argument('domain');
        $group = $this->option('group') ?: config('services.openprovider.default_ns_group');

        try {
            $domain = $openprovider->findDomainByName($domainName);

            if ($domain === null) {
                $this->error("Domain '{$domainName}' not found at Openprovider.");

                return self::FAILURE;
            }

            if (! $this->option('force')
                && ! $this->confirm("Reset {$domainName} to nameserver group '{$group}'?")) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }

            $openprovider->setNameserverGroup($domain['id'], $group);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Reset {$domainName} to nameserver group '{$group}'.");

        return self::SUCCESS;
    }
}
