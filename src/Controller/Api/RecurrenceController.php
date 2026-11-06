<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\BadRequestException;
use App\Api\HouseholdProvider;
use App\Api\Input\RecurrencePatchInput;
use App\Api\NotFoundException;
use App\Api\Presenter\RecurrencePresenter;
use App\Api\Scope;
use App\Api\ValidationException;
use App\Entity\Household;
use App\Entity\RecurringSeries;
use App\Enum\RecurrenceDirection;
use App\Enum\RecurrenceFrequency;
use App\Enum\RecurrenceStatus;
use App\Recurrence\RecurrenceCalendar;
use App\Recurrence\RecurrenceDetector;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Séries récurrentes (contrat docs/api.md, lot récurrences) : liste/filtrage (périmètre Scope),
 * lecture, modification partielle (une correction autre que le statut passe la série en
 * « confirmed »), détection à la demande. Le périmètre ne restreint que les séries des
 * comptes désignés ; le 404 s'applique hors foyer.
 */
final class RecurrenceController extends AbstractController
{
    private const array STATUS_ORDER = [
        'confirmed' => 0,
        'detected' => 1,
        'ended' => 2,
        'ignored' => 3,
    ];

    #[Route('/api/recurrences', name: 'api_recurrences', methods: ['GET'])]
    public function list(
        Request $request,
        HouseholdProvider $householdProvider,
        Scope $scope,
        EntityManagerInterface $entityManager,
        RecurrencePresenter $presenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $accountIds = $scope->accountIds($request, $household);

        $statuses = null;
        $rawStatus = $request->query->get('status');
        if (null !== $rawStatus && '' !== $rawStatus) {
            $statuses = [];
            foreach (explode(',', (string) $rawStatus) as $value) {
                $status = RecurrenceStatus::tryFrom(trim($value));
                if (null === $status) {
                    throw new BadRequestException('Paramètre status invalide (detected|confirmed|ignored|ended, liste séparée par des virgules).', 'invalid_query');
                }
                $statuses[] = $status;
            }
        }

        $direction = null;
        $rawDirection = $request->query->get('direction');
        if (null !== $rawDirection && '' !== $rawDirection) {
            $direction = RecurrenceDirection::tryFrom((string) $rawDirection)
                ?? throw new BadRequestException('Paramètre direction invalide (expense|income).', 'invalid_query');
        }

        $qb = $entityManager->getRepository(RecurringSeries::class)->createQueryBuilder('rs')
            ->join('rs.account', 'a')
            ->where('a.household = :household')
            ->setParameter('household', $household);
        $scope->apply($qb, $accountIds);
        /** @var list<RecurringSeries> $series */
        $series = $qb->getQuery()->getResult();

        // Compteurs sur le périmètre, avant filtre status/direction.
        $counts = ['detected' => 0, 'confirmed' => 0, 'ignored' => 0, 'ended' => 0];
        foreach ($series as $item) {
            ++$counts[$item->getStatus()->value];
        }

        // Tri : status (confirmed, detected, ended, ignored), nextExpected croissant (null en
        // dernier), puis id.
        usort($series, static function (RecurringSeries $a, RecurringSeries $b): int {
            return [self::STATUS_ORDER[$a->getStatus()->value], $a->getNextExpected()?->format('Y-m-d') ?? '9999-99-99', (int) $a->getId()]
                <=> [self::STATUS_ORDER[$b->getStatus()->value], $b->getNextExpected()?->format('Y-m-d') ?? '9999-99-99', (int) $b->getId()];
        });

        if (null !== $statuses) {
            $series = array_values(array_filter(
                $series,
                static fn (RecurringSeries $item): bool => \in_array($item->getStatus(), $statuses, true),
            ));
        }
        if (null !== $direction) {
            $series = array_values(array_filter(
                $series,
                static fn (RecurringSeries $item): bool => $item->getDirection() === $direction,
            ));
        }

        // Compteurs d'opérations liées (1 requête agrégée).
        $occurrences = [];
        if ([] !== $series) {
            $rows = $entityManager->getConnection()->fetchAllAssociative(
                'SELECT recurring_series_id, COUNT(*) AS cnt FROM operation WHERE recurring_series_id IN (:ids) GROUP BY 1',
                ['ids' => array_map(static fn (RecurringSeries $item): int => (int) $item->getId(), $series)],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $occurrences[(int) $row['recurring_series_id']] = (int) $row['cnt'];
            }
        }

        return new JsonResponse([
            'items' => array_map(
                static fn (RecurringSeries $item): array => $presenter->present($item, $occurrences[(int) $item->getId()] ?? 0),
                $series,
            ),
            'counts' => $counts,
        ]);
    }

