<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ConflictException;
use App\Api\HouseholdProvider;
use App\Api\Input\VehicleCreateInput;
use App\Api\Input\VehicleLookupInput;
use App\Api\Input\VehiclePatchInput;
use App\Api\NotFoundException;
use App\Api\Presenter\VehiclePresenter;
use App\Api\Query\StatsQuery;
use App\Api\ServiceUnavailableException;
use App\Api\TooManyRequestsException;
use App\Api\ValidationException;
use App\Entity\Household;
use App\Entity\Member;
use App\Entity\Vehicle;
use App\Enum\VehicleEnergy;
use App\Enum\VehicleType;
use App\Repository\OdometerReadingRepository;
use App\Util\PlateNormalizer;
use App\Vehicle\Lookup\VehicleLookup;
use App\Vehicle\Lookup\VehicleLookupException;
use App\Vehicle\VehicleAssigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class VehicleController extends AbstractController
{
    #[Route('/api/vehicles', name: 'api_vehicles', methods: ['GET'])]
    public function list(
        Request $request,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        StatsQuery $statsQuery,
        OdometerReadingRepository $odometerReadingRepository,
        VehiclePresenter $vehiclePresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        // ?archived=1 pour inclure les archivés ; défaut : non archivés uniquement.
        $includeArchived = in_array((string) $request->query->get('archived', '0'), ['1', 'true'], true);

        $qb = $entityManager->getRepository(Vehicle::class)->createQueryBuilder('v')
            ->where('v.household = :household')
            ->orderBy('v.id', 'ASC')
            ->setParameter('household', $household);
        if (!$includeArchived) {
            $qb->andWhere('v.archived = false');
        }
        /** @var list<Vehicle> $vehicles */
        $vehicles = $qb->getQuery()->getResult();

        $counts = $statsQuery->vehicleOperationCounts($household);
        $lastReadings = $odometerReadingRepository->latestByVehicleIds(
            array_map(static fn (Vehicle $vehicle): int => (int) $vehicle->getId(), $vehicles),
        );

        return new JsonResponse(array_map(
            static fn (Vehicle $vehicle): array => $vehiclePresenter->present(
                $vehicle,
                $counts[(int) $vehicle->getId()] ?? 0,
                $lastReadings[(int) $vehicle->getId()] ?? null,
            ),
            $vehicles,
        ));
    }

    #[Route('/api/vehicles', name: 'api_vehicle_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] VehicleCreateInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        VehicleAssigner $vehicleAssigner,
        StatsQuery $statsQuery,
        OdometerReadingRepository $odometerReadingRepository,
        VehiclePresenter $vehiclePresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $this->assertNameAvailable($entityManager, $household, (string) $input->name);
        $plate = $this->normalizePlate($input->plate);
        $this->assertPlateAvailable($entityManager, $household, $plate);

        $vehicle = new Vehicle($household, (string) $input->name, VehicleType::from((string) ($input->type ?? 'car')));
        $vehicle->setPlate($plate);
        $vehicle->setMake(self::nullIfEmpty($input->make));
        $vehicle->setModel(self::nullIfEmpty($input->model));
        if (null !== $input->energy) {
            $vehicle->setEnergy(VehicleEnergy::from($input->energy));
        }
        $vehicle->setFiscalPower($input->fiscalPower);
        $vehicle->setFirstRegistrationAt(self::date($input->firstRegistrationAt));
        $vehicle->setVin(self::nullIfEmpty($input->vin));
        $vehicle->setAcquiredAt(self::date($input->acquiredAt));
        $vehicle->setDisposedAt(self::date($input->disposedAt));
        $vehicle->setPurchasePrice($input->purchasePrice);
        $vehicle->setMainDriver(null === $input->mainDriverId ? null : $this->findMember($entityManager, $household, $input->mainDriverId));
        $vehicle->setArchived($input->archived ?? false);
        $this->assertValid($validator, $vehicle);

        $entityManager->persist($vehicle);
        $entityManager->flush();
        // Les dates d'activité changent l'affectation auto des opérations.
        $vehicleAssigner->sync($household);

        return new JsonResponse(
            $vehiclePresenter->present($vehicle, $statsQuery->vehicleOperationCounts($household)[(int) $vehicle->getId()] ?? 0, $odometerReadingRepository->latestByVehicleIds([(int) $vehicle->getId()])[(int) $vehicle->getId()] ?? null),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/api/vehicles/lookup', name: 'api_vehicle_lookup', methods: ['POST'])]
    public function lookup(
        #[MapRequestPayload] VehicleLookupInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        VehicleLookup $vehicleLookup,
    ): JsonResponse {
        $plate = $this->normalizePlate((string) $input->plate);
        \assert(null !== $plate);
        if (!$vehicleLookup->isEnabled()) {
            throw new ServiceUnavailableException('Le service de recherche par plaque n’est pas activé.', 'lookup_disabled');
        }
        try {
            $result = $vehicleLookup->lookup($plate);
        } catch (VehicleLookupException $exception) {
            throw match ($exception->reason) {
                VehicleLookupException::REASON_NOT_FOUND => new NotFoundException('Aucun véhicule trouvé pour cette plaque.'),
                VehicleLookupException::REASON_QUOTA => new TooManyRequestsException('Le quota de l’API de recherche par plaque est atteint.', 'quota'),
                VehicleLookupException::REASON_INVALID_RESPONSE => new ServiceUnavailableException('Réponse illisible du service de recherche par plaque.', 'invalid_response', Response::HTTP_BAD_GATEWAY),
                default => new ServiceUnavailableException('Le service de recherche par plaque est indisponible.', 'unavailable', Response::HTTP_BAD_GATEWAY),
            };
        }

        // vehicleId optionnel : on enregistre la réponse brute + lookupAt sur ce véhicule,
        // sans modifier ses autres champs (c'est le front qui applique la suggestion).
        if (null !== $input->vehicleId) {
            $vehicle = $entityManager->getRepository(Vehicle::class)->findOneBy(['id' => $input->vehicleId, 'household' => $householdProvider->get()]);
            if (!$vehicle instanceof Vehicle) {
                throw new ValidationException([['field' => 'vehicleId', 'message' => 'Véhicule introuvable dans le foyer.']]);
            }
            $vehicle->setLookupData($result->raw);
            $vehicle->setLookupAt(new \DateTimeImmutable());
            $entityManager->flush();
        }

        $suggestion = $result->toArray();
        unset($suggestion['raw']); // la réponse brute n'est exposée que via lookupData (jamais en API)

        return new JsonResponse(['plate' => $plate, 'suggestion' => $suggestion]);
    }

    #[Route('/api/vehicles/lookup/status', name: 'api_vehicle_lookup_status', methods: ['GET'])]
    public function lookupStatus(VehicleLookup $vehicleLookup): JsonResponse
    {
        return new JsonResponse(['enabled' => $vehicleLookup->isEnabled()]);
    }

    #[Route('/api/vehicles/{id}', name: 'api_vehicle_patch', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function patch(
        int $id,
        Request $request,
        #[MapRequestPayload] VehiclePatchInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        VehicleAssigner $vehicleAssigner,
        StatsQuery $statsQuery,
        OdometerReadingRepository $odometerReadingRepository,
        VehiclePresenter $vehiclePresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $vehicle = $this->find($entityManager, $household, $id);

        // Absent (null) = inchangé ; chaîne vide = remise à null (dates et textes).
        if (null !== $input->name) {
            $this->assertNameAvailable($entityManager, $household, $input->name, (int) $vehicle->getId());
            $vehicle->setName($input->name);
        }
        if (null !== $input->type) {
            $vehicle->setType(VehicleType::from($input->type));
        }
        if (null !== $input->plate) {
            $plate = $this->normalizePlate($input->plate);
            $this->assertPlateAvailable($entityManager, $household, $plate, (int) $vehicle->getId());
            $vehicle->setPlate($plate);
        }
        if (null !== $input->make) {
            $vehicle->setMake(self::nullIfEmpty($input->make));
        }
        if (null !== $input->model) {
            $vehicle->setModel(self::nullIfEmpty($input->model));
        }
        if (null !== $input->energy) {
            $vehicle->setEnergy(VehicleEnergy::from($input->energy));
        }
        if (null !== $input->fiscalPower) {
            $vehicle->setFiscalPower($input->fiscalPower);
        }
        if (null !== $input->firstRegistrationAt) {
            $vehicle->setFirstRegistrationAt(self::date($input->firstRegistrationAt));
        }
        if (null !== $input->vin) {
            $vehicle->setVin(self::nullIfEmpty($input->vin));
        }
        if (null !== $input->acquiredAt) {
            $vehicle->setAcquiredAt(self::date($input->acquiredAt));
        }
        if (null !== $input->disposedAt) {
            $vehicle->setDisposedAt(self::date($input->disposedAt));
        }
        if (null !== $input->purchasePrice) {
            $vehicle->setPurchasePrice($input->purchasePrice);
        }
        // mainDriverId : la clé présente dans le corps (même null) est appliquée, absente = inchangé.
        if (array_key_exists('mainDriverId', $request->toArray())) {
            $vehicle->setMainDriver(null === $input->mainDriverId ? null : $this->findMember($entityManager, $household, $input->mainDriverId));
        }
        if (null !== $input->archived) {
            $vehicle->setArchived($input->archived);
        }
        $this->assertValid($validator, $vehicle);
        $entityManager->flush();
        // Les dates d'activité changent l'affectation auto des opérations.
        $vehicleAssigner->sync($household);

        return new JsonResponse($vehiclePresenter->present(
            $vehicle,
            $statsQuery->vehicleOperationCounts($household)[(int) $vehicle->getId()] ?? 0,
            $odometerReadingRepository->latestByVehicleIds([(int) $vehicle->getId()])[(int) $vehicle->getId()] ?? null,
        ));
    }

    #[Route('/api/vehicles/{id}', name: 'api_vehicle_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(
        int $id,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        VehicleAssigner $vehicleAssigner,
    ): Response {
        $household = $householdProvider->get();
        $vehicle = $this->find($entityManager, $household, $id);

        $usage = (int) $entityManager->getConnection()->fetchOne(
            'SELECT
                (SELECT COUNT(*) FROM operation WHERE vehicle_id = :id)::int
              + (SELECT COUNT(*) FROM categorization_rule WHERE vehicle_id = :id)::int',
            ['id' => $vehicle->getId()],
        );
        if ($usage > 0) {
            throw new ConflictException('Véhicule utilisé (opérations ou règles rattachées) : archivez-le plutôt que de le supprimer.', 'vehicle_in_use');
        }

        $entityManager->remove($vehicle); // les relevés de compteur partent en cascade (onDelete CASCADE)
        $entityManager->flush();
        $vehicleAssigner->sync($household);

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function find(EntityManagerInterface $entityManager, Household $household, int $id): Vehicle
    {
        $vehicle = $entityManager->getRepository(Vehicle::class)->findOneBy(['id' => $id, 'household' => $household]);
        if (!$vehicle instanceof Vehicle) {
            throw new NotFoundException('Véhicule introuvable.');
        }

        return $vehicle;
    }

    private function findMember(EntityManagerInterface $entityManager, Household $household, int $memberId): Member
    {
        $member = $entityManager->getRepository(Member::class)->findOneBy(['id' => $memberId, 'household' => $household]);
        if (!$member instanceof Member) {
            throw new ValidationException([['field' => 'mainDriverId', 'message' => 'Membre introuvable dans le foyer.']]);
        }

        return $member;
    }

    /** Unicité du nom dans le foyer ⇒ 409. */
    private function assertNameAvailable(EntityManagerInterface $entityManager, Household $household, ?string $name, ?int $excludeId = null): void
    {
        $conflict = $entityManager->getRepository(Vehicle::class)->findOneBy(['household' => $household, 'name' => $name]);
        if ($conflict instanceof Vehicle && (int) $conflict->getId() !== (int) $excludeId) {
            throw new ConflictException('Un véhicule de ce nom existe déjà dans le foyer.', 'name_exists');
        }
    }

    /** Unicité de la plaque normalisée dans le foyer ⇒ 409. */
    private function assertPlateAvailable(EntityManagerInterface $entityManager, Household $household, ?string $normalizedPlate, ?int $excludeId = null): void
    {
        if (null === $normalizedPlate) {
            return;
        }
        $conflict = $entityManager->getRepository(Vehicle::class)->findOneBy(['household' => $household, 'plate' => $normalizedPlate]);
        if ($conflict instanceof Vehicle && (int) $conflict->getId() !== (int) $excludeId) {
            throw new ConflictException('Cette plaque est déjà enregistrée sur un autre véhicule du foyer.', 'plate_exists');
        }
    }

    /**
     * Normalise la plaque saisie ; chaîne vide = aucune plaque ; format inconnu ⇒ 422 sur `plate`
     * (le message ne contient jamais la plaque, donnée privée).
     */
    private function normalizePlate(?string $raw): ?string
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        try {
            return PlateNormalizer::normalize($raw);
        } catch (\InvalidArgumentException $exception) {
            throw new ValidationException([['field' => 'plate', 'message' => $exception->getMessage()]]);
        }
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return null === $value || '' === $value ? null : $value;
    }

    /** Chaîne AAAA-MM-JJ ⇒ date ; chaîne vide = null. */
    private static function date(?string $raw): ?\DateTimeImmutable
    {
        return null === $raw || '' === $raw ? null : new \DateTimeImmutable($raw);
    }

    /** Validation de l'entité (callback validateCoherence) ⇒ 422 avec violations mappées sur les champs d'entrée. */
    private function assertValid(ValidatorInterface $validator, Vehicle $vehicle): void
    {
        $violations = $validator->validate($vehicle);
        if (0 === count($violations)) {
            return;
        }
        $mapped = [];
        foreach ($violations as $violation) {
            $mapped[] = ['field' => (string) $violation->getPropertyPath(), 'message' => $violation->getMessage()];
        }
        throw new ValidationException($mapped);
    }
}
