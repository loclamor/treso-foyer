# 0003 — Lecture des PDF avec poppler `pdftotext -layout`

## Contexte
Les relevés La Banque Postale sont des PDF texte (non scannés) avec colonnes Date / Opération / Débit / Crédit, libellés multi-lignes, pages « (suite) ». Certains PDF d'archive sont légèrement corrompus (xref) mais lisibles.

## Décision
Appeler `pdftotext -layout` (poppler-utils, installé dans l'image) via `symfony/process`, puis parser le texte : la mise en page conservée permet de distinguer débit/crédit par position de colonne.
Garde-fou : **ancien solde + Σ opérations = nouveau solde** pour chaque relevé, sinon import refusé.

## Alternatives écartées
- `smalot/pdfparser` (PHP pur) : perd la position des colonnes, fragile sur les PDF abîmés.
- OCR : inutile, PDF textuels.

## Conséquences
Dépendance binaire dans l'image (dev et prod). Parseur testé sur fixtures texte anonymisées (sortie de pdftotext), sans PDF réel dans le dépôt.

## Mise en œuvre (étape 5)
- **Extraction** : `src/Import/Pdf/PdftotextExtractor.php` (`PdfTextExtractorInterface::extract()`) appelle `pdftotext -layout -enc UTF-8 <fichier> -` via `symfony/process` (timeout 30 s) ; toute erreur produit une `ParseException` ne contenant que le nom du fichier, jamais son contenu.
- **Parseur** : `src/Import/Parser/PdfStatementParser.php`, tagué `app.statement_parser` ; `supports()` accepte un contenu commençant par `%PDF`. `parse()` extrait le texte puis `parseText()` parcourt les lignes (testé directement sur les fixtures de `tests/Fixtures/pdf-text/`).
- **Algorithme** (format homogène, validé sur 103 relevés réels 2018 → 2026) :
  - Numéro de compte : ligne `Situation de votre CCP n° 12 345 67 R 999` (espaces retirés) ; repli si la ligne manque : `IBAN : FR00 …` → numéro = IBAN sans espaces, `substr($iban, 14, 11)` (caractères 14 à 24 inclus).
  - Chaque page d'opérations commence par `Vos opérations` (page 1) ou `Vos opérations CCP n° … (suite)` / `Vos opérations Compte Courant Postal n° … (suite)`, suivi d'une ligne d'en-tête `Date  Opération  Débit (¤)  Crédit (¤)` dont **les positions de colonnes varient d'une page à l'autre** : elles sont relues à chaque en-tête.
  - Ligne d'opération : `JJ/MM  libellé  montant` (`montant` = `\d{1,3}( \d{3})*,\d{2}` en fin de ligne). **Débit ou crédit selon la colonne la plus proche de la fin du montant** (comparée à la fin de `Débit (¤)` et de `Crédit (¤)` de l'en-tête de la page courante ; mesuré sur 4 509 opérations réelles : écart ≤ 3 caractères, jamais ambigu). Même règle pour le signe des soldes (`Ancien solde au`, `Nouveau solde au`), avec signe explicite `+`/`-` prioritaire quand il est présent.
  - Lignes de continuation (sans date) : ajoutées au libellé de l'opération en cours, jointes par une espace (1 à 4 lignes observées).
  - Fin d'opération / de zone : une ligne vide clôt l'opération en cours ; une ligne contenant `Page N/M` ou commençant (après trim) par `La Banque Postale - S.A.` termine la zone d'opérations jusqu'au prochain en-tête (mentions légales parfois collées sans ligne vide) ; `Total des opérations` clôt l'opération en cours ; `Nouveau solde au` termine la zone.
  - Date sans année : année = année du nouveau solde ; si le mois de l'opération est supérieur au mois du nouveau solde, année − 1 (relevé de janvier avec opérations de décembre).
  - Artefact PDF : les frais sont préfixés d'un renvoi de note collé (`4FRAIS TENUE DE COMPTE`, `4COTISATION …`) → `^\d(?=[A-Z]{4})` retiré en début de libellé.
  - Relevés annuels de frais (même dossier) : ils contiennent `Décompte des frais` et `Période Du JJ/MM/AAAA au JJ/MM/AAAA`, pas de `Vos opérations` → `NotAnAccountStatementException` : ignorés par l'import (pas de lot `failed`, statut « ignoré (pas un relevé d'opérations) »).
- **Contrôles de cohérence obligatoires** : (1) ancien solde + Σ opérations = nouveau solde ; (2) totaux débits/crédits calculés = ligne `Total des opérations` (si présente). Échec ⇒ `ParseException` (« Relevé incohérent : écart de X € entre … », sans aucun libellé ni numéro de compte) → lot `failed`. **Validé : 103/103 relevés réels cohérents avec cet algorithme.**
- Un PDF importé crée un `Statement` avec soldes d'ouverture et de clôture.


## Validation sur données réelles (étape 5)
- 103 relevés mensuels (2018 → 2026) + 6 relevés annuels de frais (ignorés) + 2 exports CSV : **4 546 opérations**, dont 4 509 issues des PDF ; les 207 opérations communes aux CSV et aux PDF ont été **enrichies** (libellé PDF), 0 doublon, 0 doublon probable.
- Chaque relevé : ancien solde + Σ opérations = nouveau solde, et totaux débit/crédit = ligne « Total des opérations » (103/103).
- Enchaînement : le nouveau solde d'un relevé = l'ancien solde du suivant pour 100 transitions sur 102 ; les 2 ruptures correspondent exactement aux relevés manquants (2019-04, 2025-09).
- Solde final : dernier solde PDF + opérations CSV postérieures = solde affiché par le dernier export CSV, au centime près.
- Cas particulier rencontré : un relevé mensuel peut contenir **en annexe** le décompte annuel des frais ; seul l'absence d'en-tête d'opérations caractérise un relevé de frais (fixture `releve-avec-decompte-frais.txt`).
