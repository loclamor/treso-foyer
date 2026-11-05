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

    public function testAssuranceMaladieRefundWithOrWithoutDots(): void
    {
        // Remboursement (crédit) : sigle pointé « C.P.A.M. » ou non.
        self::assertSame('Revenus › Remboursements', $this->resolve('VIREMENT DE C.P.A.M. TOULOUSE REMBOURSEMENT SOINS', 1550));
        self::assertSame('Revenus › Remboursements', $this->resolve('VIREMENT DE CPAM HAUTE-GARONNE', 600));
    }

    public function testFinancesPubliquesEtAmendes(): void
    {
        self::assertSame('Impôts & taxes', $this->resolve('PRELEVEMENT DE DRFIP HAUTE-GARONNE REF : 1D031 IDENT : FR63ZZZ498620', -9000));
        self::assertSame('Impôts & taxes', $this->resolve('PRELEVEMENT DE DGFIP IMPOT 2025', -9000));
        self::assertSame('Impôts & taxes › Autres taxes & amendes', $this->resolve('ACHAT CB AMENDE RADAR W 29.04.18 CARTE NUMERO 325', -4500));
        self::assertSame('Impôts & taxes › Autres taxes & amendes', $this->resolve('ACHAT CB WEB AMENDE.GOU 31.08.26 CARTE NUMERO 655', -4500));
        // MaPrimeRénov' (ANAH) versée par une DRFIP : une aide, pas un impôt.
        self::assertSame('Revenus › Allocations & aides', $this->resolve('VIREMENT DE DRFIP ILE DE FRANCE ET DE PARIS 1D0750000ANAH-MPR-2022-1 ANAH-MPR-2022-1', 100000));
    }

    public function testTraitementRemboursementsImpotEtMutuelle(): void
    {
        self::assertSame('Revenus › Salaires', $this->resolve('VIREMENT DE DRFIP OCCITANIE ET HTE GARONNE 1G0310000 PAYES 2019-05-29-00045656 REMUNERATION DU 05/2019. MINISTERE : 206', 150000));
        self::assertSame('Revenus › Salaires', $this->resolve('VIREMENT DE 0310000A310DRFIP DE LAHAUTE GARONNE 1G0310000 PAYES 2018-05-29-00044865 REMUNERATION DU 05/2018.', 150000));
        self::assertSame('Impôts & taxes', $this->resolve('VIREMENT DE DGFIP FINANCES PUBLIQUE S 1E0670000M000 REMB. EXCD. MENSUALISE 222', 18600));
        self::assertSame('Impôts & taxes', $this->resolve('VIREMENT DE DGFIP FINANCES PUBLIQUE S 6P0670000 RICI AVANCE CREDIMPOT 222', 69000));
        self::assertSame('Revenus › Remboursements', $this->resolve('VIREMENT DE MUTUELLE GENERALE EDUCA TION NATIONALE OWRQ0000000000001', 1314));
    }

    public function testEnseignesAjoutees(): void
    {
        self::assertSame('Cotisations & adhésions › Syndicat', $this->resolve('ACHAT CB SNES S4 02.09.17 CARTE NUMERO 391'));
        self::assertSame('Revenus › Intérêts d’épargne', $this->resolve('INTERETS ACQUIS POUR 2015', 1147));
        self::assertSame('Shopping › Vêtements', $this->resolve('ACHAT CB H&M067 12.03.19 CARTE NUMERO 152'));
        self::assertSame('Shopping › Vêtements', $this->resolve('ACHAT CB JustFab SLU 23.05.19 EUR 9,95 CARTE NO 152'));
        self::assertSame('Shopping › Vêtements', $this->resolve('ACHAT CB SHOP.ORCHESTRA 02.02.20 CARTE NUMERO 642'));
        self::assertSame('Shopping › Vêtements', $this->resolve('ACHAT CB NEWORCHORCHEST 02.02.20 CARTE NUMERO 642'));
        self::assertSame('Santé › Médecins', $this->resolve('ACHAT CB DR CAMACHO MAU 02.02.20 CARTE NUMERO 642'));
        self::assertSame('Santé › Médecins', $this->resolve('ACHAT CB MAISON MEDICAL 19.01.16 CARTE NUMERO 391'));
        self::assertSame('Shopping › Beauté & soins', $this->resolve('ACHAT CB WECASA 02.02.20 CARTE NUMERO 642'));
        self::assertSame('Loisirs & culture › Livres, jeux & hobbies', $this->resolve('ACHAT CB MAXITOYS COLOM 26.12.15 CARTE NUMERO 391'));
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
