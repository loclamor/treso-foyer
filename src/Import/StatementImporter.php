<?php

declare(strict_types=1);

namespace App\Import;

use App\Entity\ImportBatch;
use App\Entity\Operation;
use App\Entity\Statement;
use App\Enum\DuplicateStatus;
use App\Enum\ImportFormat;
use App\Enum\ImportStatus;
use App\Import\Exception\NotAnAccountStatementException;
use App\Import\Exception\ParseException;
use App\Import\Exception\UnsupportedFormatException;
use App\Import\Model\ImportOptions;
use App\Import\Model\ImportReport;
use App\Import\Model\NormalizedLabel;
use App\Import\Model\ParsedStatement;
use App\Import\Parser\StatementParserInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Service d'import d'un relevé (CSV/PDF) : parse, résolution du compte, création du lot
 * et du relevé, dédoublonnage exact par empreinte puis détection floue (pg_trgm).
 * Voir docs/adr/0005-dedoublonnage.md.
 */
final class StatementImporter
{
    private EntityManagerInterface $entityManager;

    public function __construct(
        private readonly ManagerRegistry $registry,
        #[AutowireIterator('app.statement_parser')]
        private readonly iterable $parsers,
        private readonly AccountResolver $accountResolver,
        private readonly LabelNormalizer $labelNormalizer,
        private readonly OperationFingerprinter $fingerprinter,
        private readonly CardDateExtractor $cardDateExtractor = new CardDateExtractor(),
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Importe un fichier et renvoie le rapport du compte principal (voir importAll pour les comptes liés).
     *
     * @throws ParseException|UnsupportedFormatException|NotAnAccountStatementException|Exception\UnknownAccountException|\Throwable
     */
    public function import(string $path, ImportOptions $options): ImportReport
    {
        return $this->importAll($path, $options)[0];
    }

    /**
     * Importe un fichier : relevé du compte principal, puis relevés des comptes liés du même document
     * (livrets d'un relevé La Banque Postale), chacun dans son propre lot, sur son compte.
     * Un fichier déjà importé est relu pour importer les comptes liés qui ne l'auraient pas été.
     * Les exceptions concernent le compte principal ; un compte lié en erreur donne un rapport « failed ».
     *
     * @return non-empty-list<ImportReport> le compte principal en premier
     *
     * @throws ParseException|UnsupportedFormatException|NotAnAccountStatementException|Exception\UnknownAccountException|\Throwable
     */
    public function importAll(string $path, ImportOptions $options): array
    {
        $raw = @file_get_contents($path);
        if (false === $raw) {
            throw new ParseException(sprintf('Fichier illisible : %s.', basename($path)));
        }
        $sha256 = hash('sha256', $raw);

        $parser = null;
        foreach ($this->parsers as $candidate) {
            if ($candidate->supports($path, $raw)) {
                $parser = $candidate;
                break;
            }
        }
        if (!$parser instanceof StatementParserInterface) {
            throw new UnsupportedFormatException(sprintf('Aucun parseur ne prend en charge « %s ».', basename($path)));
        }

        /** @var ParsedStatement|null $parsed */
        $parsed = null;
        $main = $this->importOne($path, $sha256, $options, static function () use ($parser, $path, &$parsed): ParsedStatement {
            return $parsed = $parser->parse($path);
        });

        if (null === $parsed) {
            // Fichier déjà importé : relu pour ses comptes liés (un échec de relecture n'est pas une erreur).
            try {
                $parsed = $parser->parse($path);
            } catch (\Throwable) {
                return [$main];
            }
        }

        $reports = [$main];
        foreach ($parsed->related as $related) {
            // Lot propre à chaque compte lié : empreinte du fichier suffixée par le numéro de compte.
            $relatedSha = hash('sha256', $sha256.'#'.$related->accountNumber);
            try {
                $reports[] = $this->importOne($path, $relatedSha, $options, static fn (): ParsedStatement => $related);
            } catch (\Throwable $exception) {
                $this->logUnexpected($exception, $path);
                $reports[] = new ImportReport(
                    filename: $options->originalFilename ?? basename($path),
                    status: 'failed',
                    accountAlias: null,
                    accountMasked: '',
                    periodStart: null,
                    periodEnd: null,
                    added: 0,
                    skipped: 0,
                    probableDuplicates: 0,
                    error: ImportFailure::publicMessage($exception),
                );
            }
        }

        return $reports;
    }

    /**
     * Importe un relevé dans un lot d'empreinte $sha256 (déjà importé : rapport « already_imported »,
     * sans appeler $parse).
     *
     * @param \Closure(): ParsedStatement $parse
     */
    private function importOne(string $path, string $sha256, ImportOptions $options, \Closure $parse): ImportReport
    {
        // Un rollback (dry-run, erreur) ferme l'EntityManager (ORM 3) : on repart d'un EM ouvert à chaque relevé.
        $this->entityManager = $this->openEntityManager();

        $batchRepository = $this->entityManager->getRepository(ImportBatch::class);
        $existing = $batchRepository->findOneBy(['sha256' => $sha256]);
        if ($existing instanceof ImportBatch) {
            if (ImportStatus::Completed === $existing->getStatus()) {
                return new ImportReport(
                    filename: $options->originalFilename ?? basename($path),
                    status: 'already_imported',
                    accountAlias: $existing->getAccount()?->getAlias(),
                    accountMasked: $existing->getAccount()?->getNumberMasked() ?? '',
                    periodStart: $existing->getPeriodStart(),
                    periodEnd: $existing->getPeriodEnd(),
                    added: $existing->getAddedCount(),
                    skipped: $existing->getSkippedCount(),
                    probableDuplicates: $existing->getProbableDuplicateCount(),
                    enriched: $existing->getEnrichedCount(),
                );
            }
        }
        // Lot en échec avec ce hash : supprimé avant de réessayer, dans la transaction de l'import
        // (un dry-run ou un nouvel échec le restaure par rollback au lieu de le perdre).
        $failedBatch = $existing instanceof ImportBatch ? $existing : null;

        $resolvedAccount = null;
        try {
            return $this->entityManager->wrapInTransaction(
                function () use ($path, $sha256, $parse, $options, &$resolvedAccount, $failedBatch): ImportReport {
                    if (null !== $failedBatch) {
                        $this->entityManager->remove($failedBatch);
                        $this->entityManager->flush();
                    }
                    $parsed = $parse();
                                        $account = $this->accountResolver->resolve($parsed->accountNumber, $options->autoCreateAccount, $parsed->accountType, $parsed->accountLabel);
                    $resolvedAccount = $account;

                    $filename = $options->originalFilename ?? basename($path);
                    $batch = new ImportBatch($filename, $sha256, $parsed->format, ImportStatus::Completed);
                    $batch->setAccount($account);
                    $batch->setImportedBy($options->importedBy);
                    $dates = array_map(static fn ($operation) => $operation->date, $parsed->operations);
                    $batch->setPeriodStart([] !== $dates ? min($dates) : null);
                    $batch->setPeriodEnd([] !== $dates ? max($dates) : null);
                    $this->entityManager->persist($batch);

                    $closingDate = $parsed->closingDate ?? ([] !== $dates ? max($dates) : new \DateTimeImmutable());
                    $statement = new Statement($account, $batch, $closingDate, $parsed->closingBalance ?? 0);
                    if (null !== $parsed->openingDate && null !== $parsed->openingBalance) {
                        $statement->setOpeningDate($parsed->openingDate);
                        $statement->setOpeningBalance($parsed->openingBalance);
                    }
                    $this->entityManager->persist($statement);

                    /** @var list<NormalizedLabel> $normalized */
                    $normalized = array_map(fn ($operation) => $this->labelNormalizer->normalize($operation->rawLabel), $parsed->operations);
                    $keys = array_map(static fn (NormalizedLabel $label): string => $label->key, $normalized);
                    $index = 0;
                    $occurrences = $this->fingerprinter->assignOccurrences(
                        $parsed->operations,
                        static function ($operation) use (&$index, $keys): string {
                            return $keys[$index++];
                        },
                    );

                    $fingerprints = [];
                    foreach ($parsed->operations as $i => $operation) {
                        $fingerprints[] = $this->fingerprinter->fingerprint($account, $operation->date, $operation->amount, $keys[$i], $occurrences[$i]);
                    }

                    // Opérations déjà en base (empreinte => [id, source du libellé]), en une seule requête.
                    $existingFingerprints = [];
                    if ([] !== $fingerprints) {
                        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                            'SELECT fingerprint, id, label_source FROM operation WHERE fingerprint IN (?)',
                            [$fingerprints],
                            [ArrayParameterType::STRING],
                        );
                        foreach ($rows as $row) {
                            $existingFingerprints[$row['fingerprint']] = $row;
                        }
                        // Lignes supprimées par la fusion d'un doublon : déjà connues, jamais recréées
                        // (label_source null ⇒ pas d'enrichissement, simplement ignorées).
                        $tombstones = $this->entityManager->getConnection()->fetchAllAssociative(
                            'SELECT fingerprint, kept_operation_id AS id, NULL AS label_source FROM merged_fingerprint WHERE fingerprint IN (?)',
                            [$fingerprints],
                            [ArrayParameterType::STRING],
                        );
                        foreach ($tombstones as $row) {
                            $existingFingerprints[$row['fingerprint']] ??= $row;
                        }
                    }

                    $added = 0;
                    $skipped = 0;
                    $enriched = 0;
                    /** @var list<array{0: Operation, 1: NormalizedLabel}> $addedOperations */
                    $addedOperations = [];
                    foreach ($parsed->operations as $i => $operation) {
                        if (isset($existingFingerprints[$fingerprints[$i]])) {
                            $known = $existingFingerprints[$fingerprints[$i]];
                            // Opération connue : on remplace son libellé si ce fichier est une source plus complète
                            // (PDF > CSV). Une opération saisie à la main (source null) n'est jamais modifiée.
                            $knownSource = null !== $known['label_source'] ? ImportFormat::from($known['label_source']) : null;
                            if (null !== $knownSource && $parsed->format->labelPriority() > $knownSource->labelPriority()) {
                                $existingOperation = $this->entityManager->find(Operation::class, (int) $known['id']);
                                if ($existingOperation instanceof Operation) {
                                    $existingOperation->enrichLabel($operation->rawLabel, $normalized[$i]->normalized, $normalized[$i]->merchant, $batch);
                                    // Date réelle extraite du nouveau libellé (jamais sur une ligne
                                    // déjà rapprochée d'une saisie manuelle : son spentAt vient de la saisie).
                                    if (null === $existingOperation->getReconciledAt()) {
                                        $existingOperation->setSpentAt($this->cardDateExtractor->guard(
                                            $this->cardDateExtractor->extract($operation->rawLabel),
                                            $existingOperation->getDate(),
                                        ));
                                    }
                                    $enriched++;
                                    continue;
                                }
                            }
                            $skipped++;
                            continue;
                        }
                        $entity = new Operation(
                            $account,
                            $operation->date,
                            $operation->amount,
                            $operation->rawLabel,
                            $normalized[$i]->normalized,
                            $fingerprints[$i],
                        );
                        $entity->setImportBatch($batch);
                        $entity->setMerchant($normalized[$i]->merchant);
                        $entity->setOccurrenceIndex($occurrences[$i]);
                        $entity->setLabelSource($parsed->format);
                        // Date réelle de la dépense extraite du libellé CB (« JJ.MM.AA »), avec garde-fous.
                        $entity->setSpentAt($this->cardDateExtractor->guard(
                            $this->cardDateExtractor->extract($operation->rawLabel),
                            $operation->date,
                        ));
                        $this->entityManager->persist($entity);
                        $added++;
                        $addedOperations[] = [$entity, $normalized[$i]];
                    }
                    $this->entityManager->flush();

                    // Détection floue (pg_trgm) : même compte, autre import, même montant,
                    // date à ±1 jour, similarité >= seuil, la plus similaire.
                    $probable = 0;
                    $connection = $this->entityManager->getConnection();
                    foreach ($addedOperations as [$entity, $label]) {
                        $row = $connection->fetchAssociative(
                            <<<'SQL'
                                SELECT o.id
                                FROM operation o
                                WHERE o.account_id = :account
                                  AND o.import_batch_id IS DISTINCT FROM :batch
                                  AND o.amount = :amount
                                  AND o.date BETWEEN :dayBefore AND :dayAfter
                                  AND NOT o.awaiting_statement
                                  AND similarity(o.normalized_label, :label) >= :threshold
                                ORDER BY similarity(o.normalized_label, :label) DESC
                                LIMIT 1
                                SQL,
                            [
                                'account' => $account->getId(),
                                'batch' => $batch->getId(),
                                'amount' => $entity->getAmount(),
                                'dayBefore' => $entity->getDate()->modify('-1 day'),
                                'dayAfter' => $entity->getDate()->modify('+1 day'),
                                'label' => $label->normalized,
                                'threshold' => $options->similarityThreshold,
                            ],
                            [
                                'account' => ParameterType::INTEGER,
                                'batch' => ParameterType::INTEGER,
                                'amount' => ParameterType::INTEGER,
                                'dayBefore' => Types::DATE_IMMUTABLE,
                                'dayAfter' => Types::DATE_IMMUTABLE,
                                'label' => ParameterType::STRING,
                                'threshold' => ParameterType::STRING,
                            ],
                        );
                        if (is_array($row) && null !== $row['id']) {
                            $other = $this->entityManager->find(Operation::class, (int) $row['id']);
                            if ($other instanceof Operation) {
                                $entity->setDuplicateStatus(DuplicateStatus::Probable);
                                $entity->setDuplicateOf($other);
                                $probable++;
                            }
                        }
                    }

                    $batch->setAddedCount($added);
                    $batch->setSkippedCount($skipped);
                    $batch->setProbableDuplicateCount($probable);
                    $batch->setEnrichedCount($enriched);
                    $batch->setStatus(ImportStatus::Completed);
                    $this->entityManager->flush();

                    $report = new ImportReport(
                        filename: $filename,
                        status: $options->dryRun ? 'dry_run' : 'imported',
                        accountAlias: $account->getAlias(),
                        accountMasked: $account->getNumberMasked(),
                        periodStart: $batch->getPeriodStart(),
                        periodEnd: $batch->getPeriodEnd(),
                        added: $added,
                        skipped: $skipped,
                        probableDuplicates: $probable,
                        enriched: $enriched,
                    );
                    if ($options->dryRun) {
                        // Provoque le rollback de la transaction : rien n'est écrit en dry-run.
                        throw new DryRunSignal($report);
                    }

                    return $report;
                },
            );
        } catch (DryRunSignal $signal) {
            return $signal->report;
        } catch (NotAnAccountStatementException $exception) {
            // Pas un relevé d'opérations (ex. relevé annuel de frais) : relancé tel quel,
            // sans enregistrer de lot « failed ».
            throw $exception;
        } catch (\Throwable $exception) {
            if (!$options->dryRun) {
                $this->recordFailedBatch($path, $options->originalFilename ?? basename($path), $sha256, $resolvedAccount, $exception);
            }
            throw $exception;
        } finally {
            // Un rollback (dry-run, relevé de frais, erreur) ferme l'EntityManager (ORM 3) :
            // on le rouvre pour que l'appelant (post-traitement) ne reçoive jamais un EM fermé.
            $this->entityManager = $this->openEntityManager();
        }
    }

