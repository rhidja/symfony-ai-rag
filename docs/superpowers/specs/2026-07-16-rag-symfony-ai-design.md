# RAG avec Symfony AI — Design

Date : 2026-07-16

## Contexte

Le projet est un squelette Symfony 8.1 fraîchement créé (`symfony/framework-bundle`, `symfony/console`,
aucune logique métier). Le fichier `.env.local` contient déjà des clés `OPENAI_API_KEY` et
`ANTHROPIC_API_KEY`, préparées pour l'écosystème `symfony/ai`.

## Objectif

Construire un système de RAG (Retrieval-Augmented Generation) permettant d'interroger en langage
naturel une base de connaissances interne constituée de documents PDF et Word, avec des réponses
sourcées et fondées uniquement sur le contenu indexé.

## Cas d'usage

Documentation technique / base de connaissances interne : les utilisateurs posent des questions sur
un corpus de documents métier (PDF, Word), et le système répond en citant les documents/passages
utilisés. Corpus visé : taille moyenne (une centaine à quelques milliers de documents).

## Vue d'ensemble de l'architecture

Deux flux distincts :

1. **Ingestion (offline, batch)** : une commande console scanne un dossier, extrait le texte des
   PDF/Word, découpe en chunks de taille fixe, génère les embeddings (OpenAI) et les stocke dans
   Postgres/pgvector.
2. **Interrogation (online)** : une question arrive (CLI ou API) → elle est vectorisée → recherche de
   similarité dans Postgres → les chunks pertinents sont injectés dans un prompt → un seul appel au
   LLM (GPT) génère la réponse, avec les sources citées.

### Composants principaux

- `symfony/ai-bundle` + `symfony/ai-open-ai-platform` : accès à OpenAI (embeddings + chat)
- `symfony/ai-postgres-store` : stockage et recherche vectorielle (pgvector)
- `symfony/doctrine-bundle` + `symfony/orm-pack` : connexion à Postgres (requise par le store)
- `docker-compose.yaml` avec l'image `pgvector/pgvector` : instance Postgres locale
- Une couche d'extraction PDF/Word indépendante de l'écosystème symfony/ai

### Choix d'architecture retenu : RAG classique manuel

Flux explicite et prévisible plutôt qu'un agent avec tool-calling autonome
(`symfony/ai-agent`) : la question est vectorisée, une recherche de similarité est faite, les
résultats sont injectés dans le prompt, un seul appel au LLM génère la réponse. Choisi pour sa
simplicité de débogage/test et son coût prévisible (1 appel embedding + 1 appel chat par question),
adapté à un cas d'usage de questions-réponses qui ne nécessite pas de raisonnement multi-étapes.

## Ingestion des documents

- **Commande console** `app:rag:ingest <dossier>` : parcourt récursivement un répertoire, filtre les
  fichiers `.pdf` et `.docx`.
- **Extraction de texte** : interface `DocumentExtractorInterface` avec deux implémentations :
  - `PdfExtractor` (via `smalot/pdfparser`)
  - `DocxExtractor` (via `phpoffice/phpword`)

  Sélection de l'implémentation selon l'extension du fichier.
- **Chunking** : découpage en blocs de taille fixe (par défaut ~1000 caractères avec ~150 caractères
  de chevauchement), valeurs configurables via paramètres. Pas de découpage structurel (par
  section/titre) dans ce périmètre.
- **Embeddings** : chaque chunk est vectorisé via le modèle d'embeddings OpenAI
  `text-embedding-3-small` (configurable).
- **Stockage** : chaque chunk est inséré dans le store Postgres/pgvector avec ses métadonnées (nom du
  fichier source, index du chunk, numéro de page si disponible pour le PDF) via
  `symfony/ai-postgres-store`.
- **Idempotence légère** : à la ré-ingestion d'un fichier déjà indexé, ses chunks existants (identifiés
  par métadonnée nom de fichier) sont supprimés avant réinsertion, pour éviter les doublons lors de
  ré-exécutions de la commande.

