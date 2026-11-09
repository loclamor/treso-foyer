<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pierre tombale d'une opération supprimée par la fusion d'un doublon (ADR 0005) : son empreinte
 * reste connue, si bien qu'un export ultérieur qui contient encore la même ligne la compte comme
 * déjà importée au lieu de la recréer et de la reproposer en doublon probable.
 *
 * Les entrées de l'empreinte (libellé brut, date, montant, rang) sont conservées pour que
 * app:operations:rebuild-fingerprints puisse la recalculer ; le compte est celui de l'opération
 * conservée (un doublon flou est toujours sur le même compte).
 */
#[ORM\Entity]
#[ORM\Table(name: 'merged_fingerprint')]
#[ORM\UniqueConstraint(name: 'uq_merged_fingerprint', columns: ['fingerprint'])]
class MergedFingerprint
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\ManyToOne(targetEntity: Operation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Operation $keptOperation;

    #[ORM\Column(type: Types::TEXT)]
    private string $rawLabel;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** Montant en centimes, négatif = débit. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    #[ORM\Column]
    private int $occurrenceIndex;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $mergedAt;

    public function __construct(Operation $removed, Operation $kept)
    {
        $this->fingerprint = $removed->getFingerprint();
        $this->keptOperation = $kept;
        $this->rawLabel = $removed->getRawLabel();
        $this->date = $removed->getDate();
        $this->amount = $removed->getAmount();
        $this->occurrenceIndex = $removed->getOccurrenceIndex();
        $this->mergedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getKeptOperation(): Operation
    {
        return $this->keptOperation;
    }

    public function getRawLabel(): string
    {
        return $this->rawLabel;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getOccurrenceIndex(): int
    {
        return $this->occurrenceIndex;
    }
}
