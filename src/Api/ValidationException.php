<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Response;

/** Validation des données d'entrée ⇒ 422 avec violations [{field, message}]. */
final class ValidationException extends ApiException
{
    /**
     * @param list<array{field: string, message: string}> $violations
     */
    public function __construct(array $violations, string $message = 'Données invalides.')
    {
        parent::__construct($message, 'validation_failed', Response::HTTP_UNPROCESSABLE_ENTITY, $violations);
    }
}
