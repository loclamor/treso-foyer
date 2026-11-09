<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\BadRequestException;
use App\Api\ConflictException;
use App\Api\HouseholdProvider;
use App\Api\Input\AdblueFillCreateInput;
use App\Api\Input\AdblueFillPatchInput;
use App\Api\NotFoundException;
use App\Api\Presenter\AdblueFillPresenter;
use App\Api\Presenter\CategoryPresenter;
use App\Api\ValidationException;
use App\Entity\AdblueFill;
use App\Entity\Household;
use App\Entity\Operation;
use App\Entity\Vehicle;
use App\Enum\AdblueSource;
use App\Enum\VehicleSource;
use App\Repository\AdblueFillRepository;
use App\Repository\OdometerReadingRepository;
use App\Stats\StatsPeriod;
use App\Vehicle\AdblueConsumptionCalculator;
use App\Vehicle\OdometerCoherence;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Remplissages d'AdBlue d'un véhicule (ADR 0016, contrat docs/api.md) : journal, synthèse
 * (volume, coût réel / estimé, consommation L/1000 km), opérations candidates au lien.
 */
final class AdblueController extends AbstractController
{
    /** Fenêtre de recherche des opérations candidates autour de la date du remplissage (jours). */
    private const CANDIDATE_WINDOW_DAYS = 10;

