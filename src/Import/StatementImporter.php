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
    ) {
    }

    /**
     * @throws ParseException|UnsupportedFormatException|NotAnAccountStatementException|Exception\UnknownAccountException|\Throwable
     */
    public function import(string $path, ImportOptions $options): ImportReport
    {
        // Un rollback (dry-run, erreur) ferme l'EntityManager (ORM 3) : on repart d'un EM ouvert à chaque fichier.
        $this->entityManager = $this->openEntityManager();

        $raw = @file_get_contents($path);
        if (false === $raw) {
            throw new ParseException(sprintf('Fichier illisible : %s.', basename($path)));
        }
        $sha256 = hash('sha256', $raw);

        $batchRepository = $this->entityManager->getRepository(ImportBatch::class);
        $existing = $batchRepository->findOneBy(['sha256' => $sha256]);
        if ($existing instanceof ImportBatch) {
            if (ImportStatus::Completed === $existing->getStatus()) {
                return new ImportReport(
                    filename: basename($path),
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
            // Lot en échec avec ce hash : on le supprime avant de réessayer.
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }

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

        $resolvedAccount = null;
        try {
            return $this->entityManager->wrapInTransaction(
                function () use ($path, $sha256, $parser, $options, &$resolvedAccount): ImportReport {
                    $parsed = $parser->parse($path);
                    $account = $this->accountResolver->resolve($parsed->accountNumber, $options->autoCreateAccount);
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
                $this->recordFailedBatch($path, $sha256, $resolvedAccount, $exception);
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
    private function recordFailedBatch(string $path, string $sha256, ?\App\Entity\Account $account, \Throwable $exception): void
    {
        try {
            $entityManager = $this->openEntityManager();
            $entityManager->clear();
            $format = str_ends_with(mb_strtolower($path), '.pdf') ? ImportFormat::Pdf : ImportFormat::Csv;
            $batch = new ImportBatch(basename($path), $sha256, $format, ImportStatus::Failed);
            // Le compte peut provenir de la transaction annulée : on ne garde qu'une référence s'il existe en base.
            if (null !== $account?->getId() && null !== $entityManager->find(\App\Entity\Account::class, $account->getId())) {
                $batch->setAccount($entityManager->getReference(\App\Entity\Account::class, $account->getId()));
            }
            $batch->setErrorMessage($exception->getMessage());
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
