<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CategoryKind;
use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Table(name: 'category')]
#[ORM\UniqueConstraint(name: 'uq_category_household_parent_name', columns: ['household_id', 'parent_id', 'name'])]
// Contrainte unique PARTIELLE « WHERE chart_slot IS NOT NULL » (clause where exprimable
// via options depuis ORM 3.7) : un seul chartSlot 1-8 par foyer, les NULL ne comptent pas.
// (parenthèses requises : DBAL les conserve telles quelles depuis pg_indexes.indexdef)
#[ORM\UniqueConstraint(name: 'uq_category_household_chart_slot', columns: ['household_id', 'chart_slot'], options: ['where' => '(chart_slot IS NOT NULL)'])]
class Category
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Household::class, inversedBy: 'categories')]
    #[ORM\JoinColumn(nullable: false)]
    private Household $household;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\ManyToOne(targetEntity: Category::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Category $parent = null;

    /** @var Collection<int, Category> */
    #[ORM\OneToMany(targetEntity: Category::class, mappedBy: 'parent')]
    private Collection $children;

    #[ORM\Column(type: Types::STRING, enumType: CategoryKind::class)]
    private CategoryKind $kind;

    /** Couleur hex `#RRGGBB`. */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null;

    /** Classe PrimeIcons, ex `pi pi-home`. */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column]
    private bool $archived = false;

    /** Poste lié à un véhicule (carburant, entretien, assurance auto, péages & parking). */
    #[ORM\Column(options: ['default' => false])]
    private bool $vehicleRelated = false;

    /** Poste carburant : fait apparaître les champs du plein (volume, plein complet, kilométrage, ADR 0015). */
    #[ORM\Column(options: ['default' => false])]
    private bool $fuel = false;

    /** Emplacement 1-8 sur le graphique (racines de dépense uniquement, unique par foyer). */
    #[ORM\Column(nullable: true)]
    private ?int $chartSlot = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Household $household, string $name, CategoryKind $kind)
    {
        $this->household = $household;
        $this->name = $name;
        $this->kind = $kind;
        $this->children = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
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

    public function getParent(): ?Category
    {
        return $this->parent;
    }

    public function setParent(?Category $parent): static
    {
        $this->parent = $parent;

        return $this;
    }

    /** @return Collection<int, Category> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(Category $child): static
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }

        return $this;
    }

    public function getKind(): CategoryKind
    {
        return $this->kind;
    }

    public function setKind(CategoryKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getChartSlot(): ?int
    {
        return $this->chartSlot;
    }

    public function setChartSlot(?int $chartSlot): static
    {
        $this->chartSlot = $chartSlot;

        return $this;
    }

    /**
     * Emplacement affiché : celui de la racine pour une sous-catégorie
     * (les sous-catégories n'ont pas d'emplacement propre).
     */
    public function getRootChartSlot(): ?int
    {
        return null !== $this->parent ? $this->parent->getChartSlot() : $this->chartSlot;
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

    public function isVehicleRelated(): bool
    {
        return $this->vehicleRelated;
    }

    public function setVehicleRelated(bool $vehicleRelated): static
    {
        $this->vehicleRelated = $vehicleRelated;

        return $this;
    }

    public function isFuel(): bool
    {
        return $this->fuel;
    }

    public function setFuel(bool $fuel): static
    {
        $this->fuel = $fuel;

        return $this;
    }

    /** Nom complet « Parent › Enfant » (ou simplement le nom à la racine). */
    public function getFullName(): string
    {
        return null !== $this->parent ? $this->parent->getFullName().' › '.$this->name : $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