    /**
     * GET /api/vehicles/{id}/adblue?from=AAAA-MM-JJ&to=AAAA-MM-JJ : remplissages de la période
     * (date desc) + synthèse. Bornes optionnelles : absentes = tout l'historique.
     */
    #[Route('/api/vehicles/{id}/adblue', name: 'api_vehicle_adblue', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function list(
        int $id,
        Request $request,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        AdblueFillRepository $adblueFillRepository,
        OdometerReadingRepository $odometerReadingRepository,
        AdblueConsumptionCalculator $consumptionCalculator,
        AdblueFillPresenter $presenter,
    ): JsonResponse {
        $vehicle = $this->findVehicle($entityManager, $householdProvider->get(), $id);
        $from = StatsPeriod::dateParam($request->query->get('from'), 'from');
        $to = StatsPeriod::dateParam($request->query->get('to'), 'to');
        if (null !== $from && null !== $to && $from > $to) {
            throw new BadRequestException('Paramètre from postérieur à to.', 'invalid_query');
        }

        $all = $adblueFillRepository->findByVehicleDesc($vehicle);
        $inPeriod = array_values(array_filter(
            $all,
            static fn (AdblueFill $fill): bool => (null === $from || $fill->getDate() >= $from) && (null === $to || $fill->getDate() <= $to),
        ));

        $volumeMl = 0;
        $cost = 0;
        $estimatedCost = 0;
        $costedVolumeMl = 0;
        $costedTotal = 0;
        foreach ($inPeriod as $fill) {
            $volumeMl += $fill->getVolumeMl();
            $fillCost = $fill->getCost();
            if (null === $fillCost) {
                continue;
            }
            if ($fill->isCostEstimated()) {
                $estimatedCost += $fillCost;
            } else {
                $cost += $fillCost;
            }
            $costedVolumeMl += $fill->getVolumeMl();
            $costedTotal += $fillCost;
        }

        $consumption = $consumptionCalculator->compute(
            array_map(static fn (AdblueFill $fill): array => ['date' => $fill->getDate(), 'volumeMl' => $fill->getVolumeMl()], $all),
            $odometerReadingRepository->findPointsByVehicle($vehicle),
            $from ?? new \DateTimeImmutable('1900-01-01'),
            $to ?? new \DateTimeImmutable('2999-12-31'),
        );

        return new JsonResponse([
            'fills' => array_map(static fn (AdblueFill $fill): array => $presenter->present($fill), $inPeriod),
            'summary' => [
                'count' => count($inPeriod),
                'volumeMl' => $volumeMl,
                'cost' => $cost,
                'estimatedCost' => $estimatedCost,
                'avgPricePerLiter' => $costedVolumeMl > 0 ? round($costedTotal * 10 / $costedVolumeMl, 3) : null,
                'consumption' => $consumption['consumption'],
                'distanceKm' => $consumption['distanceKm'],
            ],
        ]);
    }

    /** POST /api/vehicles/{id}/adblue ⇒ 201 AdblueFill (+ "warning" si le km est incohérent). */
    #[Route('/api/vehicles/{id}/adblue', name: 'api_vehicle_adblue_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function create(
        int $id,
        #[MapRequestPayload] AdblueFillCreateInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        AdblueFillRepository $adblueFillRepository,
        OdometerReadingRepository $odometerReadingRepository,
        OdometerCoherence $odometerCoherence,
        AdblueFillPresenter $presenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $vehicle = $this->findVehicle($entityManager, $household, $id);
        $source = AdblueSource::from((string) $input->source);
        $this->assertSourceFields($source, $input->operationId, $input->canPrice, $input->canVolumeMl);

        $fill = new AdblueFill($vehicle, new \DateTimeImmutable((string) $input->date), (int) $input->volumeMl, $source);
        $fill->setOdometerKm($input->odometerKm);
        $fill->setNote(self::nullIfEmpty($input->note));
        $this->applyCan($fill, $input->canPrice, $input->canVolumeMl);
        if (null !== $input->operationId) {
            $this->linkOperation($entityManager, $adblueFillRepository, $household, $fill, $input->operationId);
        }
        $entityManager->persist($fill);
        $entityManager->flush();

        return new JsonResponse(
            $this->withWarning($presenter->present($fill), $fill, $odometerReadingRepository, $odometerCoherence),
            Response::HTTP_CREATED,
        );
    }

    /** PATCH /api/adblue-fills/{id} ⇒ 200 AdblueFill (+ "warning"). */
    #[Route('/api/adblue-fills/{id}', name: 'api_adblue_fill_patch', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function patch(
        int $id,
        Request $request,
        #[MapRequestPayload] AdblueFillPatchInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        AdblueFillRepository $adblueFillRepository,
        OdometerReadingRepository $odometerReadingRepository,
        OdometerCoherence $odometerCoherence,
        AdblueFillPresenter $presenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $fill = $this->findFill($adblueFillRepository, $household, $id);
        $body = $request->toArray();

        $source = null !== $input->source ? AdblueSource::from($input->source) : $fill->getSource();
        $operationId = array_key_exists('operationId', $body) ? $input->operationId : $fill->getOperation()?->getId();
        $canPrice = array_key_exists('canPrice', $body) ? $input->canPrice : $fill->getCanPrice();
        $canVolumeMl = array_key_exists('canVolumeMl', $body) ? $input->canVolumeMl : $fill->getCanVolumeMl();
        // Changer d'origine sans préciser les champs de l'autre origine les efface.
        if ($source !== $fill->getSource()) {
            if (AdblueSource::Can === $source && !array_key_exists('operationId', $body)) {
                $operationId = null;
            }
            if (AdblueSource::Station === $source && !array_key_exists('canPrice', $body) && !array_key_exists('canVolumeMl', $body)) {
                $canPrice = null;
                $canVolumeMl = null;
            }
        }
        $this->assertSourceFields($source, $operationId, $canPrice, $canVolumeMl);

        $fill->setSource($source);
        if (null !== $input->date) {
            $fill->setDate(new \DateTimeImmutable($input->date));
        }
        if (null !== $input->volumeMl) {
            $fill->setVolumeMl($input->volumeMl);
        }
        if (array_key_exists('odometerKm', $body)) {
            $fill->setOdometerKm($input->odometerKm);
        }
        if (array_key_exists('note', $body)) {
            $fill->setNote(self::nullIfEmpty($input->note));
        }
        $this->applyCan($fill, $canPrice, $canVolumeMl);
        if (null === $operationId) {
            $fill->setOperation(null);
        } elseif ($operationId !== $fill->getOperation()?->getId()) {
            $this->linkOperation($entityManager, $adblueFillRepository, $household, $fill, $operationId);
        }
        $entityManager->flush();

        return new JsonResponse($this->withWarning($presenter->present($fill), $fill, $odometerReadingRepository, $odometerCoherence));
    }

    #[Route('/api/adblue-fills/{id}', name: 'api_adblue_fill_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(
        int $id,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        AdblueFillRepository $adblueFillRepository,
    ): Response {
        $fill = $this->findFill($adblueFillRepository, $householdProvider->get(), $id);
        // L'opération liée et son affectation véhicule sont conservées.
        $entityManager->remove($fill);
        $entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * GET /api/vehicles/{id}/adblue/candidates?date=AAAA-MM-JJ[&fillId=N] : débits du foyer
     * datés à ± 10 jours (date effective), sans plein de carburant, non liés à un autre
     * remplissage ; triés par proximité de date. fillId : garde l'opération déjà liée à ce
     * remplissage (modification).
     */
    #[Route('/api/vehicles/{id}/adblue/candidates', name: 'api_vehicle_adblue_candidates', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function candidates(
        int $id,
        Request $request,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        CategoryPresenter $categoryPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $this->findVehicle($entityManager, $household, $id);
        $date = StatsPeriod::dateParam($request->query->get('date'), 'date');
        if (null === $date) {
            throw new BadRequestException('Paramètre date obligatoire.', 'invalid_query');
        }
        $fillId = $request->query->has('fillId') ? (int) $request->query->get('fillId') : null;

        $effective = Operation::effectiveDateDql('o');
        /** @var list<Operation> $operations */
        $operations = $entityManager->getRepository(Operation::class)->createQueryBuilder('o')
            ->join('o.account', 'a')
            ->leftJoin(AdblueFill::class, 'f', 'WITH', 'f.operation = o')
            ->where('a.household = :household')
            ->andWhere('o.amount < 0')
            ->andWhere('o.fuelVolumeMl IS NULL')
            ->andWhere("$effective >= :from AND $effective <= :to")
            ->andWhere('f.id IS NULL OR f.id = :fillId')
            ->setParameter('household', $household)
            ->setParameter('from', $date->modify(sprintf('-%d days', self::CANDIDATE_WINDOW_DAYS)))
            ->setParameter('to', $date->modify(sprintf('+%d days', self::CANDIDATE_WINDOW_DAYS)))
            ->setParameter('fillId', $fillId ?? 0)
            ->getQuery()->getResult();

        $dayDiff = static fn (Operation $operation): int => abs((int) $operation->getEffectiveDate()->diff($date)->format('%r%a'));
        usort($operations, static fn (Operation $a, Operation $b): int => [$dayDiff($a), (int) $a->getId()] <=> [$dayDiff($b), (int) $b->getId()]);

        return new JsonResponse(array_map(
            static fn (Operation $operation): array => [
                'id' => (int) $operation->getId(),
                'date' => $operation->getEffectiveDate()->format('Y-m-d'),
                'amount' => $operation->getAmount(),
                'label' => $operation->getRawLabel(),
                'merchant' => $operation->getMerchant(),
                'category' => null !== $operation->getCategory() ? $categoryPresenter->presentRef($operation->getCategory()) : null,
                'awaitingStatement' => $operation->isAwaitingStatement(),
            ],
            array_slice($operations, 0, 30),
        ));
    }

    /** Station : pas de bidon ; bidon : pas d'opération liée ⇒ 422. */
    private function assertSourceFields(AdblueSource $source, ?int $operationId, ?int $canPrice, ?int $canVolumeMl): void
    {
        if (AdblueSource::Station === $source && (null !== $canPrice || null !== $canVolumeMl)) {
            throw new ValidationException([['field' => 'canPrice', 'message' => 'Le prix du bidon ne concerne que les remplissages au bidon.']]);
        }
        if (AdblueSource::Can === $source && null !== $operationId) {
            throw new ValidationException([['field' => 'operationId', 'message' => 'Seul un remplissage en station peut être lié à une opération.']]);
        }
        if ((null === $canPrice) !== (null === $canVolumeMl)) {
            throw new ValidationException([['field' => null === $canPrice ? 'canPrice' : 'canVolumeMl', 'message' => 'Prix et contenance du bidon vont ensemble.']]);
        }
    }

    private function applyCan(AdblueFill $fill, ?int $canPrice, ?int $canVolumeMl): void
    {
        try {
            $fill->setCan($canPrice, $canVolumeMl);
        } catch (\InvalidArgumentException $exception) {
            throw new ValidationException([['field' => 'canPrice', 'message' => $exception->getMessage()]]);
        }
    }

    /**
     * Lie l'opération (débit du foyer, libre) au remplissage et l'affecte au véhicule du
     * remplissage, sauf affectation manuelle existante.
     */
    private function linkOperation(EntityManagerInterface $entityManager, AdblueFillRepository $adblueFillRepository, Household $household, AdblueFill $fill, int $operationId): void
    {
        $operation = $entityManager->find(Operation::class, $operationId);
        if (!$operation instanceof Operation || (int) $operation->getAccount()->getHousehold()->getId() !== (int) $household->getId()) {
            throw new ValidationException([['field' => 'operationId', 'message' => 'Opération introuvable dans le foyer.']]);
        }
        if (!$operation->isDebit()) {
            throw new ValidationException([['field' => 'operationId', 'message' => 'Un remplissage ne peut être lié qu’à un débit.']]);
        }
        $other = $adblueFillRepository->findOneBy(['operation' => $operation]);
        if ($other instanceof AdblueFill && $other !== $fill) {
            throw new ConflictException('Cette opération est déjà liée à un autre remplissage d’AdBlue.', 'operation_already_linked');
        }
        $fill->setOperation($operation);
        if (VehicleSource::Manual !== $operation->getVehicleSource()) {
            $operation->assignVehicle($fill->getVehicle(), VehicleSource::Manual);
        }
    }

    /** @param array<string, mixed> $payload */
    private function withWarning(array $payload, AdblueFill $fill, OdometerReadingRepository $odometerReadingRepository, OdometerCoherence $odometerCoherence): array
    {
        if (null === $fill->getOdometerKm()) {
            return $payload;
        }
        $points = $odometerReadingRepository->findPointsByVehicle($fill->getVehicle(), null, $fill->getId());
        $warning = $odometerCoherence->warning($points, $fill->getDate(), $fill->getOdometerKm());
        if (null !== $warning) {
            $payload['warning'] = $warning;
        }

        return $payload;
    }

    private function findVehicle(EntityManagerInterface $entityManager, Household $household, int $id): Vehicle
    {
        $vehicle = $entityManager->getRepository(Vehicle::class)->findOneBy(['id' => $id, 'household' => $household]);
        if (!$vehicle instanceof Vehicle) {
            throw new NotFoundException('Véhicule introuvable.');
        }

        return $vehicle;
    }

    private function findFill(AdblueFillRepository $adblueFillRepository, Household $household, int $id): AdblueFill
    {
        $fill = $adblueFillRepository->find($id);
        if (!$fill instanceof AdblueFill || (int) $fill->getVehicle()->getHousehold()->getId() !== (int) $household->getId()) {
            throw new NotFoundException('Remplissage introuvable.');
        }

        return $fill;
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return null === $value || '' === $value ? null : $value;
    }
}
