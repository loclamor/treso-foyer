<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\AdblueFill;
use App\Entity\MergedFingerprint;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Enum\DuplicateStatus;
use App\Enum\ImportFormat;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fusion d'un doublon probable (ADR 0005, étape 7) : conserve l'opération de référence (libellé
 * PDF prioritaire, sinon `duplicateOf`), lui reporte catégorie manuelle, note, plein (ADR 0015)
 * et AdBlue (ADR 0016) si elle n'en a pas, supprime l'autre et garde son empreinte
 * (MergedFingerprint) pour qu'un import ultérieur ne la recrée pas.
 */
final class DuplicateMerger
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return Operation|null l'opération conservée, null si $operation n'est pas un doublon
     */
    public function merge(Operation $operation): ?Operation
    {
        $reference = $operation->getDuplicateOf();
        $kept = ImportFormat::Pdf === $operation->getLabelSource() || null === $reference ? $operation : $reference;
        $removed = $kept === $operation ? $reference : $operation;
        if (null === $removed) {
            return null;
        }

        if (null === $kept->getCategory()
            && null !== $removed->getCategory()
            && CategorizationSource::Manual === $removed->getCategorizationSource()) {
            $kept->categorize($removed->getCategory(), CategorizationSource::Manual);
        }
        if (null === $kept->getNote()) {
            $kept->setNote($removed->getNote());
        }
        if (!$kept->hasFuel() && $removed->hasFuel() && $kept->isDebit()) {
            $kept->recordFuel((int) $removed->getFuelVolumeMl(), $removed->getFuelFullTank() ?? true, $removed->getOdometerKm());
        }
        if (!$kept->hasGas() && $removed->hasGas() && $kept->isDebit()) {
            $kept->recordGas((int) $removed->getGasMassG());
        }
        $kept->setDuplicateStatus(DuplicateStatus::None);
        $kept->setDuplicateOf(null);

        $this->entityManager->wrapInTransaction(function () use ($kept, $removed): void {
            $this->entityManager->getRepository(AdblueFill::class)->transferOperation($removed, $kept);
            // Les pierres tombales de l'opération supprimée (fusions précédentes) passent sur la
            // conservée, sinon la cascade de suppression les effacerait.
            $this->entityManager->createQuery(
                'UPDATE '.MergedFingerprint::class.' m SET m.keptOperation = :kept WHERE m.keptOperation = :removed',
            )->setParameters(['kept' => $kept, 'removed' => $removed])->execute();
            if (!$removed->isAwaitingStatement()) {
                $this->entityManager->persist(new MergedFingerprint($removed, $kept));
            }
            $this->entityManager->remove($removed);
        });

        return $kept;
    }
}
