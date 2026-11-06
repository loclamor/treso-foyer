<?php

declare(strict_types=1);

namespace App\Command;

use App\Import\CardDateExtractor;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recalcule `spent_at` des opérations importées (label_source NOT NULL) depuis leur libellé
 * brut (« ACHAT CB <COMMERÇANT> JJ.MM.AA … », voir App\Import\CardDateExtractor et ADR 0014),
 * avec les mêmes garde-fous qu'à l'import (date valide, ≤ date du relevé, écart ≤ 31 jours).
 *
 * N'écrase jamais le spentAt d'une opération rapprochée d'une saisie manuelle
 * (reconciled_at IS NOT NULL) ni d'une saisie manuelle (awaiting_statement). Idempotente.
 */
#[AsCommand(name: 'app:operations:spent-at', description: 'Recalcule spent_at des opérations importées depuis leurs libellés bruts')]
final class SpentAtCommand extends Command
{
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly CardDateExtractor $cardDateExtractor = new CardDateExtractor(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche le nombre de modifications sans rien écrire');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $entityManager = $this->registry->getManager();
        \assert($entityManager instanceof EntityManagerInterface);
        $connection = $entityManager->getConnection();

        $rows = $connection->fetchAllAssociative(
            'SELECT id, raw_label, date, spent_at FROM operation
             WHERE label_source IS NOT NULL AND reconciled_at IS NULL AND NOT awaiting_statement
             ORDER BY id',
        );

        /** @var array<int, ?string> $newValues id => spent_at 'AAAA-MM-JJ' ou null */
        $newValues = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $operationDate = new \DateTimeImmutable((string) $row['date']);
            $spentAt = $this->cardDateExtractor->guard(
                $this->cardDateExtractor->extract((string) $row['raw_label']),
                $operationDate,
            );
            $newValue = $spentAt?->format('Y-m-d');
            $current = null !== $row['spent_at'] ? (new \DateTimeImmutable((string) $row['spent_at']))->format('Y-m-d') : null;
            if ($newValue !== $current) {
                $newValues[$id] = $newValue;
            }
        }

        $io->writeln(sprintf(
            '%d opération(s) importée(s) analysée(s), %d modifiée(s)%s.',
            count($rows),
            count($newValues),
            $dryRun ? ' (dry-run, rien n’est écrit)' : '',
        ));

        if ($dryRun || [] === $newValues) {
            return Command::SUCCESS;
        }

        $connection->beginTransaction();
        try {
            foreach ($newValues as $id => $spentAt) {
                $connection->executeStatement(
                    'UPDATE operation SET spent_at = :spentAt WHERE id = :id',
                    ['spentAt' => null === $spentAt ? null : new \DateTimeImmutable($spentAt), 'id' => $id],
                    ['spentAt' => null === $spentAt ? ParameterType::NULL : Types::DATE_IMMUTABLE, 'id' => ParameterType::INTEGER],
                );
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }
        $entityManager->clear();

        $io->success(sprintf('%d opération(s) modifiée(s).', count($newValues)));

        return Command::SUCCESS;
    }
}
