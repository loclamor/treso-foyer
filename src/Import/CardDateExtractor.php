<?php

declare(strict_types=1);

namespace App\Import;

use App\Util\Text;

/**
 * Extrait la date réelle d'une opération carte bancaire depuis son libellé brut
 * La Banque Postale : « ACHAT CB <COMMERÇANT> JJ.MM.AA [CARTE NUMERO …] »,
 * « AVOIR CARTE <COMMERÇANT> JJ.MM.AA … », « <MOT> CARTE <…> JJ.MM.AA »
 * (même amorce que LabelNormalizer::extractMerchant). L'année est 20AA.
 *
 * Garde-fous (guard) : date valide (checkdate), spentAt <= date de l'opération et
 * date − spentAt <= MAX_LAG_DAYS jours, sinon null. Une date égale à la date du relevé
 * est conservée telle quelle (sans effet sur la date effective).
 */
final class CardDateExtractor
{
    /** Écart maximal accepté entre la date du relevé et la date réelle (jours). */
    public const MAX_LAG_DAYS = 31;

    private const LABEL_DATE = '/^(?:ACHAT CB|AVOIR CARTE|[A-Z]+ CARTE)\s+.+?\s+(\d{2})\.(\d{2})\.(\d{2})\b/s';

    /** Date JJ.MM.AA portée par le libellé (année 20AA) ; null si absent ou invalide. */
    public function extract(string $rawLabel): ?\DateTimeImmutable
    {
        if (!preg_match(self::LABEL_DATE, Text::fold($rawLabel), $m)) {
            return null;
        }
        $day = (int) $m[1];
        $month = (int) $m[2];
        $year = 2000 + (int) $m[3];
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    /** Applique les garde-fous à une date extraite, relativement à la date du relevé. */
    public function guard(?\DateTimeImmutable $spentAt, \DateTimeImmutable $operationDate): ?\DateTimeImmutable
    {
        if (null === $spentAt) {
            return null;
        }
        $statementDay = $operationDate->setTime(0, 0);
        $spentDay = $spentAt->setTime(0, 0);
        if ($spentDay > $statementDay) {
            return null;
        }
        if ($statementDay->diff($spentDay)->days > self::MAX_LAG_DAYS) {
            return null;
        }

        return $spentAt;
    }
}
