<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ImportFormat;
use App\Enum\ImportStatus;
use App\Repository\ImportBatchRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Lot d'import d'un fichier relevé (PDF ou CSV). Le sha256 du fichier est unique :
 * un fichier déjà importé est rejeté (voir docs/adr/0005-dedoublonnage.md).
 */
#[ORM\Entity(repositoryClass: ImportBatchRepository::class)]
#[ORM\Table(name: 'import_batch')]
class ImportBatch
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Account $account = null;

    #[ORM\Column(length: 255)]
    private string $originalFilename;

    #[ORM\Column(length: 64, unique: true)]
    private string $sha256;

    #[ORM\Column(type: Types::STRING, enumType: ImportFormat::class)]
    private ImportFormat $format;

    #[ORM\Column(type: Types::STRING, enumType: ImportStatus::class)]
    private ImportStatus $status;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $periodStart = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $periodEnd = null;

    #[ORM\Column]
    private int $addedCount = 0;

    #[ORM\Column]
    private int $skippedCount = 0;

    #[ORM\Column]
    private int $probableDuplicateCount = 0;

    /** Opérations déjà connues dont le libellé a été remplacé par celui de ce fichier. */
    #[ORM\Column(options: ['default' => 0])]
    private int $enrichedCount = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Member $importedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $originalFilename, string $sha256, ImportFormat $format, ImportStatus $status)
    {
        $this->originalFilename = $originalFilename;
        $this->sha256 = $sha256;
        $this->format = $format;
        $this->status = $status;
        $this->importedAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): static
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    public function setSha256(string $sha256): static
    {
        $this->sha256 = $sha256;

        return $this;
    }

    public function getFormat(): ImportFormat
    {
        return $this->format;
    }

    public function setFormat(ImportFormat $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function getStatus(): ImportStatus
    {
        return $this->status;
    }

    public function setStatus(ImportStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPeriodStart(): ?\DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(?\DateTimeImmutable $periodStart): static
    {
        $this->periodStart = $periodStart;

        return $this;
    }

    public function getPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function setPeriodEnd(?\DateTimeImmutable $periodEnd): static
    {
        $this->periodEnd = $periodEnd;

        return $this;
    }

    public function getAddedCount(): int
    {
        return $this->addedCount;
    }

    public function setAddedCount(int $addedCount): static
    {
        $this->addedCount = $addedCount;

        return $this;
    }

    public function getSkippedCount(): int
    {
        return $this->skippedCount;
    }

    public function setSkippedCount(int $skippedCount): static
    {
        $this->skippedCount = $skippedCount;

        return $this;
    }

    public function getProbableDuplicateCount(): int
    {
        return $this->probableDuplicateCount;
    }

    public function setProbableDuplicateCount(int $probableDuplicateCount): static
    {
        $this->probableDuplicateCount = $probableDuplicateCount;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getImportedBy(): ?Member
    {
        return $this->importedBy;
    }

    public function setImportedBy(?Member $importedBy): static
    {
        $this->importedBy = $importedBy;

        return $this;
    }

    public function getImportedAt(): \DateTimeImmutable
    {
        return $this->importedAt;
    }

    public function setImportedAt(\DateTimeImmutable $importedAt): static
    {
        $this->importedAt = $importedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEnrichedCount(): int
    {
        return $this->enrichedCount;
    }

    public function setEnrichedCount(int $enrichedCount): static
    {
        $this->enrichedCount = $enrichedCount;

        return $this;
    }
}
