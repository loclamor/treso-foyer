<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Account;
use App\Entity\ImportBatch;
use App\Entity\InternalTransfer;
use App\Entity\Statement;
use App\Enum\CategorizationSource;
use App\Enum\ImportFormat;
use App\Enum\ImportStatus;
use App\Enum\TransferStatus;
use Symfony\Component\HttpFoundation\Response;

/**
 * Statistiques (étape 8) : jeu fictif construit ici, valeurs exactes calculées à la main.
 *
 * Période de référence : 2026-01-01..2026-03-31.
 * Opérations du foyer :
 *  - perso 2026-01-05  -800  Courses (Alimentation)      MONSHOP
 *  - perso 2026-01-20  -200  Alimentation (racine)       BOULANGER
 *  - perso 2026-02-10 -1500  Logement › Énergie          EDF
 *  - perso 2026-02-15  +300  Courses (remboursement)     MONSHOP
 *  - perso 2026-03-01 +250000 Salaires                   EMPLOYEUR
 *  - perso 2026-03-05  -450  non catégorisé              INCONNU
 *  - perso 2026-03-10 -10000 Mouvements internes + virement interne apparié
 *  - joint 2026-03-10 +10000 Mouvements internes (apparié)
 *  - joint 2026-03-12  -600  Courses                     MONSHOP
 *  - joint 2026-03-20 +50000 Salaires                    EMPLOYEUR
 *  - perso 2025-02-15 -2000  Énergie  (compare=year)
 *  - perso 2025-02-20  -500  Courses  (compare=year)
 *  - perso 2025-06-15 -1000  Courses  (average12)
 * Relevés d'ancrage : perso closing 2025-12-31 = 100000 (opening 2025-11-30 = 90000),
 * perso closing 2026-02-28 = 97800, joint closing 2026-01-31 = 50000.
 */
final class StatsApiTest extends ApiTestCase
{
    private const string FROM = '2026-01-01';
    private const string TO = '2026-03-31';

    private function seedStatsData(): void
    {
        $courses = $this->category('Alimentation › Courses');
        $alimentation = $this->category('Alimentation');
        $energie = $this->category('Logement › Énergie');
        $salaires = $this->category('Revenus › Salaires');
        $mouvements = $this->category('Mouvements internes');

        $this->addStatement($this->account, '2025-12-31', 100000, '2025-11-30', 90000);
        $this->addStatement($this->account, '2026-02-28', 97800);
        $this->addStatement($this->jointAccount, '2026-01-31', 50000);

        $this->addOperation($this->account, '2026-01-05', -800, 'ACHAT CB MONSHOP 05/01', 'MONSHOP', $courses);
        $this->addOperation($this->account, '2026-01-20', -200, 'BOULANGERIE PAUL', 'BOULANGER', $alimentation);
        $this->addOperation($this->account, '2026-02-10', -1500, 'PRELEVEMENT EDF', 'EDF', $energie);
        $this->addOperation($this->account, '2026-02-15', 300, 'REMBOURSEMENT MONSHOP', 'MONSHOP', $courses);
        $this->addOperation($this->account, '2026-03-01', 250000, 'VIREMENT EMPLOYEUR', 'EMPLOYEUR', $salaires);
        $this->addOperation($this->account, '2026-03-05', -450, 'ACHAT CB INCONNU', 'INCONNU');
        $opOut = $this->addOperation($this->account, '2026-03-10', -10000, 'VIR INTERNE VERS COMMUN', 'VIRIN', $mouvements);
        $opIn = $this->addOperation($this->jointAccount, '2026-03-10', 10000, 'VIR INTERNE DEPUIS PERSO', 'VIRIN', $mouvements);
        $this->addOperation($this->jointAccount, '2026-03-12', -600, 'ACHAT CB MONSHOP 12/03', 'MONSHOP', $courses);
        $this->addOperation($this->jointAccount, '2026-03-20', 50000, 'VIREMENT EMPLOYEUR BOB', 'EMPLOYEUR', $salaires);
        // Hors période : compare=year (2025 Q1) et average12 (2025-03..2026-02).
        $this->addOperation($this->account, '2025-02-15', -2000, 'PRELEVEMENT EDF 2025', 'EDF', $energie);
        $this->addOperation($this->account, '2025-02-20', -500, 'ACHAT CB MONSHOP FEV 2025', 'MONSHOP', $courses);
        $this->addOperation($this->account, '2025-06-15', -1000, 'ACHAT CB MONSHOP 2025', 'MONSHOP', $courses);

        $transfer = new InternalTransfer(TransferStatus::Auto);
        $this->em()->persist($transfer);
        $opOut->setInternalTransfer($transfer);
        $opIn->setInternalTransfer($transfer);
        $this->em()->flush();
    }

