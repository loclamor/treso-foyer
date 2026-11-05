<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Response;

/** Quota atteint (API externe, rate limiting…) ⇒ 429. */
final class TooManyRequestsException extends ApiException
{
    public function __construct(string $message, string $errorCode = 'too_many_requests')
    {
        parent::__construct($message, $errorCode, Response::HTTP_TOO_MANY_REQUESTS);
    }
}
