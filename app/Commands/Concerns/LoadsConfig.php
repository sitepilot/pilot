<?php

namespace App\Commands\Concerns;

use App\Exceptions\InvalidConfig;
use App\Services\Config;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\note;

/**
 * Shared loading of pilot.yml for any command: resolve the --config directory
 * (defaulting to the current working directory), load and validate the file
 * against the caller's rules, and on failure print the reason and return null.
 *
 * Each command passes the rules for the subset of pilot.yml it needs (e.g.
 * {@see \App\Services\CloudflareService::RULES}), so a project only has to
 * configure the sections for the commands it actually uses.
 */
trait LoadsConfig
{
    /**
     * Load pilot.yml and validate it against $rules.
     *
     * On failure (missing file, invalid YAML, or a rule violation) the reason is
     * printed and null is returned; the caller should then return self::FAILURE.
     *
     * @param  array<string, mixed>  $rules  Laravel validation rules.
     * @param  array<string, string>  $messages  Custom messages overriding Laravel's defaults.
     * @return array<string, mixed>|null The parsed, validated configuration, or null on failure.
     */
    protected function loadConfig(Config $config, array $rules, array $messages = []): ?array
    {
        $dir = $this->option('config') ?: getcwd();

        try {
            return $config->load($dir, $rules, $messages);
        } catch (InvalidConfig $e) {
            error('Invalid pilot.yml:');
            note($this->bulletList($e->errors));
        } catch (Throwable $e) {
            error($e->getMessage());
        }

        return null;
    }

    /**
     * Render lines as a bulleted block, ready to hand to {@see note()}.
     *
     * @param  array<int, string>  $lines
     */
    protected function bulletList(array $lines): string
    {
        return implode(PHP_EOL, array_map(fn (string $line) => '• '.$line, $lines));
    }
}
