<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TransferStatus;
use App\Repository\InternalTransferRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Virement interne apparié entre deux comptes du foyer (voir docs/adr/0009-multi-comptes-foyer.md).
 */
#[ORM\Entity(repositoryClass: InternalTransferRepository::class)]
#[ORM\Table(name: 'internal_transfer')]
class InternalTransfer
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, enumType: TransferStatus::class)]
    private TransferStatus $status;

    /** @var Collection<int, Operation> */
    #[ORM\OneToMany(targetEntity: Operation::class, mappedBy: 'internalTransfer')]
    private Collection $operations;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(TransferStatus $status = TransferStatus::Auto)
    {
        $this->status = $status;
        $this->operations = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * Équilibré = exactement 2 opérations sur des comptes différents dont la somme est nulle.
     */
    public function isBalanced(): bool
    {
        $operations = $this->operations->toArray();
        if (2 !== count($operations)) {
            return false;
        }
        [$first, $second] = $operations;

        return $first->getAccount() !== $second->getAccount()
            && 0 === $first->getAmount() + $second->getAmount();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): TransferStatus
    {
        return $this->status;
    }

    public function setStatus(TransferStatus $status): static
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
            $operation->setInternalTransfer($this);
        }

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
