<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * Compte bancaire introuvable. Le message ne contient que le masque du numéro,
 * jamais le numéro en clair.
 */
final class UnknownAccountException extends \RuntimeException
{
}
