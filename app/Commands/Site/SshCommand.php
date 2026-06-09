<?php

namespace App\Commands\Site;

use App\Commands\Concerns\ResolvesSite;
use App\Services\Config;
use App\Services\Ssh;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;

class SshCommand extends Command
{
    use ResolvesSite;

    protected $signature = 'site:ssh
        {site? : The site key from pilot.yml\'s sites map; prompts if omitted}
        {--config= : Directory containing pilot.yml (defaults to the current working directory)}
        {--source : SSH into the source host instead of the destination}';

    protected $description = 'Open an SSH session on a site\'s destination host (or its source with --source)';

    public function handle(Config $config, Ssh $ssh): int
    {
        $resolved = $this->resolveSite($config, 'SSH into');

        if ($resolved === null) {
            return self::FAILURE;
        }

        [, $migration] = $resolved;
        $remote = $this->option('source') ? $migration->source : $migration->destination;

        info("Connecting to {$remote->sshHost()}…");

        return $ssh->interactive($remote);
    }
}