    private function addStatement(Account $account, string $closingDate, int $closingBalance, ?string $openingDate = null, ?int $openingBalance = null): void
    {
        $em = $this->em();
        $managed = $em->find(Account::class, $account->getId()) ?? $account;
        $batch = new ImportBatch(
            'releve-'.$closingDate.'-'.$managed->getAlias().'.csv',
            hash('sha256', 'stats-'.$managed->getAlias().'-'.$closingDate),
            ImportFormat::Csv,
            ImportStatus::Completed,
        );
        $batch->setAccount($managed);
        $em->persist($batch);
        $statement = new Statement($managed, $batch, new \DateTimeImmutable($closingDate), $closingBalance);
        if (null !== $openingDate) {
            $statement->setOpeningDate(new \DateTimeImmutable($openingDate));
            $statement->setOpeningBalance($openingBalance);
        }
        $em->persist($statement);
        $em->flush();
    }

    private function statsUri(string $endpoint, array $extra = []): string
    {
        return '/api/stats/'.$endpoint.'?'.http_build_query($extra + ['from' => self::FROM, 'to' => self::TO]);
    }

    public function testOverview(): void
    {
        $this->seedStatsData();
        $client = $this->client();

        $data = $this->decode($this->json($client, 'GET', $this->statsUri('overview')));

        self::assertSame(self::FROM, $data['from']);
        self::assertSame(self::TO, $data['to']);
        self::assertSame(300000, $data['income']);            // 250000 + 50000
        self::assertSame(3250, $data['expense']);             // 800+200+1500-300+450+600
        self::assertSame(296750, $data['net']);
        self::assertEqualsWithDelta(0.9892, $data['savingsRate'], 1e-9); // round(296750/300000, 4)
        self::assertSame(10, $data['operationsCount']);
        self::assertSame(450, $data['uncategorizedExpense']);
        // Virement interne apparié : exclu des revenus/dépenses, à part (somme nulle au foyer).
        self::assertSame(10000, $data['transfersIn']);
        self::assertSame(10000, $data['transfersOut']);
        // Période précédente de même durée (90 jours) : 2025-10-03..2025-12-31, vide.
        self::assertSame(['from' => '2025-10-03', 'to' => '2025-12-31', 'income' => 0, 'expense' => 0, 'net' => 0], $data['previous']);
        // 12 mois complets avant le mois de to : 2025-03-01..2026-02-28 → juin 2025 (1000) + janv 2026 (1000) + fév 2026 (1200).
        self::assertSame(['income' => 0, 'expense' => 267], $data['average12']); // round(3200/12)
    }

    public function testOverviewAccountAndMemberScope(): void
    {
        $this->seedStatsData();
        $client = $this->client();
        $persoId = (int) $this->account->getId();
        $bobId = (int) $this->otherMember->getId();

        // Périmètre compte perso : le virement interne sortant est en transfersOut.
        $data = $this->decode($this->json($client, 'GET', $this->statsUri('overview', ['accountId' => [$persoId]])));
        self::assertSame(250000, $data['income']);
        self::assertSame(2650, $data['expense']);            // 800+200+1500-300+450
        self::assertSame(247350, $data['net']);
        self::assertSame(7, $data['operationsCount']);
        self::assertSame(450, $data['uncategorizedExpense']);
        self::assertSame(0, $data['transfersIn']);
        self::assertSame(10000, $data['transfersOut']);

        // Périmètre membre bob = compte commun : virement entrant en transfersIn.
        $data = $this->decode($this->json($client, 'GET', $this->statsUri('overview', ['memberId' => $bobId])));
        self::assertSame(50000, $data['income']);
        self::assertSame(600, $data['expense']);
        self::assertSame(3, $data['operationsCount']);
        self::assertSame(10000, $data['transfersIn']);
        self::assertSame(0, $data['transfersOut']);
    }

