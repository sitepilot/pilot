<?php

namespace App\Services;

use App\Exceptions\InvalidConfig;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads and validates the project's pilot.yml.
 *
 * The validation rules are supplied by the caller, so each command can validate
 * the subset of pilot.yml it needs (e.g. {@see Site::RULES} for wp:pull).
 * Returns the parsed, validated array or throws RuntimeException — no console
 * concerns.
 *
 * Validation is self-contained: it builds its own validator factory using
 * Laravel's bundled default messages, keeping this concern out of the global
 * app bootstrap. Callers can still override per-rule wording via load()'s
 * $messages argument.
 */
class Config
{
    private readonly ValidationFactory $validation;

    public function __construct()
    {
        // Point the loader at Illuminate's bundled lang dir so every rule gets a
        // real default message. Resolve it absolutely (off the Translator class
        // location), since wp:pull runs from the user's site dir, not here.
        $langPath = dirname((new ReflectionClass(Translator::class))->getFileName()).'/lang';

        $this->validation = new ValidationFactory(new Translator(new FileLoader(new Filesystem, $langPath), 'en'));
    }

    /**
     * Load and validate the pilot.yml in $directory against the given rules.
     *
     * @param  array<string, mixed>  $rules  Laravel validation rules.
     * @param  array<string, string>  $messages  Custom messages overriding Laravel's defaults.
     * @return array<string, mixed> The parsed, validated configuration.
     *
     * @throws RuntimeException when the file is missing, malformed, or invalid.
     */
    public function load(string $directory, array $rules, array $messages = []): array
    {
        $file = rtrim($directory, '/').'/pilot.yml';

        if (! is_file($file)) {
            throw new RuntimeException("No pilot.yml found in {$directory}.");
        }

        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new RuntimeException('pilot.yml is not valid YAML: '.$e->getMessage());
        }

        if (! is_array($data)) {
            throw new RuntimeException('pilot.yml is empty or malformed.');
        }

        $validator = $this->validation->make($data, $rules, $messages);

        if ($validator->fails()) {
            throw new InvalidConfig($validator->errors()->all());
        }

        return $data;
    }
}
