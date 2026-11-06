<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\BadRequestException;
use App\Api\HouseholdProvider;
use App\Api\NotFoundException;
use App\Api\Pagination;
use App\Api\Presenter\TransferPresenter;
use App\Categorization\InternalTransferMatcher;
use App\Entity\Household;
use App\Entity\InternalTransfer;
use App\Enum\TransferStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TransferController extends AbstractController
{
    #[Route('/api/transfers', name: 'api_transfers', methods: ['GET'])]
    public function list(
        Request $request,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        TransferPresenter $presenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $pagination = Pagination::fromRequest($request);

        // ?status=auto (à vérifier) | confirmed ; absent = tous.
        $rawStatus = (string) $request->query->get('status', '');
        $status = '' === $rawStatus ? null : TransferStatus::tryFrom($rawStatus);
        if ('' !== $rawStatus && null === $status) {
            throw new BadRequestException('Paramètre status invalide (auto|confirmed).', 'invalid_query');
        }

        $base = $entityManager->getRepository(InternalTransfer::class)->createQueryBuilder('t')
            ->join('t.operations', 'o')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->orderBy('t.id', 'DESC')
            ->setParameter('household', $household);
        if (null !== $status) {
            $base->andWhere('t.status = :status')->setParameter('status', $status);
        }

        // Un virement = 2 opérations : total distinct sur les virements.
        $total = (int) $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(DISTINCT t.id) FROM internal_transfer t
             JOIN operation o ON o.internal_transfer_id = t.id
             JOIN account a ON a.id = o.account_id
             WHERE a.household_id = :household AND (CAST(:status AS text) IS NULL OR t.status = :status)',
            ['household' => $household->getId(), 'status' => $status?->value],
        );

        $rows = $base
            ->select('t', 'o', 'oa', 'oc')
            ->join('o.account', 'oa')
            ->leftJoin('o.category', 'oc')
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage)
            ->getQuery()->getResult();

        // Regroupe les lignes par virement (jointure operations).
        $transfersById = [];
        foreach ($rows as $row) {
            $transfer = \is_array($row) ? $row[0] : $row;
            \assert($transfer instanceof InternalTransfer);
            $transfersById[(int) $transfer->getId()] ??= $transfer;
        }

        return new JsonResponse($pagination->envelope(
            array_map(static fn (InternalTransfer $transfer): array => $presenter->present($transfer), array_values($transfersById)),
            $total,
        ));
    }

    #[Route('/api/transfers/match', name: 'api_transfers_match', methods: ['POST'])]
    public function match(
        HouseholdProvider $householdProvider,
        InternalTransferMatcher $matcher,
    ): JsonResponse {
        $report = $matcher->match($householdProvider->get());

        return new JsonResponse(['matched' => $report->matched, 'ambiguous' => $report->ambiguous]);
    }

    /** Valide tous les virements « à vérifier » (status auto) du foyer. */
    #[Route('/api/transfers/confirm-all', name: 'api_transfers_confirm_all', methods: ['POST'])]
    public function confirmAll(
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $confirmed = $entityManager->getConnection()->executeStatement(
            "UPDATE internal_transfer SET status = 'confirmed'
             WHERE status = 'auto' AND id IN (
                 SELECT o.internal_transfer_id FROM operation o
                 JOIN account a ON a.id = o.account_id
                 WHERE a.household_id = :household AND o.internal_transfer_id IS NOT NULL
             )",
            ['household' => $householdProvider->get()->getId()],
        );

        return new JsonResponse(['confirmedCount' => $confirmed]);
    }

    /** Valide un virement détecté : il ne compte plus dans « virements à vérifier ». */
    #[Route('/api/transfers/{id}/confirm', name: 'api_transfer_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function confirm(
        int $id,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        TransferPresenter $presenter,
    ): JsonResponse {
        $transfer = $this->findInHousehold($entityManager, $householdProvider->get(), $id);
        $transfer->setStatus(TransferStatus::Confirmed);
        $entityManager->flush();

        return new JsonResponse($presenter->present($transfer));
    }

    #[Route('/api/transfers/{id}', name: 'api_transfer_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function unlink(
        int $id,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
    ): Response {
        $household = $householdProvider->get();
        $transfer = $entityManager->find(InternalTransfer::class, $id);
        if (!$transfer instanceof InternalTransfer) {
            throw new NotFoundException('Virement interne introuvable.');
        }
        $inHousehold = false;
        foreach ($transfer->getOperations() as $operation) {
            if ((int) $operation->getAccount()->getHousehold()->getId() === (int) $household->getId()) {
                $inHousehold = true;
            }
            $operation->setInternalTransfer(null);
            $operation->uncategorize(); // la catégorie « virement interne » disparaît avec l'appariement
        }
        if (!$inHousehold) {
            throw new NotFoundException('Virement interne introuvable.');
        }
        $entityManager->remove($transfer);
        $entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function findInHousehold(EntityManagerInterface $entityManager, Household $household, int $id): InternalTransfer
    {
        $transfer = $entityManager->find(InternalTransfer::class, $id);
        if ($transfer instanceof InternalTransfer) {
            foreach ($transfer->getOperations() as $operation) {
                if ((int) $operation->getAccount()->getHousehold()->getId() === (int) $household->getId()) {
                    return $transfer;
                }
            }
        }

        throw new NotFoundException('Virement interne introuvable.');
    }
}
