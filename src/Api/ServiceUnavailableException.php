<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Response;

/** Service externe indisponible ou réponse illisible ⇒ 503 (ou 502 si $status est passé). */
final class ServiceUnavailableException extends ApiException
{
    public function __construct(
        string $message,
        string $errorCode = 'service_unavailable',
        int $status = Response::HTTP_SERVICE_UNAVAILABLE,
    ) {
        parent::__construct($message, $errorCode, $status);
    }
}
