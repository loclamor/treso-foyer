# 0017 — Énergie : distinction électricité / gaz, achat de bonbonne de gaz (masse, prix au kg)

## Contexte
Le poste « Logement › Énergie » mélangeait électricité (prélèvements EDF…) et gaz. Le gaz est acheté en **bonbonne** en grande surface (Intermarché), donc dans le ticket de courses : aucune ligne de relevé dédiée, aucune règle automatique fiable (Intermarché = courses). Il faut pouvoir distinguer les deux énergies et saisir manuellement, sur l'opération de courses, la masse de gaz achetée pour suivre le prix au kg.

## Décision
- **Deux sous-catégories** sous « Logement » : « Électricité » et « Gaz » (l'ancienne feuille « Énergie » est renommée « Électricité » par la migration ; les opérations « GRDF » basculent sur « Gaz »). Règles par défaut : `EDF|ENGIE|EKWATEUR|PLANETE OUI|ENEDIS|ENERCOOP` → Électricité, `GRDF` → Gaz. Pas de règle automatique pour les bonbonnnes (classification manuelle).
- **Masse de gaz sur `Operation`** (comme le plein, [ADR 0015](0015-pleins-carburant.md), mais sans plein-complet ni kilométrage) : `gasMassG` (entier > 0, **grammes** — entiers comme les montants, ADR 0004), null = pas d'achat de gaz. Un achat de gaz n'est possible que sur un **débit**. Le prix au kg n'est pas stocké, il est calculé : `|amount| × 10 / gasMassG` (euros, 3 décimales).
- **Catégorie gaz** : nouveau drapeau `Category.gas` (comme `fuel`), qui fait apparaître les champs de l'achat (masse en kg, prix au kg) dans le front. Posé sur « Logement › Gaz » par `app:setup` et par la migration ; modifiable dans l'écran Catégories. Le back n'exige pas la catégorie (le drapeau ne pilote que l'affichage).
- **API** : `PUT /api/operations/{id}/gas` `{"massG": int > 0}` (remplace, 400 `not_a_debit` sur crédit), `DELETE /api/operations/{id}/gas`, champ optionnel `gasMassG` dans `POST /api/operations` (422 sur crédit), objet `gas` = null ou `{massG, pricePerKg}` dans la présentation d'une opération. Passer une saisie manuelle en crédit retire l'achat de gaz (comme le plein).
- **Rapprochement / doublons** : `ManualEntryReconciler::merge()` copie la masse de gaz de la saisie sur la ligne du relevé si celle-ci n'en a pas ; la résolution d'un doublon (`POST /api/operations/{id}/duplicate`, « même opération ») fait de même vers l'opération conservée.
- **Front** : composant `GasFields` (masse en kg + prix au kg, montant lié et dérivé comme les pleins) dans la saisie manuelle et la catégorisation quand la catégorie choisie porte le drapeau `gas` et que l'opération est un débit ; ligne « 13,00 kg · 3,069 €/kg » dans la liste des opérations ; case « Catégorie gaz » dans l'écran Catégories.

## Conséquences
- Une opération de courses classée « Logement › Gaz » avec une masse suivie a un prix au kg ; sans masse, seul le montant compte.
- Schéma : colonnes `operation.gas_mass_g`, `category.gas` ; contrainte `CHECK ck_operation_gas` (masse nulle ou > 0, SQL brut hors mapping) ; migration `Version20261110090000` (renommage « Énergie » → « Électricité », création de « Gaz », recatégorisation GRDF, règles EDF/GRDF).
