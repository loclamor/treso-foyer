<?php

declare(strict_types=1);

namespace App\Backup;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Process\Process;

/**
 * Dump pg_dump de la base (connexion extraite de DATABASE_URL), compressé en gzip dans
 * le dossier de backups, avec rotation (les N plus anciens sont conservés).
 *
 * Le binaire pg_dump est embarqué dans l'image (postgresql-client, voir Dockerfile) : le
 * dump se fait depuis le conteneur php, sans passer par `docker compose exec database`.
 * Voir docs/adr/0019-backups-automatisees.md.
 */
final class BackupManager
{
    private const string FILE_PATTERN = 'banque-*.sql.gz';

    public function __construct(
        private readonly string $databaseUrl,
        private readonly string $backupDir,
        private readonly int $keep,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{host: string, port: int, user: string, password: string, dbname: string}
     */
    public static function parseDatabaseUrl(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'postgresql' || !isset($parts['host'], $parts['user'], $parts['path'])) {
            throw new \InvalidArgumentException(sprintf('DATABASE_URL invalide (attendu postgresql://user:pass@host:port/db) : "%s".', $url));
        }

        return [
            'host' => $parts['host'],
            'port' => (int) ($parts['port'] ?? 5432),
            'user' => rawurldecode($parts['user']),
            'password' => rawurldecode($parts['pass'] ?? ''),
            'dbname' => rawurldecode(ltrim($parts['path'], '/')),
        ];
    }

    public function backup(?int $keep = null): BackupResult
    {
        $keep ??= $this->keep;
        $db = self::parseDatabaseUrl($this->databaseUrl);

        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0775, true) && !is_dir($this->backupDir)) {
            throw new \RuntimeException(sprintf('Impossible de créer le dossier de backups "%s".', $this->backupDir));
        }

        $path = $this->backupDir . '/' . $this->fileName();
        $tmp = $path . '.tmp';

        $process = new Process(
            [
                'pg_dump',
                '-h', $db['host'],
                '-p', (string) $db['port'],
                '-U', $db['user'],
                '--no-owner',
                '--no-privileges',
                '--format=plain',
                $db['dbname'],
            ],
            env: ['PGPASSWORD' => $db['password']],
        );

        $gz = gzopen($tmp, 'wb9');
        if ($gz === false) {
            throw new \RuntimeException(sprintf('Impossible d’ouvrir "%s" en écriture.', $tmp));
        }
        try {
            $process->run(static function (string $type, string $buffer) use ($gz): void {
                if ($buffer !== '' && gzwrite($gz, $buffer) === false) {
                    throw new \RuntimeException('Échec d’écriture dans le fichier temporaire du dump.');
                }
            });
        } finally {
            gzclose($gz);
        }

        if (!$process->isSuccessful()) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('pg_dump a échoué (code %d) : %s', $process->getExitCode() ?? -1, trim($process->getErrorOutput())));
        }

        rename($tmp, $path);
        $size = (int) filesize($path);
        $pruned = $this->prune($keep);

        $this->logger->info('Backup base effectué.', ['path' => $path, 'size' => $size, 'pruned' => $pruned]);

        return new BackupResult($path, $size, $pruned);
    }

    /**
     * Supprime les dumps au-delà des $keep plus récents (noms horodatés = ordre chrono)
     * ainsi que les fichiers temporaires abandonnés. Retourne le nombre de dumps supprimés.
     */
    public function prune(int $keep): int
    {
        foreach (glob($this->backupDir . '/*.tmp') ?: [] as $tmp) {
            @unlink($tmp);
        }

        $files = glob($this->backupDir . '/' . self::FILE_PATTERN) ?: [];
        sort($files);

        $toDelete = array_slice($files, 0, max(0, \count($files) - $keep));
        foreach ($toDelete as $file) {
            if (@unlink($file)) {
                $this->logger->info('Dump supprimé (rotation).', ['path' => $file]);
            }
        }

        return \count($toDelete);
    }

    private function fileName(): string
    {
        $name = 'banque-' . $this->clock->now()->format('Ymd-His') . '.sql.gz';
        $suffix = 1;
        while (file_exists($this->backupDir . '/' . $name)) {
            $name = 'banque-' . $this->clock->now()->format('Ymd-His') . '-' . $suffix++ . '.sql.gz';
        }

        return $name;
    }
}
