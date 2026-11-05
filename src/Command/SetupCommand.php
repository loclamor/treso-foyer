<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Category;
use App\Entity\Household;
use App\Repository\CategoryRepository;
use App\Repository\HouseholdRepository;
use App\Setup\DefaultCategories;
use App\Setup\DefaultRulesSeeder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée le foyer unique et l'arbre de catégories par défaut. Idempotent :
 * ne duplique jamais rien et ne modifie pas les catégories existantes.
 */
#[AsCommand(name: 'app:setup', description: 'Crée le foyer et l’arbre de catégories par défaut (idempotent)')]
final class SetupCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DefaultRulesSeeder $rulesSeeder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('household-name', null, InputOption::VALUE_OPTIONAL, 'Nom du foyer', 'Foyer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var HouseholdRepository $householdRepository */
        $householdRepository = $this->entityManager->getRepository(Household::class);
        $household = $householdRepository->findOneBy([]);
        $householdCreated = false;
        if (null === $household) {
            $household = new Household((string) $input->getOption('household-name'));
            $this->entityManager->persist($household);
            $householdCreated = true;
        }

        /** @var CategoryRepository $categoryRepository */
        $categoryRepository = $this->entityManager->getRepository(Category::class);
        $createdCount = 0;
        $position = 0;
        foreach (DefaultCategories::TREE as $rootData) {
            $root = $categoryRepository->findOneBy(['household' => $household, 'parent' => null, 'name' => $rootData['name']]);
            if (null === $root) {
                $root = new Category($household, $rootData['name'], $rootData['kind']);
                $root->setIcon($rootData['icon']);
                $root->setColor($rootData['color']);
                $root->setChartSlot($rootData['chartSlot']);
                $root->setPosition($position);
                $this->entityManager->persist($root);
                ++$createdCount;
            }
            $childPosition = 0;
            foreach ($rootData['children'] as $childName) {
                $child = $categoryRepository->findOneBy(['household' => $household, 'parent' => $root, 'name' => $childName]);
                if (null === $child) {
                    $child = new Category($household, $childName, $rootData['kind']);
                    $child->setParent($root);
                    $child->setPosition($childPosition);
                    $child->setVehicleRelated(DefaultCategories::isVehicleRelated($rootData['name'], $childName));
                    $this->entityManager->persist($child);
                    ++$createdCount;
                }
                ++$childPosition;
            }
            ++$position;
        }
        $this->entityManager->flush();

        $rulesCreated = $this->rulesSeeder->seed($household);

        $io->success(sprintf(
            'Foyer « %s » %s. %d catégorie(s) créée(s), %d règle(s) par défaut créée(s).',
            $household->getName(),
            $householdCreated ? 'créé' : 'déjà existant',
            $createdCount,
            $rulesCreated,
        ));

        return Command::SUCCESS;
    }
}
