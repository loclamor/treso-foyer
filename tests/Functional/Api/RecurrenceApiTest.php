<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Support\TestClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lot récurrences : détection (POST /api/recurrences/detect) et idempotence, liste/filtres,
 * lecture, PATCH (correction ⇒ confirmed, ignored délie, 422), fin automatique, filtre
 * /api/operations?recurringSeriesId= et estimate du graphe mensuel avec série payée ou non.
 * Données fictives uniquement.
 */
final class RecurrenceApiTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        TestClock::reset();
        parent::tearDown();
    }

    /** Prélèvements mensuels EDF (dépense) sur 6 mois, salaire EMPLOYEUR (revenu) sur 5 mois, courses irrégulières. */
    private function seedRecurringData(): void
    {
        $energie = $this->category('Logement › Énergie');
        $salaires = $this->category('Revenus › Salaires');
        $courses = $this->category('Alimentation › Courses');
        foreach (['2026-06-05', '2026-07-05', '2026-08-05', '2026-09-05', '2026-10-05', '2026-11-03'] as $date) {
            $this->addOperation($this->account, $date, -90000, 'PRELEVEMENT EDF', 'EDF', $energie);
        }
        foreach (['2026-06-10', '2026-07-10', '2026-08-10', '2026-09-10', '2026-10-10'] as $date) {
            $this->addOperation($this->account, $date, 250000, 'VIREMENT EMPLOYEUR', 'EMPLOYEUR', $salaires);
        }
        foreach ([['2026-09-02', -4500], ['2026-09-05', -3200], ['2026-09-17', -8100], ['2026-09-22', -2700], ['2026-10-12', -6600], ['2026-10-14', -1900], ['2026-11-02', -5400]] as [$date, $amount]) {
            $this->addOperation($this->account, $date, $amount, 'ACHAT CB', 'MONSHOP', $courses);
        }
    }

    /** @return array<string, mixed> première série de la liste (200 attendu) */
    private function firstSeries(array $query = []): array
    {
        $uri = '/api/recurrences'.([] === $query ? '' : '?'.http_build_query($query));
        $data = $this->decode($this->json($this->client(), 'GET', $uri));

        return $data['items'][0];
    }

    public function testRecentOperationWithSlightlyDifferentLabelIsAttachedToSeries(): void
    {
        TestClock::freeze('2026-11-05');
        $this->seedRecurringData();
        $client = $this->client();
        $this->json($client, 'POST', '/api/recurrences/detect');
        $edf = $this->firstSeries(['direction' => 'expense']);
        self::assertSame('2026-11-03', $edf['lastOccurrence']);

        // Prélèvement du mois issu d'un export CSV : clé commerçant différente (ponctuation), mêmes mots.
        TestClock::freeze('2026-12-06');
        $energie = $this->category('Logement › Énergie');
        $this->addOperation($this->account, '2026-12-03', -90000, 'PRLV EDF.', 'EDF.', $energie);
        $report = $this->decode($this->json($client, 'POST', '/api/recurrences/detect'));
        self::assertSame(0, $report['created']);

        $edf = $this->firstSeries(['direction' => 'expense']);
        self::assertSame('2026-12-03', $edf['lastOccurrence']);
        self::assertSame(7, $edf['occurrences']);

        // Idempotence.
        $report = $this->decode($this->json($client, 'POST', '/api/recurrences/detect'));
        self::assertSame(['created' => 0, 'updated' => 0, 'ended' => 0, 'removed' => 0, 'linked' => 0], $report);
    }

    public function testDetectedSeriesRemovedWhenPatternNoLongerRecognised(): void
    {
        TestClock::freeze('2026-11-05');
        $this->seedRecurringData();
        $client = $this->client();
        $this->json($client, 'POST', '/api/recurrences/detect');

        // Le salaire devient irrégulier (versements intercalés) : motif plus reconnu.
        $salaires = $this->category('Revenus › Salaires');
        foreach (['2026-06-21', '2026-07-03', '2026-08-26', '2026-09-18', '2026-10-01'] as $date) {
            $this->addOperation($this->account, $date, 250000, 'VIREMENT EMPLOYEUR', 'EMPLOYEUR', $salaires);
        }

        $data = $this->decode($this->json($client, 'POST', '/api/recurrences/detect'));
        self::assertSame(1, $data['removed']);
        $data = $this->decode($this->json($client, 'GET', '/api/recurrences'));
        self::assertSame(['detected' => 1, 'confirmed' => 0, 'ignored' => 0, 'ended' => 0], $data['counts']);
        self::assertSame('EDF', $data['items'][0]['merchant']);
    }

    public function testDetectCreatesSeriesAndIsIdempotent(): void
    {
        TestClock::freeze('2026-11-05');
        $this->seedRecurringData();
        $client = $this->client();

        $data = $this->decode($this->json($client, 'POST', '/api/recurrences/detect'));
        // 2 séries créées (EDF dépense, EMPLOYEUR revenu), 11 opérations liées, courses non détectées.
        self::assertSame(['created' => 2, 'updated' => 0, 'ended' => 0, 'removed' => 0, 'linked' => 11], $data);

        // Idempotence : deuxième passage, rien ne change.
        $data = $this->decode($this->json($client, 'POST', '/api/recurrences/detect'));
        self::assertSame(['created' => 0, 'updated' => 0, 'ended' => 0, 'removed' => 0, 'linked' => 0], $data);

        // Liste : 2 séries détectées, compteurs sur le périmètre.
        $data = $this->decode($this->json($client, 'GET', '/api/recurrences'));
        self::assertSame(['detected' => 2, 'confirmed' => 0, 'ignored' => 0, 'ended' => 0], $data['counts']);
        self::assertCount(2, $data['items']);

        // Tri : nextExpected croissant. EDF (2026-12-03) avant EMPLOYEUR (2026-11-10).
        $edf = $data['items'][0]['merchant'] === 'EDF' ? $data['items'][0] : $data['items'][1];
        $employeur = $data['items'][0]['merchant'] === 'EMPLOYEUR' ? $data['items'][0] : $data['items'][1];
        self::assertSame('2026-11-10', $employeur['nextExpected']);
        self::assertSame('2026-12-03', $edf['nextExpected']);

        self::assertSame('expense', $edf['direction']);
        self::assertSame('monthly', $edf['frequency']);
        self::assertSame('Mensuelle', $edf['frequencyLabel']);
        self::assertSame(90000, $edf['expectedAmount']);
        self::assertSame(6, $edf['occurrences']);
        self::assertSame('detected', $edf['status']);
        self::assertSame('Détectée', $edf['statusLabel']);
        self::assertSame('2026-11-03', $edf['lastOccurrence']);
        self::assertNull($edf['activeMonths']);
        self::assertFalse($edf['overdue']);
        self::assertSame((int) $this->account->getId(), $edf['account']['id']);
        self::assertSame('Logement › Énergie', $edf['category']['fullName']);

        self::assertSame('income', $employeur['direction']);
        self::assertSame(250000, $employeur['expectedAmount']);
        self::assertSame(5, $employeur['occurrences']);

        // Filtres status / direction (counts inchangés, avant filtre).
        $data = $this->decode($this->json($client, 'GET', '/api/recurrences?status=detected'));
        self::assertCount(2, $data['items']);
        $data = $this->decode($this->json($client, 'GET', '/api/recurrences?status=confirmed,ended'));
        self::assertCount(0, $data['items']);
        self::assertSame(2, $data['counts']['detected']);
        $data = $this->decode($this->json($client, 'GET', '/api/recurrences?direction=expense'));
        self::assertCount(1, $data['items']);
        self::assertSame('EDF', $data['items'][0]['merchant']);
        $response = $this->json($client, 'GET', '/api/recurrences?status=foo');
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $response = $this->json($client, 'GET', '/api/recurrences?direction=foo');
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testShowAnd404(): void
    {
        TestClock::freeze('2026-11-05');
        $this->seedRecurringData();
        $client = $this->client();
        $this->json($client, 'POST', '/api/recurrences/detect');
        $series = $this->firstSeries(['direction' => 'expense']);

        $data = $this->decode($this->json($client, 'GET', '/api/recurrences/'.(int) $series['id']));
        self::assertSame((int) $series['id'], $data['id']);
        self::assertSame(6, $data['occurrences']);

        $response = $this->json($client, 'GET', '/api/recurrences/999999');
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testPatchCorrectsAndConfirmsButKeepsUserValues(): void
    {
        TestClock::freeze('2026-11-05');
        $this->seedRecurringData();
        $client = $this->client();
        $this->json($client, 'POST', '/api/recurrences/detect');
        $series = $this->firstSeries(['direction' => 'expense']);
        $id = (int) $series['id'];

        // Correction du montant sur une série « detected » ⇒ passée en « confirmed ».
        $data = $this->decode($this->json($client, 'PATCH', '/api/recurrences/'.$id, ['expectedAmount' => 95000]));
        self::assertSame(95000, $data['expectedAmount']);
        self::assertSame('confirmed', $data['status']);
        self::assertSame('2026-12-03', $data['nextExpected']); // inchangé

        // Changement de fréquence ⇒ nextExpected recalculé (annuel : 2027-11-03).
        $data = $this->decode($this->json($client, 'PATCH', '/api/recurrences/'.$id, ['frequency' => 'yearly']));
        self::assertSame('yearly', $data['frequency']);
        self::assertSame('Annuelle', $data['frequencyLabel']);
        self::assertSame('2027-11-03', $data['nextExpected']);

        // Retour en mensuel + activeMonths : liste triée, sauts de mois ; décembre hors
        // liste ⇒ prochaine échéance le 3 janvier 2027.
        $data = $this->decode($this->json($client, 'PATCH', '/api/recurrences/'.$id, ['frequency' => 'monthly', 'activeMonths' => [11, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 12]]));
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], $data['activeMonths']);
        $data = $this->decode($this->json($client, 'PATCH', '/api/recurrences/'.$id, ['activeMonths' => [1, 2, 3, 4, 5, 6, 9, 10, 11]]));
        self::assertSame([1, 2, 3, 4, 5, 6, 9, 10, 11], $data['activeMonths']);
        // lastOccurrence 2026-11-03 + 1 mois = 2026-12-03 (hors liste) ⇒ 2027-01-03.
        self::assertSame('2027-01-03', $data['nextExpected']);
        // activeMonths: null explicite = tous les mois.
        $data = $this->decode($this->json($client, 'PATCH', '/api/recurrences/'.$id, ['activeMonths' => null]));
        self::assertNull($data['activeMonths']);
        self::assertSame('2026-12-03', $data['nextExpected']); // fréquence monthly conservée

        // La détection suivante ne touche pas une série « confirmed » : fréquence et montant conservés.
        $report = $this->decode($this->json($client, 'POST', '/api/recurrences/detect'));
        self::assertSame(0, $report['created']);
        self::assertSame(0, $report['updated']);
        $data = $this->decode($this->json($client, 'GET', '/api/recurrences/'.$id));
        self::assertSame('monthly', $data['frequency']);
        self::assertSame(95000, $data['expectedAmount']);
        self::assertSame('confirmed', $data['status']);

        // status explicite : corriger un champ en gardant « detected ».
        $series2 = $this->firstSeries(['direction' => 'income']);
        $data = $this->decode($this->json($client, 'PATCH', '/api/recurrences/'.(int) $series2['id'], ['expectedAmount' => 260000, 'status' => 'detected']));
        self::assertSame('detected', $data['status']);
        self::assertSame(260000, $data['expectedAmount']);

        // 422 sur valeurs invalides.
        foreach ([
            ['expectedAmount' => 0],
            ['expectedAmount' => -5],
            ['amountTolerance' => -1],
            ['frequency' => 'daily'],
            ['status' => 'foo'],
            ['activeMonths' => [13]],
            ['activeMonths' => [1, 1]],
            ['activeMonths' => []],
        ] as $body) {
            $response = $this->json($client, 'PATCH', '/api/recurrences/'.$id, $body);
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), json_encode($body));
            self::assertSame('validation_failed', $this->decode($response)['error']);
        }

        $response = $this->json($client, 'PATCH', '/api/recurrences/999999', ['status' => 'ignored']);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testIgnoredSeriesUnlinksOperationsAndIsNotRecreated(): void
    {
        TestClock::freeze('2026-11-05');
        $this->seedRecurringData();
        $client = $this->client();
        $this->json($client, 'POST', '/api/recurrences/detect');
        $series = $this->firstSeries(['direction' => 'expense']);
        $id = (int) $series['id'];

        // Filtre opérations liées + champ recurringSeriesId exposé.
        $data = $this->decode($this->json($client, 'GET', '/api/operations?recurringSeriesId='.$id));
        self::assertSame(6, $data['total']);
        self::assertSame($id, $data['items'][0]['recurringSeriesId']);
        // Série inconnue du foyer ⇒ 400.
        $response = $this->json($client, 'GET', '/api/operations?recurringSeriesId=999999');
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        // Passage en « ignored » : opérations déliées.
        $data = $this->decode($this->json($client, 'PATCH', '/api/recurrences/'.$id, ['status' => 'ignored']));
        self::assertSame('ignored', $data['status']);
        self::assertSame(0, $data['occurrences']);
        $data = $this->decode($this->json($client, 'GET', '/api/operations?recurringSeriesId='.$id));
        self::assertSame(0, $data['total']);

        // Nouvelle détection : la série ignorée n'est ni recréée ni relinkée.
        $report = $this->decode($this->json($client, 'POST', '/api/recurrences/detect'));
        self::assertSame(0, $report['created']);
        self::assertSame(0, $report['linked']);
        $data = $this->decode($this->json($client, 'GET', '/api/recurrences?status=ignored'));
        self::assertCount(1, $data['items']);
        self::assertSame($id, $data['items'][0]['id']);
        $data = $this->decode($this->json($client, 'GET', '/api/operations?recurringSeriesId='.$id));
        self::assertSame(0, $data['total']);
    }

    public function testDetectedSeriesEndsAutomaticallyWhenTooOld(): void
    {
        TestClock::freeze('2026-11-05');
        $this->seedRecurringData();
        $client = $this->client();
        $report = $this->decode($this->json($client, 'POST', '/api/recurrences/detect'));
        self::assertSame(2, $report['created']);

        // Une série passée en « confirmed » ne termine jamais automatiquement.
        $series = $this->firstSeries(['direction' => 'expense']);
        $confirmedId = (int) $series['id'];
        $data = $this->decode($this->json($client, 'PATCH', '/api/recurrences/'.$confirmedId, ['expectedAmount' => 91000]));
        self::assertSame('confirmed', $data['status']);

        // Plus de prélèvement depuis longtemps : au 01/03/2027, seule la série « detected » passe « ended ».
        TestClock::freeze('2027-03-01');
        $report = $this->decode($this->json($client, 'POST', '/api/recurrences/detect'));
        self::assertSame(0, $report['created']);
        self::assertSame(1, $report['ended']);

        $data = $this->decode($this->json($client, 'GET', '/api/recurrences?status=ended'));
        self::assertCount(1, $data['items']);
        $data = $this->decode($this->json($client, 'GET', '/api/recurrences/'.$confirmedId));
        self::assertSame('confirmed', $data['status']);
    }

    public function testMonthlyEstimateWithUnpaidRecurringSeries(): void
    {
        TestClock::freeze('2026-11-05');
        $energie = $this->category('Logement › Énergie');
        $logement = $this->category('Logement');
        $courses = $this->category('Alimentation › Courses');
        $salaires = $this->category('Revenus › Salaires');
        // Loyer mensuel juin→octobre (dernier le 05/10) : échéance de novembre non payée.
        foreach (['2026-06-05', '2026-07-05', '2026-08-05', '2026-09-05', '2026-10-05'] as $date) {
            $this->addOperation($this->account, $date, -90000, 'PRELEVEMENT EDF', 'EDF', $energie);
        }
        // Variable : courses sept/oct + salaire sept/oct, courses 500 € en novembre.
        $this->addOperation($this->account, '2026-09-15', -1000, 'ACHAT CB SEPT', 'MONSHOP', $courses);
        $this->addOperation($this->account, '2026-10-15', -3000, 'ACHAT CB OCT', 'MONSHOP', $courses);
        $this->addOperation($this->account, '2026-11-04', -500, 'ACHAT CB NOV', 'MONSHOP', $courses);
        $this->addOperation($this->account, '2026-09-10', 250000, 'VIREMENT EMPLOYEUR SEPT', 'EMPLOYEUR', $salaires);
        $this->addOperation($this->account, '2026-10-10', 250000, 'VIREMENT EMPLOYEUR OCT', 'EMPLOYEUR', $salaires);

        $client = $this->client();
        $report = $this->decode($this->json($client, 'POST', '/api/recurrences/detect'));
        self::assertSame(1, $report['created']); // seul le loyer est détecté (salaire : 2 occurrences < 4)

        $data = $this->decode($this->json($client, 'GET', '/api/stats/monthly?from=2026-09-01&to=2026-11-30'));
        self::assertSame([91000, 93000, 500], $data['expense']);
        $estimate = $data['estimate'];
        self::assertSame('2026-11', $estimate['month']);
        // k = 5 mois complets avec opérations (juin..octobre).
        self::assertSame(5, $estimate['basisMonths']);
        self::assertSame(500, $estimate['spent']);
        self::assertSame(0, $estimate['received']);
        self::assertSame(90800, $estimate['average']);       // 454000 / 5
        self::assertSame(100000, $estimate['incomeAverage']); // 500000 / 5

        // Récurrent : 1 échéance restante de 90000 € le 05/11, byRoot = racine « Logement ».
        self::assertSame(90000, $estimate['recurring']['expense']['remaining']);
        self::assertSame(
            [['rootId' => (int) $logement->getId(), 'remaining' => 90000]],
            $estimate['recurring']['expense']['byRoot'],
        );
        self::assertCount(1, $estimate['recurring']['expense']['items']);
        $item = $estimate['recurring']['expense']['items'][0];
        self::assertSame('EDF', $item['merchant']);
        self::assertSame((int) $energie->getId(), $item['categoryId']);
        self::assertSame((int) $logement->getId(), $item['rootId']);
        self::assertSame('2026-11-05', $item['expectedDate']);
        self::assertSame(90000, $item['amount']);
        self::assertFalse($item['paid']);
        self::assertSame(0, $estimate['recurring']['income']['remaining']);

        // Variable : dépenses non récurrentes (courses) moyenne 2000, à date 500 ⇒ reste 1500.
        self::assertSame(
            [
                'expenseAverage' => 800,
                'expenseSpent' => 500,
                'expenseRemaining' => 300,
                'incomeAverage' => 100000,
                'incomeReceived' => 0,
                'incomeRemaining' => 100000,
            ],
            $estimate['variable'],
        );

        // expense = 500 + 90000 + 300 ; income = 0 + 0 + 100000.
        self::assertSame(90800, $estimate['expense']);
        self::assertSame(100000, $estimate['income']);
    }

    public function testMonthlyEstimateWithPaidRecurringSeries(): void
    {
        TestClock::freeze('2026-11-05');
        $energie = $this->category('Logement › Énergie');
        $courses = $this->category('Alimentation › Courses');
        // Loyer payé chaque mois y compris novembre.
        foreach (['2026-06-05', '2026-07-05', '2026-08-05', '2026-09-05', '2026-10-05', '2026-11-03'] as $date) {
            $this->addOperation($this->account, $date, -90000, 'PRELEVEMENT EDF', 'EDF', $energie);
        }
        $this->addOperation($this->account, '2026-09-15', -1000, 'ACHAT CB SEPT', 'MONSHOP', $courses);
        $this->addOperation($this->account, '2026-10-15', -3000, 'ACHAT CB OCT', 'MONSHOP', $courses);
        $this->addOperation($this->account, '2026-11-04', -500, 'ACHAT CB NOV', 'MONSHOP', $courses);

        $client = $this->client();
        $this->json($client, 'POST', '/api/recurrences/detect');

        $data = $this->decode($this->json($client, 'GET', '/api/stats/monthly?from=2026-09-01&to=2026-11-30'));
        $estimate = $data['estimate'];
        // Échéance de novembre payée : remaining = 0, item paid avec le montant réel.
        self::assertSame(0, $estimate['recurring']['expense']['remaining']);
        self::assertSame([], $estimate['recurring']['expense']['byRoot']);
        self::assertCount(1, $estimate['recurring']['expense']['items']);
        $item = $estimate['recurring']['expense']['items'][0];
        self::assertTrue($item['paid']);
        self::assertSame(90000, $item['amount']);
        self::assertSame('2026-11-05', $item['expectedDate']);

        // Le loyer lié est exclu du variable : moyenne courses (1000+3000)/5 = 800, à date 500.
        self::assertSame(90500, $estimate['spent']);
        self::assertSame(800, $estimate['variable']['expenseAverage']);
        self::assertSame(500, $estimate['variable']['expenseSpent']);
        self::assertSame(300, $estimate['variable']['expenseRemaining']);
        // expense = 90500 + 0 + 300.
        self::assertSame(90800, $estimate['expense']);
    }
}
