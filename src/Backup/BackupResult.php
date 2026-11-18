<?php

declare(strict_types=1);

namespace App\Backup;

final readonly class BackupResult
{
    public function __construct(
        public string $path,
        public int $sizeBytes,
        public int $pruned,
    ) {
    }
}
