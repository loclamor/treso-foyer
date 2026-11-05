<?php

declare(strict_types=1);

namespace App\Security;

use App\Api\Presenter\MemberPresenter;
use App\Entity\Member;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Succès du json_login sur POST /api/login : 200 + membre connecté (contrat docs/api.md).
 */
final class JsonLoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(private readonly MemberPresenter $memberPresenter)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): JsonResponse
    {
        $user = $token->getUser();
        \assert($user instanceof Member);

        return new JsonResponse($this->memberPresenter->present($user));
    }
}
