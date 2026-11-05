<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AccountType;
use App\Repository\AccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compte bancaire suivi. Le numéro de compte en clair n'est jamais stocké :
 * seuls son empreinte HMAC (numberHash) et son masque (numberMasked) le sont.
 */
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ORM\Table(name: 'account')]
#[ORM\UniqueConstraint(name: 'uq_account_household_alias', columns: ['household_id', 'alias'])]
class Account
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Household::class, inversedBy: 'accounts')]
    #[ORM\JoinColumn(nullable: false)]
    private Household $household;

    #[ORM\Column(length: 50)]
    private string $alias;

    #[ORM\Column(length: 100)]
    private string $label;

    #[ORM\Column(type: Types::STRING, enumType: AccountType::class)]
    private AccountType $type;

    #[ORM\Column(length: 50)]
    private string $bank = 'La Banque Postale';

    #[ORM\Column(length: 64, unique: true)]
    private string $numberHash;

    #[ORM\Column(length: 20)]
    private string $numberMasked;

    /** @var Collection<int, Member> */
    #[ORM\ManyToMany(targetEntity: Member::class)]
    #[ORM\JoinTable(name: 'account_holder')]
    private Collection $holders;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Household $household,
        string $alias,
        string $label,
        AccountType $type,
        string $numberHash,
        string $numberMasked,
    ) {
        $this->household = $household;
        $this->alias = $alias;
        $this->label = $label;
        $this->type = $type;
        $this->numberHash = $numberHash;
        $this->numberMasked = $numberMasked;
        $this->holders = new ArrayCollection();
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

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function setAlias(string $alias): static
    {
        $this->alias = $alias;

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

    public function getType(): AccountType
    {
        return $this->type;
    }

    public function setType(AccountType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getBank(): string
    {
        return $this->bank;
    }

    public function setBank(string $bank): static
    {
        $this->bank = $bank;

        return $this;
    }

    public function getNumberHash(): string
    {
        return $this->numberHash;
    }

    public function setNumberHash(string $numberHash): static
    {
        $this->numberHash = $numberHash;

        return $this;
    }

    public function getNumberMasked(): string
    {
        return $this->numberMasked;
    }

    public function setNumberMasked(string $numberMasked): static
    {
        $this->numberMasked = $numberMasked;

        return $this;
    }

    /** @return Collection<int, Member> */
    public function getHolders(): Collection
    {
        return $this->holders;
    }

    public function addHolder(Member $holder): static
    {
        if (!$this->holders->contains($holder)) {
            $this->holders->add($holder);
        }

        return $this;
    }

    public function removeHolder(Member $holder): static
    {
        $this->holders->removeElement($holder);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
