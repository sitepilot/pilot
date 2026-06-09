<?php

namespace App\Commands\Site;

use App\Commands\Concerns\ResolvesSite;
use App\Services\Config;
use App\Services\RemoteWpCli;
use App\Services\Rsync;
use App\Services\Ssh;
use App\Services\WpCli;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\progress;
use function Laravel\Prompts\warning;

class PullCommand extends Command
{
    use ResolvesSite;

    protected $signature = 'site:pull
        {site? : The site key from pilot.yml\'s sites map; prompts if omitted}
        {--config= : Directory containing pilot.yml (defaults to the current working directory)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Pull a site\'s files and database from its source host to its destination';

    public function handle(Config $config, WpCli $wp, RemoteWpCli $sourceWp, Rsync $rsync, Ssh $ssh): int
    {
        $resolved = $this->resolveSite($config, 'pull');

        if ($resolved === null) {
            return self::FAILURE;
        }

        [$key, $migration] = $resolved;
        $source = $migration->source;
        $destination = $migration->destination;

        // The dump the destination pulls from the source lands here, on the destination.
        $remoteDump = '/tmp/pilot-'.uniqid().'.sql';
        $dumpFetched = false;
        $prepared = false;

        try {
            // Detect WordPress cheaply (presence of wp-config.php) — a files-only
            // source needs no WP-CLI and never triggers a download. A WordPress
            // source gets its database migrated too.
            $isWordPress = $sourceWp->isWordPress($source);

            if ($isWordPress) {
                // The database import needs the destination to be WordPress too.
                $wp->validateInstall($destination);
            } else {
                warning("Source on {$source->host} is not a WordPress install (no wp-config.php) — pulling files only.");
            }

            if (! $this->option('force')) {
                $what = $isWordPress ? 'database and files' : 'files';
                warning(
                    "This will overwrite the {$what} for '{$key}' on {$destination->host} with {$source->host}."
                    .PHP_EOL.'Destination files not present on the source WILL be deleted (rsync --delete).'
                );

                if (! confirm('Continue?', default: false)) {
                    info('Aborted.');

                    return self::SUCCESS;
                }
            }

            // Provision WP-CLI on the source only now (after confirmation, and only
            // for WordPress) so a declined or files-only run does no remote setup or
            // download. A failure here is a hard error: the source IS WordPress, so
            // we don't silently fall back to files-only.
            $sourceBinary = null;
            if ($isWordPress) {
                $sourceBinary = $sourceWp->provision($source);
                $prepared = true;
            }

            // Each step is [label, task]. The task may return an info string to be
            // reported under the bar once everything finishes. For WordPress the
            // export runs first so SSH/credential problems fail fast before the
            // long file sync. The file sync and dump fetch run on the destination
            // (the host we own) reaching the source over agent forwarding, so data
            // moves source ↔ destination directly, never through this machine.
            $steps = [];

            if ($isWordPress) {
                $steps[] = ['Exporting source database', function () use ($sourceWp, $source, $sourceBinary): string {
                    $sourceWp->exportDatabase($source, $sourceBinary);

                    return 'Exported the source database';
                }];
            }

            // wp-config.php is excluded so the destination keeps its own DB
            // credentials and salts; without that the import below can't connect.
            $excludes = $isWordPress ? ['/wp-config.php'] : [];
            $steps[] = ['Syncing files', function () use ($ssh, $destination, $rsync, $source, $excludes, $isWordPress): string {
                $ssh->run($destination, $rsync->pullDir($source, $destination->path, $excludes), forwardAgent: true);

                return $isWordPress
                    ? 'Synced files (kept the destination wp-config.php)'
                    : 'Synced files';
            }];

            if ($isWordPress) {
                $steps[] = ['Fetching database dump', function () use ($ssh, $destination, $rsync, $source, $sourceWp, $remoteDump, &$dumpFetched): void {
                    $ssh->run($destination, $rsync->pullFile($source, $sourceWp->dumpPath(), $remoteDump), forwardAgent: true);
                    $dumpFetched = true;
                }];
                $steps[] = ['Importing database', function () use ($wp, $destination, $remoteDump): string {
                    $wp->importDatabase($destination, $remoteDump);

                    return 'Imported the database';
                }];
                // The dump carries the source's table prefix; align the kept
                // destination wp-config to it so WordPress finds the imported
                // tables. Must run before any wp option/search-replace below.
                $steps[] = ['Aligning table prefix', function () use ($wp, $sourceWp, $source, $destination, $sourceBinary): string {
                    $sourcePrefix = $sourceWp->tablePrefix($source, $sourceBinary);
                    $destPrefix = $wp->tablePrefix($destination);

                    if ($sourcePrefix === $destPrefix) {
                        return "Table prefix matches ({$destPrefix}); left unchanged";
                    }

                    $wp->setTablePrefix($destination, $sourcePrefix);

                    return "Set table prefix {$destPrefix} → {$sourcePrefix}";
                }];
                $steps[] = ['Updating site URL', function () use ($wp, $destination, $migration): string {
                    $currentUrl = $wp->currentSiteUrl($destination);

                    if (rtrim($currentUrl, '/') === rtrim($migration->url, '/')) {
                        return 'Site URL already matches; skipped search-replace';
                    }

                    $wp->searchReplace($destination, $currentUrl, $migration->url);

                    return "Rewrote {$currentUrl} → {$migration->url}";
                }];
                // Last DB step: optimize after the import and all row rewrites.
                $steps[] = ['Optimizing database', function () use ($wp, $destination): string {
                    $wp->optimizeDatabase($destination);

                    return 'Optimized the database';
                }];
            }

            // Run the steps under a progress bar, with the hint showing the step
            // currently running; each step may return a note for the summary.
            $notes = progress(
                label: "Pulling '{$key}'",
                steps: $steps,
                callback: function (array $step, $progress) {
                    [$label, $action] = $step;

                    $progress->hint($label.'…');
                    $progress->render();

                    return $action();
                },
            );

            info("Pulled '{$key}': {$source->host} → {$destination->host}");

            $notes = array_values(array_filter($notes, fn ($note) => $note !== null));

            if ($notes !== []) {
                note(implode(PHP_EOL, array_map(fn (string $note) => '• '.$note, $notes)));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            error($e->getMessage());

            return self::FAILURE;
        } finally {
            // Best-effort: remove the dump fetched onto the destination, and the
            // WP-CLI (plus its dump) we copied onto the source.
            if ($dumpFetched) {
                try {
                    $ssh->run($destination, 'rm -f '.escapeshellarg($remoteDump));
                } catch (Throwable) {
                    // Leaving a temp dump behind is not worth failing the run over.
                }
            }

            if ($prepared) {
                $sourceWp->cleanup($source);
            }
        }
    }
}
