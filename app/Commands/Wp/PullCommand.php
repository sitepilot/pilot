<?php

namespace App\Commands\Wp;

use App\Exceptions\InvalidConfig;
use App\Services\Config;
use App\Services\Rsync;
use App\Services\Site;
use App\Services\WpCli;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class PullCommand extends Command
{
    protected $signature = 'wp:pull
        {--config= : Directory containing pilot.yml (defaults to the current working directory)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Pull a remote WordPress site (files + database) down to the local path defined in pilot.yml';

    public function handle(Config $config, WpCli $wp, Rsync $rsync): int
    {
        $dir = $this->option('config') ?: getcwd();

        // Pre-flight: load+validate the config and confirm the local install.
        // Both abort the same way, so they share one guard.
        try {
            $site = Site::fromArray($config->load($dir, Site::RULES));
            $localPath = rtrim($dir, '/').'/'.ltrim($site->path, '/');
            $wp->validateLocalInstall($localPath);
        } catch (InvalidConfig $e) {
            $this->error('Invalid pilot.yml:');

            foreach ($e->errors as $error) {
                $this->line("  • {$error}");
            }

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn("This will overwrite the local database and files in {$localPath} with {$site->remoteHost}.");
            $this->warn('Local files not present on the remote WILL be deleted (rsync --delete).');

            if (! $this->confirm('Continue?')) {
                $this->info('Aborted.');

                return self::SUCCESS;
            }
        }

        $dump = sys_get_temp_dir().'/pilot-'.uniqid().'.sql';

        // Each step is [label, task]. The task may return an info string to be
        // reported under the bar once everything finishes. Export runs first so
        // SSH/credential problems fail fast before the long rsync.
        $steps = [
            ['Exporting remote database', fn () => $wp->exportRemoteDatabase($site->sshTarget(), $dump)],
            ['Syncing files', fn () => $rsync->pull($site->rsyncSource(), $localPath, $site->remotePort)],
            ['Importing database', fn () => $wp->importDatabase($dump, $localPath)],
            ['Updating site URL', function () use ($wp, $site, $localPath): string {
                $currentUrl = $wp->currentSiteUrl($localPath);

                if (rtrim($currentUrl, '/') === rtrim($site->url, '/')) {
                    return 'Site URL already matches; skipped search-replace.';
                }

                $wp->searchReplace($currentUrl, $site->url, $localPath);

                return "Rewrote {$currentUrl} → {$site->url}.";
            }],
        ];

        $bar = $this->output->createProgressBar(count($steps));
        $bar->setFormat(' %current%/%max% [%bar%] %message%');
        $bar->setMessage('Starting…');
        $bar->start();

        $notes = [];

        try {
            foreach ($steps as [$label, $task]) {
                $bar->setMessage($label.'…');
                $bar->display();

                $note = $task();

                if ($note !== null) {
                    $notes[] = $note;
                }

                $bar->advance();
            }

            $bar->setMessage('Done.');
            $bar->finish();
        } catch (Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if (is_file($dump)) {
                @unlink($dump);
            }
        }

        $this->newLine(2);

        foreach ($notes as $note) {
            $this->line("  {$note}");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
