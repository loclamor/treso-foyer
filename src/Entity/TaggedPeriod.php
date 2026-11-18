<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TaggedPeriodType;
use App\Repository\TaggedPeriodRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TaggedPeriodRepository::class)]
#[ORM\Table(name: 'tagged_period')]
#[ORM\Index(name: 'idx_tagged_period_household_start', columns: ['household_id', 'start_date'])]
#[Assert\Callback('validateDates')]
class TaggedPeriod
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Household::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Household $household;

    #[ORM\Column(type: Types::STRING, enumType: TaggedPeriodType::class)]
    private TaggedPeriodType $type;

    /** Libellé libre affiché sur le graphe (bande markArea). */
    #[ORM\Column(type: Types::STRING, length: 100)]
    #[Assert\NotBlank(message: 'Le libellé est obligatoire.')]
    #[Assert\Length(max: 100, maxMessage: 'Le libellé ne doit pas dépasser {{ limit }} caractères.')]
    private string $label;

    /** Premier jour de la période (inclus). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    /** Dernier jour de la période (inclus, >= startDate ; même jour autorisé). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endDate;

    /**
     * Véhicule par défaut des dépenses de transport de la période (catégorie vehicleRelated
     * hors financement), appliqué par VehicleAssigner (source `period`). Null = aucun.
     */
    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Vehicle $vehicle = null;

    /** true ⇒ le véhicule de la période l'emporte aussi sur les règles désignant un véhicule. */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $vehicleOverridesRules = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Household $household,
        TaggedPeriodType $type,
        string $label,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ) {
        $this->household = $household;
        $this->type = $type;
        $this->label = $label;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Cohérence : la période ne peut pas finir avant de commencer (même jour autorisé). */
    public function validateDates(ExecutionContextInterface $context): void
    {
        if ($this->endDate < $this->startDate) {
            $context->buildViolation('La date de fin doit être postérieure ou égale à la date de début.')
                ->atPath('endDate')
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

    public function getType(): TaggedPeriodType
    {
        return $this->type;
    }

    public function setType(TaggedPeriodType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getVehicle(): ?Vehicle
    {
        return $this->vehicle;
    }

    public function setVehicle(?Vehicle $vehicle): static
    {
        $this->vehicle = $vehicle;

        return $this;
    }

    public function isVehicleOverridesRules(): bool
    {
        return $this->vehicleOverridesRules;
    }

    public function setVehicleOverridesRules(bool $vehicleOverridesRules): static
    {
        $this->vehicleOverridesRules = $vehicleOverridesRules;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
