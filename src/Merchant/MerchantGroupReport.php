<?php

declare(strict_types=1);

namespace App\Merchant;

/**
 * Compteurs du regroupement des commerçants (jamais de libellé).
 */
final class MerchantGroupReport
{
    public function __construct(
        /** Groupes d'au moins deux variantes. */
        public int $groups = 0,
        /** Opérations dont merchant_group a changé. */
        public int $updated = 0,
    ) {
    }

    /** @return array{groups: int, updated: int} */
    public function toArray(): array
    {
        return ['groups' => $this->groups, 'updated' => $this->updated];
    }
}
