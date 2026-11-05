<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Category;
use App\Entity\Household;
use App\Enum\CategoryKind;
use PHPUnit\Framework\TestCase;

final class CategoryTest extends TestCase
{
    public function testGetFullNameOfRootCategory(): void
    {
        $household = new Household('Foyer test');
        $root = new Category($household, 'Logement', CategoryKind::Expense);

        self::assertSame('Logement', $root->getFullName());
    }

    public function testGetFullNameJoinsParentChain(): void
    {
        $household = new Household('Foyer test');
        $root = new Category($household, 'Logement', CategoryKind::Expense);
        $child = new Category($household, 'Énergie', CategoryKind::Expense);
        $root->addChild($child);

        self::assertSame('Logement › Énergie', $child->getFullName());
        self::assertSame($root, $child->getParent());
    }
}
