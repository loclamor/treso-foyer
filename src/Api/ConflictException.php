<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Response;

/** Conflit (unicité, ressource utilisée…) ⇒ 409. */
final class ConflictException extends ApiException
{
    public function __construct(string $message, string $errorCode = 'conflict')
    {
        parent::__construct($message, $errorCode, Response::HTTP_CONFLICT);
    }
}
