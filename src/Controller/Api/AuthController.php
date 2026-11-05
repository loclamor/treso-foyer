<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\Presenter\MemberPresenter;
use App\Entity\Member;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Authentification (contrat docs/api.md) : /api/login et /api/logout sont interceptés
 * par le firewall (json_login / logout) avant d'atteindre ces méthodes.
 */
final class AuthController extends AbstractController
{
    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('Intercepté par le firewall json_login.');
    }

    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le firewall logout.');
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(MemberPresenter $memberPresenter): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof Member);

        return new JsonResponse($memberPresenter->present($user));
    }
}
