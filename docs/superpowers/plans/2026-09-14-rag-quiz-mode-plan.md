# Plan d'implémentation : Mode Quiz

Date : 2026-09-14
Spec : [2026-09-14-rag-quiz-mode-design.md](../specs/2026-09-14-rag-quiz-mode-design.md)

## Summary

Ajouter un mode Quiz : QCM généré par l'IA à partir d'un document déjà indexé, corrigé côté serveur
avec feedback immédiat, historique par session — via un Symfony UX Live Component.

## Scope

- IN : génération de QCM sur un document entier, réponse mono/multi-choix, correction + explication
  sourcée immédiate, score final, historique des tentatives par session.
- OUT (phases suivantes, pas dans ce plan) : sélection par chapitre, exercices texte libre,
  flashcards, upload manuscrit, PWA, nombre de questions choisi par l'utilisateur à chaque lancement.

## Dépendances

- Nouveau : `symfony/ux-live-component` (+ `symfony/ux-twig-component`, dépendance transitive).
- Existant, réutilisé sans modification : `ai.platform.openai`, `doctrine.dbal.default_connection`,
  `app.rag.store_table`, le pattern `ChatMessage`/`ChatHistoryService` comme modèle pour
  `QuizAttempt`/`QuizAttemptService`.
- Point d'incertitude technique à lever au checkpoint 2 : l'API exacte de sortie structurée de
  `symfony/ai-platform` (`Symfony\AI\Platform\StructuredOutput\*`, option `response_format` sur
  `Platform::invoke()`) n'a pas encore été utilisée dans ce projet — lire le code vendor et un test
  existant de la bibliothèque avant d'écrire `QuizGenerator` pour confirmer la forme exacte de
  l'appel et de la réponse.

## Renommage préalable (checkpoint 0)

`ChatSessionResolver` → `VisitorSessionResolver`, déplacé de `Service/Rag/Chat/` vers
`Service/Rag/Session/`. Comportement inchangé (même clé de session), seuls le nom de la classe, son
namespace et les imports changent. Fait en premier et isolément pour ne pas mélanger un renommage
mécanique avec le reste des changements fonctionnels.

Fichiers touchés : `ChatSessionResolver.php` (déplacé/renommé), `ChatHistoryService.php` (si il
référence le type), `RagAskController.php`, `RagHistoryController.php`, et les tests qui les
couvrent le cas échéant.

## Entités & migrations

### Nouvelle entité : `QuizAttempt` (`src/Entity/Rag/QuizAttempt.php`)

Même style que `ChatMessage` (id string uuid assigné, pas de `GeneratedValue` auto) :

- `id` : string(36)
- `sessionId` : string(36)
- `documentSource` : string
- `questions` : json — `list<array{prompt: string, options: list<string>, correctIndices: list<int>, multiple: bool, explanation: string, source: string}>`
- `answers` : json — `list<array{questionIndex: int, selectedIndices: list<int>, correct: bool}>`, `[]` au départ
- `score` : int, nullable
- `totalQuestions` : int
- `createdAt` : datetime immutable
- `completedAt` : datetime immutable, nullable

Méthodes : constructeur `(sessionId, documentSource, questions, totalQuestions)`, `recordAnswer(int $questionIndex, array $selectedIndices): bool` (calcule et retourne `correct`, pousse dans `$answers`), `complete(): void` (calcule `score` depuis `answers`, fixe `completedAt`), getters.

Index `idx_quiz_attempt_session` sur `(session_id, created_at)`.

### Repository : `QuizAttemptRepository` (`src/Repository/Rag/QuizAttemptRepository.php`)

- `findBySession(string $sessionId): list<QuizAttempt>` (comme `ChatMessageRepository`)
- `get(string $id): ?QuizAttempt`

### Migration de schéma

`doctrine:schema:update --force` (comme `chat_message`) suffit — pas de bundle de migrations dans ce
projet. `make schema-update` couvre déjà ce cas (aucun changement Makefile nécessaire).

## Services

### `QuizGenerator` (`src/Service/Rag/Quiz/QuizGenerator.php`)

- Dépend de : `Doctrine\DBAL\Connection` (requête directe sur la table du store), le platform IA
  (`ai.platform.openai`), le paramètre `app.rag.quiz_question_count`.
