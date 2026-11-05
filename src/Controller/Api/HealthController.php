<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(Connection $connection): JsonResponse
    {
        try {
            $database = 1 === (int) $connection->fetchOne('SELECT 1');
        } catch (\Throwable) {
            $database = false;
        }

        return new JsonResponse(['status' => 'ok', 'database' => $database]);
    }
}
