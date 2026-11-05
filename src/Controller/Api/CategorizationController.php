<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\HouseholdProvider;
use App\Api\Input\CategorizationRunInput;
use App\Categorization\CategorizeOptions;
use App\Categorization\Categorizer;
use App\Entity\Operation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final class CategorizationController extends AbstractController
{
    #[Route('/api/categorization/run', name: 'api_categorization_run', methods: ['POST'])]
    public function run(
        #[MapRequestPayload] CategorizationRunInput $input,
        HouseholdProvider $householdProvider,
        EntityManagerInterface $entityManager,
        Categorizer $categorizer,
    ): JsonResponse {
        $household = $householdProvider->get();
        $operations = $entityManager->getRepository(Operation::class)->createQueryBuilder('o')
            ->join('o.account', 'a')
            ->where('a.household = :household')
            ->orderBy('o.id', 'ASC')
            ->setParameter('household', $household)
            ->getQuery()->toIterable();
        // all=false : seules les opérations non catégorisées ; all=true : toutes les non manuelles.
        $report = $categorizer->categorize($operations, new CategorizeOptions(onlyUncategorized: !$input->all));

        return new JsonResponse([
            'processed' => $report->processed,
            'byRule' => $report->byRule,
            'bySimilarity' => $report->bySimilarity,
            'stillUncategorized' => $report->stillUncategorized,
            'categorizedPercent' => $report->processed > 0 ? round(100 * ($report->byRule + $report->bySimilarity) / $report->processed, 1) : 0.0,
        ]);
    }
}
