<?php

declare(strict_types=1);

namespace App\Setup;

use App\Enum\RuleDirection;
use App\Enum\RuleMatchField;
use App\Enum\RuleMatchType;

/**
 * Règles de catégorisation par défaut, insérées par `app:rules:seed` (idempotent).
 * Format de `all()` : [catégorie « Parent › Enfant », matchField, matchType, pattern, direction, priority].
 *
 * Les règles « enseignes » ne s'appliquent qu'aux paiements carte et prélèvements
 * (préfixe ACHAT CB / <MOT> CARTE / PRELEVEMENT DE du libellé normalisé) pour ne pas
 * classer un virement de particulier dont le contrepartie serait un prénom
 * présent dans une liste d'enseignes.
 */
final class DefaultRules
{
    /**
     * Enseignes : [catégorie, priorité, alternatives (regex alternation)].
     * Le libellé normalisé étant replié en majuscules sans accents, la regex est
     * évaluée avec les flags `iu` par CategorizationRule::matches().
     *
     * @var list<array{0: string, 1: int, 2: string}>
     */
    public const MERCHANT_RULES = [
        ['Alimentation › Courses', 50, 'CARREFOUR\\w*|E ?LECLERC|LECLERC|INTERMARCHE|AUCHAN|LIDL|ALDI|SUPER U|HYPER U|U EXPRESS|MONOPRIX|CASINO|FRANPRIX|NETTO|GRAND FRAIS|BIOCOOP|LEADER PRICE|CORA|SPAR|PICARD|NATURALIA|COLRUYT'],
        ['Alimentation › Restaurants & bars', 55, 'MC ?DONALD|MCDO|BURGER KING|KFC|QUICK|SUBWAY|DOMINO|PIZZA|RESTAURANT|BRASSERIE|SUSHI|STARBUCKS|COLUMBUS|BRIOCHE DOREE|DELIVEROO|UBER ?EATS|JUST EAT|BUFFALO|HIPPOPOTAMUS|COURTEPAILLE'],
        ['Alimentation › Boulangerie & snacks', 50, 'BOULANGERIE|PATISSERIE|FOURNIL|MARIE BLACHERE'],
        ['Transport › Carburant', 60, 'TOTALENERGIES|TOTAL ACCESS|ESSO|SHELL|AVIA|AGIP|DYNEFF|CARBU|DAC'],
        ['Transport › Péages & parking', 50, 'VINCI|APRR|SANEF|ASF|AUTOROUTE|PEAGE|PARKING|INDIGO|EFFIA|SAEMES|ESCOTA'],
        ['Transport › Train & avion', 50, 'SNCF|OUIGO|TRAINLINE|AIR FRANCE|EASYJET|RYANAIR|VOLOTEA|TRANSAVIA'],
        ['Transport › Transports en commun', 50, 'TISSEO|RATP|NAVIGO|KEOLIS|TRANSDEV|BLABLACAR|UBER|BOLT|HEETCH|LIME'],
        ['Transport › Entretien véhicule', 50, 'NORAUTO|FEU VERT|MIDAS|SPEEDY|EUROMASTER|CONTROLE TECHNIQUE|DEKRA|AUTOSUR|CARGLASS'],
        ['Abonnements & télécoms › Internet & téléphone', 50, 'ORANGE|SOSH|SFR|BOUYGUES TEL|BOUYGUES TELECOM|FREE MOBILE|FREE TELECOM|FREE HAUTDEBIT'],
        ['Abonnements & télécoms › Streaming & médias', 50, 'NETFLIX|SPOTIFY|DEEZER|DISNEY|CANAL|PRIME VIDEO|YOUTUBE|MOLOTOV|DAZN|AUDIBLE'],
        ['Abonnements & télécoms › Logiciels & services en ligne', 50, 'OVH|OVHCLOUD|GOOGLE|MICROSOFT|ADOBE|DROPBOX|GITHUB|ANTHROPIC|OPENAI|JETBRAINS|PROTON|ICLOUD|APPLE COM'],
        // « LA ?POSTE » : bureaux de poste (« LA POSTE 31149 », « LAPOSTE G22364 ») ; ne matche pas « BANQUE POSTALE ».
        ['Abonnements & télécoms › Courrier & colis', 50, 'LA ?POSTE|COLISSIMO|CHRONOPOST|MONDIAL RELAY|RELAIS COLIS|DHL|FEDEX|PACKLINK|BOXTAL'],
        ['Santé › Pharmacie', 50, 'PHARMACIE|PHIE'],
        ['Santé › Médecins', 50, 'DOCTEUR|DR|MEDECIN|MEDICAL|DENTAIRE|KINE|OSTEO|LABORATOIRE|CLINIQUE|HOPITAL|DOCTOLIB|CARDIOLOGIE|PEDIATRE'],
        ['Santé › Optique & dentaire', 50, 'OPTIC|OPTICIEN|KRYS|AFFLELOU|GRANDOPTICAL|ATOL'],
        ['Shopping › Achats divers', 10, 'AMAZON|AMZN|CDISCOUNT|FNAC|DARTY|BOULANGER|LEBONCOIN|VINTED|ETSY|ALIEXPRESS|TEMU|SHEIN|GIFI|STOKOMANI|PAYPAL'],
        ['Shopping › Vêtements', 50, 'DECATHLON|KIABI|ZARA|PRIMARK|CELIO|JULES|UNIQLO|GEMO|LA HALLE|HALLE CHAUSSUR|BERSHKA|ZALANDO|INTERSPORT|GO SPORT|H&M\\w*|JUSTFAB\\w*|ORCHESTRA|NEWORCH\\w*|PETIT BATEAU|VERTBAUDET|CAMAIEU|BON ?PRIX|3 SUISSES'],
        ['Shopping › High-tech', 50, 'APPLE STORE|LDLC|MATERIEL NET|RUE DU COMMERCE'],
        ['Logement › Équipement & mobilier', 50, 'IKEA|CONFORAMA|MAISONS DU MONDE'],
        // Priorité 55 : l'emporte sur l'ancienne règle « Équipement & mobilier » qui listait ces enseignes.
        // ADEO = groupe Leroy Merlin / Bricoman (libellé CB souvent tronqué en « ADEO*LEROY MER »).
        ['Shopping › Bricolage', 55, 'LEROY MER\\w*|ADEO|CASTORAMA|BRICOMARCHE|BRICO DEPOT|BRICO CASH|BRICOMAN|BRICORAMA|MR BRICOLAGE|WELDOM|POINT P|LAPEYRE|ENTREPOT DU BRICOLAGE'],
        ['Shopping › Jardinage', 55, 'GAMM VERT|JARDILAND|TRUFFAUT|BOTANIC|VILLAVERDE|POINT VERT|JARDINERIE|PEPINIERE\\w*'],
        // « FLEUR\w* » : fleuristes indépendants et Monceau Fleurs ; pas de préfixe « FLOR » (ne doit pas matcher « LE FLORENCE »).
        ['Shopping › Fleuriste', 50, 'FLEUR\\w*|INTERFLORA|AQUARELLE|FLORAJET|RAPID ?FLORE|BEBLOOM|WAKE UP FLOWERS'],
        ['Shopping › Beauté & soins', 50, 'SEPHORA|NOCIBE|MARIONNAUD|YVES ROCHER|L OCCITANE|KIKO|COIFF\\w*|BARBIER|ESTHETIQUE|INSTITUT DE BEAUTE|WECASA'],
        ['Logement › Énergie', 50, 'EDF|ENGIE|EKWATEUR|PLANETE OUI|GRDF|ENEDIS|ENERCOOP'],
        ['Logement › Eau', 50, 'VEOLIA|SUEZ|SAUR'],
        ['Loisirs & culture › Sport', 50, 'BASIC FIT|FITNESS|PISCINE|ESCALADE|KEEP COOL'],
        ['Loisirs & culture › Sorties & spectacles', 50, 'CINEMA|PATHE|GAUMONT|UGC|MEGA CGR|KINEPOLIS|TICKETMASTER|THEATRE|MUSEE'],
        ['Loisirs & culture › Voyages & vacances', 50, 'BOOKING|AIRBNB|HOTEL|IBIS|CAMPING|ABRITEL|CENTER PARCS|EXPEDIA'],
        ['Loisirs & culture › Livres, jeux & hobbies', 50, 'CULTURA|LIBRAIRIE|STEAM|PLAYSTATION|NINTENDO|MICROMANIA|KING JOUET|JOUE CLUB|PHILIBERT|MAXITOYS|LUNII\\w*'],
        ['Famille › Animaux', 50, 'VETERINAIRE|ANIMALIS|MAXI ZOO|ZOOPLUS'],
    ];

