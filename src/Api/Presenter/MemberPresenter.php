<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Entity\Member;

final class MemberPresenter
{
    /** @return array{id: int, username: string, displayName: string} */
    public function present(Member $member): array
    {
        return [
            'id' => (int) $member->getId(),
            'username' => $member->getUsername(),
            'displayName' => $member->getDisplayName(),
        ];
    }
}
