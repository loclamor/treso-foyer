<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

/**
 * Pagination commune : ?page=1&perPage=50 (max 200) ⇒ {items, total, page, perPage}.
 */
final readonly class Pagination
{
    public const int DEFAULT_PER_PAGE = 50;
    public const int MAX_PER_PAGE = 200;

    public function __construct(
        public int $page,
        public int $perPage,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $page = $request->query->get('page', '1');
        $perPage = $request->query->get('perPage', (string) self::DEFAULT_PER_PAGE);
        if (!ctype_digit((string) $page) || !ctype_digit((string) $perPage)) {
            throw new BadRequestException('Paramètres page/perPage invalides.', 'invalid_query');
        }

        return new self(
            max(1, (int) $page),
            min(self::MAX_PER_PAGE, max(1, (int) $perPage)),
        );
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    /**
     * @param list<mixed> $items
     * @return array{items: list<mixed>, total: int, page: int, perPage: int}
     */
    public function envelope(array $items, int $total): array
    {
        return [
            'items' => $items,
            'total' => $total,
            'page' => $this->page,
            'perPage' => $this->perPage,
        ];
    }
}
