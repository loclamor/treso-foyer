<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Entity\RecurringSeries;
use App\Enum\RecurrenceStatus;
use Symfony\Component\Clock\ClockInterface;

/**
 * Présentation d'une série récurrente (contrat docs/api.md, lot récurrences).
 */
final class RecurrencePresenter
{
    public function __construct(
        private readonly AccountPresenter $accountPresenter,
        private readonly CategoryPresenter $categoryPresenter,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(RecurringSeries $series, int $occurrences): array
    {
        $nextExpected = $series->getNextExpected();
        // En retard si l'échéance attendue + tolérance est passée (séries actives uniquement).
        $overdue = \in_array($series->getStatus(), [RecurrenceStatus::Detected, RecurrenceStatus::Confirmed], true)
            && null !== $nextExpected
            && $nextExpected->modify('+'.$series->getFrequency()->toleranceDays().' days') < $this->clock->now();

        return [
            'id' => (int) $series->getId(),
            'account' => $this->accountPresenter->presentRef($series->getAccount()),
            'merchant' => $series->getMerchant(),
            'direction' => $series->getDirection()->value,
            'category' => null !== $series->getCategory() ? $this->categoryPresenter->presentRef($series->getCategory()) : null,
            'frequency' => $series->getFrequency()->value,
            'frequencyLabel' => $series->getFrequency()->label(),
            'expectedAmount' => $series->getExpectedAmount(),
            'amountTolerance' => $series->getAmountTolerance(),
            'lastOccurrence' => $series->getLastOccurrence()->format('Y-m-d'),
            'nextExpected' => null !== $nextExpected ? $nextExpected->format('Y-m-d') : null,
            'activeMonths' => $series->getActiveMonths(),
            'status' => $series->getStatus()->value,
            'statusLabel' => $series->getStatus()->label(),
            'occurrences' => $occurrences,
            'overdue' => $overdue,
        ];
    }
}
