<?php

declare(strict_types=1);

namespace App\Tests\Integration\Categorization;

use App\Categorization\Categorizer;
use App\Categorization\ManualCategorizer;
use App\Entity\Account;
use App\Entity\CategorizationRule;
use App\Entity\Household;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Enum\RuleDirection;
use App\Enum\RuleMatchField;
use App\Enum\RuleMatchType;
use App\Setup\CategoryResolver;
use App\Tests\Support\CategorizationFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ManualCategorizerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ManualCategorizer $manualCategorizer;
    private Household $household;
    private Account $account;
    private CategoryResolver $categoryResolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->manualCategorizer = new ManualCategorizer(
            $this->entityManager,
            $container->get(Categorizer::class),
        );
        $this->categoryResolver = $container->get(CategoryResolver::class);

        $this->household = new Household('Foyer test');
        $this->entityManager->persist($this->household);
        CategorizationFixtures::seedCategories($this->entityManager, $this->household);
        $this->account = CategorizationFixtures::makeAccount($this->household, 'perso-a');
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();
    }

    private function persistOperation(string $date, int $amount, string $label, ?string $merchant): Operation
    {
        $operation = CategorizationFixtures::makeOperation($this->account, $date, $amount, $label, $merchant);
        $this->entityManager->persist($operation);
        $this->entityManager->flush();

        return $operation;
    }

    public function testAssignCreatesLearnedRuleWithPriority100AndAppliesToSimilar(): void
    {
        $courses = $this->categoryResolver->require($this->household, 'Alimentation › Courses');
        $target = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB SHOP X', 'SHOP X');
        $sameMerchantDebit = $this->persistOperation('2026-01-11', -2000, 'ACHAT CB SHOP X PARIS', 'SHOP X');
        $sameMerchantCredit = $this->persistOperation('2026-01-12', 2000, 'AVOIR CARTE SHOP X', 'SHOP X');
        $manualOther = $this->persistOperation('2026-01-13', -3000, 'ACHAT CB SHOP X AUTRE', 'SHOP X');
        $manualOther->categorize($this->categoryResolver->require($this->household, 'Shopping › Achats divers'), CategorizationSource::Manual);
        $this->entityManager->flush();

        $result = $this->manualCategorizer->assign($target, $courses, createRule: true, applyToSimilar: true);

        // Règle apprise : merchant exact, priorité 100, direction débit.
        self::assertNotNull($result->rule);
        self::assertSame(ManualCategorizer::LEARNED_RULE_PRIORITY, $result->rule->getPriority());
        self::assertSame(RuleMatchField::Merchant, $result->rule->getMatchField());
        self::assertSame(RuleMatchType::Exact, $result->rule->getMatchType());
        self::assertSame('SHOP X', $result->rule->getPattern());
        self::assertSame(RuleDirection::Debit, $result->rule->getDirection());

        $this->entityManager->refresh($target);
        self::assertSame($courses->getId(), $target->getCategory()?->getId());
        self::assertSame(CategorizationSource::Manual, $target->getCategorizationSource());

        // Appliquée aux autres opérations non manuelles de même commerçant et même signe.
        self::assertSame(1, $result->updatedCount);
        $this->entityManager->refresh($sameMerchantDebit);
        self::assertSame($courses->getId(), $sameMerchantDebit->getCategory()?->getId());
        self::assertSame(CategorizationSource::Rule, $sameMerchantDebit->getCategorizationSource());
        self::assertSame($result->rule->getId(), $sameMerchantDebit->getAppliedRule()?->getId());

        // Pas au crédit de signe opposé, ni à l'opération manuelle.
        $this->entityManager->refresh($sameMerchantCredit);
        self::assertNull($sameMerchantCredit->getCategory());
        $this->entityManager->refresh($manualOther);
        self::assertSame(CategorizationSource::Manual, $manualOther->getCategorizationSource());
        self::assertSame('Shopping › Achats divers', $manualOther->getCategory()?->getFullName());
    }

    public function testAssignWithoutRuleAppliesSimilaritySource(): void
    {
        $courses = $this->categoryResolver->require($this->household, 'Alimentation › Courses');
        $target = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB SHOP Y', 'SHOP Y');
        $other = $this->persistOperation('2026-01-11', -2000, 'ACHAT CB SHOP Y PARIS', 'SHOP Y');

        $result = $this->manualCategorizer->assign($target, $courses, createRule: false, applyToSimilar: true);

        self::assertNull($result->rule);
        self::assertSame(1, $result->updatedCount);
        $this->entityManager->refresh($other);
        self::assertSame($courses->getId(), $other->getCategory()?->getId());
        self::assertSame(CategorizationSource::Similarity, $other->getCategorizationSource());
    }

    public function testAssignIsIdempotentForTheLearnedRule(): void
    {
        $courses = $this->categoryResolver->require($this->household, 'Alimentation › Courses');
        $target = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB SHOP Z', 'SHOP Z');

        $this->manualCategorizer->assign($target, $courses, createRule: true);
        $second = $this->manualCategorizer->assign($target, $courses, createRule: true);

        $rules = $this->entityManager->getRepository(CategorizationRule::class)->findBy(['pattern' => 'SHOP Z']);
        self::assertCount(1, $rules);
        self::assertSame($rules[0]->getId(), $second->rule?->getId());
    }

    public function testAssignWithoutMerchantCreatesNoRule(): void
    {
        $courses = $this->categoryResolver->require($this->household, 'Alimentation › Courses');
        $target = $this->persistOperation('2026-01-10', -1000, 'LIBRE SANS COMMERCANT', null);

        $result = $this->manualCategorizer->assign($target, $courses, createRule: true, applyToSimilar: true);

        self::assertNull($result->rule);
        self::assertSame(0, $result->updatedCount);
        self::assertCount(0, $this->entityManager->getRepository(CategorizationRule::class)->findAll());
    }

    public function testClearRemovesManualCategoryAndRecategorizesAutomatically(): void
    {
        $courses = $this->categoryResolver->require($this->household, 'Alimentation › Courses');
        $highTech = $this->categoryResolver->require($this->household, 'Shopping › High-tech');
        $target = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB SHOP W', 'SHOP W');
        // Règle automatique qui matche : la re-catégorisation doit la remettre.
        $rule = new CategorizationRule($this->household, $highTech, RuleMatchField::Merchant, RuleMatchType::Exact, 'SHOP W');
        $rule->setPriority(10);
        $this->entityManager->persist($rule);
        $this->manualCategorizer->assign($target, $courses, createRule: false, applyToSimilar: false);

        $report = $this->manualCategorizer->clear($target);

        self::assertSame(1, $report->processed);
        $this->entityManager->refresh($target);
        self::assertSame($highTech->getId(), $target->getCategory()?->getId());
        self::assertSame(CategorizationSource::Rule, $target->getCategorizationSource());
    }

    public function testClearWithoutAnyMatchLeavesUncategorized(): void
    {
        $courses = $this->categoryResolver->require($this->household, 'Alimentation › Courses');
        $target = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB SHOP V', 'SHOP V');
        $this->manualCategorizer->assign($target, $courses, createRule: false, applyToSimilar: false);

        $this->manualCategorizer->clear($target);

        $this->entityManager->refresh($target);
        self::assertNull($target->getCategory());
        self::assertNull($target->getCategorizationSource());
    }
}
