<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Response;

/** Entité introuvable (ou hors foyer) ⇒ 404. */
final class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Ressource introuvable.')
    {
        parent::__construct($message, 'not_found', Response::HTTP_NOT_FOUND);
    }
}
