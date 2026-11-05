<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * Aucun parseur ne prend en charge ce fichier.
 */
final class UnsupportedFormatException extends \RuntimeException
{
}
