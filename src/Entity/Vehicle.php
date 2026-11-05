<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VehicleEnergy;
use App\Enum\VehicleType;
use App\Repository\VehicleRepository;
use App\Util\PlateNormalizer;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Véhicule du foyer. La plaque est toujours stockée normalisée (voir PlateNormalizer).
 */
#[ORM\Entity(repositoryClass: VehicleRepository::class)]
#[ORM\Table(name: 'vehicle')]
#[ORM\UniqueConstraint(name: 'uq_vehicle_household_name', columns: ['household_id', 'name'])]
#[ORM\UniqueConstraint(name: 'uq_vehicle_household_plate', columns: ['household_id', 'plate'])]
#[Assert\Callback('validateCoherence')]
class Vehicle
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Household::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Household $household;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: Types::STRING, enumType: VehicleType::class)]
    private VehicleType $type;

    /** Plaque normalisée (SIV « AA-123-AA » ou FNI sans séparateurs). */
    #[ORM\Column(length: 12, nullable: true)]
    private ?string $plate = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $make = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(type: Types::STRING, enumType: VehicleEnergy::class, nullable: true)]
    private ?VehicleEnergy $energy = null;

    /** Puissance fiscale (CV). */
    #[ORM\Column(nullable: true)]
    private ?int $fiscalPower = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstRegistrationAt = null;

    /** Numéro d'identification du véhicule (17 caractères). */
    #[ORM\Column(length: 17, nullable: true)]
    private ?string $vin = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acquiredAt = null;

    /** Date de cession : le véhicule n'est plus actif après cette date (même esprit que Account::closedAt). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $disposedAt = null;

    /** Prix d'achat en centimes. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $purchasePrice = null;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Member $mainDriver = null;

    #[ORM\Column]
    private bool $archived = false;

    /** Réponse brute d'une API externe de décodage de plaque. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $lookupData = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lookupAt = null;

    /** @var Collection<int, OdometerReading> */
    #[ORM\OneToMany(targetEntity: OdometerReading::class, mappedBy: 'vehicle')]
    private Collection $readings;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Household $household,
        string $name,
        VehicleType $type = VehicleType::Car,
    ) {
        $this->household = $household;
        $this->name = $name;
        $this->type = $type;
        $this->readings = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Vrai si le véhicule était en service à cette date (acquisition ≤ date ≤ cession).
     * L'archivage n'intervient pas : un véhicule vendu et archivé reste actif sur sa période passée.
     */
    public function isActiveOn(\DateTimeImmutable $date): bool
    {
        if (null !== $this->acquiredAt && $this->acquiredAt > $date) {
            return false;
        }
        if (null !== $this->disposedAt && $this->disposedAt < $date) {
            return false;
        }

        return true;
    }

    /** Cohérence des dates d'acquisition/cession et longueur du VIN. */
    public function validateCoherence(ExecutionContextInterface $context): void
    {
        if (null !== $this->acquiredAt && null !== $this->disposedAt && $this->disposedAt < $this->acquiredAt) {
            $context->buildViolation('La date de cession doit être postérieure ou égale à la date d’acquisition.')
                ->atPath('disposedAt')
                ->addViolation();
        }
        if (null !== $this->vin && 17 !== strlen($this->vin)) {
            $context->buildViolation('Le numéro VIN doit faire exactement 17 caractères.')
                ->atPath('vin')
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function setHousehold(Household $household): static
    {
        $this->household = $household;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): VehicleType
    {
        return $this->type;
    }

    public function setType(VehicleType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getPlate(): ?string
    {
        return $this->plate;
    }

    /** La plaque est toujours normalisée avant stockage. */
    public function setPlate(?string $plate): static
    {
        $this->plate = null === $plate ? null : PlateNormalizer::normalize($plate);

        return $this;
    }

    public function getMake(): ?string
    {
        return $this->make;
    }

    public function setMake(?string $make): static
    {
        $this->make = $make;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getEnergy(): ?VehicleEnergy
    {
        return $this->energy;
    }

    public function setEnergy(?VehicleEnergy $energy): static
    {
        $this->energy = $energy;

        return $this;
    }

    public function getFiscalPower(): ?int
    {
        return $this->fiscalPower;
    }

    public function setFiscalPower(?int $fiscalPower): static
    {
        $this->fiscalPower = $fiscalPower;

        return $this;
    }

    public function getFirstRegistrationAt(): ?\DateTimeImmutable
    {
        return $this->firstRegistrationAt;
    }

    public function setFirstRegistrationAt(?\DateTimeImmutable $firstRegistrationAt): static
    {
        $this->firstRegistrationAt = $firstRegistrationAt;

        return $this;
    }

    public function getVin(): ?string
    {
        return $this->vin;
    }

    public function setVin(?string $vin): static
    {
        $this->vin = $vin;

        return $this;
    }

    public function getAcquiredAt(): ?\DateTimeImmutable
    {
        return $this->acquiredAt;
    }

    public function setAcquiredAt(?\DateTimeImmutable $acquiredAt): static
    {
        $this->acquiredAt = $acquiredAt;

        return $this;
    }

    public function getDisposedAt(): ?\DateTimeImmutable
    {
        return $this->disposedAt;
    }

    public function setDisposedAt(?\DateTimeImmutable $disposedAt): static
    {
        $this->disposedAt = $disposedAt;

        return $this;
    }

    public function getPurchasePrice(): ?int
    {
        return $this->purchasePrice;
    }

    public function setPurchasePrice(?int $purchasePrice): static
    {
        $this->purchasePrice = $purchasePrice;

        return $this;
    }

    public function getMainDriver(): ?Member
    {
        return $this->mainDriver;
    }

    public function setMainDriver(?Member $mainDriver): static
    {
        $this->mainDriver = $mainDriver;

        return $this;
    }

    public function isArchived(): bool
    {
        return $this->archived;
    }

    public function setArchived(bool $archived): static
    {
        $this->archived = $archived;

        return $this;
    }

    /** @return ?array<string, mixed> */
    public function getLookupData(): ?array
    {
        return $this->lookupData;
    }

    /** @param ?array<string, mixed> $lookupData */
    public function setLookupData(?array $lookupData): static
    {
        $this->lookupData = $lookupData;

        return $this;
    }

    public function getLookupAt(): ?\DateTimeImmutable
    {
        return $this->lookupAt;
    }

    public function setLookupAt(?\DateTimeImmutable $lookupAt): static
    {
        $this->lookupAt = $lookupAt;

        return $this;
    }

    /** @return Collection<int, OdometerReading> */
    public function getReadings(): Collection
    {
        return $this->readings;
    }

    public function addReading(OdometerReading $reading): static
    {
        if (!$this->readings->contains($reading)) {
            $this->readings->add($reading);
            $reading->setVehicle($this);
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
