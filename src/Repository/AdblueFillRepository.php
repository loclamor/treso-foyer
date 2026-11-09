<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdblueFill;
use App\Entity\Operation;
use App\Entity\Vehicle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AdblueFill> */
final class AdblueFillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdblueFill::class);
    }

    /** @return list<AdblueFill> remplissages du véhicule, date desc puis id desc */
    public function findByVehicleDesc(Vehicle $vehicle): array
    {
        return $this->findBy(['vehicle' => $vehicle], ['date' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Fusion de deux opérations (rapprochement d'une saisie, doublon) : le remplissage lié à
     * l'opération supprimée passe sur l'opération conservée si celle-ci n'en a pas déjà un.
     * À appeler avant la suppression (sinon la clé étrangère le délie : ON DELETE SET NULL).
     */
    public function transferOperation(Operation $removed, Operation $kept): void
    {
        $fill = $this->findOneBy(['operation' => $removed]);
        if (!$fill instanceof AdblueFill) {
            return;
        }
        $fill->setOperation(null === $this->findOneBy(['operation' => $kept]) ? $kept : null);
    }
}
