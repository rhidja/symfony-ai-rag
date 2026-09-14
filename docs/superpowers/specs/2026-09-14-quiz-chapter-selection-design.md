# Sélection par chapitre pour le Quiz — Design

Date : 2026-09-14

## Contexte

Le mode Quiz (voir [2026-09-14-rag-quiz-mode-design.md](2026-09-14-rag-quiz-mode-design.md), livré)
génère un QCM à partir d'un échantillon aléatoire de chunks du document entier choisi. C'est la
phase 2 de la feuille de route de l'application d'apprentissage (voir README) : permettre de cibler
le quiz sur une partie précise d'un document plutôt que sur son intégralité.

Contrainte de départ identifiée en brainstorming : la table `document_chunks` (gérée par
`symfony/ai-postgres-store`) ne stocke aujourd'hui aucune information de position — ni numéro de
page, ni ordre, ni chapitre — seulement un id aléatoire. Reconstruire cette information après coup,
à partir des chunks déjà en base, est impossible. Cette spec porte donc autant sur l'**ingestion**
(produire l'information de chapitre à la source) que sur le quiz lui-même.

## Objectif

Après avoir choisi un document pour un quiz, permettre optionnellement de choisir un chapitre pour y
cibler les questions. Les documents déjà indexés doivent être ré-ingérés pour bénéficier de cette
fonctionnalité (aucune migration de données existantes).

## Principe

Plutôt que de retrouver après coup à quel chapitre appartient un chunk existant, l'information de
chapitre est produite **à la source, avant le découpage en chunks** :

1. À l'ingestion d'un PDF, un nouveau service détecte les frontières de chapitres dans le texte
   extrait et découpe le document en un `TextDocument` **par chapitre**, chacun portant une
   métadonnée `_chapter` (chaîne libre : titre du chapitre), au lieu d'un seul `TextDocument` pour
   tout le fichier comme aujourd'hui.
2. Le `TextSplitTransformer` de `symfony/ai-store` (déjà utilisé dans le pipeline, non modifié) copie
   automatiquement toutes les métadonnées du `TextDocument` parent — dont `_chapter` — sur chacun de
   ses chunks, exactement comme il le fait déjà pour `_source`/`_title`/`_author`. Aucun changement
   n'est nécessaire côté transformation ou stockage.
3. **Repli gracieux** : si aucune structure fiable n'est détectée (moins de 2 chapitres trouvés,
   document trop court, sommaire absent ou non reconnaissable), un seul `TextDocument` est produit
   pour tout le fichier, sans `_chapter` — comportement strictement identique à aujourd'hui. Le quiz
   sur ce document fonctionne alors comme avant, sans sélecteur de chapitre.

## Détection de structure (PDF)

Nouveau service `DocumentStructureDetector` (`src/Service/Rag/Structure/`), utilisé par `PdfLoader` :

1. **Repérage heuristique du sommaire** : recherche, dans le texte des premières pages extraites
   (ex. les 5 premières), de lignes ressemblant à des entrées de table des matières (motifs du type
   "CHAPITRE N ...", "N. Titre ... numéro de page", lignes courtes répétées en séquence). Cette étape
   est un simple filtrage textuel, sans appel IA.