    public function testMonthly(): void
    {
        $this->seedStatsData();
        $client = $this->client();

        $data = $this->decode($this->json($client, 'GET', $this->statsUri('monthly')));

        self::assertSame(['2026-01', '2026-02', '2026-03'], $data['months']);
        self::assertSame([0, 0, 300000], $data['income']);
        self::assertSame([1000, 1200, 1050], $data['expense']); // janv 800+200 ; fév 1500-300 ; mars 450+600
        self::assertSame([-1000, -1200, 298950], $data['net']);
        self::assertSame([0, 0, 450], $data['uncategorized']);

        $byRoot = [];
        foreach ($data['byRoot'] as $entry) {
            $byRoot[$entry['category']['id']] = $entry['values'];
        }
        $alimentationId = (int) $this->category('Alimentation')->getId();
        $logementId = (int) $this->category('Logement')->getId();
        self::assertSame([1000, -300, 600], $byRoot[$alimentationId]);
        self::assertSame([0, 1500, 0], $byRoot[$logementId]);
        // CategoryRef inclut chartSlot (celui de la racine).
        foreach ($data['byRoot'] as $entry) {
            self::assertArrayHasKey('chartSlot', $entry['category']);
        }
    }

    public function testCategories(): void
    {
        $this->seedStatsData();
        $client = $this->client();
        $alimentationId = (int) $this->category('Alimentation')->getId();
        $logementId = (int) $this->category('Logement')->getId();
        $coursesId = (int) $this->category('Alimentation › Courses')->getId();

        // Racines + ligne non catégorisée, tri total desc.
        $rows = $this->decode($this->json($client, 'GET', $this->statsUri('categories', ['direction' => 'expense'])));
        self::assertCount(3, $rows);
        self::assertSame($logementId, $rows[0]['category']['id']);
        self::assertSame(1500, $rows[0]['total']);
        self::assertSame(1, $rows[0]['count']);
        self::assertEqualsWithDelta(0.4615, $rows[0]['share'], 1e-9);
        self::assertNull($rows[0]['compareTotal']);
        self::assertSame($alimentationId, $rows[1]['category']['id']);
        self::assertSame(1300, $rows[1]['total']);          // 800+200-300+600
        self::assertSame(4, $rows[1]['count']);
        self::assertEqualsWithDelta(0.4, $rows[1]['share'], 1e-9);
        self::assertNull($rows[2]['category']);               // non catégorisé
        self::assertSame(450, $rows[2]['total']);
        self::assertSame(1, $rows[2]['count']);
        self::assertEqualsWithDelta(0.1385, $rows[2]['share'], 1e-9);

        // compare=year : 2025-01-01..2025-03-31 → Énergie 2000, Courses 500.
        $rows = $this->decode($this->json($client, 'GET', $this->statsUri('categories', ['direction' => 'expense', 'compare' => 'year'])));
        self::assertSame(2000, $rows[0]['compareTotal']);     // Logement
        self::assertSame(500, $rows[1]['compareTotal']);      // Alimentation
        self::assertNull($rows[2]['compareTotal']);           // non catégorisé absent en 2025 Q1

        // compare=previous (vide) : compareTotal null.
        $rows = $this->decode($this->json($client, 'GET', $this->statsUri('categories', ['compare' => 'previous'])));
        self::assertNull($rows[0]['compareTotal']);

        // parentId = Alimentation : sous-catégories + racine elle-même.
        $rows = $this->decode($this->json($client, 'GET', $this->statsUri('categories', ['parentId' => $alimentationId])));
        self::assertCount(2, $rows);
        self::assertSame($coursesId, $rows[0]['category']['id']);
        self::assertSame(1100, $rows[0]['total']);           // 800-300+600
        self::assertSame(3, $rows[0]['count']);
        self::assertSame(1, $rows[0]['category']['chartSlot']); // hérité de la racine
        self::assertSame($alimentationId, $rows[1]['category']['id']);
        self::assertSame(200, $rows[1]['total']);
        self::assertSame(1, $rows[1]['count']);

        // direction=income.
        $rows = $this->decode($this->json($client, 'GET', $this->statsUri('categories', ['direction' => 'income'])));
        self::assertCount(1, $rows);
        self::assertSame('Revenus', $rows[0]['category']['name']);
        self::assertSame(300000, $rows[0]['total']);
        self::assertSame(2, $rows[0]['count']);
        self::assertEqualsWithDelta(1.0, $rows[0]['share'], 1e-9);
    }

