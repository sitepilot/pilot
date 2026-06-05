<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when pilot.yml fails validation. Carries the individual validation
 * messages so a command can render them (e.g. as a bullet list); the flattened
 * parent message keeps a sensible single-line form for logging/other callers.
 */
class InvalidConfig extends RuntimeException
{
    /**
     * @param  array<int, string>  $errors  One human-readable message per failed rule.
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Invalid pilot.yml — '.implode('; ', $errors));
    }
}
