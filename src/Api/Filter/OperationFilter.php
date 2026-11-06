<?php

declare(strict_types=1);

namespace App\Api\Filter;

use App\Api\BadRequestException;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\RecurringSeries;
use App\Entity\Vehicle;
use App\Enum\CategorizationSource;
use App\Enum\DuplicateStatus;
use App\Util\Text;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Filtres de GET /api/operations (contrat docs/api.md), appliqués à un QueryBuilder
 * dont la racine est l'alias « o » (opération) avec la jointure compte « a ».
 */
final class OperationFilter
{
    private const SORTS = ['date', 'amount', 'merchant'];

    /**
     * @param list<int>|null $accountIds périmètre (null = tout le foyer)
     * @param list<int> $categoryIds catégorie et ses sous-catégories
     */
    public function __construct(
        public readonly ?array $accountIds,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly ?int $categoryId,
        public readonly array $categoryIds,
        public readonly bool $uncategorized,
        public readonly ?string $q,
        public readonly ?string $merchant,
        public readonly ?string $direction,
        public readonly ?int $minAmount,
        public readonly ?int $maxAmount,
        public readonly ?string $duplicateStatus,
        public readonly ?string $source,
        public readonly string $sort,
        public readonly string $order,
        public readonly ?int $vehicleId,
        public readonly bool $vehicleUnassigned,
        public readonly ?int $recurringSeriesId,
    ) {
    }

    public static function fromRequest(Request $request, Household $household, EntityManagerInterface $entityManager): self
    {
        $from = self::date($request->query->get('from'), 'from');
        $to = self::date($request->query->get('to'), 'to');

        $categoryId = null;
        $categoryIds = [];
        $rawCategoryId = $request->query->get('categoryId');
        if (null !== $rawCategoryId && '' !== $rawCategoryId) {
            if (!ctype_digit((string) $rawCategoryId)) {
                throw new BadRequestException('Paramètre categoryId invalide.', 'invalid_query');
            }
            $categoryId = (int) $rawCategoryId;
            $categoryIds = self::categoryWithDescendants($entityManager, $household, $categoryId);
            if ([] === $categoryIds) {
                throw new BadRequestException('Catégorie introuvable.', 'invalid_query');
            }
        }

        $q = $request->query->get('q');
        $q = null !== $q && '' !== trim((string) $q) ? trim((string) $q) : null;

        $merchant = $request->query->get('merchant');
        $merchant = null !== $merchant && '' !== $merchant ? (string) $merchant : null;

        $direction = $request->query->get('direction');
        if (null !== $direction && !in_array($direction, ['debit', 'credit'], true)) {
            throw new BadRequestException('Paramètre direction invalide (debit|credit).', 'invalid_query');
        }

        $minAmount = self::amount($request->query->get('minAmount'), 'minAmount');
        $maxAmount = self::amount($request->query->get('maxAmount'), 'maxAmount');

        $duplicateStatus = $request->query->get('duplicateStatus');
        if (null !== $duplicateStatus && '' !== $duplicateStatus) {
            DuplicateStatus::tryFrom((string) $duplicateStatus)
                ?? throw new BadRequestException('Paramètre duplicateStatus invalide.', 'invalid_query');
        } else {
            $duplicateStatus = null;
        }

        $source = $request->query->get('source');
        if (null !== $source && '' !== $source) {
            CategorizationSource::tryFrom((string) $source)
                ?? throw new BadRequestException('Paramètre source invalide.', 'invalid_query');
        } else {
            $source = null;
        }

        $sort = (string) $request->query->get('sort', 'date');
        if (!in_array($sort, self::SORTS, true)) {
            throw new BadRequestException('Paramètre sort invalide (date|amount|merchant).', 'invalid_query');
        }
        $order = strtolower((string) $request->query->get('order', 'desc'));
        if (!in_array($order, ['asc', 'desc'], true)) {
            throw new BadRequestException('Paramètre order invalide (asc|desc).', 'invalid_query');
        }

        $uncategorized = in_array((string) $request->query->get('uncategorized', '0'), ['1', 'true'], true);

        $vehicleId = null;
        $rawVehicleId = $request->query->get('vehicleId');
        if (null !== $rawVehicleId && '' !== $rawVehicleId) {
            if (!ctype_digit((string) $rawVehicleId)) {
                throw new BadRequestException('Paramètre vehicleId invalide.', 'invalid_query');
            }
            $vehicleId = (int) $rawVehicleId;
            $exists = $entityManager->getRepository(Vehicle::class)->findOneBy(['id' => $vehicleId, 'household' => $household]);
            if (!$exists instanceof Vehicle) {
                throw new BadRequestException('Véhicule introuvable.', 'invalid_query');
            }
        }
        $vehicleUnassigned = in_array((string) $request->query->get('vehicleUnassigned', '0'), ['1', 'true'], true);

        $recurringSeriesId = null;
        $rawRecurringSeriesId = $request->query->get('recurringSeriesId');
        if (null !== $rawRecurringSeriesId && '' !== $rawRecurringSeriesId) {
            if (!ctype_digit((string) $rawRecurringSeriesId)) {
                throw new BadRequestException('Paramètre recurringSeriesId invalide.', 'invalid_query');
            }
            $recurringSeriesId = (int) $rawRecurringSeriesId;
            $exists = $entityManager->getRepository(RecurringSeries::class)->createQueryBuilder('rs')
                ->select('rs.id')
                ->join('rs.account', 'a')
                ->where('rs.id = :id')
                ->andWhere('a.household = :household')
                ->setParameter('id', $recurringSeriesId)
                ->setParameter('household', $household)
                ->getQuery()->getOneOrNullResult();
            if (null === $exists) {
                throw new BadRequestException('Série récurrente introuvable.', 'invalid_query');
            }
        }

        return new self(
            accountIds: null, // posé par l'appelant via withAccountIds()
            from: $from,
            to: $to,
            categoryId: $categoryId,
            categoryIds: $categoryIds,
            uncategorized: $uncategorized,
            q: $q,
            merchant: $merchant,
            direction: null === $direction ? null : (string) $direction,
            minAmount: $minAmount,
            maxAmount: $maxAmount,
            duplicateStatus: $duplicateStatus,
            source: $source,
            sort: $sort,
            order: $order,
            vehicleId: $vehicleId,
            vehicleUnassigned: $vehicleUnassigned,
            recurringSeriesId: $recurringSeriesId,
        );
    }