- `generate(string $documentSource): list<GeneratedQuestion>` :
  1. `sampleSize = max(6, questionCount * 3)`.
  2. `SELECT content FROM <table> WHERE metadata->>'source' = :source ORDER BY random() LIMIT :sampleSize`
     (nom de table résolu via le paramètre `app.rag.store_table`, pas en dur).
  3. Si le nombre de lignes retournées est 0 → `QuizGenerationException` ("document introuvable ou
     vide").
  4. Appel au platform avec le prompt système dédié (voir ci-dessous) + les chunks en contexte +
     `response_format` pointant vers un DTO `GeneratedQuizPayload` (liste de `GeneratedQuestion`).
  5. Validation minimale du résultat (nombre de questions == `questionCount`, chaque question a au
     moins 2 options et au moins 1 `correctIndices` valide) ; sinon `QuizGenerationException`.

### DTOs (`src/Service/Rag/Quiz/Dto/`)

- `GeneratedQuestion` : `prompt`, `options` (list<string>), `correctIndices` (list<int>), `multiple`
  (bool), `explanation`, `source` — sert à la fois de cible de désérialisation pour la sortie
  structurée et de forme stockée dans `QuizAttempt::questions`.
- `GeneratedQuizPayload` : `questions` (list<GeneratedQuestion>) — un wrapper est nécessaire si la
  sortie structurée du platform attend un objet racine plutôt qu'une liste nue (à confirmer au
  checkpoint 2).

### `QuizAttemptService` (`src/Service/Rag/Quiz/QuizAttemptService.php`)

Couche fine au-dessus du repository + de `QuizGenerator`, pour garder `QuizComponent` mince (comme
`ChatHistoryService` pour le chat) :

