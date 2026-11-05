<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Member;
use App\Enum\AccountType;
use App\Enum\BudgetScope;
use App\Enum\CategoryKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class BudgetValidationTest extends KernelTestCase
{
    private Household $household;
    private Category $category;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
        $this->household = new Household('Foyer test');
        $this->category = new Category($this->household, 'Courses', CategoryKind::Expense);
    }

    private function makeBudget(BudgetScope $scope, int $monthlyAmount = 50000): Budget
    {
        return new Budget(
            $this->household,
            $this->category,
            $monthlyAmount,
            $scope,
            new \DateTimeImmutable('2026-01-01'),
        );
    }

    public function testValidHouseholdBudgetHasNoViolation(): void
    {
        self::assertCount(0, $this->validator->validate($this->makeBudget(BudgetScope::Household)));
    }

    public function testMemberScopeRequiresMember(): void
    {
        $violations = $this->validator->validate($this->makeBudget(BudgetScope::Member));

        self::assertGreaterThanOrEqual(1, count($violations));
        self::assertSame('member', (string) $violations->get(0)->getPropertyPath());
    }

    public function testMemberScopeWithMemberIsValid(): void
    {
        $member = new Member($this->household, 'alice', 'Alice');
        $budget = $this->makeBudget(BudgetScope::Member);
        $budget->setMember($member);

        self::assertCount(0, $this->validator->validate($budget));
    }

    public function testAccountScopeRequiresAccount(): void
    {
        $violations = $this->validator->validate($this->makeBudget(BudgetScope::Account));

        self::assertGreaterThanOrEqual(1, count($violations));
        self::assertSame('account', (string) $violations->get(0)->getPropertyPath());
    }

    public function testAccountScopeWithAccountIsValid(): void
    {
        $account = new Account(
            $this->household,
            'perso',
            'Compte perso',
            AccountType::Personal,
            hash_hmac('sha256', 'FIXTURE-BUDGET', 'test-pepper'),
            '****PERS',
        );
        $budget = $this->makeBudget(BudgetScope::Account);
        $budget->setAccount($account);

        self::assertCount(0, $this->validator->validate($budget));
    }

    public function testMonthlyAmountMustBeStrictlyPositive(): void
    {
        $violations = $this->validator->validate($this->makeBudget(BudgetScope::Household, 0));

        self::assertGreaterThanOrEqual(1, count($violations));
        self::assertSame('monthlyAmount', (string) $violations->get(0)->getPropertyPath());

        $violations = $this->validator->validate($this->makeBudget(BudgetScope::Household, -100));
        self::assertGreaterThanOrEqual(1, count($violations));
    }
}
