<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecurrenceDirection;
use App\Enum\RecurrenceFrequency;
use App\Enum\RecurrenceStatus;
use App\Repository\RecurringSeriesRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Série d'opérations récurrentes détectée sur un compte (abonnement, loyer, salaire…).
 * Le montant attendu est TOUJOURS positif : le signe est porté par `direction`.
 */
#[ORM\Entity(repositoryClass: RecurringSeriesRepository::class)]
#[ORM\Table(name: 'recurring_series')]
#[ORM\UniqueConstraint(name: 'uq_recurring_series_account_match_direction', columns: ['account_id', 'match_key', 'direction'])]
class RecurringSeries
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $account;

    /** Clé de regroupement = coalesce(operation.merchant, operation.normalized_label). */
    #[ORM\Column(length: 255)]
    private string $matchKey;

    #[ORM\Column(type: Types::STRING, enumType: RecurrenceDirection::class)]
    private RecurrenceDirection $direction;

    /** Libellé affiché : merchant de l'opération, sinon les 120 premiers caractères du libellé normalisé. */
    #[ORM\Column(length: 120)]
    private string $merchant;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\Column(type: Types::STRING, enumType: RecurrenceFrequency::class)]
    private RecurrenceFrequency $frequency;

    /** Montant attendu en centimes. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $expectedAmount;

    /** Tolérance sur le montant en centimes. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $amountTolerance = 0;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $lastOccurrence;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextExpected = null;

    /** Mois calendaires actifs (1..12, triés) ; null = tous les mois. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $activeMonths = null;

    #[ORM\Column(type: Types::STRING, enumType: RecurrenceStatus::class)]
    private RecurrenceStatus $status = RecurrenceStatus::Detected;

    /** @var Collection<int, Operation> */
    #[ORM\OneToMany(targetEntity: Operation::class, mappedBy: 'recurringSeries')]
    private Collection $operations;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Account $account,
        string $matchKey,
        RecurrenceDirection $direction,
        string $merchant,
        RecurrenceFrequency $frequency,
        int $expectedAmount,
        \DateTimeImmutable $lastOccurrence,
    ) {
        $this->account = $account;
        $this->matchKey = $matchKey;
        $this->direction = $direction;
        $this->merchant = $merchant;
        $this->frequency = $frequency;
        $this->expectedAmount = $expectedAmount;
        $this->lastOccurrence = $lastOccurrence;
        $this->operations = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getMatchKey(): string
    {
        return $this->matchKey;
    }

    public function setMatchKey(string $matchKey): static
    {
        $this->matchKey = $matchKey;

        return $this;
    }

    public function getDirection(): RecurrenceDirection
    {
        return $this->direction;
    }

    public function setDirection(RecurrenceDirection $direction): static
    {
        $this->direction = $direction;

        return $this;
    }

    public function setAccount(Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getMerchant(): string
    {
        return $this->merchant;
    }

    public function setMerchant(string $merchant): static
    {
        $this->merchant = $merchant;

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getFrequency(): RecurrenceFrequency
    {
        return $this->frequency;
    }

    public function setFrequency(RecurrenceFrequency $frequency): static
    {
        $this->frequency = $frequency;

        return $this;
    }

    public function getExpectedAmount(): int
    {
        return $this->expectedAmount;
    }

    public function setExpectedAmount(int $expectedAmount): static
    {
        $this->expectedAmount = $expectedAmount;

        return $this;
    }

    public function getAmountTolerance(): int
    {
        return $this->amountTolerance;
    }

    public function setAmountTolerance(int $amountTolerance): static
    {
        $this->amountTolerance = $amountTolerance;

        return $this;
    }

    public function getLastOccurrence(): \DateTimeImmutable
    {
        return $this->lastOccurrence;
    }

    public function setLastOccurrence(\DateTimeImmutable $lastOccurrence): static
    {
        $this->lastOccurrence = $lastOccurrence;

        return $this;
    }

    public function getNextExpected(): ?\DateTimeImmutable
    {
        return $this->nextExpected;
    }

    public function setNextExpected(?\DateTimeImmutable $nextExpected): static
    {
        $this->nextExpected = $nextExpected;

        return $this;
    }

    /** @return list<int>|null */
    public function getActiveMonths(): ?array
    {
        return $this->activeMonths;
    }

    /** @param list<int>|null $activeMonths */
    public function setActiveMonths(?array $activeMonths): static
    {
        $this->activeMonths = $activeMonths;

        return $this;
    }

    public function getStatus(): RecurrenceStatus
    {
        return $this->status;
    }

    public function setStatus(RecurrenceStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** @return Collection<int, Operation> */
    public function getOperations(): Collection
    {
        return $this->operations;
    }

    public function addOperation(Operation $operation): static
    {
        if (!$this->operations->contains($operation)) {
            $this->operations->add($operation);
            $operation->setRecurringSeries($this);
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