    /**
     * Règles bancaires sur libellé normalisé : [catégorie, pattern regex, direction, priorité].
     *
     * @var list<array{0: string, 1: string, 2: RuleDirection, 3: int}>
     */
    public const BANK_RULES = [
        ['Banque & assurances › Frais bancaires', '^(COTISATION|FRAIS|COMMISSION|MINIMUM|AVANTAGE)\b', RuleDirection::Any, 80],
        ['Banque & assurances › Intérêts & agios', '^(INTERETS|AGIOS)\b', RuleDirection::Any, 80],
        // Catégorie racine : un prélèvement de la DGFiP / DRFiP / DDFiP peut être l'impôt sur le revenu, la taxe foncière…
        // « FINANCES PUBL\w* » : le libellé est souvent tronqué (« DIRECTION GENERALE D ES FINANCES PUBL »).
        ['Impôts & taxes', '^PRELEVEMENT DE .*\b(DGFIP|DRFIP|DDFIP|IMPOTS?|FINANCES PUBL\w*)\b', RuleDirection::Any, 80],
        // Amendes : radar, amende.gouv (paiement carte ou prélèvement).
        ['Impôts & taxes › Autres taxes & amendes', '\bAMENDES?\b', RuleDirection::Debit, 85],
        ['Retraits d’espèces', '^RETRAIT\b', RuleDirection::Debit, 80],
        // OGEC : organisme de gestion des écoles privées sous contrat (paiement carte, prélèvement ou virement).
        ['Scolarité › Frais de scolarité', '\b(OGEC|APEL)\b', RuleDirection::Debit, 80],
        ['Scolarité › Cantine & garderie', '\b(CANTINE|RESTAURATION SCOLAIRE|PERISCOLAIRE|GARDERIE)\b', RuleDirection::Debit, 80],
        ['Revenus › Allocations & aides', '\b(CAF|CAISSE D ALLOCATIONS|FRANCE TRAVAIL|POLE EMPLOI|ANAH)\b', RuleDirection::Credit, 80],
        // Traitement d'agent public versé par la DRFiP (« … PAYES 2019-05-29-… REMUNERATION DU 05/2019 »).
        ['Revenus › Salaires', '^VIREMENT DE .*\bPAYES \d{4}-\d{2}-\d{2}\b.*\bREMUNERATION DU\b', RuleDirection::Credit, 80],
        // Remboursements d'impôt (excédent de mensualisation, avance de crédit d'impôt RICI).
        ['Impôts & taxes', '^VIREMENT DE .*\b(DGFIP|DRFIP|DDFIP)\b.*\b(REMB|RICI)\b', RuleDirection::Credit, 80],
        // Remboursements de mutuelle (« MUTUELLE GENERALE EDUCA TION NATIONALE », MGEN…).
        ['Revenus › Remboursements', '^VIREMENT DE .*\b(MGEN|MUTUELLE)\b', RuleDirection::Credit, 80],
        // « C\.?P\.?A\.?M » : aussi la forme pointée « C.P.A.M. TOULOUSE ».
        ['Revenus › Remboursements', '\b(C\.?P\.?A\.?M|AMELI|ASSURANCE MALADIE)\b', RuleDirection::Credit, 80],
    ];

    /**
     * Toutes les règles par défaut.
     *
     * @return list<array{0: string, 1: RuleMatchField, 2: RuleMatchType, 3: string, 4: RuleDirection, 5: int}>
     *         [catégorie, matchField, matchType, pattern, direction, priority]
     */
    public static function all(): array
    {
        $rules = [];
        foreach (self::MERCHANT_RULES as [$category, $priority, $alternatives]) {
            $rules[] = [$category, RuleMatchField::Label, RuleMatchType::Regex, self::merchantPattern($alternatives), RuleDirection::Any, $priority];
        }
        foreach (self::BANK_RULES as [$category, $pattern, $direction, $priority]) {
            $rules[] = [$category, RuleMatchField::Label, RuleMatchType::Regex, $pattern, $direction, $priority];
        }

        return $rules;
    }

    /**
     * Motif « enseigne » : paiement carte (ACHAT CB …, <MOT> CARTE …) ou prélèvement,
     * avec l'une des alternatives quelque part dans le libellé.
     */
    public static function merchantPattern(string $alternatives): string
    {
        return '^(ACHAT CB|[A-Z]+ CARTE|PRELEVEMENT DE)\b.*\b('.$alternatives.')\b';
    }
}
