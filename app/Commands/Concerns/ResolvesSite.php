<?php

namespace App\Commands\Concerns;

use App\Exceptions\InvalidConfig;
use App\Services\Config;
use App\Services\Migration;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;

/**
 * Shared resolution for the `site:*` commands: load and validate pilot.yml from
 * the --config directory, then turn the requested site key into a Migration.
 */
trait ResolvesSite
{
    /**
     * Load pilot.yml and resolve the requested site to its key and Migration.
     *
     * On failure (invalid config, or an unknown site key) the reason is printed
     * and null is returned; the caller should then return self::FAILURE. $verb
     * fills the selection prompt shown when the site argument is omitted, e.g.
     * "pull" or "SSH into".
     *
     * @return array{string, Migration}|null
     */
    protected function resolveSite(Config $config, string $verb): ?array
    {
        $dir = $this->option('config') ?: getcwd();

        try {
            $data = $config->load($dir, Migration::RULES);
        } catch (InvalidConfig $e) {
            error('Invalid pilot.yml:');
            note(implode(PHP_EOL, array_map(fn (string $err) => '• '.$err, $e->errors)));

            return null;
        } catch (Throwable $e) {
            error($e->getMessage());

            return null;
        }

        // Resolve which site: the positional argument, or an interactive pick from
        // the configured keys when it is omitted. Use ?? (not ?:) so a site
        // literally keyed "0" is still selectable.
        $sites = $data['sites'];
        $key = $this->argument('site') ?? select("Which site do you want to {$verb}?", array_keys($sites));

        if (! isset($sites[$key])) {
            error("Unknown site '{$key}'.");
            note('Available sites: '.implode(', ', array_keys($sites)).'.');

            return null;
        }

        return [$key, Migration::fromArray($sites[$key])];
    }
}
