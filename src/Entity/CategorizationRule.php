<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RuleDirection;
use App\Enum\RuleMatchField;
use App\Enum\RuleMatchType;
use App\Repository\CategorizationRuleRepository;
use App\Util\Text;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategorizationRuleRepository::class)]
#[ORM\Table(name: 'categorization_rule')]
class CategorizationRule
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Household::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Household $household;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\Column(type: Types::STRING, enumType: RuleMatchField::class)]
    private RuleMatchField $matchField;

    #[ORM\Column(type: Types::STRING, enumType: RuleMatchType::class)]
    private RuleMatchType $matchType;

    #[ORM\Column(length: 255)]
    private string $pattern;

    #[ORM\Column(type: Types::STRING, enumType: RuleDirection::class)]
    private RuleDirection $direction = RuleDirection::Any;

    /** Borne basse en centimes, comparée en valeur absolue. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $amountMin = null;

    /** Borne haute en centimes, comparée en valeur absolue. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $amountMax = null;

    /** Compte auquel la règle s'applique (null = tous les comptes du foyer). */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Account $account = null;

    /** Véhicule auquel la règle affecte les opérations (null = règle générique). */
    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Vehicle $vehicle = null;

    /** Le plus grand priority gagne. */
    #[ORM\Column]
    private int $priority = 0;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Household $household,
        Category $category,
        RuleMatchField $matchField,
        RuleMatchType $matchType,
        string $pattern,
    ) {
        $this->household = $household;
        $this->category = $category;
        $this->matchField = $matchField;
        $this->matchType = $matchType;
        $this->pattern = $pattern;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Teste la règle sur une opération (méthode pure : sans effet de bord).
     * contains / starts_with / exact sont insensibles à la casse et aux accents.
     */
    public function matches(Operation $operation): bool
    {
        if (null !== $this->account && $this->account !== $operation->getAccount()) {
            return false;
        }
        if (RuleDirection::Debit === $this->direction && !$operation->isDebit()) {
            return false;
        }
        if (RuleDirection::Credit === $this->direction && !$operation->isCredit()) {
            return false;
        }
        $amount = abs($operation->getAmount());
        if (null !== $this->amountMin && $amount < $this->amountMin) {
            return false;
        }
        if (null !== $this->amountMax && $amount > $this->amountMax) {
            return false;
        }

        $value = match ($this->matchField) {
            RuleMatchField::Merchant => $operation->getMerchant(),
            RuleMatchField::Label => $operation->getNormalizedLabel(),
        };
        if (null === $value || '' === $value) {
            return false;
        }

        if (RuleMatchType::Regex === $this->matchType) {
            // Délimiteur ~, flag iu ; une regex invalide ne matche rien, sans warning.
            return 1 === @preg_match('~'.str_replace('~', '\\~', $this->pattern).'~iu', $value);
        }

        $foldedValue = Text::fold($value);
        $foldedPattern = Text::fold($this->pattern);

        return match ($this->matchType) {
            RuleMatchType::Contains => str_contains($foldedValue, $foldedPattern),
            RuleMatchType::StartsWith => str_starts_with($foldedValue, $foldedPattern),
            RuleMatchType::Exact => $foldedValue === $foldedPattern,
            RuleMatchType::Regex => false, // déjà traité ci-dessus
        };
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

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getMatchField(): RuleMatchField
    {
        return $this->matchField;
    }

    public function setMatchField(RuleMatchField $matchField): static
    {
        $this->matchField = $matchField;

        return $this;
    }

    public function getMatchType(): RuleMatchType
    {
        return $this->matchType;
    }

    public function setMatchType(RuleMatchType $matchType): static
    {
        $this->matchType = $matchType;

        return $this;
    }

    public function getPattern(): string
    {
        return $this->pattern;
    }

    public function setPattern(string $pattern): static
    {
        $this->pattern = $pattern;

        return $this;
    }

    public function getDirection(): RuleDirection
    {
        return $this->direction;
    }

    public function setDirection(RuleDirection $direction): static
    {
        $this->direction = $direction;

        return $this;
    }

    public function getAmountMin(): ?int
    {
        return $this->amountMin;
    }

    public function setAmountMin(?int $amountMin): static
    {
        $this->amountMin = $amountMin;

        return $this;
    }

    public function getAmountMax(): ?int
    {
        return $this->amountMax;
    }

    public function setAmountMax(?int $amountMax): static
    {
        $this->amountMax = $amountMax;

        return $this;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(?Account $account): static
    {
        $this->account = $account;

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

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
