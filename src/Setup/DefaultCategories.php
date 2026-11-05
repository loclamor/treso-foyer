<?php

declare(strict_types=1);

namespace App\Setup;

use App\Enum\CategoryKind;

/**
 * Arbre de catégories par défaut créé par la commande app:setup.
 * Les enfants héritent du kind de leur racine (color null).
 *
 * @var list<array{name: string, kind: CategoryKind, icon: string, color: string, chartSlot: ?int, children: list<string>}>
 *     chartSlot : emplacement 1-8 sur les graphiques pour 8 racines de dépense
 *     (palette validée), null sinon ; les autres racines prennent la couleur neutre #898781.
 */
final class DefaultCategories
{
    /**
     * @var list<array{name: string, kind: CategoryKind, icon: string, color: string, chartSlot: ?int, children: list<string>}>
     */
    public const TREE = [
        [
            'name' => 'Logement',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-home',
            'color' => '#eb6834',
            'chartSlot' => 2,
            'children' => [
                'Loyer / crédit immobilier',
                'Charges & copropriété',
                'Énergie',
                'Eau',
                'Assurance habitation',
                'Travaux & entretien',
                'Équipement & mobilier',
            ],
        ],
        [
            'name' => 'Alimentation',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-shopping-cart',
            'color' => '#2a78d6',
            'chartSlot' => 1,
            'children' => [
                'Courses',
                'Restaurants & bars',
                'Boulangerie & snacks',
            ],
        ],
        [
            'name' => 'Transport',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-car',
            'color' => '#eda100',
            'chartSlot' => 4,
            'children' => [
                'Carburant',
                'Entretien véhicule',
                'Assurance auto',
                'Péages & parking',
                'Transports en commun',
                'Train & avion',
            ],
        ],
        [
            'name' => 'Abonnements & télécoms',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-wifi',
            'color' => '#008300',
            'chartSlot' => 6,
            'children' => [
                'Internet & téléphone',
                'Streaming & médias',
                'Logiciels & services en ligne',
                'Courrier & colis',
            ],
        ],
        [
            'name' => 'Santé',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-heart',
            'color' => '#6250d6',
            'chartSlot' => 7,
            'children' => [
                'Médecins',
                'Pharmacie',
                'Mutuelle',
                'Optique & dentaire',
            ],
        ],
        [
            'name' => 'Loisirs & culture',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-ticket',
            'color' => '#898781',
            'chartSlot' => null,
            'children' => [
                'Sport',
                'Sorties & spectacles',
                'Voyages & vacances',
                'Livres, jeux & hobbies',
            ],
        ],
        [
            'name' => 'Shopping',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-shopping-bag',
            'color' => '#1baf7a',
            'chartSlot' => 3,
            'children' => [
                'Vêtements',
                'High-tech',
                'Cadeaux',
                'Bricolage',
                'Jardinage',
                'Fleuriste',
                'Beauté & soins',
                'Achats divers',
            ],
        ],
        [
            'name' => 'Famille',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-users',
            'color' => '#898781',
            'chartSlot' => null,
            'children' => [
                'Enfants',
                'Animaux',
                'Aide & services à la personne',
                'Remboursements à des proches',
            ],
        ],
        [
            'name' => 'Scolarité',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-graduation-cap',
            'color' => '#898781',
            'chartSlot' => null,
            'children' => [
                'Frais de scolarité',
                'Cantine & garderie',
                'Fournitures & manuels',
                'Sorties & voyages scolaires',
                'Activités périscolaires',
                'Soutien scolaire',
            ],
        ],
        [
            'name' => 'Banque & assurances',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-building-columns',
            'color' => '#e34948',
            'chartSlot' => 8,
            'children' => [
                'Frais bancaires',
                'Intérêts & agios',
                'Assurances & prévoyance',
            ],
        ],
        [
            'name' => 'Impôts & taxes',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-percentage',
            'color' => '#e87ba4',
            'chartSlot' => 5,
            'children' => [
                'Impôt sur le revenu',
                'Taxe foncière',
                'Taxe d’habitation',
                'Autres taxes & amendes',
            ],
        ],
        [
            'name' => 'Retraits d’espèces',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-money-bill',
            'color' => '#898781',
            'chartSlot' => null,
            'children' => [],
        ],
        [
            'name' => 'Dons & solidarité',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-gift',
            'color' => '#898781',
            'chartSlot' => null,
            'children' => [],
        ],
        [
            'name' => 'Cotisations & adhésions',
            'kind' => CategoryKind::Expense,
            'icon' => 'pi pi-id-card',
            'color' => '#898781',
            'chartSlot' => null,
            'children' => [
                'Syndicat',
                'Associations & clubs',
            ],
        ],
        [
            'name' => 'Revenus',
            'kind' => CategoryKind::Income,
            'icon' => 'pi pi-wallet',
            'color' => '#898781',
            'chartSlot' => null,
            'children' => [
                'Salaires',
                'Remboursements',
                'Allocations & aides',
                'Revenus divers',
                'Intérêts d’épargne',
            ],
        ],
        [
            'name' => 'Mouvements internes',
            'kind' => CategoryKind::Transfer,
            'icon' => 'pi pi-arrow-right-arrow-left',
            'color' => '#898781',
            'chartSlot' => null,
            'children' => [
                'Virements entre comptes du foyer',
                'Épargne',
            ],
        ],
    ];
}