    /** @param list<int>|null $accountIds */
    public function withAccountIds(?array $accountIds): self
    {
        return new self(
            accountIds: $accountIds,
            from: $this->from,
            to: $this->to,
            categoryId: $this->categoryId,
            categoryIds: $this->categoryIds,
            uncategorized: $this->uncategorized,
            q: $this->q,
            merchant: $this->merchant,
            direction: $this->direction,
            minAmount: $this->minAmount,
            maxAmount: $this->maxAmount,
            duplicateStatus: $this->duplicateStatus,
            source: $this->source,
            sort: $this->sort,
            order: $this->order,
            vehicleId: $this->vehicleId,
            vehicleUnassigned: $this->vehicleUnassigned,
            recurringSeriesId: $this->recurringSeriesId,
        );
    }

    /**
     * Applique les filtres au QueryBuilder (la jointure « a » sur le compte et la
     * condition de foyer sont posées par l'appelant).
     */
    public function apply(QueryBuilder $qb): void
    {
        if (null !== $this->from) {
            $qb->andWhere('o.date >= :from')->setParameter('from', new \DateTimeImmutable($this->from), Types::DATE_IMMUTABLE);
        }
        if (null !== $this->to) {
            $qb->andWhere('o.date <= :to')->setParameter('to', new \DateTimeImmutable($this->to), Types::DATE_IMMUTABLE);
        }
        if ([] !== $this->categoryIds) {
            $qb->andWhere('o.category IN (:categoryIds)')->setParameter('categoryIds', $this->categoryIds);
        }
        if ($this->uncategorized) {
            $qb->andWhere('o.category IS NULL');
        }
        if (null !== $this->q) {
            // Libellés repliés (normalisés, commerçant) : comparaison sur upper() + texte replié ;
            // libellé brut : insensible à la casse uniquement.
            $folded = Text::fold($this->q);
            $qb->andWhere('(UPPER(o.normalizedLabel) LIKE :qFolded OR UPPER(o.merchant) LIKE :qFolded OR LOWER(o.rawLabel) LIKE :qRaw)')
                ->setParameter('qFolded', '%'.self::escapeLike($folded).'%')
                ->setParameter('qRaw', '%'.self::escapeLike(mb_strtolower($this->q)).'%');
        }
        if (null !== $this->merchant) {
            // Clé de groupe « commerçant » (cf. /api/merchants) : commerçant, ou libellé normalisé à défaut.
            $qb->andWhere('COALESCE(o.merchant, o.normalizedLabel) = :merchant')->setParameter('merchant', $this->merchant);
        }
        if ('debit' === $this->direction) {
            $qb->andWhere('o.amount < 0');
        } elseif ('credit' === $this->direction) {
            $qb->andWhere('o.amount > 0');
        }
        if (null !== $this->minAmount) {
            $qb->andWhere('ABS(o.amount) >= :minAmount')->setParameter('minAmount', $this->minAmount);
        }
        if (null !== $this->maxAmount) {
            $qb->andWhere('ABS(o.amount) <= :maxAmount')->setParameter('maxAmount', $this->maxAmount);
        }
        if (null !== $this->duplicateStatus) {
            $qb->andWhere('o.duplicateStatus = :duplicateStatus')->setParameter('duplicateStatus', $this->duplicateStatus);
        }
        if (null !== $this->source) {
            $qb->andWhere('o.categorizationSource = :source')->setParameter('source', $this->source);
        }
        if (null !== $this->vehicleId) {
            $qb->andWhere('o.vehicle = :vehicleId')->setParameter('vehicleId', $this->vehicleId);
        }
        if ($this->vehicleUnassigned) {
            // Opérations de catégorie vehicleRelated sans véhicule (jointure propre : l'alias « c »
            // est posé par l'appelant sur le select final).
            $qb->leftJoin('o.category', 'fvc')
                ->andWhere('o.vehicle IS NULL')
                ->andWhere('fvc.vehicleRelated = true');
        }
        if (null !== $this->recurringSeriesId) {
            $qb->andWhere('o.recurringSeries = :recurringSeriesId')->setParameter('recurringSeriesId', $this->recurringSeriesId);
        }
    }

