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
#[ORM\Index(name: 'idx_operation_merchant_group', columns: ['merchant_group'])]
#[ORM\Index(name: 'idx_operation_vehicle', columns: ['vehicle_id'])]
#[ORM\Index(name: 'idx_operation_awaiting_statement', columns: ['awaiting_statement'], options: ['where' => 'awaiting_statement'])]
// Index fonctionnel réel : (account_id, COALESCE(spent_at, date)), créé en SQL brut dans la
// migration Version20261106140000. Déclaré ici sur ['account_id'] car c'est ainsi que
// l'introspection pg_index le relit (les expressions d'index sont ignorées, comme pour
// l'index GIN pg_trgm déclaré sur sa colonne) : le diff de schéma reste vide, et l'index
// auto de la clé étrangère sur account_id (IDX_…) n'est pas re-généré par le mapping.
#[ORM\Index(name: 'idx_operation_account_effective_date', columns: ['account_id'])]
#[ORM\Index(name: 'idx_operation_normalized_label_trgm', columns: ['normalized_label'])]
class Operation
{
    /**
     * Date « effective » en SQL brut : COALESCE(spent_at, date), paramétrable par alias
     * (sprintf avec l'alias en premier argument, ex. Operation::effectiveDateSql('o')).
     * `date` reste la date du relevé (foi pour soldes, empreinte, dédoublonnage, virements) ;
     * `spentAt` est la date réelle de la dépense (saisie manuelle ou extraite du libellé CB).
     */
    public const EFFECTIVE_DATE_SQL = 'COALESCE(%s.spent_at, %s.date)';

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

    /**
     * Date réelle de la dépense (saisie manuelle, ou extraite du libellé CB « JJ.MM.AA »).
     * Nullable : null = date du relevé. La date effective = COALESCE(spent_at, date).
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $spentAt = null;

    /**
     * true = saisie manuelle pas encore rapprochée d'une ligne de relevé (voir ADR 0014).
     * Une telle opération n'est jamais candidate au dédoublonnage flou, aux virements
     * internes ni aux récurrences.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $awaitingStatement = false;

    /** Posé sur la ligne de RELEVÉ qui a absorbé une saisie manuelle (voir ADR 0014). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reconciledAt = null;

    /** Montant en centimes, négatif = débit. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $amount;

    #[ORM\Column(type: Types::TEXT)]
    private string $rawLabel;

    #[ORM\Column(length: 255)]
    private string $normalizedLabel;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $merchant = null;

    /**
     * Clé représentative du groupe de variantes du commerçant (CSV/PDF, ponctuation…), calculée
     * par App\Merchant\MerchantGrouper ; NULL pour un commerçant sans variante.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $merchantGroup = null;

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

    /** Volume du plein de carburant en millilitres (> 0), null = pas de plein (ADR 0015). */
    #[ORM\Column(nullable: true)]
    private ?int $fuelVolumeMl = null;

    /** true = plein complet (défaut), false = plein partiel (plafond de carte). Non nul ssi fuelVolumeMl non nul. */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $fuelFullTank = null;

    /** Kilométrage au compteur lors du plein (≥ 0, optionnel) ; n'existe qu'avec un volume. Point de compteur. */
    #[ORM\Column(nullable: true)]
    private ?int $odometerKm = null;

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

    /**
     * Saisie manuelle d'une dépense (voir ADR 0014) : date = spentAt = date de saisie,
     * en attente de rapprochement avec une ligne de relevé, sans source de libellé ni lot,
     * et une empreinte aléatoire « manual:… » jamais retrouvée par l'import exact
     * (les empreintes d'import sont des sha256 hexadécimaux de 64 caractères).
     */
    public static function manualEntry(
        Account $account,
        \DateTimeImmutable $spentAt,
        int $amount,
        string $label,
        string $normalizedLabel,
        ?string $merchant,
    ): static {
        $operation = new static(
            $account,
            $spentAt,
            $amount,
            $label,
            $normalizedLabel,
            'manual:'.bin2hex(random_bytes(28)),
        );
        $operation->spentAt = $spentAt;
        $operation->awaitingStatement = true;
        $operation->merchant = $merchant;

        return $operation;
    }

    /** Date effective : date réelle de la dépense si connue, sinon date du relevé. */
    public function getEffectiveDate(): \DateTimeImmutable
    {
        return $this->spentAt ?? $this->date;
    }