    public function testMerchants(): void
    {
        $this->seedStatsData();
        $client = $this->client();

        $rows = $this->decode($this->json($client, 'GET', $this->statsUri('merchants', ['direction' => 'expense'])));
        self::assertCount(4, $rows);
        // Tri total desc ; clé de groupe coalesce(merchant, normalized_label), sans le sens.
        self::assertSame('EDF', $rows[0]['key']);
        self::assertSame(1500, $rows[0]['total']);
        self::assertSame(1, $rows[0]['count']);
        self::assertSame('Logement › Énergie', $rows[0]['category']['fullName']);
        self::assertSame('MONSHOP', $rows[1]['key']);
        self::assertSame(1100, $rows[1]['total']);           // 800-300+600 (remboursement inclus, négatif)
        self::assertSame(3, $rows[1]['count']);
        self::assertSame('Alimentation › Courses', $rows[1]['category']['fullName']); // majoritaire
        self::assertSame('INCONNU', $rows[2]['key']);
        self::assertSame(450, $rows[2]['total']);
        self::assertNull($rows[2]['category']);
        self::assertSame('BOULANGER', $rows[3]['key']);
        self::assertSame(200, $rows[3]['total']);

        // limit=2.
        $rows = $this->decode($this->json($client, 'GET', $this->statsUri('merchants', ['limit' => 2])));
        self::assertCount(2, $rows);

        // direction=income.
        $rows = $this->decode($this->json($client, 'GET', $this->statsUri('merchants', ['direction' => 'income'])));
        self::assertCount(1, $rows);
        self::assertSame('EMPLOYEUR', $rows[0]['key']);
        self::assertSame('EMPLOYEUR', $rows[0]['merchant']);
        self::assertSame(300000, $rows[0]['total']);
        self::assertSame(2, $rows[0]['count']);
        self::assertSame('Revenus › Salaires', $rows[0]['category']['fullName']);
    }

