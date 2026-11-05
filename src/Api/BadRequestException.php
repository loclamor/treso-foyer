<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Response;

/** Requête invalide (JSON mal formé, filtre illisible…) ⇒ 400. */
final class BadRequestException extends ApiException
{
    public function __construct(string $message, string $errorCode = 'bad_request')
    {
        parent::__construct($message, $errorCode, Response::HTTP_BAD_REQUEST);
    }
}
