<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Household;
use App\Repository\HouseholdRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Foyer unique de l'application (racine de tout le modèle de données).
 */
final class HouseholdProvider
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function get(): Household
    {
        /** @var HouseholdRepository $repository */
        $repository = $this->entityManager->getRepository(Household::class);
        $household = $repository->findOneBy([]);
        if (!$household instanceof Household) {
            throw new NotFoundException('Aucun foyer configuré.');
        }

        return $household;
    }
}
