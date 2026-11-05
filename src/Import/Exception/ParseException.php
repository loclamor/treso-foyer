<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * Erreur de parsing d'un relevé. Le message indique le numéro de ligne
 * mais jamais le contenu de la ligne (donnée bancaire privée).
 */
final class ParseException extends \RuntimeException
{
}
