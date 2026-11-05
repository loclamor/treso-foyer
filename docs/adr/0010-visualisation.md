# 0010 — Visualisation des statistiques

## Contexte
Tableau de bord et écran Statistiques (étape 8) : dépenses par poste, évolution, flux, calendrier, solde. Les graphiques doivent rester lisibles (daltonisme, mode sombre) et cohérents d'un écran à l'autre.

## Décision
- **ECharts** (via `vue-echarts`, import tree-shaké `assets/src/charts/echarts.js`) : barres empilées, barres horizontales, lignes avec `dataZoom`, Sankey, calendrier.
- **Couleur portée par l'entité, jamais par le rang** : chaque catégorie racine de dépense peut recevoir un **emplacement de couleur** `chartSlot` (1-8, unique, réglable dans Réglages › Catégories). Les racines sans emplacement et le non catégorisé sont regroupés en **« Autres »** (gris), jamais de 9ᵉ couleur générée.
- **Palette catégorielle validée** (outil de validation : séparation pour les daltonismes, plancher de vision normale, bande de luminance) en clair **et** en sombre (étapes dédiées au fond sombre, pas une inversion) : clair `#2a78d6 #eb6834 #1baf7a #eda100 #e87ba4 #008300 #6250d6 #e34948`, sombre `#3987e5 #d95926 #199e70 #c98500 #d55181 #008300 #9085e9 #e66767`. Trois teintes claires sont sous 3:1 de contraste ⇒ **chaque graphique a une vue « Tableau »** et une légende.
- Une seule échelle Y par graphique (jamais de double axe) ; pas de camembert pour comparer des postes proches (barres horizontales triées) ; calendrier en dégradé d'une seule teinte (bleu) ; statut (variation favorable/défavorable) = icône + texte.
- Règles de calcul centralisées côté serveur (`src/Stats/`) : dépense/revenu selon le type de la catégorie (non catégorisé ⇒ signe), virements internes et mouvements `transfer` exclus au niveau foyer ; solde reconstitué ancré sur les relevés.

## Conséquences
- Attribution initiale des emplacements selon le poids réel des dépenses (Alimentation, Logement, Shopping, Transport, Impôts & taxes, Abonnements & télécoms, Santé, Banque & assurances).
- Ajouter une 9ᵉ catégorie importante ⇒ lui céder un emplacement existant (pas de nouvelle couleur).
