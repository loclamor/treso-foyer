<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AdblueSource;
use App\Repository\AdblueFillRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Remplissage d'AdBlue d'un véhicule (ADR 0016) : journal indépendant des opérations, car un
 * bidon acheté dans les courses n'a pas de ligne de relevé à lui et peut être versé en
 * plusieurs fois.
 *
 * - Station : `operation` = la ligne de relevé du paiement (facultative tant qu'elle n'est
 *   pas importée) ; coût réel = montant de l'opération.
 * - Bidon : `canPrice` / `canVolumeMl` = prix et contenance du bidon ; coût **estimé** au
 *   prorata du volume versé (déjà compté dans les courses côté banque : jamais ajouté aux
 *   dépenses du véhicule).
 */
#[ORM\Entity(repositoryClass: AdblueFillRepository::class)]
#[ORM\Table(name: 'adblue_fill')]
#[ORM\Index(name: 'idx_adblue_fill_vehicle_date', columns: ['vehicle_id', 'date'])]
#[ORM\UniqueConstraint(name: 'uq_adblue_fill_operation', columns: ['operation_id'])]
class AdblueFill
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Vehicle $vehicle;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /** Volume versé, en millilitres (> 0). */
    #[ORM\Column]
    private int $volumeMl;

    /** Kilométrage au compteur lors du remplissage (point de compteur, comme un plein). */
    #[ORM\Column(nullable: true)]
    private ?int $odometerKm = null;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AdblueSource::class)]
    private AdblueSource $source;

    /** Paiement en station (une opération = au plus un remplissage). */
    #[ORM\ManyToOne(targetEntity: Operation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Operation $operation = null;

    /** Prix du bidon, en centimes (> 0). */
    #[ORM\Column(nullable: true)]
    private ?int $canPrice = null;

    /** Contenance du bidon, en millilitres (> 0). */
    #[ORM\Column(nullable: true)]
    private ?int $canVolumeMl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Vehicle $vehicle, \DateTimeImmutable $date, int $volumeMl, AdblueSource $source)
    {
        $this->vehicle = $vehicle;
        $this->date = $date;
        $this->setVolumeMl($volumeMl);
        $this->source = $source;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVehicle(): Vehicle
    {
        return $this->vehicle;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getVolumeMl(): int
    {
        return $this->volumeMl;
    }

    public function setVolumeMl(int $volumeMl): static
    {
        if ($volumeMl <= 0) {
            throw new \InvalidArgumentException('Le volume doit être strictement positif.');
        }
        $this->volumeMl = $volumeMl;

        return $this;
    }

    public function getOdometerKm(): ?int
    {
        return $this->odometerKm;
    }

    public function setOdometerKm(?int $odometerKm): static
    {
        if (null !== $odometerKm && $odometerKm < 0) {
            throw new \InvalidArgumentException('Le kilométrage doit être positif ou nul.');
        }
        $this->odometerKm = $odometerKm;

        return $this;
    }

    public function getSource(): AdblueSource
    {
        return $this->source;
    }

    /** Change l'origine : efface les champs propres à l'autre origine. */
    public function setSource(AdblueSource $source): static
    {
        $this->source = $source;
        if (AdblueSource::Station === $source) {
            $this->canPrice = null;
            $this->canVolumeMl = null;
        } else {
            $this->operation = null;
        }

        return $this;
    }

    public function getOperation(): ?Operation
    {
        return $this->operation;
    }

    public function setOperation(?Operation $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    public function getCanPrice(): ?int
    {
        return $this->canPrice;
    }

    public function getCanVolumeMl(): ?int
    {
        return $this->canVolumeMl;
    }

    /** Prix et contenance du bidon : tous deux renseignés (> 0) ou tous deux null. */
    public function setCan(?int $canPrice, ?int $canVolumeMl): static
    {
        if ((null === $canPrice) !== (null === $canVolumeMl)) {
            throw new \InvalidArgumentException('Prix et contenance du bidon vont ensemble.');
        }
        if (null !== $canPrice && ($canPrice <= 0 || (int) $canVolumeMl <= 0)) {
            throw new \InvalidArgumentException('Prix et contenance du bidon doivent être strictement positifs.');
        }
        $this->canPrice = $canPrice;
        $this->canVolumeMl = $canVolumeMl;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    /**
     * Coût du remplissage en centimes (positif) : montant de l'opération liée (station),
     * prorata du prix du bidon (bidon, estimé), null si inconnu.
     */
    public function getCost(): ?int
    {
        if (AdblueSource::Station === $this->source) {
            return null !== $this->operation ? -$this->operation->getAmount() : null;
        }
        if (null === $this->canPrice || null === $this->canVolumeMl) {
            return null;
        }

        return (int) round($this->canPrice * $this->volumeMl / $this->canVolumeMl);
    }

    /** Vrai si le coût est une estimation (bidon), faux s'il vient d'une ligne de relevé. */
    public function isCostEstimated(): bool
    {
        return AdblueSource::Can === $this->source;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
