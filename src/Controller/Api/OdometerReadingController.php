<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ConflictException;
use App\Api\HouseholdProvider;
use App\Api\Input\OdometerReadingPatchInput;
use App\Api\NotFoundException;
use App\Entity\Household;
use App\Entity\OdometerReading;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Modification/suppression d'un relevé kilométrique (contrat docs/api.md, lot 4).
 * Le périmètre foyer est vérifié via le véhicule du relevé.
 */
final class OdometerReadingController extends AbstractController
{
    #[Route('/api/odometer-readings/{id}', name: 'api_odometer_reading_patch', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function patch(
        int $id,
        Request $request,
        #[MapRequestPayload] OdometerReadingPatchInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
    ): Response {
        $reading = $this->find($entityManager, $householdProvider->get(), $id);

        // Absent (null) = inchangé ; note : clé présente dans le corps (même null) = appliquée.
        if (null !== $input->date) {
            $date = new \DateTimeImmutable($input->date); // format validé par Assert\Date
            $conflict = $entityManager->getRepository(OdometerReading::class)->findOneBy(['vehicle' => $reading->getVehicle(), 'date' => $date]);
            if ($conflict instanceof OdometerReading && (int) $conflict->getId() !== (int) $reading->getId()) {
                throw new ConflictException('Un relevé existe déjà à cette date pour ce véhicule.', 'reading_date_exists');
            }
            $reading->setDate($date);
        }
        if (null !== $input->km) {
            $reading->setKm($input->km);
        }
        if (array_key_exists('note', $request->toArray())) {
            $note = $input->note;
            $reading->setNote(null === $note || '' === $note ? null : $note);
        }
        $entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/odometer-readings/{id}', name: 'api_odometer_reading_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(
        int $id,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
    ): Response {
        $reading = $this->find($entityManager, $householdProvider->get(), $id);

        $entityManager->remove($reading);
        $entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /** Relevé introuvable dans le foyer (via son véhicule) ⇒ 404. */
    private function find(EntityManagerInterface $entityManager, Household $household, int $id): OdometerReading
    {
        $reading = $entityManager->getRepository(OdometerReading::class)->find($id);
        if (!$reading instanceof OdometerReading || (int) $reading->getVehicle()->getHousehold()->getId() !== (int) $household->getId()) {
            throw new NotFoundException('Relevé introuvable.');
        }

        return $reading;
    }
}
