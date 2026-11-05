<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StatementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Relevé / solde issu d'un import (un CSV n'a qu'un solde : ouverture nullable).
 */
#[ORM\Entity(repositoryClass: StatementRepository::class)]
#[ORM\Table(name: 'statement')]
#[ORM\Index(name: 'idx_statement_account_closing', columns: ['account_id', 'closing_date'])]
class Statement
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $account;

    #[ORM\OneToOne(targetEntity: ImportBatch::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ImportBatch $importBatch;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $openingDate = null;

    /** Solde d'ouverture en centimes. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $openingBalance = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $closingDate;

    /** Solde de clôture en centimes. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $closingBalance;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Account $account, ImportBatch $importBatch, \DateTimeImmutable $closingDate, int $closingBalance)
    {
        $this->account = $account;
        $this->importBatch = $importBatch;
        $this->closingDate = $closingDate;
        $this->closingBalance = $closingBalance;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function setAccount(Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getImportBatch(): ImportBatch
    {
        return $this->importBatch;
    }

    public function setImportBatch(ImportBatch $importBatch): static
    {
        $this->importBatch = $importBatch;

        return $this;
    }

    public function getOpeningDate(): ?\DateTimeImmutable
    {
        return $this->openingDate;
    }

    public function setOpeningDate(?\DateTimeImmutable $openingDate): static
    {
        $this->openingDate = $openingDate;

        return $this;
    }

    public function getOpeningBalance(): ?int
    {
        return $this->openingBalance;
    }

    public function setOpeningBalance(?int $openingBalance): static
    {
        $this->openingBalance = $openingBalance;

        return $this;
    }

    public function getClosingDate(): \DateTimeImmutable
    {
        return $this->closingDate;
    }

    public function setClosingDate(\DateTimeImmutable $closingDate): static
    {
        $this->closingDate = $closingDate;

        return $this;
    }

    public function getClosingBalance(): int
    {
        return $this->closingBalance;
    }

    public function setClosingBalance(int $closingBalance): static
    {
        $this->closingBalance = $closingBalance;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