## Interrogation (recherche + génération de réponse)

- **Service central `RagQueryService`** : reçoit une question texte, orchestre le flux complet.
  1. Vectorise la question (même modèle d'embeddings que l'ingestion).
  2. Recherche de similarité dans le store Postgres (top-K chunks, K configurable, défaut 5).
  3. Construit un prompt avec :
     - un system prompt fixe demandant de répondre uniquement à partir du contexte fourni, de citer
       les sources, et d'indiquer explicitement une absence d'information si le contexte ne permet
       pas de répondre ;
     - les chunks trouvés ;
     - la question de l'utilisateur.
  4. Appelle le modèle de chat OpenAI `gpt-4o-mini` (configurable) via `symfony/ai-open-ai-platform`.
  5. Retourne la réponse texte + la liste des sources (nom de fichier / identifiant de chunk)
     effectivement utilisées.

- **Commande console** `app:rag:ask "<question>"` : appelle `RagQueryService` et affiche la réponse
  et les sources en sortie console. Utilisée pour tester sans monter de serveur HTTP.

- **Endpoint API** `POST /api/rag/ask` : accepte `{"question": "..."}` en JSON, retourne
  `{"answer": "...", "sources": [...]}`. Pas d'authentification dans ce périmètre (hors scope
  explicite, à ajouter ultérieurement si nécessaire).

- **Gestion des erreurs / cas vide** : si aucun chunk pertinent n'est trouvé (store vide ou score de
  similarité sous un seuil configurable), le système répond directement "je ne trouve pas
  d'information sur ce sujet dans la base" sans appeler le LLM de génération — évite les coûts
  inutiles et réduit le risque d'hallucination.

## Infrastructure & configuration

- **`docker-compose.yaml`** à la racine : service Postgres avec l'image `pgvector/pgvector`
  (Postgres 16 ou 17), volume persistant, port exposé en local pour le développement.
- **`symfony/doctrine-bundle` + `symfony/orm-pack`** : gèrent la connexion (`DATABASE_URL` dans
  `.env.local`). Aucune entité Doctrine métier n'est nécessaire — `symfony/ai-postgres-store`
  s'appuie sur cette connexion pour dialoguer directement avec Postgres/pgvector.
- **Initialisation du store** : utilisation de la commande fournie par `symfony/ai-postgres-store`
  pour créer la table et activer l'extension `vector`, à exécuter une fois le conteneur Postgres
  démarré.
- **Variables d'environnement** : `OPENAI_API_KEY` (déjà présente, utilisée) et `ANTHROPIC_API_KEY`
  (déjà présente, conservée pour un usage futur non couvert par ce périmètre — aucun code ne
  l'utilise ici).
- **`config/packages/ai.yaml`** : configuration du bundle `symfony/ai-bundle` déclarant la plateforme
  OpenAI, le store Postgres, et les modèles utilisés (embeddings + chat) via des paramètres
  facilement modifiables.

## Hors scope

- Authentification/autorisation sur l'endpoint API.
- Découpage structurel des documents (par section/titre).
- Architecture agent avec tool-calling autonome.
- Ingestion incrémentale par upload unitaire (seul le traitement batch d'un dossier est prévu).
- Utilisation d'Anthropic/Claude (clé conservée pour plus tard, non câblée dans ce périmètre).

## Tests

- Tests unitaires pour `DocumentExtractorInterface` (extraction PDF et DOCX) avec des fichiers
  d'exemple courts.
- Tests unitaires pour la logique de chunking (taille, chevauchement, cas limites : texte plus court
  que la taille d'un chunk).
- Test d'intégration pour `RagQueryService` avec un store et une plateforme IA mockés/fake, couvrant
  le cas nominal et le cas "aucun résultat pertinent".
- Test fonctionnel pour l'endpoint `POST /api/rag/ask` (statut, forme de la réponse JSON).
