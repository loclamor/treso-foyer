<?php

declare(strict_types=1);

namespace App\Tests\Integration\Categorization;

use App\Categorization\CategorizeOptions;
use App\Categorization\Categorizer;
use App\Categorization\SimilarityFinder;
use App\Entity\Account;
use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\InternalTransfer;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Enum\RuleMatchField;
use App\Enum\RuleMatchType;
use App\Enum\TransferStatus;
use App\Setup\CategoryResolver;
use App\Tests\Support\CategorizationFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CategorizerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Categorizer $categorizer;
    private Household $household;
    private Account $accountA;
    private Account $accountB;
    private CategoryResolver $categoryResolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->categorizer = $container->get(Categorizer::class);
        $this->categoryResolver = $container->get(CategoryResolver::class);

        $this->household = new Household('Foyer test');
        $this->entityManager->persist($this->household);
        CategorizationFixtures::seedCategories($this->entityManager, $this->household);
        $this->accountA = CategorizationFixtures::makeAccount($this->household, 'perso-a');
        $this->accountB = CategorizationFixtures::makeAccount($this->household, 'perso-b');
        $this->entityManager->persist($this->accountA);
        $this->entityManager->persist($this->accountB);
        $this->entityManager->flush();
    }

    private function category(string $fullName): Category
    {
        return $this->categoryResolver->require($this->household, $fullName);
    }

    private function persistOperation(string $date, int $amount, string $label, ?string $merchant = null, ?Account $account = null): Operation
    {
        $operation = CategorizationFixtures::makeOperation($account ?? $this->accountA, $date, $amount, $label, $merchant);
        $this->entityManager->persist($operation);
        $this->entityManager->flush();

        return $operation;
    }

    private function addRule(Category $category, string $pattern, int $priority = 10, RuleMatchField $field = RuleMatchField::Merchant, RuleMatchType $type = RuleMatchType::Exact): CategorizationRule
    {
        $rule = new CategorizationRule($this->household, $category, $field, $type, $pattern);
        $rule->setPriority($priority);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        return $rule;
    }

    public function testRuleWinsOverSimilarity(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $highTech = $this->category('Shopping › High-tech');
        $this->addRule($highTech, 'MONSHOP', 10);
        $manual = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB MONSHOP', 'MONSHOP');
        $manual->categorize($courses, CategorizationSource::Manual);
        $this->entityManager->flush();

        $candidate = $this->persistOperation('2026-01-11', -2000, 'ACHAT CB MONSHOP', 'MONSHOP');
        $report = $this->categorizer->categorize([$candidate], new CategorizeOptions());

        self::assertSame(1, $report->processed);
        self::assertSame(1, $report->byRule);
        self::assertSame(0, $report->bySimilarity);
        $this->entityManager->refresh($candidate);
        self::assertSame($highTech->getId(), $candidate->getCategory()?->getId());
        self::assertSame(CategorizationSource::Rule, $candidate->getCategorizationSource());
        self::assertNotNull($candidate->getAppliedRule());
    }

    public function testSimilarityAboveThresholdUsesManualCategory(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $manual = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB CARREFOUR MARKET', 'CARREFOUR MARKET');
        $manual->categorize($courses, CategorizationSource::Manual);
        $this->entityManager->flush();

        $candidate = $this->persistOperation('2026-01-11', -2000, 'ACHAT CB CARREFOUR MARKET PARIS', 'CARREFOUR MARKET PARIS');
        $report = $this->categorizer->categorize([$candidate], new CategorizeOptions());

        self::assertSame(1, $report->bySimilarity);
        $this->entityManager->refresh($candidate);
        self::assertSame($courses->getId(), $candidate->getCategory()?->getId());
        self::assertSame(CategorizationSource::Similarity, $candidate->getCategorizationSource());
    }

    public function testSimilarityBelowThresholdLeavesUncategorized(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $manual = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB CARREFOUR MARKET', 'CARREFOUR MARKET');
        $manual->categorize($courses, CategorizationSource::Manual);
        $this->entityManager->flush();

        $candidate = $this->persistOperation('2026-01-11', -2000, 'ACHAT CB BOULANGERIE CENTRALE', 'BOULANGERIE CENTRALE');
        $report = $this->categorizer->categorize([$candidate], new CategorizeOptions(similarityThreshold: 0.6));

        self::assertSame(0, $report->byRule);
        self::assertSame(0, $report->bySimilarity);
        self::assertSame(1, $report->stillUncategorized);
        $this->entityManager->refresh($candidate);
        self::assertNull($candidate->getCategory());
    }

    public function testSimilarityRequiresSameSign(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $manual = $this->persistOperation('2026-01-10', 1000, 'VIREMENT DE CARREFOUR MARKET', 'CARREFOUR MARKET');
        $manual->categorize($courses, CategorizationSource::Manual);
        $this->entityManager->flush();

        // Même commerçant mais signe opposé : pas de similarité.
        $candidate = $this->persistOperation('2026-01-11', -1000, 'ACHAT CB CARREFOUR MARKET', 'CARREFOUR MARKET');
        $report = $this->categorizer->categorize([$candidate], new CategorizeOptions());

        self::assertSame(0, $report->bySimilarity);
        self::assertSame(1, $report->stillUncategorized);
    }

    public function testManualOperationsAreNeverModified(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $highTech = $this->category('Shopping › High-tech');
        // Règle "attrape-tout" qui devrait matcher si l'opération était traitée.
        $this->addRule($highTech, 'MONSHOP', 10);
        $manual = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB MONSHOP', 'MONSHOP');
        $manual->categorize($courses, CategorizationSource::Manual);
        $this->entityManager->flush();

        $report = $this->categorizer->categorize([$manual], new CategorizeOptions(onlyUncategorized: false));

        self::assertSame(0, $report->processed);
        $this->entityManager->refresh($manual);
        self::assertSame($courses->getId(), $manual->getCategory()?->getId());
        self::assertSame(CategorizationSource::Manual, $manual->getCategorizationSource());
    }

    public function testInternalTransferLinkedOperationsAreIgnored(): void
    {
        $transfer = new InternalTransfer(TransferStatus::Auto);
        $this->entityManager->persist($transfer);
        $operation = $this->persistOperation('2026-01-10', -1000, 'VIREMENT DE A VERS B', 'A VERS B');
        $transfer->addOperation($operation);
        $this->entityManager->flush();

        $report = $this->categorizer->categorize([$operation], new CategorizeOptions(onlyUncategorized: false));

        self::assertSame(0, $report->processed);
    }

    public function testOnlyUncategorizedSkipsAlreadyCategorized(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $operation = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB X', 'X');
        $operation->categorize($courses, CategorizationSource::Rule);
        $this->entityManager->flush();

        $report = $this->categorizer->categorize([$operation], new CategorizeOptions(onlyUncategorized: true));
        self::assertSame(0, $report->processed);

        $reportAll = $this->categorizer->categorize([$operation], new CategorizeOptions(onlyUncategorized: false));
        self::assertSame(1, $reportAll->processed);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $highTech = $this->category('Shopping › High-tech');
        $this->addRule($highTech, 'MONSHOP', 10);
        $candidate = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB MONSHOP', 'MONSHOP');
        $id = $candidate->getId();

        $report = $this->categorizer->categorize([$candidate], new CategorizeOptions(dryRun: true));
        self::assertSame(1, $report->byRule);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Operation::class, $id);
        self::assertNull($reloaded?->getCategory());
    }

    public function testResetUnmatchedRemovesStaleAutomaticCategory(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $operation = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB INCONNU', 'INCONNU');
        $operation->categorize($courses, CategorizationSource::Rule);
        $this->entityManager->flush();
        $id = $operation->getId();

        $report = $this->categorizer->categorize(
            [$operation],
            new CategorizeOptions(onlyUncategorized: false, resetUnmatched: true),
        );
        self::assertSame(1, $report->stillUncategorized);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Operation::class, $id)?->getCategory());
    }

    public function testWithoutResetUnmatchedStaleCategoryIsKept(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $operation = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB INCONNU', 'INCONNU');
        $operation->categorize($courses, CategorizationSource::Rule);
        $this->entityManager->flush();
        $id = $operation->getId();

        $report = $this->categorizer->categorize(
            [$operation],
            new CategorizeOptions(onlyUncategorized: false, resetUnmatched: false),
        );
        self::assertSame(1, $report->unchanged);
        $this->entityManager->clear();
        self::assertSame($courses->getId(), $this->entityManager->find(Operation::class, $id)?->getCategory()?->getId());
    }

    public function testAccountIdFilter(): void
    {
        $highTech = $this->category('Shopping › High-tech');
        $this->addRule($highTech, 'MONSHOP', 10);
        $onA = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB MONSHOP', 'MONSHOP', $this->accountA);
        $onB = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB MONSHOP', 'MONSHOP', $this->accountB);

        $report = $this->categorizer->categorize(
            [$onA, $onB],
            new CategorizeOptions(accountId: $this->accountB->getId()),
        );

        self::assertSame(1, $report->processed);
        self::assertSame(1, $report->byRule);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Operation::class, $onA->getId())?->getCategory());
        self::assertNotNull($this->entityManager->find(Operation::class, $onB->getId())?->getCategory());
    }

    public function testDisabledRulesAreIgnored(): void
    {
        $highTech = $this->category('Shopping › High-tech');
        $rule = $this->addRule($highTech, 'MONSHOP', 10);
        $rule->setEnabled(false);
        $this->entityManager->flush();
        $candidate = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB MONSHOP', 'MONSHOP');

        $report = $this->categorizer->categorize([$candidate], new CategorizeOptions());

        self::assertSame(0, $report->byRule);
        self::assertSame(1, $report->stillUncategorized);
    }

    public function testRuleOrderingPriorityDescThenIdAsc(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $highTech = $this->category('Shopping › High-tech');
        // insérée en première mais priorité plus faible
        $this->addRule($courses, 'MONSHOP', 10);
        $this->addRule($highTech, 'MONSHOP', 50);
        $candidate = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB MONSHOP', 'MONSHOP');

        $this->categorizer->categorize([$candidate], new CategorizeOptions());

        $this->entityManager->refresh($candidate);
        self::assertSame($highTech->getId(), $candidate->getCategory()?->getId());
    }

    public function testSimilarityFallsBackToNormalizedLabelWhenNoMerchant(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $manual = $this->persistOperation('2026-01-10', -1000, 'ACHAT CB CARREFOUR MARKET TOULOUSE', null);
        $manual->categorize($courses, CategorizationSource::Manual);
        $this->entityManager->flush();

        $candidate = $this->persistOperation('2026-01-11', -2000, 'ACHAT CB CARREFOUR MARKET TOULOUSE', null);
        $report = $this->categorizer->categorize([$candidate], new CategorizeOptions());

        self::assertSame(1, $report->bySimilarity);
        $this->entityManager->refresh($candidate);
        self::assertSame($courses->getId(), $candidate->getCategory()?->getId());
    }

    public function testPlusieursLotsAvecClearEntreLesLots(): void
    {
        // Régression (données réelles) : après le clear() d'un lot, les règles chargées étaient détachées
        // et la catégorisation échouait (« new entity found through relationship »).
        $courses = $this->category('Alimentation › Courses');
        $this->addRule($courses, 'MONSHOP', 10);
        for ($i = 1; $i <= 5; ++$i) {
            $this->persistOperation(sprintf('2026-01-%02d', $i), -100 * $i, 'ACHAT CB MONSHOP', 'MONSHOP');
        }
        $this->entityManager->clear();

        $categorizer = new Categorizer($this->entityManager, new SimilarityFinder($this->entityManager->getConnection()), 2);
        $operations = $this->entityManager->createQuery('SELECT o FROM App\Entity\Operation o ORDER BY o.id')->toIterable();
        $report = $categorizer->categorize($operations, new CategorizeOptions());

        self::assertSame(5, $report->byRule);
        $this->entityManager->clear();
        $categorized = (int) $this->entityManager->createQuery('SELECT COUNT(o.id) FROM App\Entity\Operation o WHERE o.category IS NOT NULL')->getSingleScalarResult();
        self::assertSame(5, $categorized);
    }
}
