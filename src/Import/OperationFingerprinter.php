<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\Account;
use App\Import\Model\ParsedOperation;

/**
 * Empreinte d'une opération : sha256(compte | date | montant | clé de libellé | rang d'occurrence),
 * unique en base (voir docs/adr/0005-dedoublonnage.md).
 */
final class OperationFingerprinter
{
    public function fingerprint(Account $account, \DateTimeImmutable $date, int $amount, string $key, int $occurrenceIndex): string
    {
        return hash('sha256', implode('|', [
            (string) $account->getId(),
            $date->format('Y-m-d'),
            (string) $amount,
            $key,
            (string) $occurrenceIndex,
        ]));
    }

    /**
     * Rang d'occurrence (0, 1, 2…) de chaque opération parmi celles du même fichier
     * ayant même (date, montant, clé), dans l'ordre chronologique de la liste fournie.
     *
     * @param list<ParsedOperation> $operations
     * @param callable(ParsedOperation): string $keyOf
     * @return list<int>
     */
    public function assignOccurrences(array $operations, callable $keyOf): array
    {
        $counters = [];
        $result = [];
        foreach ($operations as $operation) {
            $group = $operation->date->format('Y-m-d').'|'.$operation->amount.'|'.$keyOf($operation);
            $rank = $counters[$group] ?? 0;
            $counters[$group] = $rank + 1;
            $result[] = $rank;
        }

        return $result;
    }
}
