<?php

declare(strict_types=1);

namespace App\Import;

use App\Import\Model\ImportReport;

/**
 * Signal interne : provoqué en dry-run pour faire rollback la transaction
 * après avoir tout calculé, tout en récupérant le rapport.
 *
 * @internal
 */
final class DryRunSignal extends \RuntimeException
{
    public function __construct(public readonly ImportReport $report)
    {
        parent::__construct('Dry-run : rollback demandé.');
    }
}
