<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Entity\ImportBatch;

final class ImportBatchPresenter
{
    public function __construct(
        private readonly AccountPresenter $accountPresenter,
        private readonly MemberPresenter $memberPresenter,
    ) {
    }

    /** @return array<string, mixed> */
    public function present(ImportBatch $batch): array
    {
        return [
            'id' => (int) $batch->getId(),
            'filename' => $batch->getOriginalFilename(),
            'format' => $batch->getFormat()->value,
            'status' => $batch->getStatus()->value,
            'account' => null !== $batch->getAccount() ? $this->accountPresenter->presentRef($batch->getAccount()) : null,
            'periodStart' => $batch->getPeriodStart()?->format('Y-m-d'),
            'periodEnd' => $batch->getPeriodEnd()?->format('Y-m-d'),
            'added' => $batch->getAddedCount(),
            'skipped' => $batch->getSkippedCount(),
            'enriched' => $batch->getEnrichedCount(),
            'probableDuplicates' => $batch->getProbableDuplicateCount(),
            'error' => $batch->getErrorMessage(),
            'importedAt' => $batch->getImportedAt()->format('c'),
            'importedBy' => null !== $batch->getImportedBy() ? $this->memberPresenter->present($batch->getImportedBy()) : null,
        ];
    }
}
