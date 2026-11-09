<?php

declare(strict_types=1);

namespace App\Api\Presenter;

use App\Entity\Category;

final class CategoryPresenter
{
    /** @return array{id: int, name: string, fullName: string, kind: string, color: ?string, icon: ?string, chartSlot: ?int} */
    public function presentRef(Category $category): array
    {
        return [
            'id' => (int) $category->getId(),
            'name' => $category->getName(),
            'fullName' => $category->getFullName(),
            'kind' => $category->getKind()->value,
            'color' => $category->getColor(),
            'icon' => $category->getIcon(),
            // chartSlot : celui de la racine (les sous-catégories en héritent).
            'chartSlot' => $category->getRootChartSlot(),
        ];
    }

    /**
     * @param array<int, int> $operationsCountById compteurs d'opérations par catégorie
     * @return array<string, mixed>
     */
    public function present(Category $category, array $operationsCountById = []): array
    {
        $id = (int) $category->getId();
        $children = $category->getChildren()->toArray();
        usort($children, static fn (Category $a, Category $b): int => [$a->getPosition(), (int) $a->getId()] <=> [$b->getPosition(), (int) $b->getId()]);

        return $this->presentRef($category) + [
            'position' => $category->getPosition(),
            'archived' => $category->isArchived(),
            'vehicleRelated' => $category->isVehicleRelated(),
            'fuel' => $category->isFuel(),
            'parentId' => null !== $category->getParent() ? (int) $category->getParent()->getId() : null,
            'operationsCount' => $operationsCountById[$id] ?? 0,
            'children' => array_map(
                fn (Category $child): array => $this->present($child, $operationsCountById),
                $children,
            ),
        ];
    }
}