    #[Route('/api/recurrences/{id}', name: 'api_recurrence', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        int $id,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        RecurrencePresenter $presenter,
    ): JsonResponse {
        $series = $this->find($entityManager, $householdProvider->get(), $id);

        return new JsonResponse($presenter->present($series, $this->occurrencesCount($entityManager, $series)));
    }

    #[Route('/api/recurrences/{id}', name: 'api_recurrence_patch', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function patch(
        int $id,
        Request $request,
        #[MapRequestPayload] RecurrencePatchInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        RecurrencePresenter $presenter,
        ClockInterface $clock,
    ): JsonResponse {
        $household = $householdProvider->get();
        $series = $this->find($entityManager, $household, $id);
        $body = $request->toArray();

        $status = null;
        if (array_key_exists('status', $body) && null !== $body['status']) {
            $status = RecurrenceStatus::tryFrom((string) $body['status']);
            if (null === $status) {
                throw new ValidationException([['field' => 'status', 'message' => 'status doit être « detected », « confirmed », « ignored » ou « ended ».']]);
            }
        }

        $frequency = null;
        if (array_key_exists('frequency', $body) && null !== $body['frequency']) {
            $frequency = RecurrenceFrequency::tryFrom((string) $body['frequency']);
            if (null === $frequency) {
                throw new ValidationException([['field' => 'frequency', 'message' => 'frequency invalide (weekly|monthly|bimonthly|quarterly|semiannual|yearly).']]);
            }
        }

        $activeMonths = null;
        $hasActiveMonths = array_key_exists('activeMonths', $body);
        if ($hasActiveMonths && null !== $body['activeMonths']) {
            if (!\is_array($body['activeMonths']) || [] === $body['activeMonths']) {
                throw new ValidationException([['field' => 'activeMonths', 'message' => 'activeMonths doit être une liste non vide de mois (1..12) ou null.']]);
            }
            $months = [];
            foreach ($body['activeMonths'] as $month) {
                if (!\is_int($month) || $month < 1 || $month > 12) {
                    throw new ValidationException([['field' => 'activeMonths', 'message' => 'activeMonths doit contenir des mois entre 1 et 12, sans doublon.']]);
                }
                $months[$month] = true;
            }
            if (count($months) !== count($body['activeMonths'])) {
                throw new ValidationException([['field' => 'activeMonths', 'message' => 'activeMonths ne doit pas contenir de doublon.']]);
            }
            $activeMonths = array_keys($months);
            sort($activeMonths);
        }

        $touchesFields = false;
        if (array_key_exists('expectedAmount', $body) && null !== $body['expectedAmount']) {
            if (!\is_int($body['expectedAmount']) || $body['expectedAmount'] <= 0) {
                throw new ValidationException([['field' => 'expectedAmount', 'message' => 'expectedAmount doit être un entier strictement positif (centimes).']]);
            }
            $series->setExpectedAmount($body['expectedAmount']);
            $touchesFields = true;
        }
        if (array_key_exists('amountTolerance', $body) && null !== $body['amountTolerance']) {
            if (!\is_int($body['amountTolerance']) || $body['amountTolerance'] < 0) {
                throw new ValidationException([['field' => 'amountTolerance', 'message' => 'amountTolerance doit être un entier positif ou nul (centimes).']]);
            }
            $series->setAmountTolerance($body['amountTolerance']);
            $touchesFields = true;
        }
        if (null !== $frequency) {
            $series->setFrequency($frequency);
            $touchesFields = true;
        }
        if ($hasActiveMonths) {
            $series->setActiveMonths(null !== $body['activeMonths'] ? $activeMonths : null);
            $touchesFields = true;
        }

        // Toute correction autre que le statut d'une série « detected » la passe en « confirmed ».
        if ($touchesFields && RecurrenceStatus::Detected === $series->getStatus() && null === $status) {
            $series->setStatus(RecurrenceStatus::Confirmed);
        }
        if (null !== $status) {
            $series->setStatus($status);
        }

        // Recalcul de la prochaine échéance si la fréquence ou les mois actifs changent.
        if (null !== $frequency || $hasActiveMonths) {
            $series->setNextExpected(RecurrenceCalendar::nextAfter(
                $series->getLastOccurrence(),
                $series->getFrequency(),
                $series->getActiveMonths(),
            ));
        }

        if ($touchesFields || null !== $status) {
            $series->setUpdatedAt($clock->now());
        }
        $entityManager->flush();

        // « ignored » : délie les opérations (DBAL, hors UnitOfWork) puis recharge la série.
        if (RecurrenceStatus::Ignored === $series->getStatus()) {
            $entityManager->getConnection()->executeStatement(
                'UPDATE operation SET recurring_series_id = NULL WHERE recurring_series_id = :id',
                ['id' => $series->getId()],
                ['id' => ParameterType::INTEGER],
            );
            $entityManager->refresh($series);
        }

        return new JsonResponse($presenter->present($series, $this->occurrencesCount($entityManager, $series)));
    }

    #[Route('/api/recurrences/detect', name: 'api_recurrences_detect', methods: ['POST'])]
    public function detect(
        HouseholdProvider $householdProvider,
        RecurrenceDetector $recurrenceDetector,
        ClockInterface $clock,
    ): JsonResponse {
        $report = $recurrenceDetector->sync($householdProvider->get(), $clock->now());

        return new JsonResponse($report->toArray());
    }

    private function find(EntityManagerInterface $entityManager, Household $household, int $id): RecurringSeries
    {
        $series = $entityManager->getRepository(RecurringSeries::class)->createQueryBuilder('rs')
            ->join('rs.account', 'a')
            ->where('rs.id = :id')
            ->andWhere('a.household = :household')
            ->setParameter('id', $id)
            ->setParameter('household', $household)
            ->getQuery()->getOneOrNullResult();
        if (!$series instanceof RecurringSeries) {
            throw new NotFoundException('Série récurrente introuvable.');
        }

        return $series;
    }

    private function occurrencesCount(EntityManagerInterface $entityManager, RecurringSeries $series): int
    {
        return (int) $entityManager->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM operation WHERE recurring_series_id = :id',
            ['id' => $series->getId()],
            ['id' => ParameterType::INTEGER],
        );
    }
}
