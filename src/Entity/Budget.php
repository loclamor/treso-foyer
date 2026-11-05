<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BudgetScope;
use App\Repository\BudgetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BudgetRepository::class)]
#[ORM\Table(name: 'budget')]
#[Assert\Callback('validateScope')]
class Budget
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Household::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Household $household;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    /** Montant mensuel en centimes, strictement positif. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $monthlyAmount;

    #[ORM\Column(type: Types::STRING, enumType: BudgetScope::class)]
    private BudgetScope $scope;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Member $member = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Account $account = null;

    /** Premier mois du budget (1er du mois). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startMonth;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endMonth = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Household $household,
        Category $category,
        int $monthlyAmount,
        BudgetScope $scope,
        \DateTimeImmutable $startMonth,
    ) {
        $this->household = $household;
        $this->category = $category;
        $this->monthlyAmount = $monthlyAmount;
        $this->scope = $scope;
        $this->startMonth = $startMonth;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** Contraintes de cohérence entre la portée et la cible du budget. */
    public function validateScope(ExecutionContextInterface $context): void
    {
        if ($this->monthlyAmount <= 0) {
            $context->buildViolation('Le montant mensuel doit être strictement positif (en centimes).')
                ->atPath('monthlyAmount')
                ->addViolation();
        }
        if (BudgetScope::Member === $this->scope && null === $this->member) {
            $context->buildViolation('Un membre est obligatoire pour un budget de portée « membre ».')
                ->atPath('member')
                ->addViolation();
        }
        if (BudgetScope::Account === $this->scope && null === $this->account) {
            $context->buildViolation('Un compte est obligatoire pour un budget de portée « compte ».')
                ->atPath('account')
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

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getMonthlyAmount(): int
    {
        return $this->monthlyAmount;
    }

    public function setMonthlyAmount(int $monthlyAmount): static
    {
        $this->monthlyAmount = $monthlyAmount;

        return $this;
    }

    public function getScope(): BudgetScope
    {
        return $this->scope;
    }

    public function setScope(BudgetScope $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    public function getMember(): ?Member
    {
        return $this->member;
    }

    public function setMember(?Member $member): static
    {
        $this->member = $member;

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

    public function getStartMonth(): \DateTimeImmutable
    {
        return $this->startMonth;
    }

    public function setStartMonth(\DateTimeImmutable $startMonth): static
    {
        $this->startMonth = $startMonth;

        return $this;
    }

    public function getEndMonth(): ?\DateTimeImmutable
    {
        return $this->endMonth;
    }

    public function setEndMonth(?\DateTimeImmutable $endMonth): static
    {
        $this->endMonth = $endMonth;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
