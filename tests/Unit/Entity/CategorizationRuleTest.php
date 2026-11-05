<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Account;
use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Operation;
use App\Enum\CategoryKind;
use App\Enum\RuleDirection;
use App\Enum\RuleMatchField;
use App\Enum\RuleMatchType;
use App\Enum\AccountType;
use PHPUnit\Framework\TestCase;

final class CategorizationRuleTest extends TestCase
{
    private Household $household;
    private Category $category;
    private Account $accountA;
    private Account $accountB;

    protected function setUp(): void
    {
        $this->household = new Household('Foyer test');
        $this->category = new Category($this->household, 'Courses', CategoryKind::Expense);
        $this->accountA = $this->makeAccount('perso-a', 'FIXTURE-A');
        $this->accountB = $this->makeAccount('perso-b', 'FIXTURE-B');
    }

    private function makeAccount(string $alias, string $seed): Account
    {
        return new Account(
            $this->household,
            $alias,
            'Compte '.$alias,
            AccountType::Personal,
            hash('sha256', $seed),
            '****'.substr($seed, -4),
        );
    }

    private function makeOperation(int $amount, string $label, ?string $merchant = null, ?Account $account = null): Operation
    {
        $operation = new Operation(
            $account ?? $this->accountA,
            new \DateTimeImmutable('2026-01-15'),
            $amount,
            $label,
            $label,
            hash('sha256', uniqid('fixture', true)),
        );
        $operation->setMerchant($merchant);

        return $operation;
    }

    private function makeRule(RuleMatchField $field, RuleMatchType $type, string $pattern): CategorizationRule
    {
        return new CategorizationRule($this->household, $this->category, $field, $type, $pattern);
    }

    public function testContainsIsCaseAndAccentInsensitive(): void
    {
        $rule = $this->makeRule(RuleMatchField::Label, RuleMatchType::Contains, 'intermarché');
        self::assertTrue($rule->matches($this->makeOperation(-2756, 'ACHAT CB INTERMARCHE PARIS')));
        self::assertTrue($rule->matches($this->makeOperation(-2756, 'Achat Intermarché')));
        self::assertFalse($rule->matches($this->makeOperation(-2756, 'CARrefour city')));
    }

    public function testStartsWith(): void
    {
        $rule = $this->makeRule(RuleMatchField::Label, RuleMatchType::StartsWith, 'VIR SEPA');
        self::assertTrue($rule->matches($this->makeOperation(-50000, 'VIR SEPA LOYER JANVIER')));
        self::assertFalse($rule->matches($this->makeOperation(-50000, 'PRELEVEMENT LOYER')));
    }

    public function testExact(): void
    {
        $rule = $this->makeRule(RuleMatchField::Label, RuleMatchType::Exact, 'salaire janvier 2026');
        self::assertTrue($rule->matches($this->makeOperation(250000, 'SALAIRE JANVIER 2026')));
        self::assertFalse($rule->matches($this->makeOperation(250000, 'SALAIRE JANVIER 2026 PRIME')));
    }

    public function testRegex(): void
    {
        $rule = $this->makeRule(RuleMatchField::Label, RuleMatchType::Regex, '^CB \d{4}');
        self::assertTrue($rule->matches($this->makeOperation(-1000, 'CB 1234 INTERMARCHE')));
        self::assertFalse($rule->matches($this->makeOperation(-1000, 'CARTE 1234')));
    }

    public function testRegexPatternMayContainDelimiter(): void
    {
        $rule = $this->makeRule(RuleMatchField::Label, RuleMatchType::Regex, 'A~B');
        self::assertTrue($rule->matches($this->makeOperation(-1000, 'XA~BX')));
    }

    public function testInvalidRegexMatchesNothingWithoutWarning(): void
    {
        $rule = $this->makeRule(RuleMatchField::Label, RuleMatchType::Regex, '[invalide(');
        self::assertFalse($rule->matches($this->makeOperation(-1000, 'CB 1234')));
    }

    public function testMerchantField(): void
    {
        $rule = $this->makeRule(RuleMatchField::Merchant, RuleMatchType::Contains, 'boulangerie');
        self::assertTrue($rule->matches($this->makeOperation(-850, 'CB DU PAIN', 'Boulangerie du coin')));
        self::assertFalse($rule->matches($this->makeOperation(-850, 'CB DU PAIN', 'Librairie')));
    }

    public function testNullMerchantNeverMatches(): void
    {
        $rule = $this->makeRule(RuleMatchField::Merchant, RuleMatchType::Contains, ' anything ');
        self::assertFalse($rule->matches($this->makeOperation(-850, 'CB DU PAIN', null)));
    }

    public function testDirection(): void
    {
        $debitRule = $this->makeRule(RuleMatchField::Label, RuleMatchType::Contains, 'INTERMARCHE');
        $debitRule->setDirection(RuleDirection::Debit);
        self::assertTrue($debitRule->matches($this->makeOperation(-2756, 'INTERMARCHE')));
        self::assertFalse($debitRule->matches($this->makeOperation(2756, 'INTERMARCHE')));

        $creditRule = $this->makeRule(RuleMatchField::Label, RuleMatchType::Contains, 'INTERMARCHE');
        $creditRule->setDirection(RuleDirection::Credit);
        self::assertTrue($creditRule->matches($this->makeOperation(2756, 'INTERMARCHE')));
        self::assertFalse($creditRule->matches($this->makeOperation(-2756, 'INTERMARCHE')));
    }

    public function testAmountBoundsUseAbsoluteValue(): void
    {
        $rule = $this->makeRule(RuleMatchField::Label, RuleMatchType::Contains, 'X');
        $rule->setAmountMin(1000)->setAmountMax(2000);
        self::assertTrue($rule->matches($this->makeOperation(-1500, 'X')));
        self::assertTrue($rule->matches($this->makeOperation(2000, 'X')));
        self::assertFalse($rule->matches($this->makeOperation(-500, 'X')));
        self::assertFalse($rule->matches($this->makeOperation(-2500, 'X')));
    }

    public function testAccountRestriction(): void
    {
        $rule = $this->makeRule(RuleMatchField::Label, RuleMatchType::Contains, 'X');
        $rule->setAccount($this->accountA);
        self::assertTrue($rule->matches($this->makeOperation(-100, 'X', null, $this->accountA)));
        self::assertFalse($rule->matches($this->makeOperation(-100, 'X', null, $this->accountB)));
    }
}
