<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * Document PDF sans en-tête d'opérations de relevé de compte (ex. relevé annuel de frais) :
 * l'import le relance sans enregistrer de lot « failed » ; la commande l'affiche « ignoré ».
 * Le message ne contient jamais de contenu du document.
 */
final class NotAnAccountStatementException extends \RuntimeException
{
}