    /** Expression SQL de la date effective (COALESCE(spent_at, date)) pour l'alias donné. */
    public static function effectiveDateSql(string $alias = 'o'): string
    {
        return sprintf(self::EFFECTIVE_DATE_SQL, $alias, $alias);
    }

    /**
     * Expression DQL de la date effective pour l'alias donné (COALESCE n'est pas une fonction
     * DQL enregistrée : on passe par un CASE WHEN, accepté en WHERE et ORDER BY).
     */
    public static function effectiveDateDql(string $alias = 'o'): string
    {
        return 'CASE WHEN '.$alias.'.spentAt IS NULL THEN '.$alias.'.date ELSE '.$alias.'.spentAt END';
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

    /** Marque l'opération comme volontairement sans véhicule (vehicleSource « ignored »). */
    public function ignoreVehicle(): static
    {
        $this->vehicle = null;
        $this->vehicleSource = VehicleSource::Ignored;

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

    public function getSpentAt(): ?\DateTimeImmutable
    {
        return $this->spentAt;
    }

    public function setSpentAt(?\DateTimeImmutable $spentAt): static
    {
        $this->spentAt = $spentAt;

        return $this;
    }

    /** true = saisie manuelle en attente de rapprochement avec une ligne de relevé. */
    public function isAwaitingStatement(): bool
    {
        return $this->awaitingStatement;
    }

    public function setAwaitingStatement(bool $awaitingStatement): static
    {
        $this->awaitingStatement = $awaitingStatement;

        return $this;
    }

    public function getReconciledAt(): ?\DateTimeImmutable
    {
        return $this->reconciledAt;
    }

    public function setReconciledAt(?\DateTimeImmutable $reconciledAt): static
    {
        $this->reconciledAt = $reconciledAt;

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

    public function getMerchantGroup(): ?string
    {
        return $this->merchantGroup;
    }

    /** Clé de regroupement effective : groupe, sinon commerçant, sinon libellé normalisé. */
    public function getMerchantKey(): string
    {
        return $this->merchantGroup ?? $this->merchant ?? $this->normalizedLabel;
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
     * Enregistre un plein de carburant sur l'opération (ADR 0015) : volume en millilitres,
     * plein complet par défaut, kilométrage au compteur optionnel. Remplace un plein existant.
     *
     * @throws \InvalidArgumentException si le volume est ≤ 0, le kilométrage < 0 ou l'opération n'est pas un débit
     */
    public function recordFuel(int $volumeMl, bool $fullTank = true, ?int $odometerKm = null): static
    {
        if ($volumeMl <= 0) {
            throw new \InvalidArgumentException('Le volume du plein doit être strictement positif (millilitres).');
        }
        if (null !== $odometerKm && $odometerKm < 0) {
            throw new \InvalidArgumentException('Le kilométrage au compteur doit être positif ou nul.');
        }
        if (!$this->isDebit()) {
            throw new \InvalidArgumentException('Un plein ne peut être enregistré que sur un débit.');
        }
        $this->fuelVolumeMl = $volumeMl;
        $this->fuelFullTank = $fullTank;
        $this->odometerKm = $odometerKm;

        return $this;
    }

    /** Supprime le plein de l'opération (ADR 0015). */
    public function clearFuel(): static
    {
        $this->fuelVolumeMl = null;
        $this->fuelFullTank = null;
        $this->odometerKm = null;

        return $this;
    }

    /** true si l'opération porte un plein de carburant (volume renseigné). */
    public function hasFuel(): bool
    {
        return null !== $this->fuelVolumeMl;
    }

    /**
     * Prix au litre en euros (3 décimales) : |amount| (centimes) × 10 / volumeMl (mL).
     * Le prix n'est pas stocké, il est calculé (ADR 0015) ; null sans plein.
     */
    public function getFuelPricePerLiter(): ?float
    {
        if (null === $this->fuelVolumeMl || 0 === $this->fuelVolumeMl) {
            return null;
        }

        return round(abs($this->amount) * 10 / $this->fuelVolumeMl, 3);
    }

    public function getFuelVolumeMl(): ?int
    {
        return $this->fuelVolumeMl;
    }

    public function getFuelFullTank(): ?bool
    {
        return $this->fuelFullTank;
    }

    public function getOdometerKm(): ?int
    {
        return $this->odometerKm;
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
