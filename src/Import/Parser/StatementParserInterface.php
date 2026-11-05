<?php

declare(strict_types=1);

namespace App\Import\Parser;

use App\Import\Model\ParsedStatement;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Parseur de relevé (CSV, PDF…). Tous les parseurs sont tagués `app.statement_parser`
 * et essayés dans l'ordre jusqu'à ce que l'un d'eux accepte le fichier.
 */
#[AutoconfigureTag('app.statement_parser')]
interface StatementParserInterface
{
    /** Le contenu brut du fichier est fourni pour éviter de le relire. */
    public function supports(string $path, string $content): bool;

    /**
     * @throws \App\Import\Exception\ParseException si le fichier est illisible ou mal formé
     */
    public function parse(string $path): ParsedStatement;
}
