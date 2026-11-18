<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\HouseholdProvider;
use App\Api\Input\TaggedPeriodCreateInput;
use App\Api\Input\TaggedPeriodPatchInput;
use App\Api\NotFoundException;
use App\Api\Presenter\TaggedPeriodPresenter;
use App\Api\ValidationException;
use App\Entity\Household;
use App\Entity\TaggedPeriod;
use App\Entity\Vehicle;
use App\Enum\TaggedPeriodType;
use App\Repository\TaggedPeriodRepository;
use App\Stats\StatsPeriod;
use App\Vehicle\VehicleAssigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Périodes labellisées du foyer (ADR 0018) : bandes affichées sur le graphe quotidien
 * des dépenses par poste. Périmètre = foyer ; une période d'un autre foyer ⇒ 404.
 * Une période peut porter un véhicule par défaut pour ses dépenses de transport : toute
 * écriture touchant une période avec véhicule (avant ou après) resynchronise l'axe véhicule.
 */
final class TaggedPeriodController extends AbstractController
{
    #[Route('/api/tagged-periods', name: 'api_tagged_periods', methods: ['GET'])]
    public function list(
        Request $request,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        TaggedPeriodPresenter $presenter,
    ): JsonResponse {
        // Bornes optionnelles : seules les périodes chevauchant [from, to] sont renvoyées.
        $from = StatsPeriod::dateParam($request->query->get('from'), 'from');
        $to = StatsPeriod::dateParam($request->query->get('to'), 'to');

        /** @var TaggedPeriodRepository $repository */
        $repository = $entityManager->getRepository(TaggedPeriod::class);
        $periods = $repository->overlapping($householdProvider->get(), $from, $to);

        return new JsonResponse(array_map(static fn (TaggedPeriod $period): array => $presenter->present($period), $periods));
    }

    #[Route('/api/tagged-periods', name: 'api_tagged_period_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] TaggedPeriodCreateInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        TaggedPeriodPresenter $presenter,
        VehicleAssigner $vehicleAssigner,
    ): JsonResponse {
        $household = $householdProvider->get();
        $period = new TaggedPeriod(
            $household,
            TaggedPeriodType::from((string) $input->type),
            (string) $input->label,
            new \DateTimeImmutable((string) $input->startDate),
            new \DateTimeImmutable((string) $input->endDate),
        );
        if (null !== $input->vehicleId) {
            $period->setVehicle($this->findVehicle($entityManager, $household, $input->vehicleId));
        }
        $period->setVehicleOverridesRules($input->vehicleOverridesRules ?? false);
        $this->assertValid($validator, $period);
        $entityManager->persist($period);
        $entityManager->flush();
        if (null !== $period->getVehicle()) {
            $vehicleAssigner->sync($household);
        }

        return new JsonResponse($presenter->present($period), Response::HTTP_CREATED);
    }

    #[Route('/api/tagged-periods/{id}', name: 'api_tagged_period_patch', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function patch(
        int $id,
        #[MapRequestPayload] TaggedPeriodPatchInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        TaggedPeriodPresenter $presenter,
        VehicleAssigner $vehicleAssigner,
    ): JsonResponse {
        $household = $householdProvider->get();
        $period = $this->find($entityManager, $household, $id);
        $hadVehicle = null !== $period->getVehicle();

        if (null !== $input->type) {
            $period->setType(TaggedPeriodType::from($input->type));
        }
        if (null !== $input->label) {
            $period->setLabel($input->label);
        }
        if (null !== $input->startDate) {
            $period->setStartDate(new \DateTimeImmutable($input->startDate));
        }
        if (null !== $input->endDate) {
            $period->setEndDate(new \DateTimeImmutable($input->endDate));
        }
        if ($input->clearVehicle) {
            $period->setVehicle(null);
        } elseif (null !== $input->vehicleId) {
            $period->setVehicle($this->findVehicle($entityManager, $household, $input->vehicleId));
        }
        if (null !== $input->vehicleOverridesRules) {
            $period->setVehicleOverridesRules($input->vehicleOverridesRules);
        }
        $this->assertValid($validator, $period);
        $entityManager->flush();
        if ($hadVehicle || null !== $period->getVehicle()) {
            $vehicleAssigner->sync($household);
        }

        return new JsonResponse($presenter->present($period));
    }

    #[Route('/api/tagged-periods/{id}', name: 'api_tagged_period_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(
        int $id,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        VehicleAssigner $vehicleAssigner,
    ): Response {
        $household = $householdProvider->get();
        $period = $this->find($entityManager, $household, $id);
        $hadVehicle = null !== $period->getVehicle();
        $entityManager->remove($period);
        $entityManager->flush();
        if ($hadVehicle) {
            $vehicleAssigner->sync($household);
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function find(EntityManagerInterface $entityManager, Household $household, int $id): TaggedPeriod
    {
        $period = $entityManager->getRepository(TaggedPeriod::class)->findOneBy(['id' => $id, 'household' => $household]);
        if (!$period instanceof TaggedPeriod) {
            throw new NotFoundException('Période introuvable.');
        }

        return $period;
    }

    private function findVehicle(EntityManagerInterface $entityManager, Household $household, int $vehicleId): Vehicle
    {
        $vehicle = $entityManager->getRepository(Vehicle::class)->findOneBy(['id' => $vehicleId, 'household' => $household]);
        if (!$vehicle instanceof Vehicle) {
            throw new ValidationException([['field' => 'vehicleId', 'message' => 'Véhicule introuvable dans le foyer.']]);
        }

        return $vehicle;
    }

    /** Validation de l'entité (callback validateDates + contraintes label) ⇒ 422 avec violations mappées sur les champs d'entrée. */
    private function assertValid(ValidatorInterface $validator, TaggedPeriod $period): void
    {
        $violations = $validator->validate($period);
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
