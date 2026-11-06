<?php

declare(strict_types=1);

namespace App\Tests\Integration\Reconciliation;

use App\Categorization\InternalTransferMatcher;
use App\Entity\Account;
use App\Entity\CategorizationRule;
use App\Entity\Household;
use App\Entity\Operation;
use App\Entity\Vehicle;
use App\Enum\CategorizationSource;
use App\Enum\ImportFormat;
use App\Enum\RuleMatchField;
use App\Enum\RuleMatchType;
use App\Enum\VehicleSource;
use App\Enum\VehicleType;
use App\Import\LabelNormalizer;
use App\Import\Model\ImportOptions;
use App\Import\PostImportProcessor;
use App\Import\StatementImporter;
use App\Recurrence\RecurrenceDetector;
use App\Reconciliation\ManualEntryReconciler;
use App\Security\AccountNumberHasher;
use App\Tests\Support\CategorizationFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Rapprochement des saisies manuelles avec les lignes de relevé (ADR 0014) :
 * appariement automatique univoque (montant exact, fenêtre −2/+10 j), ambiguïtés,
 * merge (catégorie/véhicule/note/spentAt), exclusions (doublons flous, virements, récurrences),
 * import d'un relevé qui absorbe une saisie, candidats de l'appariement manuel.
 */
final class ManualEntryReconcilerTest extends KernelTestCase
{
    private const FIXTURE_CSV = __DIR__.'/../../Fixtures/csv/releve-a.csv';

    private EntityManagerInterface $entityManager;
    private ManualEntryReconciler $reconciler;
    private Household $household;
    private Account $account;
    private Account $otherAccount;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->reconciler = $container->get(ManualEntryReconciler::class);

