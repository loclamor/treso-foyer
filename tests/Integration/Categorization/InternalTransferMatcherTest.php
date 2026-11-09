<?php

declare(strict_types=1);

namespace App\Tests\Integration\Categorization;

use App\Categorization\InternalTransferMatcher;
use App\Entity\Account;
use App\Entity\Household;
use App\Entity\InternalTransfer;
use App\Entity\Operation;
use App\Enum\CategorizationSource;
use App\Enum\TransferStatus;
use App\Setup\CategoryResolver;
use App\Tests\Support\CategorizationFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InternalTransferMatcherTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private InternalTransferMatcher $matcher;
    private Household $household;
    private Account $accountA;
    private Account $accountB;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->matcher = $container->get(InternalTransferMatcher::class);

        $this->household = new Household('Foyer test');
        $this->entityManager->persist($this->household);
        CategorizationFixtures::seedCategories($this->entityManager, $this->household);
        $this->accountA = CategorizationFixtures::makeAccount($this->household, 'perso-a');
        $this->accountB = CategorizationFixtures::makeAccount($this->household, 'perso-b');
        $this->entityManager->persist($this->accountA);
        $this->entityManager->persist($this->accountB);
        $this->entityManager->flush();
    }

    private function persistOperation(Account $account, string $date, int $amount, string $label): Operation
    {
        $operation = CategorizationFixtures::makeOperation($account, $date, $amount, $label);
        $this->entityManager->persist($operation);
        $this->entityManager->flush();

        return $operation;
    }

    private function transferCategoryId(): int
    {
        return $this->getContainer()->get(CategoryResolver::class)
            ->require($this->household, InternalTransferMatcher::TRANSFER_CATEGORY)->getId();
    }

    public function testNominalPairMatchedAcrossAccounts(): void
    {
        $debit = $this->persistOperation($this->accountA, '2026-01-10', -100000, 'VIREMENT DE PERSO A VERS COMMUN');
        $credit = $this->persistOperation($this->accountB, '2026-01-11', 100000, 'VIREMENT DE PERSO A VERS COMMUN');

        $report = $this->matcher->match($this->household, days: 3);

        self::assertSame(1, $report->matched);
        self::assertSame(0, $report->ambiguous);
        $this->entityManager->refresh($debit);
        $this->entityManager->refresh($credit);
        self::assertNotNull($debit->getInternalTransfer());
        self::assertSame($debit->getInternalTransfer()?->getId(), $credit->getInternalTransfer()?->getId());
        self::assertSame(TransferStatus::Auto, $debit->getInternalTransfer()?->getStatus());
        self::assertTrue($debit->getInternalTransfer()?->isBalanced());
        self::assertSame($this->transferCategoryId(), $debit->getCategory()?->getId());
        self::assertSame(CategorizationSource::Transfer, $debit->getCategorizationSource());
        self::assertSame(CategorizationSource::Transfer, $credit->getCategorizationSource());
    }

    public function testMatchesBeyondOneBatch(): void
    {
        // Lot de 2 pour 3 paires : le flush après clear() ne doit pas échouer sur la catégorie détachée.
        $matcher = new InternalTransferMatcher(
            $this->entityManager,
            $this->getContainer()->get(CategoryResolver::class),
            batchSize: 2,
        );
        $debitIds = [];
        foreach ([10000, 20000, 30000] as $i => $amount) {
            $debitIds[] = $this->persistOperation($this->accountA, '2026-01-1'.$i, -$amount, 'VIREMENT VERS COMMUN')->getId();
            $this->persistOperation($this->accountB, '2026-01-1'.$i, $amount, 'VIREMENT DEPUIS PERSO');
        }

        $report = $matcher->match($this->household, days: 3);

        self::assertSame(3, $report->matched);
        $this->entityManager->clear();
        foreach ($debitIds as $debitId) {
            $debit = $this->entityManager->find(Operation::class, $debitId);
            self::assertNotNull($debit?->getInternalTransfer());
            self::assertSame($this->transferCategoryId(), $debit->getCategory()?->getId());
        }
    }

    public function testDifferentAmountsDoNotMatch(): void
    {
        $this->persistOperation($this->accountA, '2026-01-10', -100000, 'VIREMENT DE PERSO A VERS COMMUN');
        $this->persistOperation($this->accountB, '2026-01-11', 99999, 'VIREMENT DE PERSO A VERS COMMUN');

        $report = $this->matcher->match($this->household);

        self::assertSame(0, $report->matched);
    }

    public function testSameAccountDoesNotMatch(): void
    {
        $this->persistOperation($this->accountA, '2026-01-10', -100000, 'VIREMENT DE A VERS A');
        $this->persistOperation($this->accountA, '2026-01-10', 100000, 'VIREMENT DE A VERS A');

        self::assertSame(0, $this->matcher->match($this->household)->matched);
    }

    public function testDateWindowIsRespected(): void
    {
        $this->persistOperation($this->accountA, '2026-01-10', -100000, 'VIREMENT DE PERSO A VERS COMMUN');
        $this->persistOperation($this->accountB, '2026-01-14', 100000, 'VIREMENT DE PERSO A VERS COMMUN');

        self::assertSame(0, $this->matcher->match($this->household, days: 3)->matched);
        self::assertSame(1, $this->matcher->match($this->household, days: 4)->matched);
    }

    public function testMultipleCandidatesAreAmbiguous(): void
    {
        $this->persistOperation($this->accountA, '2026-01-10', -100000, 'VIREMENT DE PERSO A VERS COMMUN');
        $this->persistOperation($this->accountB, '2026-01-11', 100000, 'ACHAT CB COMMUN A');
        $this->persistOperation($this->accountB, '2026-01-12', 100000, 'ACHAT CB COMMUN B');

        $report = $this->matcher->match($this->household, days: 3);

        self::assertSame(0, $report->matched);
        self::assertSame(1, $report->ambiguous);
    }

    public function testVirementLabelPreferredAmongSeveralCandidates(): void
    {
        $this->persistOperation($this->accountA, '2026-01-10', -100000, 'ACHAT CB DIVERS');
        $this->persistOperation($this->accountB, '2026-01-11', 100000, 'ACHAT CB COMMUN A');
        $this->persistOperation($this->accountB, '2026-01-12', 100000, 'VIREMENT DE PERSO A VERS COMMUN');

        $report = $this->matcher->match($this->household, days: 3);

        self::assertSame(1, $report->matched);
        self::assertSame(0, $report->ambiguous);
    }

    public function testAlreadyLinkedOperationsAreIgnored(): void
    {
        $debit = $this->persistOperation($this->accountA, '2026-01-10', -100000, 'VIREMENT DE PERSO A VERS COMMUN');
        $credit = $this->persistOperation($this->accountB, '2026-01-11', 100000, 'VIREMENT DE PERSO A VERS COMMUN');
        self::assertSame(1, $this->matcher->match($this->household)->matched);

        // Second passage : la paire est déjà liée, rien de nouveau.
        $report = $this->matcher->match($this->household);
        self::assertSame(0, $report->matched);
        self::assertCount(1, $this->entityManager->getRepository(InternalTransfer::class)->findAll());
        $this->entityManager->refresh($debit);
        self::assertNotNull($debit->getInternalTransfer());
        self::assertSame($credit->getInternalTransfer()?->getId(), $debit->getInternalTransfer()?->getId());
    }

    public function testManuallyCategorizedInOtherCategoryIsExcluded(): void
    {
        $courses = $this->getContainer()->get(CategoryResolver::class)->require($this->household, 'Alimentation › Courses');
        $debit = $this->persistOperation($this->accountA, '2026-01-10', -100000, 'VIREMENT DE PERSO A VERS COMMUN');
        $debit->categorize($courses, CategorizationSource::Manual);
        $this->entityManager->flush();
        $this->persistOperation($this->accountB, '2026-01-11', 100000, 'VIREMENT DE PERSO A VERS COMMUN');

        self::assertSame(0, $this->matcher->match($this->household)->matched);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $this->persistOperation($this->accountA, '2026-01-10', -100000, 'VIREMENT DE PERSO A VERS COMMUN');
        $this->persistOperation($this->accountB, '2026-01-11', 100000, 'VIREMENT DE PERSO A VERS COMMUN');

        $report = $this->matcher->match($this->household, dryRun: true);

        self::assertSame(1, $report->matched);
        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(InternalTransfer::class)->findAll());
        self::assertNull($this->entityManager->getRepository(Operation::class)->findOneBy([])?->getInternalTransfer());
    }

    public function testUnlinkRemovesLinkAndTransferCategory(): void
    {
        $debit = $this->persistOperation($this->accountA, '2026-01-10', -100000, 'VIREMENT DE PERSO A VERS COMMUN');
        $credit = $this->persistOperation($this->accountB, '2026-01-11', 100000, 'VIREMENT DE PERSO A VERS COMMUN');
        $this->matcher->match($this->household);
        $this->entityManager->refresh($debit);
        $transfer = $debit->getInternalTransfer();
        self::assertNotNull($transfer);

        $this->matcher->unlink($transfer);

        $this->entityManager->clear();
        self::assertCount(0, $this->entityManager->getRepository(InternalTransfer::class)->findAll());
        $reloadedDebit = $this->entityManager->find(Operation::class, $debit->getId());
        $reloadedCredit = $this->entityManager->find(Operation::class, $credit->getId());
        self::assertNull($reloadedDebit?->getInternalTransfer());
        self::assertNull($reloadedDebit?->getCategory());
        self::assertNull($reloadedCredit?->getCategory());
        self::assertNull($reloadedCredit?->getCategorizationSource());
    }
}
