<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CategorizationSource;
use App\Enum\DuplicateStatus;
use App\Enum\ImportFormat;
use App\Enum\VehicleSource;
use App\Repository\OperationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Opération bancaire (issue d'un import). Le montant est en centimes, négatif = débit.
 * Le fingerprint (sha256 compte | date | montant | libellé normalisé | rang) est unique.
 */
#[ORM\Entity(repositoryClass: OperationRepository::class)]
#[ORM\Table(name: 'operation')]
#[ORM\Index(name: 'idx_operation_account_date', columns: ['account_id', 'date'])]
#[ORM\Index(name: 'idx_operation_category', columns: ['category_id'])]
#[ORM\Index(name: 'idx_operation_merchant', columns: ['merchant'])]
#[ORM\Index(name: 'idx_operation_vehicle', columns: ['vehicle_id'])]
#[ORM\Index(name: 'idx_operation_normalized_label_trgm', columns: ['normalized_label'])]
class Operation
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: ImportBatch::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ImportBatch $importBatch = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $valueDate = null;

    /** Montant en centimes, négatif = débit. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    #[ORM\Column(type: Types::TEXT)]
    private string $rawLabel;

    #[ORM\Column(length: 255)]
    private string $normalizedLabel;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $merchant = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $fingerprint;

    /** Rang parmi les opérations strictement identiques au sein d'un même fichier. */
    #[ORM\Column]
    private int $occurrenceIndex = 0;

    #[ORM\ManyToOne(targetEntity: Category::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\Column(type: Types::STRING, enumType: CategorizationSource::class, nullable: true)]
    private ?CategorizationSource $categorizationSource = null;

    #[ORM\ManyToOne(targetEntity: CategorizationRule::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?CategorizationRule $appliedRule = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $categorizedAt = null;

    #[ORM\Column(type: Types::STRING, enumType: DuplicateStatus::class)]
    private DuplicateStatus $duplicateStatus = DuplicateStatus::None;

    #[ORM\ManyToOne(targetEntity: Operation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Operation $duplicateOf = null;

    #[ORM\ManyToOne(targetEntity: InternalTransfer::class, inversedBy: 'operations')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?InternalTransfer $internalTransfer = null;

    #[ORM\ManyToOne(targetEntity: RecurringSeries::class, inversedBy: 'operations')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RecurringSeries $recurringSeries = null;

    #[ORM\ManyToOne(targetEntity: Vehicle::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Vehicle $vehicle = null;

    /** Origine de l'affectation au véhicule. */
    #[ORM\Column(type: Types::STRING, enumType: VehicleSource::class, nullable: true)]
    private ?VehicleSource $vehicleSource = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    /** Format d'origine du libellé (null = opération saisie à la main). */
    #[ORM\Column(length: 10, nullable: true, enumType: ImportFormat::class)]
    private ?ImportFormat $labelSource = null;

    /** Date à laquelle le libellé a été remplacé par celui d'une source plus complète. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $enrichedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Account $account,
        \DateTimeImmutable $date,
        int $amount,
        string $rawLabel,
        string $normalizedLabel,
        string $fingerprint,
    ) {
        $this->account = $account;
        $this->date = $date;
        $this->amount = $amount;
        $this->rawLabel = $rawLabel;
        $this->normalizedLabel = $normalizedLabel;
        $this->fingerprint = $fingerprint;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isDebit(): bool
    {
        return $this->amount < 0;
    }

    public function isCredit(): bool
    {
        return $this->amount > 0;
    }

    public function categorize(Category $category, CategorizationSource $source, ?CategorizationRule $rule = null): static
    {
        $this->category = $category;
        $this->categorizationSource = $source;
        $this->appliedRule = $rule;
        $this->categorizedAt = new \DateTimeImmutable();

        return $this;
    }

    public function uncategorize(): static
    {
        $this->category = null;
        $this->categorizationSource = null;
        $this->appliedRule = null;
        $this->categorizedAt = null;

        return $this;
    }

    public function assignVehicle(Vehicle $vehicle, VehicleSource $source): static
    {
        $this->vehicle = $vehicle;
        $this->vehicleSource = $source;

        return $this;
    }

    public function unassignVehicle(): static
    {
        $this->vehicle = null;
        $this->vehicleSource = null;

        return $this;
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

    public function getImportBatch(): ?ImportBatch
    {
        return $this->importBatch;
    }

    public function setImportBatch(?ImportBatch $importBatch): static
    {
        $this->importBatch = $importBatch;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getValueDate(): ?\DateTimeImmutable
    {
        return $this->valueDate;
    }

    public function setValueDate(?\DateTimeImmutable $valueDate): static
    {
        $this->valueDate = $valueDate;

        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getRawLabel(): string
    {
        return $this->rawLabel;
    }

    public function setRawLabel(string $rawLabel): static
    {
        $this->rawLabel = $rawLabel;

        return $this;
    }

    public function getNormalizedLabel(): string
    {
        return $this->normalizedLabel;
    }

    public function setNormalizedLabel(string $normalizedLabel): static
    {
        $this->normalizedLabel = $normalizedLabel;

        return $this;
    }

    public function getMerchant(): ?string
    {
        return $this->merchant;
    }

    public function setMerchant(?string $merchant): static
    {
        $this->merchant = $merchant;

        return $this;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): static
    {
        $this->fingerprint = $fingerprint;

        return $this;
    }

    public function getOccurrenceIndex(): int
    {
        return $this->occurrenceIndex;
    }

    public function setOccurrenceIndex(int $occurrenceIndex): static
    {
        $this->occurrenceIndex = $occurrenceIndex;

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

    public function getCategorizationSource(): ?CategorizationSource
    {
        return $this->categorizationSource;
    }

    public function setCategorizationSource(?CategorizationSource $categorizationSource): static
    {
        $this->categorizationSource = $categorizationSource;

        return $this;
    }

    public function getAppliedRule(): ?CategorizationRule
    {
        return $this->appliedRule;
    }

    public function setAppliedRule(?CategorizationRule $appliedRule): static
    {
        $this->appliedRule = $appliedRule;

        return $this;
    }

    public function getCategorizedAt(): ?\DateTimeImmutable
    {
        return $this->categorizedAt;
    }

    public function setCategorizedAt(?\DateTimeImmutable $categorizedAt): static
    {
        $this->categorizedAt = $categorizedAt;

        return $this;
    }

    public function getDuplicateStatus(): DuplicateStatus
    {
        return $this->duplicateStatus;
    }

    public function setDuplicateStatus(DuplicateStatus $duplicateStatus): static
    {
        $this->duplicateStatus = $duplicateStatus;

        return $this;
    }

    public function getDuplicateOf(): ?Operation
    {
        return $this->duplicateOf;
    }

    public function setDuplicateOf(?Operation $duplicateOf): static
    {
        $this->duplicateOf = $duplicateOf;

        return $this;
    }

    public function getInternalTransfer(): ?InternalTransfer
    {
        return $this->internalTransfer;
    }

    public function setInternalTransfer(?InternalTransfer $internalTransfer): static
    {
        $this->internalTransfer = $internalTransfer;

        return $this;
    }

    public function getRecurringSeries(): ?RecurringSeries
    {
        return $this->recurringSeries;
    }

    public function setRecurringSeries(?RecurringSeries $recurringSeries): static
    {
        $this->recurringSeries = $recurringSeries;

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

    public function getVehicleSource(): ?VehicleSource
    {
        return $this->vehicleSource;
    }

    public function setVehicleSource(?VehicleSource $vehicleSource): static
    {
        $this->vehicleSource = $vehicleSource;

        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLabelSource(): ?ImportFormat
    {
        return $this->labelSource;
    }

    public function setLabelSource(?ImportFormat $labelSource): static
    {
        $this->labelSource = $labelSource;

        return $this;
    }

    public function getEnrichedAt(): ?\DateTimeImmutable
    {
        return $this->enrichedAt;
    }

    /**
     * Remplace le libellé par celui d'une source plus complète (ex. PDF après CSV).
     * Identité (empreinte), catégorie, note et liens sont conservés.
     */
    public function enrichLabel(string $rawLabel, string $normalizedLabel, ?string $merchant, ImportBatch $source): static
    {
        $this->rawLabel = $rawLabel;
        $this->normalizedLabel = $normalizedLabel;
        $this->merchant = $merchant;
        $this->importBatch = $source;
        $this->labelSource = $source->getFormat();
        $this->enrichedAt = new \DateTimeImmutable();

        return $this;
    }
}
