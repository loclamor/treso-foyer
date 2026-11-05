<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stats;

use App\Stats\AmountClassifier;
use PHPUnit\Framework\TestCase;

/**
 * L'expression de classification est unique et réutilisée partout (contrat étape 8) :
 * on vérifie ici sa forme (les comportements sont couverts par StatsApiTest en base).
 */
final class AmountClassifierTest extends TestCase
{
    public function testKindExprExcludesInternalTransfersFirst(): void
    {
        $expr = AmountClassifier::kindExpr();

        // Virement interne apparié = transfer, avant toute autre règle.
        self::assertStringContainsString('o.internal_transfer_id IS NOT NULL', $expr);
        self::assertLessThan(
            strpos($expr, "COALESCE(r.kind, c.kind) = 'transfer'"),
            strpos($expr, 'o.internal_transfer_id IS NOT NULL'),
        );
    }

    public function testKindExprUsesRootKindCoalesce(): void
    {
        $expr = AmountClassifier::kindExpr();

        self::assertStringContainsString("COALESCE(r.kind, c.kind) = 'expense'", $expr);
        self::assertStringContainsString("COALESCE(r.kind, c.kind) = 'income'", $expr);
        self::assertStringContainsString("COALESCE(r.kind, c.kind) = 'transfer'", $expr);
    }

    public function testKindExprFallsBackToSignWhenUncategorized(): void
    {
        $expr = AmountClassifier::kindExpr();

        self::assertStringContainsString('o.category_id IS NULL AND o.amount < 0', $expr);
        self::assertStringContainsString('o.category_id IS NULL AND o.amount > 0', $expr);
    }

    public function testAmountExprsReuseTheSameKindExpr(): void
    {
        $kind = AmountClassifier::kindExpr();

        self::assertStringContainsString($kind, AmountClassifier::expenseAmountExpr());
        self::assertStringContainsString($kind, AmountClassifier::incomeAmountExpr());
        self::assertStringContainsString($kind, AmountClassifier::transferInExpr());
        self::assertStringContainsString($kind, AmountClassifier::transferOutExpr());
        self::assertStringContainsString($kind, AmountClassifier::uncategorizedExpenseExpr());

        // Dépense renvoyée positive (somme de -amount : un remboursement la diminue).
        self::assertStringContainsString('-o.amount', AmountClassifier::expenseAmountExpr());
        self::assertStringContainsString('o.amount', AmountClassifier::incomeAmountExpr());
    }

    public function testCustomAliases(): void
    {
        $expr = AmountClassifier::kindExpr('op', 'cat', 'root');

        self::assertStringContainsString('op.internal_transfer_id IS NOT NULL', $expr);
        self::assertStringContainsString('COALESCE(root.kind, cat.kind)', $expr);
        self::assertStringContainsString('op.category_id IS NULL', $expr);
    }
}
