<?php

declare(strict_types=1);

namespace App\Stats;

/**
 * Classification des montants d'opérations (contrat docs/api.md, étape 8) :
 * UNE SEULE expression SQL (kindExpr) réutilisée par toutes les requêtes de stats.
 *
 * - catégorie « expense » (kind de la racine si la sous-catégorie diffère :
 *   COALESCE(root.kind, cat.kind)) ⇒ dépense, renvoyée positive (-amount ;
 *   un remboursement dans une catégorie de dépense la diminue) ;
 * - catégorie « income » ⇒ revenu ;
 * - catégorie « transfer » ou virement interne apparié (internal_transfer_id)
 *   ⇒ exclu des dépenses/revenus, renvoyé à part (transferts internes) ;
 * - non catégorisé ⇒ le signe décide (amount < 0 = dépense, > 0 = revenu).
 */
final class AmountClassifier
{
    public const EXPENSE = 'expense';
    public const INCOME = 'income';
    public const TRANSFER = 'transfer';

    /**
     * Jointures catégorie (c) et racine (r) à poser après « operation o ».
     */
    public static function joins(string $op = 'o', string $cat = 'c', string $root = 'r'): string
    {
        return "LEFT JOIN category $cat ON $cat.id = $op.category_id
                LEFT JOIN category $root ON $root.id = $cat.parent_id";
    }

    /** Expression CASE unique donnant le kind effectif de l'opération. */
    public static function kindExpr(string $op = 'o', string $cat = 'c', string $root = 'r'): string
    {
        return "CASE
                    WHEN $op.internal_transfer_id IS NOT NULL THEN '".self::TRANSFER."'
                    WHEN COALESCE($root.kind, $cat.kind) = 'transfer' THEN '".self::TRANSFER."'
                    WHEN COALESCE($root.kind, $cat.kind) = 'expense' THEN '".self::EXPENSE."'
                    WHEN COALESCE($root.kind, $cat.kind) = 'income' THEN '".self::INCOME."'
                    WHEN $op.category_id IS NULL AND $op.amount < 0 THEN '".self::EXPENSE."'
                    WHEN $op.category_id IS NULL AND $op.amount > 0 THEN '".self::INCOME."'
                    ELSE 'other'
                END";
    }

    /** Montant de dépense, positif (somme de -amount). */
    public static function expenseAmountExpr(string $op = 'o', string $cat = 'c', string $root = 'r'): string
    {
        return "CASE WHEN ".self::kindExpr($op, $cat, $root)." = '".self::EXPENSE."' THEN -$op.amount ELSE 0 END";
    }

    /** Montant de revenu. */
    public static function incomeAmountExpr(string $op = 'o', string $cat = 'c', string $root = 'r'): string
    {
        return "CASE WHEN ".self::kindExpr($op, $cat, $root)." = '".self::INCOME."' THEN $op.amount ELSE 0 END";
    }

    /** Virement interne entrant (crédit exclu des revenus). */
    public static function transferInExpr(string $op = 'o', string $cat = 'c', string $root = 'r'): string
    {
        return "CASE WHEN ".self::kindExpr($op, $cat, $root)." = '".self::TRANSFER."' AND $op.amount > 0 THEN $op.amount ELSE 0 END";
    }

    /** Virement interne sortant, positif (débit exclu des dépenses). */
    public static function transferOutExpr(string $op = 'o', string $cat = 'c', string $root = 'r'): string
    {
        return "CASE WHEN ".self::kindExpr($op, $cat, $root)." = '".self::TRANSFER."' AND $op.amount < 0 THEN -$op.amount ELSE 0 END";
    }

    /** Dépense non catégorisée, positive. */
    public static function uncategorizedExpenseExpr(string $op = 'o', string $cat = 'c', string $root = 'r'): string
    {
        return "CASE WHEN ".self::kindExpr($op, $cat, $root)." = '".self::EXPENSE."' AND $op.category_id IS NULL THEN -$op.amount ELSE 0 END";
    }
}
