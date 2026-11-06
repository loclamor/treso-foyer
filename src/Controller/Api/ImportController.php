<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\HouseholdProvider;
use App\Api\Pagination;
use App\Api\Presenter\AccountPresenter;
use App\Api\Presenter\ImportBatchPresenter;
use App\Entity\ImportBatch;
use App\Entity\Member;
use App\Import\Exception\NotAnAccountStatementException;
use App\Import\Model\ImportOptions;
use App\Import\Model\ImportReport;
use App\Import\PostImportProcessor;
use App\Import\StatementImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ImportController extends AbstractController
{
    private const int MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 Mo
    private const array ALLOWED_EXTENSIONS = ['pdf', 'csv'];

    #[Route('/api/imports', name: 'api_imports', methods: ['GET'])]
    public function list(
        Request $request,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        ImportBatchPresenter $presenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $pagination = Pagination::fromRequest($request);

        $base = $entityManager->getRepository(ImportBatch::class)->createQueryBuilder('b')
            ->leftJoin('b.account', 'a')
            ->where('a.household = :household OR b.account IS NULL')
            ->setParameter('household', $household);

        $total = (int) (clone $base)->select('COUNT(b.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $batches = $base
            ->select('b', 'a', 'm')
            ->leftJoin('b.importedBy', 'm')
            ->orderBy('b.importedAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setFirstResult($pagination->offset())
            ->setMaxResults($pagination->perPage)
            ->getQuery()->getResult();

        return new JsonResponse($pagination->envelope(
            array_map(
                static fn (mixed $row): array => $presenter->present(\is_array($row) ? $row[0] : $row),
                $batches,
            ),
            $total,
        ));
    }

    /**
     * POST /api/imports (multipart/form-data, champ files[]) : conserve chaque fichier
     * sous uploads_dir/<sha256>.<ext>, l'importe (création auto du compte) puis lance
     * le post-traitement (virements internes + catégorisation).
     */
    #[Route('/api/imports', name: 'api_imports_upload', methods: ['POST'])]
    public function upload(
        Request $request,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        StatementImporter $importer,
        PostImportProcessor $postImportProcessor,
        AccountPresenter $accountPresenter,
        #[Autowire('%app.uploads_dir%')] string $uploadsDir,
    ): JsonResponse {
        $user = $this->getUser();
        \assert($user instanceof Member);
        $household = $householdProvider->get();

        $files = $request->files->get('files', []);
        if (!is_array($files)) {
            $files = [$files];
        }
        if ([] === $files) {
            return new JsonResponse(['reports' => [], 'postProcessing' => ['reconciliation' => ['matched' => 0, 'ambiguous' => 0, 'pending' => 0], 'transfersMatched' => 0, 'categorized' => 0, 'vehiclesAssigned' => 0, 'recurrences' => ['created' => 0, 'updated' => 0, 'ended' => 0, 'linked' => 0]]]);
        }

        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0775, true);
        }

        $reports = [];
        $addedOrEnriched = 0;
        foreach ($files as $file) {
            $report = $this->importOne($file, $uploadsDir, $importer, $entityManager, $user, $accountPresenter);
            $reports[] = $report['payload'];
            $addedOrEnriched += $report['added'] + $report['enriched'];
        }

        $postProcessing = ['reconciliation' => ['matched' => 0, 'ambiguous' => 0, 'pending' => 0], 'transfersMatched' => 0, 'categorized' => 0, 'vehiclesAssigned' => 0, 'recurrences' => ['created' => 0, 'updated' => 0, 'ended' => 0, 'linked' => 0]];
        if ($addedOrEnriched > 0) {
            try {
                $result = $postImportProcessor->process($household);
                $postProcessing = [
                    'reconciliation' => $result->reconciliation->toArray(),
                    'transfersMatched' => $result->transfersMatched,
                    'categorized' => $result->categorized(),
                    'vehiclesAssigned' => $result->vehiclesAssigned(),
                    'recurrences' => $result->recurrences->toArray(),
                ];
            } catch (\Throwable) {
                // Un échec du post-traitement ne fait pas échouer l'import déjà réussi.
            }
        }

        return new JsonResponse(['reports' => $reports, 'postProcessing' => $postProcessing]);
    }

    /**
     * @return array{payload: array<string, mixed>, added: int, enriched: int}
     */
    private function importOne(mixed $file, string $uploadsDir, StatementImporter $importer, EntityManagerInterface $entityManager, Member $user, AccountPresenter $accountPresenter): array
    {
        $result = static fn (array $payload, int $added = 0, int $enriched = 0): array => ['added' => $added, 'enriched' => $enriched, 'payload' => $payload];
        if (!$file instanceof UploadedFile) {
            return $result($this->reportPayload(filename: 'fichier invalide', status: 'failed', error: 'Fichier uploadé invalide.'));
        }
        $filename = $file->getClientOriginalName() ?: 'fichier';
        if (!$file->isValid()) {
            return $result($this->reportPayload(filename: $filename, status: 'failed', error: 'Upload invalide (code '.$file->getError().').'));
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return $result($this->reportPayload(filename: $filename, status: 'failed', error: 'Format non pris en charge (pdf, csv uniquement).'));
        }
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return $result($this->reportPayload(filename: $filename, status: 'failed', error: 'Fichier trop volumineux (20 Mo max).'));
        }

        // Conservation du fichier sous <sha256>.<ext> (contenu identique = un seul exemplaire).
        $sha256 = hash_file('sha256', $file->getPathname()) ?: '';
        $target = $uploadsDir.'/'.$sha256.'.'.$extension;
        if (!is_file($target)) {
            $file->move($uploadsDir, $sha256.'.'.$extension);
        }

        $options = new ImportOptions(
            autoCreateAccount: true,
            importedBy: $user,
            originalFilename: $filename,
        );

        try {
            $report = $importer->import($target, $options);
        } catch (NotAnAccountStatementException) {
            return $result($this->reportPayload(filename: $filename, status: 'ignored'));
        } catch (\Throwable $exception) {
            return $result($this->reportPayload(filename: $filename, status: 'failed', error: $exception->getMessage()));
        }

        $batch = $entityManager->getRepository(ImportBatch::class)->findOneBy(['sha256' => $sha256]);

        // Fichier déjà importé : compteurs d'origine affichés, mais rien de nouveau à post-traiter.
        return [
            'added' => $report->isAlreadyImported() ? 0 : $report->added,
            'enriched' => $report->isAlreadyImported() ? 0 : $report->enriched,
            'payload' => $this->reportPayload(
                filename: $report->filename,
                status: $report->status,
                account: null !== $batch?->getAccount() ? $accountPresenter->presentRef($batch->getAccount()) : null,
                periodStart: $report->periodStart?->format('Y-m-d'),
                periodEnd: $report->periodEnd?->format('Y-m-d'),
                added: $report->added,
                skipped: $report->skipped,
                enriched: $report->enriched,
                probableDuplicates: $report->probableDuplicates,
                error: $report->error,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function reportPayload(string $filename, string $status, ?string $error = null, ?array $account = null, ?string $periodStart = null, ?string $periodEnd = null, int $added = 0, int $skipped = 0, int $enriched = 0, int $probableDuplicates = 0): array
    {
        return [
            'filename' => $filename,
            'status' => $status,
            'account' => $account,
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
            'added' => $added,
            'skipped' => $skipped,
            'enriched' => $enriched,
            'probableDuplicates' => $probableDuplicates,
            'error' => $error,
        ];
    }
}
