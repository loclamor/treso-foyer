<?php

declare(strict_types=1);

namespace App\Tests\Integration\Vehicle;

use App\Entity\Account;
use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Operation;
use App\Entity\Vehicle;
use App\Enum\CategorizationSource;
use App\Enum\RuleMatchField;
use App\Enum\RuleMatchType;
use App\Enum\VehicleSource;
use App\Setup\CategoryResolver;
use App\Tests\Support\CategorizationFixtures;
use App\Vehicle\VehicleAssigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Synchronisation de l'axe véhicule (base de test réelle, données fictives uniquement).
 */
final class VehicleAssignerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private VehicleAssigner $assigner;
    private Household $household;
    private Account $account;
    private CategoryResolver $categoryResolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->assigner = $container->get(VehicleAssigner::class);
        $this->categoryResolver = $container->get(CategoryResolver::class);

        $this->household = new Household('Foyer test');
        $this->entityManager->persist($this->household);
        CategorizationFixtures::seedCategories($this->entityManager, $this->household);
        $this->account = CategorizationFixtures::makeAccount($this->household, 'perso-a');
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();
    }

    private function category(string $fullName): Category
    {
        return $this->categoryResolver->require($this->household, $fullName);
    }

    private function vehicle(string $name, ?string $acquiredAt = null, ?string $disposedAt = null): Vehicle
    {
        $vehicle = new Vehicle($this->household, $name);
        $vehicle->setAcquiredAt(null === $acquiredAt ? null : new \DateTimeImmutable($acquiredAt));
        $vehicle->setDisposedAt(null === $disposedAt ? null : new \DateTimeImmutable($disposedAt));
        $this->entityManager->persist($vehicle);
        $this->entityManager->flush();

        return $vehicle;
    }

    private function persistOperation(string $date, string $label, ?Category $category = null, ?CategorizationRule $rule = null): Operation
    {
        $operation = CategorizationFixtures::makeOperation($this->account, $date, -1000, $label, 'TOTAL');
        if (null !== $category) {
            $operation->categorize($category, CategorizationSource::Rule, $rule);
        }
        $this->entityManager->persist($operation);
        $this->entityManager->flush();

        return $operation;
    }

    private function ruleWithVehicle(Category $category, Vehicle $vehicle): CategorizationRule
    {
        $rule = new CategorizationRule($this->household, $category, RuleMatchField::Merchant, RuleMatchType::Exact, 'TOTAL');
        $rule->setVehicle($vehicle);
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        return $rule;
    }

    /** @return array{vehicle_id: ?int, vehicle_source: ?string} */
    private function vehicleState(int $operationId): array
    {
        $row = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT vehicle_id, vehicle_source FROM operation WHERE id = :id',
            ['id' => $operationId],
        );

        return [
            'vehicle_id' => null === $row['vehicle_id'] ? null : (int) $row['vehicle_id'],
            'vehicle_source' => null === $row['vehicle_source'] ? null : (string) $row['vehicle_source'],
        ];
    }

    public function testAutoAssignmentWhenSingleActiveVehicle(): void
    {
        $carburant = $this->category('Transport › Carburant');
        self::assertTrue($carburant->isVehicleRelated());
        $vehicle = $this->vehicle('Clio');
        $operation = $this->persistOperation('2026-03-10', 'ACHAT CB TOTAL 10.03.26', $carburant);

        $report = $this->assigner->sync($this->household);

        self::assertSame(1, $report->processed);
        self::assertSame(1, $report->auto);
        self::assertSame(0, $report->byRule);
        self::assertSame(0, $report->cleared);
        self::assertSame(['vehicle_id' => $vehicle->getId(), 'vehicle_source' => VehicleSource::Auto->value], $this->vehicleState($operation->getId()));
    }

    public function testRuleWithVehicleWinsOverAuto(): void
    {
        $carburant = $this->category('Transport › Carburant');
        $v1 = $this->vehicle('Clio');
        $v2 = $this->vehicle('308');
        $rule = $this->ruleWithVehicle($carburant, $v1);
        $operation = $this->persistOperation('2026-03-10', 'ACHAT CB TOTAL 10.03.26', $carburant, $rule);

        $report = $this->assigner->sync($this->household);

        // 2 véhicules actifs : l'auto ne s'appliquerait pas, mais la règle désigne v1.
        self::assertSame(1, $report->byRule);
        self::assertSame(0, $report->auto);
        self::assertSame(['vehicle_id' => $v1->getId(), 'vehicle_source' => VehicleSource::Rule->value], $this->vehicleState($operation->getId()));
    }

    public function testRuleVehicleAppliesEvenIfCategoryNotVehicleRelated(): void
    {
        $courses = $this->category('Alimentation › Courses');
        self::assertFalse($courses->isVehicleRelated());
        $v1 = $this->vehicle('Clio');
        $rule = $this->ruleWithVehicle($courses, $v1);
        $operation = $this->persistOperation('2026-03-10', 'ACHAT CB TOTAL 10.03.26', $courses, $rule);

        $report = $this->assigner->sync($this->household);

        self::assertSame(1, $report->byRule);
        self::assertSame(['vehicle_id' => $v1->getId(), 'vehicle_source' => VehicleSource::Rule->value], $this->vehicleState($operation->getId()));
    }

    public function testManualAssignmentIsNeverModified(): void
    {
        $carburant = $this->category('Transport › Carburant');
        $v1 = $this->vehicle('Clio');
        $this->vehicle('308'); // 2 véhicules actifs : l'auto ne s'appliquerait pas
        $operation = $this->persistOperation('2026-03-10', 'ACHAT CB TOTAL 10.03.26', $carburant);
        $operation->assignVehicle($v1, VehicleSource::Manual);
        $this->entityManager->flush();

        $report = $this->assigner->sync($this->household);

        // Opération manuelle : pas candidate, donc pas comptée ni modifiée.
        self::assertSame(0, $report->processed);
        self::assertSame(['vehicle_id' => $v1->getId(), 'vehicle_source' => VehicleSource::Manual->value], $this->vehicleState($operation->getId()));
    }

    public function testClearedWhenSecondVehicleBecomesActive(): void
    {
        $carburant = $this->category('Transport › Carburant');
        $v1 = $this->vehicle('Clio');
        $operation = $this->persistOperation('2026-03-10', 'ACHAT CB TOTAL 10.03.26', $carburant);

        $first = $this->assigner->sync($this->household);
        self::assertSame(1, $first->auto);
        self::assertSame($v1->getId(), $this->vehicleState($operation->getId())['vehicle_id']);

        // Un 2e véhicule actif à la date de l'opération : l'affectation auto devient caduque.
        $this->vehicle('308');
        $second = $this->assigner->sync($this->household);

        self::assertSame(1, $second->processed);
        self::assertSame(1, $second->cleared);
        self::assertSame(['vehicle_id' => null, 'vehicle_source' => null], $this->vehicleState($operation->getId()));
    }

    public function testIdempotent(): void
    {
        $carburant = $this->category('Transport › Carburant');
        $this->vehicle('Clio');
        $this->persistOperation('2026-03-10', 'ACHAT CB TOTAL 10.03.26', $carburant);

        $this->assigner->sync($this->household);
        $second = $this->assigner->sync($this->household);

        self::assertSame(1, $second->processed);
        self::assertSame(1, $second->unchanged);
        self::assertSame(0, $second->byRule);
        self::assertSame(0, $second->auto);
        self::assertSame(0, $second->cleared);
    }

    public function testDryRunCountsButWritesNothing(): void
    {
        $carburant = $this->category('Transport › Carburant');
        $this->vehicle('Clio');
        $operation = $this->persistOperation('2026-03-10', 'ACHAT CB TOTAL 10.03.26', $carburant);

        $report = $this->assigner->sync($this->household, dryRun: true);

        self::assertSame(1, $report->auto);
        self::assertSame(['vehicle_id' => null, 'vehicle_source' => null], $this->vehicleState($operation->getId()));
    }

    public function testUncategorizedOperationWithoutRuleVehicleIsNotCandidate(): void
    {
        $this->vehicle('Clio');
        $operation = $this->persistOperation('2026-03-10', 'ACHAT CB TOTAL 10.03.26'); // sans catégorie

        $report = $this->assigner->sync($this->household);

        self::assertSame(0, $report->processed);
        self::assertSame(['vehicle_id' => null, 'vehicle_source' => null], $this->vehicleState($operation->getId()));
    }

    public function testSyncWorksAfterEntityManagerClear(): void
    {
        $carburant = $this->category('Transport › Carburant');
        $this->vehicle('Clio');
        $operation = $this->persistOperation('2026-03-10', 'ACHAT CB TOTAL 10.03.26', $carburant);

        // Le Categorizer fait des clear() : sync ne doit pas dépendre de l'UnitOfWork.
        $this->entityManager->clear();
        $report = $this->assigner->sync($this->household);

        self::assertSame(1, $report->auto);
        self::assertNotNull($this->vehicleState($operation->getId())['vehicle_id']);
    }
}