    public function applyOrderBy(QueryBuilder $qb): void
    {
        $qb->orderBy('o.'.$this->sort, $this->order)
            ->addOrderBy('o.id', 'desc');
    }

    /**
     * Sommes sur l'ensemble filtré (requête agrégée séparée) : {debit, credit} en centimes.
     *
     * @return array{debit: int, credit: int}
     */
    public function sums(QueryBuilder $base): array
    {
        $qb = clone $base;
        $qb->select(
            'COALESCE(SUM(CASE WHEN o.amount < 0 THEN o.amount ELSE 0 END), 0) AS debit',
            'COALESCE(SUM(CASE WHEN o.amount > 0 THEN o.amount ELSE 0 END), 0) AS credit',
        )->resetDQLPart('orderBy')->setFirstResult(null)->setMaxResults(null);
        $row = $qb->getQuery()->getSingleResult();

        return [
            'debit' => (int) $row['debit'],
            'credit' => (int) $row['credit'],
        ];
    }

    /**
     * Catégorie et toutes ses sous-catégories (arbre à 2 niveaux).
     *
     * @return list<int>
     */
    private static function categoryWithDescendants(EntityManagerInterface $entityManager, Household $household, int $categoryId): array
    {
        $ids = [$categoryId];
        $current = [$categoryId];
        // Arbre à 2 niveaux : deux itérations suffisent, boucle de garde par profondeur.
        for ($depth = 0; $depth < 5 && [] !== $current; ++$depth) {
            $children = $entityManager->getRepository(Category::class)->createQueryBuilder('c')
                ->select('c.id')
                ->where('c.household = :household')
                ->andWhere('c.parent IN (:parents)')
                ->setParameter('household', $household)
                ->setParameter('parents', $current)
                ->getQuery()->getSingleColumnResult();
            $current = array_map('intval', $children);
            $ids = array_merge($ids, $current);
        }

        return array_values(array_unique($ids));
    }

    private static function date(mixed $raw, string $param): ?string
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        $raw = (string) $raw;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw new BadRequestException(sprintf('Paramètre %s invalide (format AAA-MM-JJ attendu).', $param), 'invalid_query');
        }
        [$y, $m, $d] = array_map('intval', explode('-', $raw));
        if (!checkdate($m, $d, $y)) {
            throw new BadRequestException(sprintf('Paramètre %s invalide (date inexistante).', $param), 'invalid_query');
        }

        return $raw;
    }

    private static function amount(mixed $raw, string $param): ?int
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (!ctype_digit((string) $raw)) {
            throw new BadRequestException(sprintf('Paramètre %s invalide (entier positif attendu).', $param), 'invalid_query');
        }

        return (int) $raw;
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
