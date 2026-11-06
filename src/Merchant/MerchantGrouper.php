<?php

declare(strict_types=1);

namespace App\Merchant;

use App\Entity\Household;
use App\Recurrence\TrigramSimilarity;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Regroupe les variantes d'un même commerçant (voir docs/adr/0013-regroupement-commercants.md) :
 * clé brute = coalesce(merchant, normalized_label) ; le libellé d'un export CSV diffère souvent
 * de celui du relevé PDF (ponctuation, mot coupé, troncature). Les clés d'un même groupe reçoivent
 * operation.merchant_group = clé représentative (la plus fréquente) ; NULL pour un commerçant seul.
 *
 * Règles prudentes (des commerçants distincts aux libellés proches ne doivent pas fusionner) :
 * - même forme compacte (majuscules, sans ponctuation ni espace) ⇒ même groupe ;
 * - même premier mot et forme compacte d'au moins MIN_LENGTH caractères, préfixe d'UNE SEULE autre
 *   forme compacte (troncature ; une clé courte préfixe de plusieurs libellés — « LA BANQUE POSTALE »
 *   → crédit, assurance… — est un libellé générique, pas une troncature) ⇒ même groupe ;
 * - même premier mot, formes compactes d'au moins MIN_SIMILARITY_LENGTH caractères et similarité
 *   trigramme ≥ MIN_SIMILARITY (mot coupé : « PARTI IERS » / « PARTICUL IERS ») ⇒ même groupe ;
 *   la longueur minimale écarte les libellés courts distincts (« PHARMACIE DU L » / « DU G »).
 * Idempotent ; UPDATE groupés ; aucun libellé dans les logs ni les exceptions (ADR 0007).
 */
final class MerchantGrouper
{
    public const int MIN_LENGTH = 12;
    public const int MIN_SIMILARITY_LENGTH = 16;
    public const float MIN_SIMILARITY = 0.8;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function sync(Household $household, bool $dryRun = false): MerchantGroupReport
    {
        $connection = $this->entityManager->getConnection();
        $householdId = (int) $household->getId();
        $rows = $connection->fetchAllAssociative(
            'SELECT COALESCE(o.merchant, o.normalized_label) AS k, COUNT(*) AS n
             FROM operation o JOIN account a ON a.id = o.account_id
             WHERE a.household_id = :household
             GROUP BY 1',
            ['household' => $householdId],
            ['household' => ParameterType::INTEGER],
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['k']] = (int) $row['n'];
        }

        $mapping = self::groups($counts);
        $report = new MerchantGroupReport(groups: count(array_unique($mapping)));
        if ($dryRun) {
            return $report;
        }

        $accountIds = 'SELECT id FROM account WHERE household_id = :household';
        foreach ($mapping as $key => $representative) {
            $report->updated += $connection->executeStatement(
                "UPDATE operation SET merchant_group = :rep
                 WHERE account_id IN ($accountIds) AND COALESCE(merchant, normalized_label) = :key
                   AND merchant_group IS DISTINCT FROM :rep",
                ['rep' => $representative, 'key' => $key, 'household' => $householdId],
                ['rep' => ParameterType::STRING, 'key' => ParameterType::STRING, 'household' => ParameterType::INTEGER],
            );
        }
        // Clés qui ne sont plus groupées.
        $grouped = array_keys($mapping);
        $report->updated += $connection->executeStatement(
            "UPDATE operation SET merchant_group = NULL
             WHERE account_id IN ($accountIds) AND merchant_group IS NOT NULL"
            .([] === $grouped ? '' : ' AND COALESCE(merchant, normalized_label) NOT IN (:keys)'),
            ['household' => $householdId] + ([] === $grouped ? [] : ['keys' => $grouped]),
            ['household' => ParameterType::INTEGER] + ([] === $grouped ? [] : ['keys' => ArrayParameterType::STRING]),
        );

        return $report;
    }

    /**
     * Calcul pur des groupes (testé unitairement).
     *
     * @param array<string, int> $counts clé brute => nombre d'opérations
     * @return array<string, string> clé brute => clé représentative, seulement pour les groupes d'au moins 2 clés
     */
    public static function groups(array $counts): array
    {
        $keys = array_map('strval', array_keys($counts));
        $parent = array_combine($keys, $keys);
        $find = static function (string $k) use (&$parent): string {
            while ($parent[$k] !== $k) {
                $parent[$k] = $parent[$parent[$k]];
                $k = $parent[$k];
            }

            return $k;
        };
        $union = static function (string $a, string $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        };

        // 1. Même forme compacte. 2. Comparaison par premier mot (évite le produit cartésien).
        $byCompact = [];
        $byFirstWord = [];
        foreach ($keys as $key) {
            $compact = self::compact($key);
            if ('' === $compact) {
                continue;
            }
            $byCompact[$compact][] = $key;
            if (mb_strlen($compact) >= self::MIN_LENGTH) {
                $byFirstWord[self::firstWord($key)][$compact] = $key;
            }
        }
        foreach ($byCompact as $sameCompact) {
            for ($i = 1, $n = count($sameCompact); $i < $n; ++$i) {
                $union($sameCompact[0], $sameCompact[$i]);
            }
        }
        foreach ($byFirstWord as $bucket) {
            $compacts = array_map('strval', array_keys($bucket));
            // Troncature : la forme courte n'est préfixe que d'une seule autre forme.
            foreach ($compacts as $short) {
                $longer = array_values(array_filter(
                    $compacts,
                    static fn (string $other): bool => $other !== $short && str_starts_with($other, $short),
                ));
                if (1 === count($longer)) {
                    $union($bucket[$short], $bucket[$longer[0]]);
                }
            }
            // Mot coupé : similarité élevée entre libellés assez longs.
            for ($i = 0, $n = count($compacts); $i < $n; ++$i) {
                for ($j = $i + 1; $j < $n; ++$j) {
                    $a = $compacts[$i];
                    $b = $compacts[$j];
                    if (mb_strlen($a) >= self::MIN_SIMILARITY_LENGTH && mb_strlen($b) >= self::MIN_SIMILARITY_LENGTH
                        && TrigramSimilarity::similarity($bucket[$a], $bucket[$b]) >= self::MIN_SIMILARITY) {
                        $union($bucket[$a], $bucket[$b]);
                    }
                }
            }
        }

        // Représentant : clé la plus fréquente du groupe (à égalité : la plus longue, puis l'ordre alphabétique).
        $members = [];
        foreach ($keys as $key) {
            $members[$find($key)][] = $key;
        }
        $mapping = [];
        foreach ($members as $group) {
            if (count($group) < 2) {
                continue;
            }
            usort($group, static fn (string $a, string $b): int => [$counts[$b], mb_strlen($b), $a] <=> [$counts[$a], mb_strlen($a), $b]);
            foreach ($group as $key) {
                $mapping[$key] = $group[0];
            }
        }

        return $mapping;
    }

    /** Forme compacte : majuscules, lettres et chiffres seulement. */
    public static function compact(string $key): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper($key));
    }

    private static function firstWord(string $key): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtoupper($key), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return $words[0] ?? '';
    }
}