    public function testBalanceMonth(): void
    {
        $this->seedStatsData();
        $client = $this->client();

        $data = $this->decode($this->json($client, 'GET', $this->statsUri('balance', ['granularity' => 'month'])));

        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $data['dates']);
        $byAccount = [];
        foreach ($data['accounts'] as $entry) {
            $byAccount[$entry['account']['id']] = $entry['values'];
        }
        $persoId = (int) $this->account->getId();
        $jointId = (int) $this->jointAccount->getId();
        // Perso : ancrage closing 2025-12-31 = 100000, closing 2026-02-28 = 97800.
        self::assertSame([99000, 97800, 337350], $byAccount[$persoId]);
        // 2026-01-31 : 100000 - 1000 (janv) ; 2026-02-28 : relevé ; 2026-03-31 : 97800 + 250000 - 450 - 10000.
        // Commun : ancrage 2026-01-31 = 50000 ; 2026-03-31 : 50000 + 10000 - 600 + 50000.
        self::assertSame([50000, 50000, 109400], $byAccount[$jointId]);
        self::assertSame([149000, 147800, 446750], $data['total']);
    }

    public function testBalanceDayBeforeFirstStatement(): void
    {
        $this->seedStatsData();
        $client = $this->client();

        $data = $this->decode($this->json($client, 'GET', '/api/stats/balance?from=2025-11-20&to=2025-12-02&granularity=day'));

        self::assertCount(13, $data['dates']);
        self::assertSame('2025-11-20', $data['dates'][0]);
        self::assertSame('2025-12-02', $data['dates'][12]);

        $byAccount = [];
        foreach ($data['accounts'] as $entry) {
            $byAccount[$entry['account']['id']] = $entry['values'];
        }
        $persoId = (int) $this->account->getId();
        $jointId = (int) $this->jointAccount->getId();
        // Avant l'opening du premier relevé (2025-11-30) : null ; puis opening 90000 (aucune opération avant décembre).
        self::assertSame(
            [null, null, null, null, null, null, null, null, null, null, 90000, 90000, 90000],
            $byAccount[$persoId],
        );
        // Commun : pas de relevé avant 2026-01-31 et pas d'opening → null partout.
        self::assertSame(array_fill(0, 13, null), $byAccount[$jointId]);
        // total = null tant qu'aucun compte n'a de valeur.
        self::assertSame(
            [null, null, null, null, null, null, null, null, null, null, 90000, 90000, 90000],
            $data['total'],
        );
    }

    public function testSankey(): void
    {
        $this->seedStatsData();
        $client = $this->client();
        $revenusId = (int) $this->category('Revenus')->getId();

        $data = $this->decode($this->json($client, 'GET', $this->statsUri('sankey')));

        $nodes = [];
        foreach ($data['nodes'] as $node) {
            $nodes[$node['id']] = $node;
        }
        self::assertSame('hub', $nodes['hub']['kind']);
        self::assertSame('Budget', $nodes['hub']['name']);
        self::assertSame('income', $nodes['income-'.$revenusId]['kind']);
        self::assertSame('Revenus', $nodes['income-'.$revenusId]['name']);
        self::assertSame('expense-null', array_key_first(array_filter($nodes, static fn (array $n): bool => 'expense' === $n['kind'] && null === $n['category'])));
        // Revenus > dépenses ⇒ nœud savings, pas de deficit.
        self::assertArrayHasKey('savings', $nodes);
        self::assertSame('savings', $nodes['savings']['kind']);
        self::assertSame('Épargne / excédent', $nodes['savings']['name']);
        self::assertArrayNotHasKey('deficit', $nodes);

        $outHub = array_sum(array_map(
            static fn (array $link): int => 'hub' === $link['source'] ? $link['value'] : 0,
            $data['links'],
        ));
        // Somme des liens sortants du hub = dépenses + épargne = revenus.
        self::assertSame(300000, $outHub);

        $hubToSavings = array_values(array_filter($data['links'], static fn (array $l): bool => 'hub' === $l['source'] && 'savings' === $l['target']))[0];
        self::assertSame(296750, $hubToSavings['value']);

        $incomeToHub = array_values(array_filter($data['links'], static fn (array $l): bool => 'income-'.$revenusId === $l['source']))[0];
        self::assertSame('hub', $incomeToHub['target']);
        self::assertSame(300000, $incomeToHub['value']);
    }

    public function testCalendar(): void
    {
        $this->seedStatsData();
        $client = $this->client();

        $rows = $this->decode($this->json($client, 'GET', $this->statsUri('calendar')));

        self::assertSame(
            [
                ['date' => '2026-01-05', 'expense' => 800, 'count' => 1],
                ['date' => '2026-01-20', 'expense' => 200, 'count' => 1],
                ['date' => '2026-02-10', 'expense' => 1500, 'count' => 1],
                ['date' => '2026-02-15', 'expense' => -300, 'count' => 1], // remboursement : diminue
                ['date' => '2026-03-05', 'expense' => 450, 'count' => 1],
                ['date' => '2026-03-12', 'expense' => 600, 'count' => 1],
            ],
            $rows,
        );
    }

    public function testCategoryTrend(): void
    {
        $this->seedStatsData();
        $client = $this->client();
        $alimentationId = (int) $this->category('Alimentation')->getId();
        $coursesId = (int) $this->category('Alimentation › Courses')->getId();

        // Racine : sous-catégories incluses.
        $data = $this->decode($this->json($client, 'GET', $this->statsUri('category-trend', ['categoryId' => $alimentationId])));
        self::assertSame($alimentationId, $data['category']['id']);
        self::assertSame(1, $data['category']['chartSlot']);
        self::assertSame(['2026-01', '2026-02', '2026-03'], $data['months']);
        self::assertSame([1000, -300, 600], $data['values']);
        self::assertSame([2, 1, 1], $data['counts']);

        // Feuille : elle seule.
        $data = $this->decode($this->json($client, 'GET', $this->statsUri('category-trend', ['categoryId' => $coursesId])));
        self::assertSame($coursesId, $data['category']['id']);
        self::assertSame([800, -300, 600], $data['values']);
        self::assertSame([1, 1, 1], $data['counts']);
    }

    public function testInvalidParamsAre400(): void
    {
        $client = $this->client();

        $cases = [
            '/api/stats/overview?from=2026-03-31&to=2026-01-01',   // from > to
            '/api/stats/overview?from=2026-02-30',                 // date inexistante
            '/api/stats/overview?from=01-01-2026',                 // mauvais format
            '/api/stats/overview?accountId[]=abc',                 // accountId non entier
            '/api/stats/merchants?limit=51',                       // limit max 50
            '/api/stats/merchants?limit=abc',
            '/api/stats/balance?granularity=week',
            '/api/stats/categories?direction=foo',
            '/api/stats/categories?compare=foo',
            '/api/stats/category-trend',                           // categoryId obligatoire
            '/api/stats/category-trend?categoryId=999999',         // introuvable
        ];
        foreach ($cases as $uri) {
            $response = $this->json($client, 'GET', $uri);
            self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), $uri);
            self::assertSame('invalid_query', $this->decode($response)['error'], $uri);
        }
    }

    public function testChartSlot(): void
    {
        $this->seedStatsData();
        $client = $this->client();
        $alimentation = $this->category('Alimentation');
        $logement = $this->category('Logement');
        $courses = $this->category('Alimentation › Courses');
        $revenus = $this->category('Revenus');

        // Arbre : chartSlot exposé sur Category et CategoryRef (hérité de la racine).
        $roots = $this->decode($this->json($client, 'GET', '/api/categories'));
        $byName = [];
        foreach ($roots as $root) {
            $byName[$root['name']] = $root;
        }
        self::assertSame(1, $byName['Alimentation']['chartSlot']);
        self::assertSame('#2a78d6', $byName['Alimentation']['color']);
        self::assertSame(2, $byName['Logement']['chartSlot']);
        self::assertSame('#eb6834', $byName['Logement']['color']);
        self::assertNull($byName['Revenus']['chartSlot']);
        self::assertSame('#898781', $byName['Revenus']['color']);
        foreach ($byName['Alimentation']['children'] as $child) {
            if ('Courses' === $child['name']) {
                self::assertSame(1, $child['chartSlot']); // celui de la racine
            }
        }

        // CategoryRef d'une opération : chartSlot présent.
        $ops = $this->decode($this->json($client, 'GET', '/api/operations?perPage=50'));
        $withCategory = null;
        foreach ($ops['items'] as $item) {
            if ('Alimentation › Courses' === ($item['category']['fullName'] ?? null)) {
                $withCategory = $item;
                break;
            }
        }
        self::assertNotNull($withCategory);
        self::assertSame(1, $withCategory['category']['chartSlot']);

        // PATCH OK : libère l'emplacement 1 puis le reprend.
        $response = $this->json($client, 'PATCH', '/api/categories/'.(int) $alimentation->getId(), ['chartSlot' => null]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertNull($this->decode($response)['chartSlot']);
        $response = $this->json($client, 'PATCH', '/api/categories/'.(int) $alimentation->getId(), ['chartSlot' => 1]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(1, $this->decode($response)['chartSlot']);

        // 409 : emplacement déjà pris (Logement tente de prendre 1).
        $response = $this->json($client, 'PATCH', '/api/categories/'.(int) $logement->getId(), ['chartSlot' => 1]);
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame('chart_slot_taken', $this->decode($response)['error']);

        // 422 : hors 1-8.
        $response = $this->json($client, 'PATCH', '/api/categories/'.(int) $alimentation->getId(), ['chartSlot' => 9]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        // 422 : sous-catégorie interdite.
        $response = $this->json($client, 'PATCH', '/api/categories/'.(int) $courses->getId(), ['chartSlot' => 5]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('chartSlot', $this->decode($response)['violations'][0]['field']);

        // 422 : racine de revenu interdite.
        $response = $this->json($client, 'PATCH', '/api/categories/'.(int) $revenus->getId(), ['chartSlot' => 2]);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    public function testVehicles(): void
    {
        $vehicle = new \App\Entity\Vehicle($this->household, 'Clio', \App\Enum\VehicleType::Car);
        $this->em()->persist($vehicle);
        $carburant = $this->category('Transport › Carburant');
        $entretien = $this->category('Transport › Entretien véhicule');
        $courses = $this->category('Alimentation › Courses');
        $mouvements = $this->category('Mouvements internes');

        $op1 = $this->addOperation($this->account, '2026-01-05', -5000, 'ESSENCE STATION', 'STATION', $carburant, CategorizationSource::Manual);
        $op1->assignVehicle($vehicle, \App\Enum\VehicleSource::Manual);
        $op2 = $this->addOperation($this->account, '2026-01-12', -3000, 'VIDANGE GARAGE', 'GARAGE', $entretien, CategorizationSource::Manual);
        $op2->assignVehicle($vehicle, \App\Enum\VehicleSource::Manual);
        // Catégorie non vehicleRelated mais affectée manuellement : comptée, par catégorie.
        $op4 = $this->addOperation($this->account, '2026-01-25', -700, 'ACHAT CB SHOP', 'SHOP', $courses, CategorizationSource::Manual);
        $op4->assignVehicle($vehicle, \App\Enum\VehicleSource::Manual);
        // Non affectée (catégorie vehicleRelated sans véhicule).
        $this->addOperation($this->account, '2026-01-20', -1000, 'PEAGE AUTOROUTE', 'AUTOROUTE', $carburant, CategorizationSource::Manual);
        // Virement interne affecté au véhicule ⇒ exclu (kind transfer).
        $op5 = $this->addOperation($this->account, '2026-01-28', -10000, 'VIR INTERNE', 'VIRIN', $mouvements);
        $transfer = new InternalTransfer(TransferStatus::Auto);
        $this->em()->persist($transfer);
        $op5->setInternalTransfer($transfer);
        $op5->assignVehicle($vehicle, \App\Enum\VehicleSource::Manual);
        $this->em()->flush();

        $client = $this->client();
        $data = $this->decode($this->json($client, 'GET', '/api/stats/vehicles?from=2026-01-01&to=2026-01-31'));

        self::assertCount(1, $data['vehicles']);
        $row = $data['vehicles'][0];
        self::assertSame((int) $vehicle->getId(), $row['vehicle']['id']);
        self::assertSame('Clio', $row['vehicle']['name']);
        // Dépense positive (somme de -amount), virement interne exclu.
        self::assertSame(8700, $row['total']);
        self::assertSame(3, $row['count']);
        // 1 mois dans la période ⇒ moyenne mensuelle = total.
        self::assertSame(8700, $row['monthlyAverage']);

        $byCategory = [];
        foreach ($row['byCategory'] as $entry) {
            $byCategory[$entry['category']['fullName']] = $entry['total'];
        }
        ksort($byCategory);
        self::assertSame([
            'Alimentation › Courses' => 700,
            'Transport › Carburant' => 5000,
            'Transport › Entretien véhicule' => 3000,
        ], $byCategory);

        self::assertSame(['total' => 1000, 'count' => 1], $data['unassigned']);
    }

    public function testVehiclesDistanceAndCostPerKm(): void
    {
        $vehicle = new \App\Entity\Vehicle($this->household, 'Clio', \App\Enum\VehicleType::Car);
        $this->em()->persist($vehicle);
        $carburant = $this->category('Transport › Carburant');
        $entretien = $this->category('Transport › Entretien véhicule');
        $this->em()->flush();

        // Relevés : 10 000 km le 10/01, 13 000 km le 20/03.
        $managed = $this->em()->find(\App\Entity\Vehicle::class, (int) $vehicle->getId());
        $this->em()->persist(new \App\Entity\OdometerReading($managed, new \DateTimeImmutable('2026-01-10'), 10000));
        $this->em()->persist(new \App\Entity\OdometerReading($managed, new \DateTimeImmutable('2026-03-20'), 13000));

        // Dépenses : avant coveredFrom (hors coût/km), entre les bornes (dans le coût/km), après coveredTo (hors coût/km).
        $op1 = $this->addOperation($this->account, '2026-01-05', -5000, 'ESSENCE AVANT', 'STATION', $carburant, CategorizationSource::Manual);
        $op1->assignVehicle($managed, \App\Enum\VehicleSource::Manual);
        $op2 = $this->addOperation($this->account, '2026-01-12', -3000, 'VIDANGE GARAGE', 'GARAGE', $entretien, CategorizationSource::Manual);
        $op2->assignVehicle($managed, \App\Enum\VehicleSource::Manual);
        $op3 = $this->addOperation($this->account, '2026-03-25', -7000, 'ESSENCE APRES', 'STATION', $carburant, CategorizationSource::Manual);
        $op3->assignVehicle($managed, \App\Enum\VehicleSource::Manual);
        $this->em()->flush();

        $client = $this->client();
        $data = $this->decode($this->json($client, 'GET', '/api/stats/vehicles?from=2026-01-01&to=2026-03-31'));

        self::assertCount(1, $data['vehicles']);
        $row = $data['vehicles'][0];
        // total sur toute la période = 5000 + 3000 + 7000.
        self::assertSame(15000, $row['total']);
        self::assertSame(3, $row['count']);
        // distance : la période couvre tous les relevés ⇒ bornes = relevés, 3000 km.
        self::assertSame('2026-01-10', $row['distance']['coveredFrom']);
        self::assertSame('2026-03-20', $row['distance']['coveredTo']);
        self::assertEqualsWithDelta(3000.0, $row['distance']['km'], 1e-9);
        // coût au km : seule la vidange (3000 c) tombe dans [coveredFrom, coveredTo] ⇒ 3000 / 3000 = 1.0.
        self::assertEqualsWithDelta(1.0, $row['costPerKm'], 1e-9);
    }

    public function testVehiclesDistanceInterpolatedBoundsAndNullCases(): void
    {
        $em = $this->em();
        $vehicleA = new \App\Entity\Vehicle($this->household, 'Clio', \App\Enum\VehicleType::Car);
        $vehicleB = new \App\Entity\Vehicle($this->household, 'Scooter', \App\Enum\VehicleType::Motorcycle);
        $em->persist($vehicleA);
        $em->persist($vehicleB);
        $em->flush();
        $carburant = $this->category('Transport › Carburant');

        // A : relevés 01-01: 1000, 01-11: 2000, 01-21: 4000 ; période 01-06..01-25.
        $em->persist(new \App\Entity\OdometerReading($vehicleA, new \DateTimeImmutable('2026-01-01'), 1000));
        $em->persist(new \App\Entity\OdometerReading($vehicleA, new \DateTimeImmutable('2026-01-11'), 2000));
        $em->persist(new \App\Entity\OdometerReading($vehicleA, new \DateTimeImmutable('2026-01-21'), 4000));
        // B : un seul relevé ⇒ distance null.
        $em->persist(new \App\Entity\OdometerReading($vehicleB, new \DateTimeImmutable('2026-01-05'), 500));

        $opA1 = $this->addOperation($this->account, '2026-01-08', -2000, 'ESSENCE A1', 'STATION', $carburant, CategorizationSource::Manual);
        $opA1->assignVehicle($vehicleA, \App\Enum\VehicleSource::Manual);
        // Dans la période mais après coveredTo (dernier relevé 01-21) : comptée dans total, pas dans costPerKm.
        $opA2 = $this->addOperation($this->account, '2026-01-25', -9000, 'ESSENCE A2', 'STATION', $carburant, CategorizationSource::Manual);
        $opA2->assignVehicle($vehicleA, \App\Enum\VehicleSource::Manual);
        $opB = $this->addOperation($this->account, '2026-01-09', -1500, 'ESSENCE B', 'STATION', $carburant, CategorizationSource::Manual);
        $opB->assignVehicle($vehicleB, \App\Enum\VehicleSource::Manual);
        $em->flush();

        $client = $this->client();
        $data = $this->decode($this->json($client, 'GET', '/api/stats/vehicles?from=2026-01-06&to=2026-01-25'));

        self::assertCount(2, $data['vehicles']);
        $byId = [];
        foreach ($data['vehicles'] as $row) {
            $byId[$row['vehicle']['id']] = $row;
        }
        $rowA = $byId[(int) $vehicleA->getId()];
        // total A sur la période = 2000 (01-08) + 9000 (01-25).
        self::assertSame(11000, $rowA['total']);
        self::assertSame(2, $rowA['count']);
        // coveredTo ramené au dernier relevé (01-21) ; km(01-06) interpolé = 1500, km(01-21) = 4000 ⇒ 2500 km.
        self::assertSame('2026-01-06', $rowA['distance']['coveredFrom']);
        self::assertSame('2026-01-21', $rowA['distance']['coveredTo']);
        self::assertEqualsWithDelta(2500.0, $rowA['distance']['km'], 1e-9);
        // Seule l'opération du 01-08 (2000 c) est dans [coveredFrom, coveredTo] ⇒ 2000/2500 = 0.8.
        self::assertEqualsWithDelta(0.8, $rowA['costPerKm'], 1e-9);

        // B : un seul relevé ⇒ distance null, costPerKm null.
        $rowB = $byId[(int) $vehicleB->getId()];
        self::assertSame(1500, $rowB['total']);
        self::assertNull($rowB['distance']);
        self::assertNull($rowB['costPerKm']);
    }
}
