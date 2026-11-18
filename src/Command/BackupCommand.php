<?php

declare(strict_types=1);

namespace App\Command;

use App\Backup\BackupInterval;
use App\Backup\BackupManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sauvegarde la base (pg_dump gzipé dans data/backups/, rotation BACKUP_KEEP dumps).
 * Lancée en boucle par le conteneur php quand BACKUP_ENABLED=true (voir .env et
 * frankenphp/docker-entrypoint.sh) : `app:backup --watch --interval=$BACKUP_INTERVAL`.
 */
#[AsCommand(name: 'app:backup', description: 'Dump pg_dump de la base dans data/backups/ (gzip), avec rotation')]
final class BackupCommand extends Command
{
    public function __construct(
        private readonly BackupManager $backupManager,
        private readonly string $defaultInterval,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('keep', null, InputOption::VALUE_REQUIRED, 'Nombre de dumps conservés (défaut : BACKUP_KEEP)')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Intervalle entre deux backups en mode --watch (défaut : BACKUP_INTERVAL, ex. 3600, 30m, 12h, 1d, 1w)', $this->defaultInterval)
            ->addOption('watch', null, InputOption::VALUE_NONE, 'Boucle infinie : sauvegarde, attend l’intervalle, recommence (tourne en arrière-plan dans le conteneur php)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keep = $input->getOption('keep') !== null ? (int) $input->getOption('keep') : null;

        if (!$input->getOption('watch')) {
            return $this->runOnce($io, $keep);
        }

        $seconds = BackupInterval::toSeconds((string) $input->getOption('interval'));
        $io->note(sprintf('Mode watch : un dump toutes les %d secondes (%s).', $seconds, (string) $input->getOption('interval')));
        $this->logger->info('app:backup --watch démarré.', ['interval' => $seconds]);

        for (;;) {
            try {
                $this->runOnce($io, $keep);
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
                $this->logger->error('Backup échoué en mode watch.', ['exception' => $e]);
            }
            sleep($seconds);
        }
    }

    private function runOnce(SymfonyStyle $io, ?int $keep): int
    {
        try {
            $result = $this->backupManager->backup($keep);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s (%s, %d dump(s) supprimé(s) par rotation)',
            $result->path,
            $this->humanSize($result->sizeBytes),
            $result->pruned,
        ));

        return Command::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => sprintf('%.1f Mo', $bytes / 1048576),
            $bytes >= 1024 => sprintf('%.1f Ko', $bytes / 1024),
            default => $bytes . ' o',
        };
    }
}
