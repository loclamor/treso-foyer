<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Erreur métier de l'API, traduite en réponse JSON du contrat docs/api.md :
 * {"error": <code>, "message": <FR>, "violations"?: [{field, message}]}.
 */
abstract class ApiException extends \RuntimeException
{
    /**
     * @param list<array{field: string, message: string}> $violations
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $violations = [],
    ) {
        parent::__construct($message);
    }

    /** @return array{error: string, message: string, violations?: list<array{field: string, message: string}>} */
    public function toPayload(): array
    {
        $payload = ['error' => $this->errorCode, 'message' => $this->getMessage()];
        if ([] !== $this->violations) {
            $payload['violations'] = $this->violations;
        }

        return $payload;
    }
}
