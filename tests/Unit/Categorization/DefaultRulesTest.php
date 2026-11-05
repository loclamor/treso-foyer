<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categorization;

use App\Entity\CategorizationRule;
use App\Entity\Category;
use App\Entity\Household;
use App\Entity\Operation;
use App\Enum\CategoryKind;
use App\Setup\DefaultCategories;
use App\Setup\DefaultRules;
use App\Util\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Règles par défaut : catégories existantes, regex valides, exemples résolus
 * par un petit résolveur en mémoire appliquant les règles par priorité
 * (priority DESC, ordre d'origine ASC) comme le Categorizer.
 */
final class DefaultRulesTest extends TestCase
{
    /** @var array<string, Category> nom complet => catégorie */
    private array $categories = [];

    /** @var list<CategorizationRule> */
    private array $rules = [];

    protected function setUp(): void
    {
        $household = new Household('Foyer test');
        foreach (DefaultCategories::TREE as $rootData) {
            $root = new Category($household, $rootData['name'], $rootData['kind']);
            $this->categories[$root->getFullName()] = $root;
            foreach ($rootData['children'] as $childName) {
                $child = new Category($household, $childName, $rootData['kind']);
                $root->addChild($child);
                $this->categories[$child->getFullName()] = $child;
            }
        }
        foreach (DefaultRules::all() as [$categoryName, $field, $type, $pattern, $direction, $priority]) {
            $rule = new CategorizationRule($household, $this->categories[$categoryName], $field, $type, $pattern);
            $rule->setDirection($direction);
            $rule->setPriority($priority);
            $this->rules[] = $rule;
        }
    }

    public function testEveryReferencedCategoryExistsInDefaultTree(): void
    {
        foreach (DefaultRules::all() as [$categoryName]) {
            self::assertArrayHasKey($categoryName, $this->categories, sprintf('Catégorie « %s » introuvable dans DefaultCategories.', $categoryName));
        }
    }

    public function testEveryPatternCompilesWithIuFlags(): void
    {
        foreach (DefaultRules::all() as [, , , $pattern]) {
            self::assertNotFalse(@preg_match('~'.str_replace('~', '\\~', $pattern).'~iu', ''), sprintf('Regex invalide : %s', $pattern));
        }
    }

    public function testMerchantRulesAreRestrictedToCardPaymentsAndDirectDebits(): void
    {
        foreach (DefaultRules::MERCHANT_RULES as [$category, , $alternatives]) {
            $pattern = DefaultRules::merchantPattern($alternatives);
            self::assertStringStartsWith('^(ACHAT CB|[A-Z]+ CARTE|PRELEVEMENT DE)\b', $pattern);
            self::assertNull($this->resolve('VIREMENT DE PAUL MARTIN', -100, $pattern), $category);
        }
    }

    /**
     * @return list<array{0: string, 1: string}> [libellé brut, catégorie résolue]
     */
    public static function expectedExamples(): array
    {
        return [
            ['ACHAT CB CARREFOUR MARKET TOULOUSE', 'Alimentation › Courses'],
            ['ACHAT CB TOTALENERGIES INTERMARCHE', 'Transport › Carburant'],
            ['ACHAT CB CARREFOUR DAC 12.03.26', 'Transport › Carburant'],
            ['ACHAT CB CARREFOUR MARKET 12.03.26', 'Alimentation › Courses'],
            ['ACHAT CB CARREFOUR.FR 12.03.26', 'Alimentation › Courses'],
            ['ACHAT CB CARREFOURMARKET TOULOUSE', 'Alimentation › Courses'],
            ['ACHAT CB E.LECLERC DAC 12.03.26', 'Transport › Carburant'],
            ['ACHAT CB BOULANGER LABEGE', 'Shopping › Achats divers'],
            ['ACHAT CB BOULANGERIE DU COIN', 'Alimentation › Boulangerie & snacks'],
            ['ACHAT CB UBER EATS', 'Alimentation › Restaurants & bars'],
            ['ACHAT CB UBER TRIP', 'Transport › Transports en commun'],
            ['ACHAT CB PAYPAL SPOTIFY', 'Abonnements & télécoms › Streaming & médias'],
            ['COTISATION TRIMESTRIELLE DE VOTRE FORMULE', 'Banque & assurances › Frais bancaires'],
            ['PRELEVEMENT DE DGFIP IMPOT', 'Impôts & taxes'],
            ['PRELEVEMENT DE DIRECTION GENERALE D ES FINANCES PUBL', 'Impôts & taxes'],
            // Scolarité : quel que soit le moyen de paiement.
            ['ACHAT CB AEP OGEC SAINT 12.09.25 CARTE NUMERO 275', 'Scolarité › Frais de scolarité'],
            ['PRELEVEMENT DE OGEC ECOLE SAINTE MARIE', 'Scolarité › Frais de scolarité'],
            ['VIREMENT POUR APEL ECOLE COTISATION', 'Scolarité › Frais de scolarité'],
            ['PRELEVEMENT DE MAIRIE RESTAURATION SCOLAIRE', 'Scolarité › Cantine & garderie'],
            // Bricolage / jardinage : l'emportent sur « Équipement & mobilier ».
            ['ACHAT CB ADEO*LEROY MER 03.05.25 CARTE NUMERO 275', 'Shopping › Bricolage'],
            ['ACHAT CB LEROY MERLIN F 03.05.25', 'Shopping › Bricolage'],
            ['CREDIT CARTE BANCAIRE CASTORAMA', 'Shopping › Bricolage'],
            ['ACHAT CB TRUFFAUT COLOM 12.04.25', 'Shopping › Jardinage'],
            ['ACHAT CB IKEA TOULOUSE', 'Logement › Équipement & mobilier'],
            ['ACHAT CB TENDANCE COIFF 02.02.24', 'Shopping › Beauté & soins'],
            // Fleuriste : indépendants (« FLEURS », « FLEURISTE ») et livraison de fleurs.
            ['ACHAT CB AU PAYS DES FLEURS 13.02.25 CARTE NUMERO 275', 'Shopping › Fleuriste'],
            ['ACHAT CB MONCEAU FLEURS 08.05.24', 'Shopping › Fleuriste'],
            ['ACHAT CB INTERFLORA 26.05.24', 'Shopping › Fleuriste'],
            // Courrier & colis : bureaux de poste et transporteurs, jamais la banque.
            ['ACHAT CB LA POSTE L3114 05.03.24 CARTE NUMERO 275', 'Abonnements & télécoms › Courrier & colis'],
            ['ACHAT CB LAPOSTE G22364 18.11.25', 'Abonnements & télécoms › Courrier & colis'],
            ['ACHAT CB MONDIAL RELAY 02.02.26', 'Abonnements & télécoms › Courrier & colis'],
        ];
    }

    #[DataProvider('expectedExamples')]
    public function testExamplesResolveToExpectedCategory(string $label, string $expectedCategory): void
    {
        self::assertSame($expectedCategory, $this->resolve($label));
    }

    public function testBanquePostaleIsNotPostalMail(): void
    {
        self::assertNotSame('Abonnements & télécoms › Courrier & colis', $this->resolve('PRELEVEMENT DE LA BANQUE POSTALE ASSURANCE'));
    }

    public function testFlorenceIsNotAFlorist(): void
    {
        self::assertNotSame('Shopping › Fleuriste', $this->resolve('ACHAT CB LE FLORENCE 01.03.21 CARTE NUMERO 275'));
    }

    public function testVirementFromAPersonMatchesNoRule(): void
    {
        self::assertNull($this->resolve('VIREMENT DE PAUL MARTIN'));
    }

    public function testCreditRulesRequireCredit(): void
    {
        self::assertSame('Revenus › Allocations & aides', $this->resolve('VIREMENT DE CAF ALLOCATIONS JANVIER', 50000));
        self::assertNull($this->resolve('PRELEVEMENT DE CAF COTISATION', -50000));
    }

    /**
     * Résout un libellé comme le Categorizer : première règle (priority DESC,
     * ordre d'origine ASC) qui matche.
     */
    private function resolve(string $rawLabel, int $amount = -100, ?string $onlyPattern = null): ?string
    {
        $operation = new Operation(
            new \App\Entity\Account(new Household('h'), 'a', 'a', \App\Enum\AccountType::Personal, hash('sha256', 'a'), '****A'),
            new \DateTimeImmutable('2026-01-15'),
            $amount,
            $rawLabel,
            Text::fold($rawLabel),
            hash('sha256', $rawLabel.$amount),
        );
        $rules = $this->rules;
        usort($rules, static fn (CategorizationRule $a, CategorizationRule $b): int => $b->getPriority() <=> $a->getPriority());
        foreach ($rules as $rule) {
            if (null !== $onlyPattern && $rule->getPattern() !== $onlyPattern) {
                continue;
            }
            if ($rule->matches($operation)) {
                return $rule->getCategory()->getFullName();
            }
        }

        return null;
    }
}