        $this->household = new Household('Foyer test');
        $this->entityManager->persist($this->household);
        // Compte « ccp-perso » = même empreinte HMAC que le n° 1234567R999 des fixtures CSV :
        // l'import du relevé le résout sans créer de compte (comme dans ApiTestCase).
        $hasher = self::getContainer()->get(AccountNumberHasher::class);
        $this->account = new Account(
            $this->household,
            'ccp-perso',
            'Compte '.$hasher->mask('1234567R999'),
            \App\Enum\AccountType::Personal,
            $hasher->hash('1234567R999'),
            $hasher->mask('1234567R999'),
        );
        $this->entityManager->persist($this->account);
        $this->otherAccount = CategorizationFixtures::makeAccount($this->household, 'compte-joint');
        $this->entityManager->persist($this->otherAccount);
        CategorizationFixtures::seedCategories($this->entityManager, $this->household);
        $this->entityManager->flush();
    }

    /** Crée une saisie manuelle en attente (comme POST /api/operations). */
    private function makeEntry(string $spentAt, int $amount, string $label, ?Account $account = null): Operation
    {
        $account ??= $this->account;
        $managed = $this->entityManager->find(Account::class, $account->getId()) ?? $account;
        $normalized = self::getContainer()->get(LabelNormalizer::class)->normalize($label);
        $entry = Operation::manualEntry(
            $managed,
            new \DateTimeImmutable($spentAt),
            $amount,
            $label,
            $normalized->normalized,
            $normalized->merchant,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    /** Crée une ligne de relevé (importée : label_source renseigné, en base). */
    private function makeStatementLine(string $date, int $amount, string $rawLabel, ?Account $account = null, ?string $merchant = null): Operation
    {
        $account ??= $this->account;
        $managed = $this->entityManager->find(Account::class, $account->getId()) ?? $account;
        $line = CategorizationFixtures::makeOperation($managed, $date, $amount, $rawLabel, $merchant);
        $line->setLabelSource(ImportFormat::Csv);
        $this->entityManager->persist($line);
        $this->entityManager->flush();

        return $line;
    }

    private function reload(Operation $operation): Operation
    {
        $this->entityManager->clear();

        return $this->entityManager->find(Operation::class, $operation->getId());
    }

    public function testReconcileUnivoque(): void
    {
        $entry = $this->makeEntry('2026-08-25', -2756, 'ACHAT CB SUPERMARCHE 25.08.26');
        $entryId = (int) $entry->getId();
        $line = $this->makeStatementLine('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE EXEMPLE 25.08.26 CARTE NUMERO 123', null, 'SUPERMARCHE EXEMPLE');
        $lineId = (int) $line->getId();

        $report = $this->reconciler->reconcile($this->household);

        self::assertSame(1, $report->matched);
        self::assertSame(0, $report->ambiguous);
        self::assertSame(0, $report->pending);
        self::assertSame([$entryId => $lineId], $report->matchedLines);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Operation::class, $entryId));
        $merged = $this->entityManager->find(Operation::class, $line->getId());
        self::assertNotNull($merged);
        self::assertNotNull($merged->getReconciledAt());
        self::assertFalse($merged->isAwaitingStatement());
        // La ligne n'avait pas de spentAt extrait de son libellé : celui de la saisie est repris.
        self::assertSame('2026-08-25', $merged->getSpentAt()?->format('Y-m-d'));
        // Valeurs du relevé conservées.
        self::assertSame('2026-08-26', $merged->getDate()->format('Y-m-d'));
        self::assertSame(-2756, $merged->getAmount());
    }

    public function testReconcileSpentAtDeLaLignePrioritaire(): void
    {
        $entry = $this->makeEntry('2026-08-24', -500, 'SUPER MARKET');
        $line = $this->makeStatementLine('2026-08-26', -500, 'ACHAT CB "SUPER; MARKET" 26.08.26 CARTE NUMERO 321', null, 'SUPER MARKET');
        // La ligne a un spentAt extrait de son libellé CB (26.08.26, égal à sa date : conservé).
        $line->setSpentAt(new \DateTimeImmutable('2026-08-26'));
        $this->entityManager->flush();

        $report = $this->reconciler->reconcile($this->household);
        self::assertSame(1, $report->matched);

        $merged = $this->reload($line);
        // Celui de la ligne (extrait du libellé CB) gagne sur celui de la saisie.
        self::assertSame('2026-08-26', $merged->getSpentAt()?->format('Y-m-d'));
    }

    public function testReconcileIgnoreUneLigneCbDontLaDateDAchatEstEloignee(): void
    {
        // Saisie du 06/11 ; la ligne débitée le 08/11 (dans la fenêtre) est un AUTRE achat du même
        // montant fait le 01/11 : sa date CB l'exclut, la saisie reste en attente.
        $entry = $this->makeEntry('2026-11-06', -1200, 'BOULANGERIE');
        $line = $this->makeStatementLine('2026-11-08', -1200, 'ACHAT CB BOULANGERIE 01.11.26', null, 'BOULANGERIE');
        $line->setSpentAt(new \DateTimeImmutable('2026-11-01'));
        $this->entityManager->flush();

        $report = $this->reconciler->reconcile($this->household);

        self::assertSame(0, $report->matched);
        self::assertSame(1, $report->pending);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Operation::class, $entry->getId()));
    }

    public function testReconcileAccepteUneLigneCbDontLaDateDAchatEstProche(): void
    {
        // Saisie datée du 06/11 alors que l'achat (date CB) est du 05/11 : tolérance de 2 jours.
        $this->makeEntry('2026-11-06', -1200, 'BOULANGERIE');
        $line = $this->makeStatementLine('2026-11-08', -1200, 'ACHAT CB BOULANGERIE 05.11.26', null, 'BOULANGERIE');
        $line->setSpentAt(new \DateTimeImmutable('2026-11-05'));
        $this->entityManager->flush();

        $report = $this->reconciler->reconcile($this->household);

        self::assertSame(1, $report->matched);
    }

    public function testReconcileAmbiguDeuxSaisiesUneLigne(): void
    {
        $entryA = $this->makeEntry('2026-08-25', -2756, 'ACHAT CB SUPERMARCHE 25.08.26');
        $entryB = $this->makeEntry('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26');
        $this->makeStatementLine('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE EXEMPLE 25.08.26', null, 'SUPERMARCHE EXEMPLE');

        $report = $this->reconciler->reconcile($this->household);

        // La ligne est candidate des deux saisies : aucune paire univoque.
        self::assertSame(0, $report->matched);
        self::assertSame(2, $report->ambiguous);
        self::assertSame(0, $report->pending);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Operation::class, $entryA->getId()));
        self::assertNotNull($this->entityManager->find(Operation::class, $entryB->getId()));
    }

    public function testReconcileAmbigueUneSaisieDeuxLignes(): void
    {
        $entry = $this->makeEntry('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26');
        $this->makeStatementLine('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE EXEMPLE 25.08.26', null, 'SUPERMARCHE EXEMPLE');
        $this->makeStatementLine('2026-08-27', -2756, 'ACHAT CB SUPERMARCHE EXEMPLE 26.08.26', null, 'SUPERMARCHE EXEMPLE');

        $report = $this->reconciler->reconcile($this->household);

        self::assertSame(0, $report->matched);
        self::assertSame(1, $report->ambiguous);
        self::assertSame(0, $report->pending);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Operation::class, $entry->getId()));
    }

    public function testReconcileHorsFenetre(): void
    {
        $entry = $this->makeEntry('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26');
        // +11 jours : hors fenêtre automatique (+10 j).
        $this->makeStatementLine('2026-09-06', -2756, 'ACHAT CB SUPERMARCHE 06.09.26', null, 'SUPERMARCHE');
        // −3 jours : hors fenêtre (−2 j).
        $this->makeStatementLine('2026-08-23', -2756, 'ACHAT CB SUPERMARCHE 23.08.26', null, 'SUPERMARCHE');
        // Fenêtre ok mais autre compte : pas candidat.
        $this->makeStatementLine('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26', $this->otherAccount, 'SUPERMARCHE');

        $report = $this->reconciler->reconcile($this->household);

        self::assertSame(0, $report->matched);
        self::assertSame(0, $report->ambiguous);
        self::assertSame(1, $report->pending);
    }

    public function testReconcileMontantDifferent(): void
    {
        $entry = $this->makeEntry('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26');
        $this->makeStatementLine('2026-08-26', -2700, 'ACHAT CB SUPERMARCHE 26.08.26', null, 'SUPERMARCHE');

        $report = $this->reconciler->reconcile($this->household);

        self::assertSame(0, $report->matched);
        self::assertSame(0, $report->ambiguous);
        self::assertSame(1, $report->pending);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Operation::class, $entry->getId()));
    }

    public function testReconcileLigneDejaRapprocheeExclue(): void
    {
        $entry = $this->makeEntry('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26');
        $line = $this->makeStatementLine('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26', null, 'SUPERMARCHE');
        $line->setReconciledAt(new \DateTimeImmutable('2026-08-27 10:00:00'));
        $this->entityManager->flush();

        $report = $this->reconciler->reconcile($this->household);

        self::assertSame(1, $report->pending);
        self::assertSame(0, $report->matched);
    }

    public function testMergeCopieCategorieVehiculeNoteEtSpentAt(): void
    {
        $courses = self::getContainer()->get(\App\Setup\CategoryResolver::class)->require($this->household, 'Alimentation › Courses');
        $carburant = self::getContainer()->get(\App\Setup\CategoryResolver::class)->require($this->household, 'Transport › Carburant');
        $rule = new CategorizationRule($this->household, $courses, RuleMatchField::Merchant, RuleMatchType::Exact, 'AMAZON');
        $this->entityManager->persist($rule);
        $vehicle = new Vehicle($this->household, 'Clio', VehicleType::Car);
        $this->entityManager->persist($vehicle);

        $entry = $this->makeEntry('2026-10-20', -4599, 'Achat Amazon ce mois-ci');
        $entry->categorize($courses, CategorizationSource::Manual, $rule);
        $entry->assignVehicle($vehicle, VehicleSource::Manual);
        $entry->setNote('Gros achat en ligne');
        $this->entityManager->flush();

        // La ligne a déjà une note et un libellé différent ; catégorie déjà manuelle : non écrasée.
        $line = $this->makeStatementLine('2026-10-22', -4599, 'ACHAT CB AMAZON EU 20.10.26 CARTE NUMERO 9', null, 'AMAZON EU');
        $line->categorize($carburant, CategorizationSource::Manual);
        $line->setNote('Note du relevé');
        $line->setSpentAt(new \DateTimeImmutable('2026-10-20'));
        $this->entityManager->flush();

        $entryId = (int) $entry->getId();
        $lineId = (int) $line->getId();
        $merged = $this->reconciler->merge($entry, $line);

        self::assertSame($lineId, (int) $merged->getId());
        // Catégorie manuelle de la ligne conservée (la saisie ne l'écrase pas).
        self::assertSame((int) $carburant->getId(), (int) $merged->getCategory()?->getId());
        // Véhicule de la saisie copié (la ligne n'était ni manual ni ignored).
        self::assertSame((int) $vehicle->getId(), (int) $merged->getVehicle()?->getId());
        self::assertSame(VehicleSource::Manual, $merged->getVehicleSource());
        // Note : « ligne — saisie » + libellé saisi différent.
        self::assertSame("Note du relevé — Gros achat en ligne\nSaisie : Achat Amazon ce mois-ci", $merged->getNote());
        // spentAt de la ligne (extrait du libellé CB) prioritaire.
        self::assertSame('2026-10-20', $merged->getSpentAt()?->format('Y-m-d'));
        self::assertNotNull($merged->getReconciledAt());
        // Valeurs du relevé conservées.
        self::assertSame('2026-10-22', $merged->getDate()->format('Y-m-d'));
        self::assertSame(-4599, $merged->getAmount());
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Operation::class, $entryId));
    }

    public function testMergeCopieCategorieAvecSourceEtRegleSiLigneSansManuelle(): void
    {
        $courses = self::getContainer()->get(\App\Setup\CategoryResolver::class)->require($this->household, 'Alimentation › Courses');
        $rule = new CategorizationRule($this->household, $courses, RuleMatchField::Merchant, RuleMatchType::Exact, 'AMAZON');
        $this->entityManager->persist($rule);

        $entry = $this->makeEntry('2026-10-20', -4599, 'Achat Amazon');
        // Saisie catégorisée par règle (source « rule ») : copiée telle quelle avec sa règle.
        $entry->categorize($courses, CategorizationSource::Rule, $rule);
        $this->entityManager->flush();
        $line = $this->makeStatementLine('2026-10-22', -4599, 'ACHAT CB AMAZON EU 20.10.26', null, 'AMAZON EU');

        $merged = $this->reconciler->merge($entry, $line);

        self::assertSame((int) $courses->getId(), (int) $merged->getCategory()?->getId());
        self::assertSame(CategorizationSource::Rule, $merged->getCategorizationSource());
        self::assertSame((int) $rule->getId(), (int) $merged->getAppliedRule()?->getId());
        // Pas de note sur la saisie, libellé identique au libellé saisi ? non : libellés différents.
        self::assertSame('Saisie : Achat Amazon', $merged->getNote());
    }

    public function testMergeRefuseHorsPreconditions(): void
    {
        $entry = $this->makeEntry('2026-08-26', -100, 'Saisie');
        $line = $this->makeStatementLine('2026-08-26', -100, 'ACHAT CB X 26.08.26', null, 'X');

        // Cible déjà rapprochée.
        $line->setReconciledAt(new \DateTimeImmutable());
        $this->entityManager->flush();
        try {
            $this->reconciler->merge($entry, $line);
            self::fail('InvalidArgumentException attendue.');
        } catch (\InvalidArgumentException) {
        }

        // Cible = une autre saisie en attente.
        $otherEntry = $this->makeEntry('2026-08-26', -200, 'Autre saisie');
        try {
            $this->reconciler->merge($entry, $otherEntry);
            self::fail('InvalidArgumentException attendue.');
        } catch (\InvalidArgumentException) {
        }

        // Saisie non en attente.
        $imported = $this->makeStatementLine('2026-08-26', -300, 'ACHAT CB Y 26.08.26', null, 'Y');
        try {
            $this->reconciler->merge($imported, $line);
            self::fail('InvalidArgumentException attendue.');
        } catch (\InvalidArgumentException) {
        }

        // Foyer différent.
        $otherHousehold = new Household('Autre foyer');
        $this->entityManager->persist($otherHousehold);
        $foreignAccount = CategorizationFixtures::makeAccount($otherHousehold, 'etranger');
        $this->entityManager->persist($foreignAccount);
        $this->entityManager->flush();
        $foreignLine = $this->makeStatementLine('2026-08-26', -100, 'ACHAT CB Z 26.08.26', $foreignAccount, 'Z');
        $this->entityManager->clear();
        $entry = $this->entityManager->find(Operation::class, $entry->getId());
        self::assertNotNull($entry);
        try {
            $this->reconciler->merge($entry, $foreignLine);
            self::fail('InvalidArgumentException attendue.');
        } catch (\InvalidArgumentException) {
        }
    }

    public function testCandidatesOrdreEtFenetres(): void
    {
        $entry = $this->makeEntry('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26');
        // Montant exact, même compte, +1 j → en tête.
        $exactSameAccount = $this->makeStatementLine('2026-08-27', -2756, 'ACHAT CB SUPERMARCHE 27.08.26', null, 'SUPERMARCHE');
        // Montant exact, autre compte → ensuite.
        $exactOtherAccount = $this->makeStatementLine('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26', $this->otherAccount, 'SUPERMARCHE');
        // Montant différent, même compte → après (écart de montant croissant).
        $otherAmount = $this->makeStatementLine('2026-08-26', -3000, 'ACHAT CB SUPERMARCHE 26.08.26', null, 'SUPERMARCHE');
        // Hors fenêtre manuelle (+30 j) : exclue.
        $this->makeStatementLine('2026-09-27', -2756, 'ACHAT CB SUPERMARCHE 27.09.26', null, 'SUPERMARCHE');
        // Sens opposé : exclue.
        $this->makeStatementLine('2026-08-26', 2756, 'AVOIR CARTE SUPERMARCHE 26.08.26', null, 'SUPERMARCHE');
        // Une autre saisie en attente : exclue.
        $this->makeEntry('2026-08-26', -2756, 'Autre saisie en attente');
        // Ligne déjà rapprochée : exclue.
        $reconciled = $this->makeStatementLine('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26', null, 'SUPERMARCHE');
        $reconciled->setReconciledAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        $candidates = $this->reconciler->candidates($entry);
        $ids = array_map(static fn (Operation $o): int => (int) $o->getId(), $candidates);

        self::assertSame(
            [(int) $exactSameAccount->getId(), (int) $exactOtherAccount->getId(), (int) $otherAmount->getId()],
            $ids,
        );
    }

    public function testImportDuReleveAbsorbeLaSaisie(): void
    {
        $container = self::getContainer();
        /** @var StatementImporter $importer */
        $importer = $container->get(StatementImporter::class);
        $report = $importer->import(self::FIXTURE_CSV, new ImportOptions(autoCreateAccount: true));
        self::assertSame(7, $report->added);

        $this->entityManager->clear();
        $account = $this->entityManager->getRepository(Account::class)->findOneBy(['alias' => 'ccp-perso']);
        self::assertNotNull($account);
        // La ligne -500 du 26/08/2026 a un spentAt extrait du libellé CB (26.08.26 = sa date).
        $line = $this->entityManager->getRepository(Operation::class)->findOneBy(['account' => $account, 'amount' => -500]);
        self::assertNotNull($line);
        self::assertSame('2026-08-26', $line->getSpentAt()?->format('Y-m-d'));

        // Saisie de la même dépense, faite avant l'import du relevé.
        $entry = $this->makeEntry('2026-08-24', -500, 'SUPER MARKET', $account);
        $entryId = (int) $entry->getId();

        // Post-traitement d'import : le rapprochement passe en premier.
        $postReport = $container->get(PostImportProcessor::class)->process($this->household);

        self::assertSame(1, $postReport->reconciliation->matched);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Operation::class, $entryId));
        $merged = $this->entityManager->find(Operation::class, $line->getId());
        self::assertNotNull($merged);
        self::assertNotNull($merged->getReconciledAt());
        // spentAt de la ligne (extrait du libellé CB) prioritaire sur celui de la saisie (24.08).
        self::assertSame('2026-08-26', $merged->getSpentAt()?->format('Y-m-d'));
        self::assertSame('2026-08-26', $merged->getDate()->format('Y-m-d'));
    }

    public function testSaisieJamaisCandidateAuDoublonFlou(): void
    {
        // Saisie en attente, montant/date/libellé très proches d'une ligne du relevé à importer.
        $this->makeEntry('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE EXEMPLE 26.08.26 CARTE NUMERO 999');

        $importer = self::getContainer()->get(StatementImporter::class);
        $report = $importer->import(self::FIXTURE_CSV, new ImportOptions(autoCreateAccount: true));
        self::assertSame(7, $report->added);
        // Sans l'exclusion, les lignes -2756 du 26/08 seraient marquées « doublon probable » de la saisie.
        self::assertSame(0, $report->probableDuplicates);

        $this->entityManager->clear();
        foreach ($this->entityManager->getRepository(Operation::class)->findAll() as $operation) {
            if (!$operation->isAwaitingStatement()) {
                self::assertSame(\App\Enum\DuplicateStatus::None, $operation->getDuplicateStatus());
            }
        }
    }

    public function testSaisieExclueDesVirementsInternes(): void
    {
        $entry = $this->makeEntry('2026-08-26', -10000, 'VIREMENT VERS COMPTE JOINT');
        $credit = $this->makeStatementLine('2026-08-26', 10000, 'VIREMENT DE CCP PERSO', $this->otherAccount, 'CCP PERSO');

        $matcher = self::getContainer()->get(InternalTransferMatcher::class);
        $report = $matcher->match($this->household);

        // La saisie en attente n'est jamais appariée comme virement interne.
        self::assertSame(0, $report->matched);
        self::assertSame(0, $report->ambiguous);
        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Operation::class, $credit->getId())?->getInternalTransfer());

        // Contrôle : une paire classique (hors saisie) est bien appariée.
        $this->makeStatementLine('2026-08-26', -10000, 'VIREMENT VERS COMPTE JOINT', $this->account, 'COMPTE JOINT');
        $report = $matcher->match($this->household);
        self::assertSame(1, $report->matched);
    }

    public function testSaisieExclueDesRecurrences(): void
    {
        // 6 saisies mensuelles en attente (juin → nov. 2026, même commerçant, même montant).
        foreach (['2026-06-05', '2026-07-05', '2026-08-05', '2026-09-05', '2026-10-05', '2026-11-05'] as $date) {
            $this->makeEntry($date, -8500, 'PRELEVEMENT DE EDIFICE');
        }

        $detector = self::getContainer()->get(RecurrenceDetector::class);
        $report = $detector->sync($this->household, new \DateTimeImmutable('2026-11-06'));

        // Aucune série détectée à partir de saisies en attente.
        self::assertSame(0, $report->created);
        self::assertCount(0, $this->entityManager->getRepository(\App\Entity\RecurringSeries::class)->findAll());

        // Contrôle : les mêmes opérations en lignes de relevé forment bien une série.
        foreach (['2026-06-05', '2026-07-05', '2026-08-05', '2026-09-05', '2026-10-05', '2026-11-05'] as $date) {
            $this->makeStatementLine($date, -8500, 'PRELEVEMENT DE EDIFICE', null, 'EDIFICE');
        }
        $report = $detector->sync($this->household, new \DateTimeImmutable('2026-11-06'));
        self::assertGreaterThanOrEqual(1, $report->created);
    }

    public function testReconcileDryRunNeModifieRien(): void
    {
        $entry = $this->makeEntry('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26');
        $line = $this->makeStatementLine('2026-08-26', -2756, 'ACHAT CB SUPERMARCHE 26.08.26', null, 'SUPERMARCHE');

        $report = $this->reconciler->reconcile($this->household, dryRun: true);

        self::assertSame(1, $report->matched);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Operation::class, $entry->getId()));
        self::assertNull($this->entityManager->find(Operation::class, $line->getId())?->getReconciledAt());
    }
}