    /**
     * Enregistre (hors transaction courante, dans une nouvelle) un lot en échec
     * avec le message d'erreur, puis l'exception est relancée par l'appelant.
     */
    private function logUnexpected(\Throwable $exception, string $path): void
    {
        if (!ImportFailure::isExpected($exception)) {
            $this->logger->error('Échec inattendu de l\'import de {file}', ['file' => basename($path), 'exception' => $exception]);
        }
    }

    private function recordFailedBatch(string $path, string $filename, string $sha256, ?\App\Entity\Account $account, \Throwable $exception): void
    {
        try {
            $entityManager = $this->openEntityManager();
            $entityManager->clear();
            $format = str_ends_with(mb_strtolower($path), '.pdf') ? ImportFormat::Pdf : ImportFormat::Csv;
            // Ancien lot en échec du même fichier (restauré par le rollback) : remplacé.
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM import_batch WHERE sha256 = ? AND status <> ?',
                [$sha256, ImportStatus::Completed->value],
            );
            $batch = new ImportBatch($filename, $sha256, $format, ImportStatus::Failed);
            // Le compte peut provenir de la transaction annulée : on ne garde qu'une référence s'il existe en base.
            if (null !== $account?->getId() && null !== $entityManager->find(\App\Entity\Account::class, $account->getId())) {
                $batch->setAccount($entityManager->getReference(\App\Entity\Account::class, $account->getId()));
            }
            $batch->setErrorMessage(ImportFailure::publicMessage($exception));
            $entityManager->persist($batch);
            $entityManager->flush();
        } catch (\Throwable) {
            // L'enregistrement du lot en échec ne doit jamais masquer l'exception d'origine.
        }
    }

    private function openEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->registry->getManager();
        if ($entityManager instanceof EntityManagerInterface && !$entityManager->isOpen()) {
            $this->registry->resetManager();
            $entityManager = $this->registry->getManager();
        }
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }
}
