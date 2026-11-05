<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\HouseholdProvider;
use App\Api\Presenter\MemberPresenter;
use App\Entity\Member;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class MemberController extends AbstractController
{
    #[Route('/api/members', name: 'api_members', methods: ['GET'])]
    public function list(HouseholdProvider $householdProvider, MemberPresenter $memberPresenter, EntityManagerInterface $entityManager): JsonResponse
    {
        $members = $entityManager->getRepository(Member::class)->findBy(
            ['household' => $householdProvider->get()],
            ['id' => 'ASC'],
        );

        return new JsonResponse(array_map(
            static fn (Member $member): array => $memberPresenter->present($member),
            $members,
        ));
    }
}
