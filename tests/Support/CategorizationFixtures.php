<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Operation;
use App\Enum\AccountType;
use App\Setup\DefaultCategories;
use App\Util\Text;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fabrique de données fictives pour les tests de catégorisation (base de test uniquement).
 */
final class CategorizationFixtures
{
    /**
     * Crée l'arbre de catégories par défaut du foyer (comme app:setup).
     */
    public static function seedCategories(EntityManagerInterface $entityManager, Household $household): void
    {
        $repository = $entityManager->getRepository(Category::class);
        $position = 0;
        foreach (DefaultCategories::TREE as $rootData) {
            $root = new Category($household, $rootData['name'], $rootData['kind']);
            $root->setIcon($rootData['icon']);
            $root->setColor($rootData['color']);
            $root->setChartSlot($rootData['chartSlot']);
            $root->setPosition($position++);
            $entityManager->persist($root);
            foreach ($rootData['children'] as $childPosition => $childName) {
                $child = new Category($household, $childName, $rootData['kind']);
                $root->addChild($child); // renseigne aussi la collection inverse (getChildren())
                $child->setPosition($childPosition);
                $child->setVehicleRelated(DefaultCategories::isVehicleRelated($rootData['name'], $childName));
                $child->setFuel(DefaultCategories::isFuel($rootData['name'], $childName));
                $child->setFinancing(DefaultCategories::isFinancing($rootData['name'], $childName));
                $child->setGas(DefaultCategories::isGas($rootData['name'], $childName));
                $entityManager->persist($child);
            }
        }
        $entityManager->flush();
    }

    public static function makeAccount(Household $household, string $alias): Account
    {
        return new Account(
            $household,
            $alias,
            'Compte '.$alias,
            AccountType::Personal,
            hash('sha256', 'fixture-'.$alias),
            '****'.strtoupper(substr($alias, -4)),
        );
    }

    public static function makeOperation(Account $account, string $date, int $amount, string $rawLabel, ?string $merchant = null): Operation
    {
        $operation = new Operation(
            $account,
            new \DateTimeImmutable($date),
            $amount,
            $rawLabel,
            Text::fold($rawLabel),
            hash('sha256', $account->getAlias().'|'.$date.'|'.$amount.'|'.Text::fold($rawLabel).'|'.uniqid('', true)),
        );
        $operation->setMerchant($merchant);

        return $operation;
    }
}
