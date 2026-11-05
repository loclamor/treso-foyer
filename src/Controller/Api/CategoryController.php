<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\ConflictException;
use App\Api\HouseholdProvider;
use App\Api\NotFoundException;
use App\Api\Presenter\CategoryPresenter;
use App\Api\Query\StatsQuery;
use App\Api\ValidationException;
use App\Entity\Category;
use App\Entity\Household;
use App\Enum\CategoryKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class CategoryController extends AbstractController
{
    #[Route('/api/categories', name: 'api_categories', methods: ['GET'])]
    public function list(
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        StatsQuery $statsQuery,
        CategoryPresenter $categoryPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $roots = $entityManager->getRepository(Category::class)->findBy(['household' => $household, 'parent' => null]);
        usort($roots, static fn (Category $a, Category $b): int => [$a->getPosition(), (int) $a->getId()] <=> [$b->getPosition(), (int) $b->getId()]);
        $counts = $statsQuery->categoryOperationCounts($household);

        return new JsonResponse(array_map(
            static fn (Category $root): array => $categoryPresenter->present($root, $counts),
            $roots,
        ));
    }

    #[Route('/api/categories', name: 'api_category_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] \App\Api\Input\CategoryCreateInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        StatsQuery $statsQuery,
        CategoryPresenter $categoryPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $parent = null;
        if (null !== $input->parentId) {
            $parent = $entityManager->getRepository(Category::class)->findOneBy(['id' => $input->parentId, 'household' => $household]);
            if (!$parent instanceof Category) {
                throw new ValidationException([['field' => 'parentId', 'message' => 'Catégorie parente introuvable dans le foyer.']]);
            }
        }
        $existing = $entityManager->getRepository(Category::class)->findOneBy([
            'household' => $household,
            'parent' => $parent,
            'name' => $input->name,
        ]);
        if ($existing instanceof Category) {
            throw new ConflictException('Une catégorie de ce nom existe déjà à cet endroit de l’arbre.', 'category_exists');
        }

        $category = new Category($household, (string) $input->name, CategoryKind::from((string) $input->kind));
        $category->setParent($parent);
        $category->setColor($input->color);
        $category->setIcon($input->icon);
        // Position : après les frères et sœurs existants.
        $siblings = $entityManager->getRepository(Category::class)->findBy(['household' => $household, 'parent' => $parent]);
        $category->setPosition(array_reduce($siblings, static fn (int $carry, Category $c): int => max($carry, $c->getPosition() + 1), 0));
        $entityManager->persist($category);
        $entityManager->flush();

        $counts = $statsQuery->categoryOperationCounts($household);

        return new JsonResponse($categoryPresenter->present($category, $counts), Response::HTTP_CREATED);
    }

    #[Route('/api/categories/{id}', name: 'api_category_patch', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function patch(
        int $id,
        Request $request,
        #[MapRequestPayload] \App\Api\Input\CategoryPatchInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        StatsQuery $statsQuery,
        CategoryPresenter $categoryPresenter,
    ): JsonResponse {
        $household = $householdProvider->get();
        $category = $this->find($entityManager, $household, $id);

        if (null !== $input->name) {
            $category->setName($input->name);
        }
        if (null !== $input->kind) {
            $category->setKind(CategoryKind::from($input->kind));
        }
        if (null !== $input->color) {
            $category->setColor($input->color);
        }
        if (null !== $input->icon) {
            $category->setIcon($input->icon);
        }
        if (null !== $input->position) {
            $category->setPosition($input->position);
        }
        if (null !== $input->archived) {
            $category->setArchived($input->archived);
        }
        if (null !== $input->parentId) {
            $parent = $this->find($entityManager, $household, $input->parentId);
            $forbidden = $this->descendantIds($entityManager, $category);
            $forbidden[] = (int) $category->getId();
            if (in_array((int) $parent->getId(), $forbidden, true)) {
                throw new ValidationException([['field' => 'parentId', 'message' => 'La catégorie parente ne peut pas être elle-même ou une de ses sous-catégories.']]);
            }
            $category->setParent($parent);
        }
        // chartSlot : la clé présente dans le corps (même null) est appliquée, absente = inchangé.
        if (array_key_exists('chartSlot', $request->toArray())) {
            $slot = $input->chartSlot;
            if (null !== $slot) {
                $rootExpense = null === $category->getParent() && CategoryKind::Expense === $category->getKind();
                if (!$rootExpense) {
                    throw new ValidationException([['field' => 'chartSlot', 'message' => 'chartSlot est réservé aux racines de catégorie de type « expense ».']]);
                }
                $taken = $entityManager->getRepository(Category::class)->findOneBy(['household' => $household, 'chartSlot' => $slot]);
                if ($taken instanceof Category && (int) $taken->getId() !== (int) $category->getId()) {
                    throw new ConflictException('Cet emplacement est déjà pris par une autre catégorie du foyer.', 'chart_slot_taken');
                }
            }
            $category->setChartSlot($slot);
        }

        // Unicité (foyer, parent, nom) après modification.
        $conflict = $entityManager->getRepository(Category::class)->findOneBy([
            'household' => $household,
            'parent' => $category->getParent(),
            'name' => $category->getName(),
        ]);
        if ($conflict instanceof Category && (int) $conflict->getId() !== (int) $category->getId()) {
            throw new ConflictException('Une catégorie de ce nom existe déjà à cet endroit de l’arbre.', 'category_exists');
        }

        $entityManager->flush();
        $counts = $statsQuery->categoryOperationCounts($household);

        return new JsonResponse($categoryPresenter->present($category, $counts));
    }

    #[Route('/api/categories/{id}', name: 'api_category_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(
        int $id,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
    ): Response {
        $household = $householdProvider->get();
        $category = $this->find($entityManager, $household, $id);
        $ids = $this->descendantIds($entityManager, $category);
        $ids[] = (int) $category->getId();

        $connection = $entityManager->getConnection();
        $usage = $connection->fetchOne(
            'SELECT
                (SELECT COUNT(*) FROM operation WHERE category_id IN (?))::int
              + (SELECT COUNT(*) FROM categorization_rule WHERE category_id IN (?))::int
              + (SELECT COUNT(*) FROM budget WHERE category_id IN (?))::int',
            [$ids, $ids, $ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        if ((int) $usage > 0) {
            throw new ConflictException('Catégorie utilisée (opérations, règles ou budgets) : archivez-la plutôt que de la supprimer.', 'category_in_use');
        }

        $entityManager->remove($category); // les sous-catégories partent en cascade (onDelete CASCADE)
        $entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function find(EntityManagerInterface $entityManager, Household $household, int $id): Category
    {
        $category = $entityManager->getRepository(Category::class)->findOneBy(['id' => $id, 'household' => $household]);
        if (!$category instanceof Category) {
            throw new NotFoundException('Catégorie introuvable.');
        }

        return $category;
    }

    /** @return list<int> ids des sous-catégories (récursif) */
    private function descendantIds(EntityManagerInterface $entityManager, Category $category): array
    {
        $ids = [];
        $current = [(int) $category->getId()];
        for ($depth = 0; $depth < 5 && [] !== $current; ++$depth) {
            $children = $entityManager->getRepository(Category::class)->createQueryBuilder('c')
                ->select('c.id')
                ->where('c.parent IN (:parents)')
                ->setParameter('parents', $current)
                ->getQuery()->getSingleColumnResult();
            $current = array_map('intval', $children);
            $ids = array_merge($ids, $current);
        }

        return $ids;
    }
}
