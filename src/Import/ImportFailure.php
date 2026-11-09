<?php

declare(strict_types=1);

namespace App\Import;

use App\Import\Exception\ParseException;
use App\Import\Exception\UnknownAccountException;
use App\Import\Exception\UnsupportedFormatException;

/**
 * Message d'échec d'import montré à l'utilisateur (réponse API, lot « failed ») :
 * le message des erreurs métier attendues, un texte générique pour le reste
 * (erreur SQL, chemin, bug) afin de ne pas exposer de détail interne.
 */
final class ImportFailure
{
    public const GENERIC_MESSAGE = 'Erreur interne pendant l\'import (détails dans les journaux).';

    public static function isExpected(\Throwable $exception): bool
    {
        return $exception instanceof ParseException
            || $exception instanceof UnknownAccountException
            || $exception instanceof UnsupportedFormatException;
    }

    public static function publicMessage(\Throwable $exception): string
    {
        return self::isExpected($exception) ? $exception->getMessage() : self::GENERIC_MESSAGE;
    }
}