- `start(string $sessionId, string $documentSource): QuizAttempt` (appelle `QuizGenerator`, persiste)
- `answer(string $attemptId, int $questionIndex, array $selectedIndices): QuizAttempt` (charge la
  tentative, `recordAnswer`, `complete()` si c'était la dernière question, flush)
- `getHistory(string $sessionId): list<QuizAttempt>`
- `listAvailableDocuments(): list<array{source: string, title: ?string}>` — `SELECT DISTINCT` sur la
  table du store.

### Prompt système : `config/prompts/quiz_generation_system_prompt.md`

Nouveau fichier, même esprit que `rag_agent_system_prompt.md` : demande de générer exactement N
questions à choix multiples fondées exclusivement sur les extraits fournis, de faire varier les
niveaux de difficulté, d'indiquer `multiple: true` uniquement quand plusieurs réponses sont
réellement correctes, de fournir une explication courte par question, et de reprendre le chemin de
document fourni tel quel comme `source` (pas d'invention de référence).

## Frontend

### Config

`composer require symfony/ux-live-component`, puis vérifier que
`assets/vendor/@symfony/ux-live-component/` est bien généré par AssetMapper (comme pour les autres
paquets `symfony/ux-*`), et ajouter `@symfony/stimulus-bundle` → contrôleur Live si nécessaire (la
recette Flex du paquet gère normalement `importmap.php` et l'attribut Twig).

### `QuizComponent` (`src/Twig/Components/Rag/QuizComponent.php` + `templates/components/Rag/QuizComponent.html.twig`)

`LiveProp` : `documentSource` (writable, avant démarrage), `attemptId` (nullable), `currentIndex`
(int), `selectedIndices` (array, état du formulaire en cours), `error` (nullable).

`LiveAction`s :
- `start()` : appelle `QuizAttemptService::start()`, initialise `attemptId`/`currentIndex` à 0.
- `submitAnswer()` : appelle `QuizAttemptService::answer()`, réinitialise `selectedIndices`, avance
  `currentIndex` si non terminé.
- `restart()` : reset complet des `LiveProp` pour revenir à l'écran de sélection.

Template : trois états rendus conditionnellement (sélection / question en cours avec feedback de la
dernière réponse / récapitulatif final), plus une section historique (via
`QuizAttemptService::getHistory()`).

### Route & navigation

- `GET /quiz` → un contrôleur `QuizUiController` (miroir de `RagUiController`) qui rend un template
  englobant contenant `<twig:Rag:QuizComponent />`.
- `templates/base.html.twig` : petite barre de nav avec deux liens ("Chat" `app_rag_ui`, "Quiz"
  `app_quiz_ui`).

## Gestion des erreurs

- `QuizGenerationException` (nouvelle, `Service/Rag/Quiz/Exception/`) : catchée dans
  `QuizComponent::start()`, place le message dans la `LiveProp $error`, affichée avec un bouton
  "Réessayer" (rappelle `start()`).
- Aucune option cochée à la soumission : validation Symfony Validator sur une propriété dédiée du
  composant (`#[Assert\NotBlank]` sur `selectedIndices` avec un message adapté), erreurs affichées
  inline comme un formulaire classique.

## Tests

1. `QuizGeneratorTest` (unitaire) : double du platform (`PlatformInterface` factice retournant un
   `GeneratedQuizPayload` fixe, comme `RagQueryServiceTest` le fait pour son propre appel) + une
   vraie connexion DBAL de test avec quelques lignes insérées dans la table du store pour l'étape
   d'échantillonnage. Cas couverts : succès, document introuvable, sortie structurée invalide.
2. `QuizAttemptTest` (unitaire) : `recordAnswer()` (mono et multi-réponses, y compris réponse
   partiellement correcte sur une question multiple → `correct = false`), `complete()` calcule le
   bon score.
3. Test de `QuizComponent` (fonctionnel, `InteractsWithLiveComponents` de
   `symfony/ux-live-component`) : cycle complet démarrage → réponse (question mono-choix et
   question multi-choix) → récapitulatif, avec `QuizGenerator` remplacé par un double dans le
   conteneur de test (même pattern que `RagAgentQueryService` dans `RagAskControllerTest`).
4. `QuizAttemptRepositoryTest` ou test fonctionnel dédié : une tentative terminée apparaît dans
   `findBySession()`, sur le modèle de `RagHistoryControllerTest`.

## Implementation Steps

### Checkpoint 0 — Renommage préalable
1. [ ] Renommer `ChatSessionResolver` → `VisitorSessionResolver`, déplacer vers `Service/Rag/Session/`
2. [ ] Mettre à jour tous les usages (`RagAskController`, `RagHistoryController`, tests)
3. [ ] `make test` vert avant de continuer

### Checkpoint 1 — Entité & persistance
4. [ ] Créer `QuizAttempt` (entité) + `QuizAttemptRepository`
5. [ ] `QuizAttemptTest` (recordAnswer, complete) — écrit avant l'implémentation (TDD)
6. [ ] `make schema-update` en dev et en test, vérifier la table créée
7. [ ] `make test` vert

### Checkpoint 2 — Génération des questions
8. [ ] Lire `vendor/symfony/ai-platform/src/StructuredOutput/` pour confirmer l'API exacte de sortie
       structurée (lève l'incertitude notée dans "Dépendances")
9. [ ] Créer `GeneratedQuestion` / `GeneratedQuizPayload` (DTOs)
10. [ ] Créer `config/prompts/quiz_generation_system_prompt.md`
11. [ ] `QuizGeneratorTest` (TDD) puis `QuizGenerator`
12. [ ] `make test` vert

### Checkpoint 3 — Orchestration
13. [ ] `QuizAttemptService` (start / answer / getHistory / listAvailableDocuments)
14. [ ] Tests unitaires/d'intégration du service

### Checkpoint 4 — Frontend
15. [ ] `composer require symfony/ux-live-component`
16. [ ] `QuizComponent` + template (les trois états + historique)
17. [ ] `QuizUiController` + route `/quiz` + lien de nav dans `base.html.twig`
18. [ ] Test fonctionnel `QuizComponent` (`InteractsWithLiveComponents`)
19. [ ] Vérification manuelle en conditions réelles (navigateur, comme pour le chat) : sélection →
        quiz mono/multi-choix → récap → historique

### Checkpoint 5 — Documentation
20. [ ] README : section "Quiz" (à côté de "Interroger la base"), mise à jour de la feuille de route
21. [ ] Commit(s) — un commit logique par checkpoint plutôt qu'un unique gros commit

## Acceptance Criteria

- [ ] L'utilisateur choisit un document indexé et démarre un quiz de `app.rag.quiz_question_count`
      questions (5 par défaut)
- [ ] Chaque question est mono ou multi-choix selon ce que l'IA a déterminé (`multiple`)
- [ ] La réponse est corrigée immédiatement, avec explication et source, avant la question suivante
- [ ] Le score final s'affiche à la fin, et la tentative apparaît dans l'historique de la session
- [ ] Un document sans contenu suffisant affiche une erreur claire, sans planter
- [ ] `make test` vert (nouveaux tests unitaires + fonctionnels inclus)

## Risks & Mitigations

| Risque | Probabilité | Impact | Mitigation |
| --- | --- | --- | --- |
| API de sortie structurée `symfony/ai-platform` différente de ce qui est supposé ici | Moyenne | Moyen | Levée explicitement au checkpoint 2 avant d'écrire `QuizGenerator`, avant tout le reste du code qui en dépend |
| Modèle renvoie un JSON valide mais incohérent (ex. `correctIndices` hors bornes) | Moyenne | Moyen | Validation post-génération dans `QuizGenerator` (checkpoint 2), erreur claire plutôt qu'un crash silencieux plus tard |
| Document avec trop peu de chunks pour générer N questions distinctes | Faible | Faible | Vérification du nombre de lignes échantillonnées avant l'appel au modèle |
| Régression sur le chat via le renommage `ChatSessionResolver` | Faible | Moyen | Checkpoint 0 isolé, `make test` vert avant de passer à la suite |
