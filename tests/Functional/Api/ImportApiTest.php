<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Operation;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class ImportApiTest extends ApiTestCase
{
    private const string FIXTURE_CSV = __DIR__.'/../../Fixtures/csv/releve-a.csv';

    /** Copie temporaire de la fixture (l'upload déplace le fichier d'origine). */
    private function uploadFixture(string $name = 'releve-a.csv'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'csv-');
        copy(self::FIXTURE_CSV, $tmp);

        return new UploadedFile($tmp, $name, 'text/csv', null, true);
    }

    public function testUploadCsvFixtureThenAlreadyImported(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/imports', [], ['files' => [$this->uploadFixture()]]);
        $response = $client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);

        self::assertCount(1, $data['reports']);
        $report = $data['reports'][0];
        self::assertSame('releve-a.csv', $report['filename']);
        self::assertSame('imported', $report['status']);
        self::assertSame(7, $report['added']);
        self::assertNotNull($report['account']);
        self::assertSame('ccp-perso', $report['account']['alias']); // alias lu dans tests/Fixtures/comptes.ini
        self::assertSame('2026-08-24', $report['periodStart']);
        self::assertSame('2026-08-26', $report['periodEnd']);
        self::assertArrayHasKey('transfersMatched', $data['postProcessing']);
        self::assertArrayHasKey('categorized', $data['postProcessing']);
        self::assertGreaterThanOrEqual(0, $data['postProcessing']['categorized']);

        // Le fichier est conservé sous var/test-uploads/<sha256>.csv (jamais data/).
        $sha = hash_file('sha256', self::FIXTURE_CSV);
        self::assertFileExists(self::getContainer()->getParameter('kernel.project_dir').'/var/test-uploads/'.$sha.'.csv');

        // Le post-traitement a catégorisé les opérations (règles par défaut non seedées ici :
        // aucune règle ⇒ rien de catégorisé, mais le lot est bien créé).
        $batches = $this->em()->getRepository(\App\Entity\ImportBatch::class)->findAll();
        self::assertCount(1, $batches);
        self::assertSame('completed', $batches[0]->getStatus()->value);
        self::assertSame((int) $this->member->getId(), (int) $batches[0]->getImportedBy()?->getId());

        // Ré-upload du même contenu ⇒ already_imported.
        $client2 = $this->client();
        $client2->request('POST', '/api/imports', [], ['files' => [$this->uploadFixture()]]);
        $data2 = $this->decode($client2->getResponse());
        self::assertSame('already_imported', $data2['reports'][0]['status']);
        self::assertSame(7, $data2['reports'][0]['added']);
    }

    public function testListImports(): void
    {
        $client = $this->client();
        $client->request('POST', '/api/imports', [], ['files' => [$this->uploadFixture()]]);
        self::assertSame(Response::HTTP_OK, $client->getResponse()->getStatusCode());

        $client = $this->client();
        $response = $this->json($client, 'GET', '/api/imports');
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = $this->decode($response);
        self::assertSame(1, $data['total']);
        self::assertSame('releve-a.csv', $data['items'][0]['filename']);
        self::assertSame('csv', $data['items'][0]['format']);
        self::assertSame('completed', $data['items'][0]['status']);
        self::assertSame(7, $data['items'][0]['added']);
        self::assertSame('alice', $data['items'][0]['importedBy']['username']);
        self::assertNotNull($data['items'][0]['importedAt']);
    }

    public function testUnsupportedFileReportsFailed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'api-import-').'.txt';
        file_put_contents($path, 'not a statement');
        $client = $this->client();
        $file = new UploadedFile($path, 'fichier.txt', 'text/plain', null, true);
        $client->request('POST', '/api/imports', [], ['files' => [$file]]);
        $data = $this->decode($client->getResponse());
        self::assertSame('failed', $data['reports'][0]['status']);
        self::assertNotNull($data['reports'][0]['error']);
        unlink($path);
    }
}