2. **Extraction de la liste des titres** : le texte de cette zone candidate est envoyé au modèle de
   chat avec une sortie structurée (même mécanisme que `QuizGenerator`, `response_format` vers un DTO
   `DetectedChapterList`), pour en extraire une liste ordonnée de titres de chapitres propres
   (nettoyés des numéros de page et artefacts d'extraction).
3. **Localisation réelle dans le texte** : pour chaque titre obtenu, une recherche de texte simple
   (`mb_strpos`, pas d'IA) trouve sa **première occurrence après la zone de sommaire** — c'est le
   début réel de ce chapitre dans le texte intégral. Un titre introuvable dans le corps du texte
   (sommaire imprécis, OCR différent) est ignoré.
4. **Validation** : si moins de 2 chapitres sont localisés avec succès, la détection est considérée
   comme un échec → repli (un seul `TextDocument`, pas de `_chapter`).
5. **Découpage** : le texte intégral est tranché aux positions trouvées ; chaque tranche devient un
   `TextDocument` avec `_chapter` = titre nettoyé, dans l'ordre du document.

Ce mécanisme ne nécessite qu'un seul appel IA par document (sur la seule zone de sommaire, pas tout
le livre), quelle que soit la taille du document.

## Détection de structure (DOCX)

`DocxLoader` : les styles de paragraphe "Heading 1"/"Heading 2" de `phpoffice/phpword` sont lus
directement (pas d'appel IA, détection fiable à 100% quand le document utilise des styles de titre).
Un document sans styles de titre suit le même repli gracieux qu'un PDF sans sommaire détectable.

## CSV / XLSX

Aucun changement : ces formats n'ont pas de notion de chapitre, `CsvLoader`/`XlsxLoader` continuent
à produire un `TextDocument` par ligne comme aujourd'hui.

## Modèle de données

Aucune nouvelle table, aucune migration sur `document_chunks` :

- `_chapter` est une métadonnée supplémentaire dans la colonne JSON existante, au même titre que
  `_title`/`_author` (déjà gérés à la main dans `PdfLoader`, pas via une constante `Metadata::KEY_*`
  puisque ce ne sont pas des clés connues de `symfony/ai-store`).

Une seule évolution de schéma, sur `QuizAttempt` (Doctrine, migration via `doctrine:schema:update`
comme pour `chat_message`/`quiz_attempt`) :

- Nouvelle colonne `chapter` (string, nullable) : mémorise le chapitre demandé pour cette tentative,
  affiché dans l'historique. `null` signifie "document entier".

## Génération du quiz

- `QuizAttemptService::listChaptersForDocument(string $documentSource): list<string>` — requête
  `SELECT DISTINCT metadata->>'_chapter'` filtrée sur ce document, valeurs non nulles, dans l'ordre
  d'apparition (par `MIN(ctid)` ou équivalent, à défaut d'ordre explicite stocké).
- `QuizAttemptService::start(string $sessionId, string $documentSource, ?string $chapter)` — transmet
  `$chapter` à `QuizGenerator::generate()`.
- `QuizGenerator::generate(string $documentSource, ?string $chapter = null)` — ajoute
  `AND metadata->>'_chapter' = :chapter` à la requête d'échantillonnage SQL quand `$chapter` est
  fourni ; comportement inchangé (tout le document) quand `null`.

## Interface

Dans `QuizComponent` : après le choix d'un document, si `listChaptersForDocument()` retourne au
moins une valeur, un second `<select>` "Chapitre" apparaît (option "Tout le document" en tête,
sélectionnée par défaut). Si la liste est vide (document sans chapitres détectés, ou pas encore
ré-ingéré), le comportement est identique à aujourd'hui — aucun sélecteur affiché.

L'historique des tentatives affiche le chapitre choisi le cas échéant (ex. "Chimie générale —
Chapitre 3 : Atomes, molécules et ions"), sinon juste le nom du document comme aujourd'hui.

## Gestion des erreurs

- **Aucun chunk pour ce document + ce chapitre** (cas normalement impossible si la liste de
  chapitres vient bien de la base, mais document modifié entre-temps) : même message d'erreur que
  "document introuvable" côté `QuizGenerationException`.
- **Échec de la sortie structurée du détecteur de structure** (JSON invalide, timeout) : traité comme
  un échec de détection → repli gracieux, pas d'erreur remontée à l'utilisateur ni blocage de
  l'ingestion — le fichier est indexé normalement, simplement sans chapitres.

## Tests

- `DocumentStructureDetectorTest` (unitaire/intégration) : sommaire bien formé → chapitres corrects ;
  sommaire absent ou titres introuvables dans le corps du texte → repli (liste vide) ; moins de 2
  chapitres localisés → repli.
- `PdfLoaderTest` : un PDF avec sommaire détectable produit plusieurs `TextDocument` avec `_chapter`
  distincts ; un PDF sans sommaire produit toujours un seul `TextDocument` sans `_chapter` (non
  régression du comportement actuel).
- `DocxLoaderTest` : styles de titre → plusieurs `TextDocument` avec `_chapter`.
- `QuizGeneratorTest` : génération filtrée par chapitre ne pioche que dans les chunks de ce chapitre.
- `QuizComponentTest` : le sélecteur de chapitre apparaît/disparaît selon la présence de chapitres
  pour le document choisi ; le chapitre choisi est bien transmis à la génération.

## Hors scope

- Ré-ingestion automatique des documents déjà indexés (reste une action manuelle, `make ingest`).
- Sous-chapitres/hiérarchie à plusieurs niveaux (un seul niveau de chapitre, pas de sections
  imbriquées).
- Affichage d'un sommaire/table des matières visuel en dehors du sélecteur du quiz (pas de vue
  "structure du document" dédiée).
- Les autres phases de la feuille de route (exercices texte libre, flashcards, upload manuscrit,
  PWA), toujours hors périmètre de cette spec.
