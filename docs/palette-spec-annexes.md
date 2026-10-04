# Annexes à la spécification de la palette

**Se rapporte à :** `docs/palette-spec.md` (PR #217), rédigée contre `backprod@30c5c50`.
**Rédigées le :** 4 octobre 2026, contre le même commit.
**Statut :** revue de la proposition, **avant toute ligne de code**. Ces annexes ne modifient pas
la spécification : elles disent ce qui doit l'être, et pourquoi.

| Annexe | Contenu |
|---|---|
| A | Méthode, périmètre et fiabilité de la revue |
| B | Ce que la spec affirme correctement sur le dépôt |
| C | Objections bloquantes |
| D | Objections majeures |
| E | Corrections mineures du texte |
| F | Contrastes calculés |
| G | Incohérences internes et cas limites |
| H | Portes de qualité et documents à mettre à jour |
| I | Proposition de recadrage et ordre de travail révisé |
| J | Décisions à trancher |

Chaque référence `fichier:ligne` vaut pour `30c5c50`.

---

## Annexe A — Méthode, périmètre et fiabilité

La revue a confronté la spec au dépôt selon six axes :

| # | Axe | Ce qu'il vérifiait |
|---|---|---|
| 1 | Affirmations sur le code | chaque fait que la spec avance sur le dépôt |
| 2 | Règles d'architecture | CLAUDE.md, les ADR, les portes de qualité |
| 3 | API, données, conventions | routes, codes d'erreur, cache, audit, tables, pagination, débit |
| 4 | Fonctionnement du frontend | feuilles de style, CSP, démarrage, coque unique, aperçu, cache TanStack, i18n |
| 5 | Produit, droits, sécurité | habillage, `white_label`, motif, permission, écrans à effet juridique, besoin réel |
| 6 | Contrastes, calculés | WCAG 2.x sur les valeurs réelles d'`index.css`, dans les deux thèmes |

Deux contrôles s'y sont ajoutés :

- **Contre-vérification.** Un relecteur a cherché à réfuter les 30 objections des axes 1 et 2 :
  25 confirmées, 5 partielles (lignes décalées pour la plupart), aucune réfutée. Les
  rectifications sont reportées dans ces annexes.
- **Passage final.** Recherche de ce qu'aucun axe n'avait vu : incohérences entre sections, cas
  limites, tests qui ne prouvent rien, étapes qui laissent l'interface inutilisable (annexe G).

Un désaccord entre relecteurs a été tranché sur pièce : **aucune écriture du personnel chez un
tenant n'exige de motif**. `GrantTenantEntitlementController` le dit en toutes lettres : « No motive
header: nothing of the customer's is read ».

---

## Annexe B — Ce que la spec affirme correctement

- **Les 23 jetons sont là**, en clair (`frontend/src/index.css:156-194`) et en sombre (`:196-232`),
  sur `:root`, hors couche Tailwind. Le découpage du §1.1 et les valeurs du §1.2 sont exacts.
- **Le sombre ne dépend que de `@media (prefers-color-scheme: dark)`** (`index.css:196`) : ni
  `.dark`, ni `data-theme`. La règle 4 du §1.3 (`@media not all and (prefers-color-scheme: dark)`)
  est valide, et couvre clair et `no-preference`.
- **Redéfinir `--ds-*` sur `:root` recolore tout** : `@theme` (non `inline`) déclare
  `--color-x: var(--ds-x)` (`index.css:102-141`). L'opacité (`border-success/30`) passe par
  `color-mix` et suit.
- **Le défaut du §2.3 est réel.** Le commentaire est cité mot pour mot (`index.css:36-38`) ;
  `useSkin()` (`queries/skin.ts:21`) n'est appelé que par `BrandingScreen.tsx:44` ; aucun code ne
  pose `--ds-accent`.
- **L'habillage est bien décrit** : colonnes et `CHECK` (`migrations/Version20260909120000.php:43-57`),
  `SkinRoute::colour` en `^#[0-9a-f]{6}$`, lecture par l'appartenance, écriture par `skin.manage` et
  `white_label` (`src/Skin/Controller/SkinRoute.php:33-45`).
- **Les briques de la console existent** sous les noms cités : `StaffContext`, `StaffAccess`,
  `AccessMotive`, `AccessMotiveGate`, `X-Access-*`, `GET /staff/me` (qui renvoie `permissions`).
- **L'audit sans tenant a un précédent** : `StaffAccess` accepte tenant et produit nuls
  (`CONFIGURE_NAVIGATION`, `src/Navigation/Service/NavigationDesk.php:44-55` ; `FeatureDesk`).
- **Les chemins sont cohérents** avec l'existant : `/public/*` est PUBLIC
  (`config/container.php:816`), `/tenant/palette` répond à `/tenant/skin`
  (`config/routes.php:583-585`), `/staff/palette` à `/staff/navigation`,
  `/staff/tenants/{tenantId}/palette` à `/staff/tenants/{tenantId}/tax-profile`.
- **L'enveloppe** `{error:{code,message,details,request_id}}` (`src/Shared/Http/ErrorResponse.php`)
  et l'enveloppe de ressource `{"palette": …}` suivent la convention. `VALIDATION_FAILED` est bien en
  400 avec `{field, requirement}`.
- **`GET /staff/palettes` sans motif** est cohérent : une liste ne révèle rien.
- **Le CSP déployé autorise une `<style>` en ligne** (`deploy/siteground/htaccess.template:100`).
- **Une lecture publique avant la session a un précédent** : `/public/tenant`, appelée depuis
  `SignInGate` et la vitrine (`queries/storefront.ts:69`).
- **Onze des douze paires de contraste passent** avec les origines (annexe F).

---

## Annexe C — Objections bloquantes

Quatre points à régler avant toute ligne de code, plus un cinquième qui est une décision
commerciale.

### C.1 Une seule coque : la palette suit l'écran affiché, pas le démarrage

**Sections :** §0, §5.3, §9.2, décision 3.

- La spec ne connaît que deux cas au démarrage, « application (tenant) » et « console (personnel) ».
  Or `AppShell` sert les deux (ADR-046) : `onPlatformScreen = pathname === '/console' ||
  startsWith('/console/')` (`app/shells/AppShell.tsx:90`), et la navigation est cliente.
- Une même personne, `PLATFORM_ADMIN` et `TENANT_ADMIN`, passe de `/console/products` à
  `/projects` sans recharger. La barre de contexte, la navigation primaire et les surimpressions
  sont partagées : la spec ne dit pas quelle palette elles prennent.
- Un `PLATFORM_ADMIN` sans appartenance reçoit un 403 sur `/me` : `GET /tenant/palette` ne peut
  pas répondre pour lui.
- Le « sélecteur de tenant » du §5.3 n'existe pas : changer d'organisation, c'est
  `location.assign('/<slug>/')` (`landing.ts:97`), donc un rechargement.
- Poser la feuille depuis la requête TanStack met un effet visuel dans la couche qui ne fait que
  transporter les données, et cette couche ne connaît pas la route.

**À écrire :**
- La feuille suit la route : `/console/*` prend la plateforme seule ; toute autre route prend la
  palette du tenant de la session. Recalculée à chaque navigation, posée par `AppShell`.
- `/console/tenants/{id}` garde la palette de la plateforme.
- La requête tenant n'est active que si la session a un contexte tenant ; sinon, la plateforme,
  sans erreur affichée.
- Retirer le « sélecteur de tenant » du §5.3 et son test au §9.2. Le remplacer par : une personne
  qui détient les deux autorités passe d'un écran à l'autre sans recharger.

### C.2 Une palette de plateforme ratée et enregistrée bloque l'outil qui la répare

**Sections :** §7.3, §8, §6.4, décision 3.

- §7.3 protège le **brouillon** : « jamais sur la console elle-même ».
- Mais le serveur « n'en refuse aucune » (§6.4), et la palette **enregistrée** de la plateforme
  « s'applique » à la console (décision 3), écran Palette compris. Un `ink` égal au `canvas`, une
  fois enregistré, ne laisse que l'API brute.
- Le test du §9.2 (« l'écran reste lisible, puisqu'il ne prend pas la palette éditée ») passe par
  construction : il ne prouve rien.
- Le dépôt a déjà réglé ce piège : l'entrée Menus est la seule que le réglage ne peut pas masquer,
  « or the choice could not be undone » (`app/frame/navigation.ts:165-168`, `MenusScreen.tsx:38`).

**À écrire :** l'écran Palette se peint toujours aux origines (la feuille `ds-palette` est retirée
tant qu'il est monté) ; éventuellement une échappatoire documentée (`?palette=origin`). Le scan du
§9.2 teste une palette extrême **enregistrée**.

### C.3 Le produit est le contexte racine

**Sections :** périmètre, §2.2, §5.2, §6.1, décision 2.

- L'habillage est enregistré par (tenant, produit), et la migration dit pourquoi : une organisation
  qui habille deux produits n'a aucune raison d'y vouloir la même teinte
  (`Version20260909120000.php:13-17`).
- Toutes les routes tenant exigent `X-Product` ; `ambientParams` lève `NoProductChosen` sans produit
  (`frontend/src/api/client.ts:305-311`). La palette résolue varie donc avec le produit. Pourtant :
  - ni l'ETag ni la clé de cache ne contiennent le produit ;
  - rien ne recharge la palette au changement de produit ;
  - une route `/staff/*` ne résout aucun produit (`StaffRoute.php:52-75` lit `?product=`), donc
    « son habillage » n'y est pas défini.
- « Aucun produit hébergé n'est concerné » est faux : l'espace de travail est peint avec les
  `--ds-*` (`features/workspace/ProjectsScreen.tsx:168`).
- En v2, `white_label` est une capacité résolue pour une personne **sur un produit**
  (`RequestContextMiddleware.php:151`). Une palette par tenant seul laisserait la capacité d'un
  produit repeindre tous les autres.
- CLAUDE.md exige pour chaque route une portée produit et une portée tenant : les tableaux du §5.2
  et du §6.1 ne les donnent pas.

**À écrire :** clé (tenant, produit) (voir I.1), `X-Product` sur la lecture tenant, produit dans
l'ETag, rechargement au changement de produit, palette de la plateforme tant qu'aucun produit
n'est choisi, et une colonne « portée produit / portée tenant » dans les tableaux de routes.

### C.4 La correspondance habillage → jetons est fausse, dans les deux thèmes

**Sections :** §2.3, §10 étape 2.

- **Elle inverse l'intention d'origine.** Le commentaire d'`index.css:36-38` dit, dans l'ordre,
  `primary_color` → `--ds-accent` ; `architecture-v2.md:1824-1827` fait de `--brand-primary` la
  couleur principale. Or `accent-strong` ne sert qu'au survol (`ui/Field.tsx:139`) et à l'entrée
  active (`app/frame/regions.tsx:247`). Avec la spec, la couleur principale d'un client ne
  s'afficherait qu'au survol.
- **Elle casse le sombre.** En sombre, l'accent d'origine est clair (`#4cc2ee`), le texte posé
  dessus foncé (`#04202c`), et `accent-strong` est **plus clair** qu'`accent` (`#8ad9f5`). Une seule
  valeur pour les deux thèmes, sans dériver `on-accent` ni `accent-wash`, donne (annexe F) :
  - avec l'exemple du contrat `#1f4b99`, en sombre : texte du bouton principal **2,02**, entrée
    active de la navigation, visible sur tous les écrans, **1,78** ;
  - avec l'accent clair de la plateforme elle-même, `#0b6e99` : **2,97** ;
  - avec la donnée de test `#112233` : **1,04**.
- L'étape 2 livrerait ce mapping avant le moindre contrôle de contraste (étape 4).

**À écrire :** `primary_color` → `accent`, `accent_color` → `accent-strong`, ou trancher par écrit.
L'habillage ne s'applique qu'au thème clair, ou le serveur dérive les variantes sombres et un
`on-accent` lisible, ou l'habillage demande deux valeurs. L'étape 2 passe par une résolution
**serveur** (I.3).

### C.5 Décision 1 : la marque blanche donnée sans être vendue

**Sections :** §3.2, décision 1.

- `white_label` est vendu, « Your own logo and colours instead of ours »
  (`src/Demo/Domain/DemoWorld.php:257`), à partir de Pro
  (`src/Demo/Infrastructure/PostgresDemoFixtures.php:701-708`).
- La plateforme a déjà un moyen tracé et motivé de l'offrir : l'octroi par le personnel (ADR-056,
  `GrantTenantEntitlementController`).
- La décision 1 ouvre un second chemin, gratuit et hors catalogue, vers ce que l'offre vend.

**À écrire :** une palette de tenant ne s'applique que si `white_label` est tenue sur ce produit ;
le personnel qui veut l'offrir passe par un octroi.

---

## Annexe D — Objections majeures

### D.1 Contrastes et accessibilité

1. **La paire `line-strong` / `surface` (minimum 3) échoue déjà avec les origines** : 1,56 en clair,
   1,59 en sombre. C'est la bordure des champs (`ui/Field.tsx:88`) et des en-têtes de tableau
   (`ui/Table.tsx:57`) : un défaut WCAG 1.4.11 du code actuel, invisible parce qu'axe ne mesure pas
   le contraste des éléments non textuels. Une palette vide afficherait en permanence « 1 contraste
   insuffisant ». Corriger l'origine, et tester « une palette égale aux origines ne produit aucun
   avertissement ».
2. **Le registre oublie des paires réellement utilisées** (annexe F.2) : `on-accent` /
   `accent-strong`, `accent-strong` / `accent-wash`, `subtle` / `well`, `muted` / `line`.
3. **Le scan axe n'est pas ce que la spec en dit.**
   - Aucun projet Playwright n'émule le sombre (`playwright.config.ts:39-40`) ; le seul
     `emulateMedia` règle les animations (`e2e/accessibility.spec.ts:448`).
   - Le scan couvre 11 routes console sur 22 et 21 autres routes sur 28
     (`accessibility.spec.ts:258-292`).
   - `index.css:41-42` (« every route in both themes ») et `ui-roadmap.md:775-778` (« all 30 routes
     in both shells ») disent le contraire.
   « Aligner » les paires sur ce scan, c'est les aligner sur une mesure qui n'existe pas. Ajouter un
   projet `colorScheme: 'dark'` à l'étape 1.
4. **« Avertir sans refuser » renverse une règle du dépôt** : « Accessibility is a gate, not an
   audit » (`ui-roadmap.md:775-778`) ; `production-readiness.md:31` affirme que chaque route passe
   WCAG 2.1 AA, ce qu'une palette à 3:1 rendrait faux en production sans aucun test rouge.
   Défendable au nom d'une charte imposée, mais à décider par écrit. Option : refuser en 422
   (`CONTRAST_INSUFFICIENT`) les paires de texte sous 4,5, et n'admettre une dérogation motivée et
   journalisée qu'au niveau plateforme.
5. **Les ratios affichés pendant l'édition seraient calculés dans le navigateur.** Le brouillon est
   invisible du serveur ; cela fait deux calculs d'une même quantité comparée à un seuil, en PHP et
   en TypeScript : l'exemple de la formule du lacet que CLAUDE.md interdit, et le frontend n'a pas de
   Core TypeScript. Précédent pour calculer à blanc : `POST /tax/calculate`. Écrire
   `POST …/palette/preview`, qui résout et juge sans enregistrer, appelé après une courte pause de
   saisie. Comparer sur la valeur non arrondie : 4,496 s'affiche « 4,50 » et reste sous le seuil.

### D.2 Contrat, registre, cache

6. **`If-Match`, 428 et 304 n'ont aucun précédent.** Aucune exception ne produit 428 ; la CORS
   n'autorise pas `If-Match` et n'expose pas `ETag` (`CorsMiddleware.php:47-48, 111`) ;
   `queries/showcase.ts:404-416` documente l'absence de précondition comme un choix. Le seul ETag
   existant (`ShowInvoicePdfController.php:60`) n'est contrôlé par rien.
   **Recommandé :** `expected_revision` dans le corps du `PUT` et du `restore` (paramètre de requête
   pour le `DELETE`), `409 PALETTE_STALE` avec `details.revision`, sans 428 : c'est le motif
   `JsonBody`, et le client généré le porte seul. Sinon : une exception 428, l'en-tête déclaré dans
   OpenAPI, et un ADR.
7. **L'ETag ignore les couleurs d'origine.** `resolved` les contient ; un déploiement qui change une
   origine ne touche aucune révision. Le navigateur reçoit un 304, garde l'ancien corps, et
   `cssPalette` y voit un écart avec les nouvelles origines du bundle : il **repose l'ancienne
   couleur** jusqu'à la prochaine écriture. `tenant_skins` n'a pas de révision, donc la part
   « habillage » de l'ETag n'est pas définie. **Remède :** le serveur n'envoie que les écarts
   fusionnés et leurs `sources`, jamais les origines ; ou un ETag calculé sur le corps.
8. **L'administrateur ne voit pas son propre enregistrement.** `public, max-age=60` laisse le
   navigateur resservir l'ancienne réponse même après invalidation ; `staleTime` global de 30 s et
   `refetchOnWindowFocus: false` (`AppProviders.tsx:26, 36`). La réponse du `PUT` est le nouvel état :
   `setQueryData` sur la clé staff **et** sur la clé publique (convention de
   `queries/notifications.ts:16-17`) ; côté serveur, `max-age=0, must-revalidate`.
9. **`NoSharedCacheMiddleware`** respecte un `Cache-Control` posé par la route mais ajoute toujours
   `Vary: Authorization, Cookie, X-Product, X-Tenant` (`NoSharedCacheMiddleware.php:37-46`) : seul le
   cache du navigateur en profite. Précédent : `JwksController.php:36`. Le dire.
10. **Débit de `GET /public/palette`.** Le seau `public` est de 60 requêtes par minute **par IP**,
    partagé avec tout `/public/*` (`RateLimitMiddleware.php:71-75, 99-101`) ; une personne connectée
    y est comptée aussi. Appelé à chaque démarrage, un bureau derrière un NAT l'épuise. Ne l'appeler
    qu'avant la connexion et pour la console : `/tenant/palette` est déjà résolue sur la plateforme.
11. **`config/palette-tokens.json` est une seconde source de contrat**, à côté d'OpenAPI et
    d'`index.css` (§8.1 d'architecture-v2, non-négociable 25). En plus :
    - le filtre de la CI frontend ne couvre pas `config/` (`.github/workflows/ci.yml:79-82`) : le
      test qui garde le registre ne tournerait pas ;
    - `config/` contient du PHP (`container.php`, `routes.php`), et Vite peut refuser
      `../config/*` en dev (`server.fs.allow`) ;
    - la spec prévoit à la fois l'import et `GET /staff/palette/registry` : deux chemins pour une
      donnée.
    **À écrire :** les noms de jetons deviennent une énumération du contrat ; un test PHP vérifie que
    registre, énumération et `index.css` concordent ; le frontend n'importe jamais `config/`.
12. **Les libellés ne seraient pas traduits.** `scripts/i18n-keys.mjs:66-70` ne collecte que les
    littéraux des `.ts`/`.tsx` de `src/` : les `label` d'un JSON ou d'une réponse d'API, et les noms
    de modèles de `ui/palettes/*.json`, resteraient en anglais dans les autres langues
    (`i18n/index.ts:20`). Libellés dans un module TS de `src/`, ou étendre le script.

### D.3 Données et exploitation

13. **La ligne unique de `platform_palette` disparaît à chaque remise à zéro de la démo.**
    `TRUNCATE … users … RESTART IDENTITY CASCADE` vide toute table qui référence `users`, quelle
    que soit la règle `ON DELETE` (`PostgresDemoFixtures.php:98`) ; `TestDatabase::reset` produit le
    même effet (`TestDatabase.php:80-92`). La table singleton `BOOLEAN PK CHECK(id)` n'a d'ailleurs
    aucun précédent : les réglages de plateforme vivent dans `platform_settings(key, value JSONB)`
    (`Version20260917120000.php:37-43`), où l'absence vaut le défaut (ADR-063). Voir I.2.
14. **L'unicité « NULL = plateforme »** : la convention est `NULLS NOT DISTINCT`
    (`Version20260925100000.php:38-42`), pas l'astuce `COALESCE(…, '000…')`.
15. **Ne pas enregistrer un écart égal à la valeur héritée** contredit ce que `BrandingScreen` dit
    déjà : laisser vide « is not the same as setting it to the product's current value, because the
    product's may change » (`BrandingScreen.tsx:105`). Stocker exactement ce qui a été choisi ;
    « revenir » retire l'écart.
16. **L'élagage « par la file de jobs »** impose de déclarer un type dans `Job\Domain\Schedule`
    (`Schedule.php:84`, ADR-067), et `JobScheduleTest` échoue sinon. `sweep.jobs` (PR #216) ne purge
    que la file elle-même. Plus simple : un `DELETE` borné dans la transaction de chaque écriture.
    L'invariant est vrai à tout instant, sans job, et le test devient déterministe.

### D.4 Frontend

17. **L'aperçu limité à une zone de l'écran ne marche pas tel qu'écrit.** `--color-x: var(--ds-x)`
    se calcule sur `:root`, et les descendants héritent de la valeur déjà substituée : redéfinir
    `--ds-*` sur le conteneur ne change rien. Les noms ne se recopient pas non plus mécaniquement
    (`ink-muted` → `--color-muted`, `index.css:110-111`). Sans `.dark` ni `data-theme`, l'aperçu du
    thème inactif ne peut pas venir d'une media query, et quatre écrans emploient
    `dark:hover:bg-inverse` (`StaffTenantsScreen.tsx:105`, `VatReportsScreen.tsx:96`,
    `SupportConversationsScreen.tsx:88`, `OfferAuthoringScreen.tsx:128`).
    **À écrire :** le conteneur reçoit le jeu complet `--color-*` résolu et `color-scheme` ; ou
    `@theme inline` (en adaptant `ui/tokens.test.ts:41`) ; ou l'aperçu se limite au thème courant.
    Pas de surcouche `fixed inset-0` dans l'aperçu : il n'y a aucun `createPortal`, et
    `CommandPalette` et `MoreSheet` sortiraient du cadre.
18. **Le flash au chargement s'évite sans rendu serveur** (décision 4). `main.tsx` s'exécute avant
    `createRoot` : y appliquer de façon synchrone la dernière feuille gardée dans `localStorage`,
    puis revalider (précédents : la langue, `i18n/index.ts:120` ; le produit,
    `state/session.ts:194`), lectures et écritures sous try/catch. Le CSP interdit le script en
    ligne, ce qui exclut un script dans `index.html` mais pas `main.tsx`.
    Il faut aussi empêcher le clignotement en cours de séance : `ProductSwitcher` appelle
    `resetQueries()` (`ProductSwitcher.tsx:236`), et `AppProviders` est remonté à chaque changement
    de langue (`key={locale}`, `AppProviders.tsx:98`). L'applicateur ne retire jamais la feuille
    sur `undefined` ni au démontage ; il ne la remplace qu'à réception d'une réponse.
19. **Ordre des feuilles.** Les `--ds-*` sont hors couche ; à spécificité égale, l'ordre
    d'insertion décide, et Vite injecte `index.css` à l'import en dev (`main.tsx:5`). Écrire
    `:root:root` dans `ds-palette` pour ne plus en dépendre, et entourer le bloc sombre de
    `@media (prefers-color-scheme: dark)`.

### D.5 Droits et sécurité

20. **Le motif d'accès s'applique à la lecture, pas à l'écriture.** `StaffRoute::motive` n'est
    appelé que par 9 GET (List* ×7, `ShowTenant`, `ShowSupportConversation`). Le code dit pourquoi :
    « R14 asks for a reason where a staff member reveals a customer's own data »
    (`SetOfferAuthoringController.php:24-27`), et les écritures n'en demandent pas
    (`GrantTenantEntitlementController` : « No motive header »).
    - Un motif pour lire la palette d'un tenant (elle expose l'habillage) et ses révisions ; aucun
      pour `PUT`, `DELETE`, `restore`, ou bien un écart justifié par écrit.
    - `PURPOSES` (`AccessMotive.php:47-53`) n'a pas de valeur pour « configuration » : le journal
      compterait de faux `SUPPORT_REQUEST`, ce qui détruit la propriété « countable » que R14 cherche.
      En ajouter une, ou décider que les couleurs ne sont pas sensibles.
    - Le motif est déjà gardé **par tenant pour toute la séance** dans `state/console.ts:14-36`
      (`giveMotive`, `motives`), pas « une fois par écran » : réutiliser.
21. **En v2, un tenant pourrait tromper ses propres membres** sur des écrans à effet juridique.
    Rien n'empêche `ink == canvas` (un avertissement seulement), ni `danger` en vert et `success` en
    rouge. Or le `TENANT_ADMIN` est le **vendeur** de la place à ses membres (ADR-055) : il pourrait
    atténuer des conditions de résiliation ou un avis `SECURITY`. Risque réaliste et faible, mais il
    coûte peu à fermer : en v2, le tenant ne règle que la famille Accent, et les contrastes sont
    refusés, pas avertis. Le commentaire du dépôt va dans ce sens : `info` existe pour que « a brand
    set to pink » ne repeigne pas chaque badge (`index.css`, bloc `@theme`).
22. **Les pages publiques d'un tenant** gardent les couleurs de la plateforme, alors que
    `home-showcase-spec.md:152-157` veut que le bouton de `/globex/` suive la couleur du tenant. Le
    slug est connu avant la connexion (`root.ts:55-68`, `GET /public/tenant`,
    `openapi.json:18811`), et la page de connexion est la plus « marquée » de toutes. Ajouter
    l'accent du tenant à `/public/tenant`, ou amender la spec de la vitrine.
23. **`GET /tenant/palette` sous la chaîne FULL** : refusée sur `EMAIL_UNCONFIRMED`,
    `NO_TENANT_ACCESS`, `TENANT_SELECTION_REQUIRED` (`RequestContextMiddleware.php:125-127`,
    `TenantResolver.php:43, 63, 73`), mais pas sur `SUBSCRIPTION_REQUIRED` ni
    `SUBSCRIPTION_PAST_DUE`. `IDENTITY_ONLY` ne résout aucun tenant et ne convient pas. Garder FULL,
    et écrire que sur ces refus le frontend garde la palette de la plateforme.

---

## Annexe E — Corrections mineures du texte

| # | Section | Correction |
|---|---|---|
| a | §3.1 | **Mauvais précédent de migration.** `skin.manage` est une permission **tenant** (`permissions`/`role_permissions`, `Version20260909120000.php:69-81`). Prendre `staff.sign_up.manage` (`Version20260928090000.php:50-58`) ou `staff.navigation.manage` (`Version20260917120000.php:46-58`) : `platform_permissions`, constante dans `src/Staff/Domain/StaffPermission.php`, ligne dans la matrice d'`identities-and-permissions.md`. Copier le précédent cité donnerait une permission invisible pour `/staff/me`. |
| b | §6.5 | `ACCESS_MOTIVE_REQUIRED` répond **422**, pas 400 (`AccessMotive.php:79-80`). Ajouter `ACCESS_MOTIVE_INVALID` (422, `:94`). |
| c | §6.5, §8 | Le 413 n'est pas « refusé avant lecture complète » : `JsonBody::of` lit tout le corps (`JsonBody.php:33`). Les 413 existants portent `{limit_bytes, size_bytes}` (`UploadPolicy.php:66`, `DocumentPolicy.php:64`). Écrire « mesuré sur le corps reçu », ou prévoir un middleware et le dire. |
| d | §6.2 | `StaffContext` autorise mais ne journalise pas : c'est `StaffAccessLog::record`, appelé par les services (`StaffDesk.php`). En v2, l'écriture d'un `TENANT_ADMIN` va dans `audit_log`. Aujourd'hui, les écritures d'habillage ne sont pas auditées du tout (seule la colonne `updated_by`). |
| e | §4, §9.1 | **Retirer l'argument RGPD et le test « Effacement ».** Aucun code ne supprime un tenant ; `staff_access_log.tenant_id` est `ON DELETE RESTRICT` (`Version20260903090000.php:122`), donc les audits `CONFIGURE_PALETTE` bloqueraient eux-mêmes le `DELETE`. L'effacement d'une personne anonymise sans supprimer (`PostgresErasureRepository.php:140-165`) : le `ON DELETE SET NULL` ne se déclenche jamais. Garder `CASCADE` par cohérence avec `tenant_skins`. |
| f | §5.2 | `Cache-Control: public` est la première exception à `NoSharedCacheMiddleware` (« No answer of this API may be served to anybody but the person it was made for », `:13`). La dire et la justifier. |
| g | Tout | « Palette » est déjà pris : `CommandPalette`, `usePaletteShortcut`, `openPalette`/`paletteOpen` (`AppShell.tsx:77-81`), testid `command-palette`, « Palette de commandes » en français. Nommer la fonctionnalité « Colours / Couleurs » (`ui/colours.ts`, `queries/colours.ts`). |
| h | §1.3 | `SkinRoute` **normalise** les majuscules (`strtolower`, `SkinRoute.php:60, 68`) ; la spec dit qu'elle « refuse ». Choisir une règle. |
| i | §5.3, §8 | Retirer « à vérifier contre le CSP » et `adoptedStyleSheets` : le CSP est connu et autorise l'en ligne. Le commentaire du gabarit `.htaccess` (`:91-95`) doit nommer la palette comme seconde raison de `'unsafe-inline'`. |
| j | §7.2 (5) | L'aperçu cite des composants absents : ni `Toast`, ni `Badge`, ni `Banner` dans `src/ui` (les toasts ne sont qu'un commentaire, `AppFrame.tsx:35`). Ce qui existe : `Button`/`buttonClass`, `Field`, `FormCard`, `Table`, et `pill()`, `panel()`, `notice()` (`ui/tone.ts:75-106`). |
| k | §1.1 | **Couleurs hors jetons**, que la palette n'atteindra pas : `bg-black/40` (`CommandPalette.tsx:54`, `MoreSheet.tsx:37`), `bg-white/5` (`ProblemBand.tsx:51`), les ombres en `rgb()` (`index.css:148-149, 229-230`), `color-scheme`, et le formulaire Stripe rendu sans `appearance` (`PaymentElementPanel.tsx:116`), qui ignore aussi le thème sombre. |
| l | §0 | « L'ambre de l'administration » est `--ds-warning`, qui porte aussi chaque badge « en attente ». Le dire, et avertir quand on le modifie. |
| m | §7.2 (1) | `GET /staff/tenants` n'a pas de recherche (seulement `limit`/`offset`). Faire de la palette d'un tenant un onglet de `/console/tenants/$tenantId` plutôt qu'un sélecteur avec recherche. |
| n | §6.1 | « Paginé » : la convention est `limit`/`offset` validés par `PageRequest::bounded` (refusés hors bornes), enveloppe `{<items>, total, limit, offset}`. L'écrire pour `revisions` et `GET /staff/palettes`. |
| o | §5.1 | `contrast_warnings.ratio: 3.8` (une décimale) contre « contraste 3,80 » à l'écran (§7.2) : fixer la précision dans le contrat. |

---

## Annexe F — Contrastes calculés

Ratios WCAG 2.x (luminance relative), sur les valeurs d'`index.css` à `30c5c50`.

### F.1 Les douze paires du registre (§1.2)

| Texte / fond | Min | Clair | Sombre | Verdict |
|---|---|---|---|---|
| `ink` / `canvas` | 4,5 | 17,05 | 16,60 | passe |
| `ink` / `surface` | 4,5 | 18,31 | 15,29 | passe |
| `ink-muted` / `surface` | 4,5 | 7,57 | 7,40 | passe |
| `ink-subtle` / `canvas` | 4,5 | 4,69 | 5,83 | passe, de justesse en clair |
| `on-accent` / `accent` | 4,5 | 5,67 | 8,22 | passe |
| `accent` / `surface` | 4,5 | 5,67 | 8,70 | passe |
| `on-inverse` / `inverse` | 4,5 | 17,76 | 16,60 | passe |
| `success` / `success-wash` | 4,5 | 5,59 | 8,28 | passe |
| `warning` / `warning-wash` | 4,5 | 5,58 | 8,51 | passe |
| `danger` / `danger-wash` | 4,5 | 6,15 | 7,18 | passe |
| `info` / `info-wash` | 4,5 | 6,67 | 8,07 | passe |
| **`line-strong` / `surface`** | **3** | **1,56** | **1,59** | **échoue** |

### F.2 Paires utilisées mais absentes du registre

| Texte / fond | Où | Clair | Sombre |
|---|---|---|---|
| `on-accent` / `accent-strong` | bouton principal survolé (`Field.tsx:139`) | 8,51 | 10,68 |
| `accent-strong` / `accent-wash` | entrée active de la navigation (`regions.tsx:247`) | 7,39 | 9,40 |
| `subtle` / `well` | 219 `text-subtle`, 49 `bg-well` | **4,57** | 5,58 |
| `subtle` / `raised` | | 5,04 | 4,94 |
| `muted` / `line` | pastille neutre (`ui/tone.ts:42`) | 6,04 | 6,16 |
| `accent` / `canvas` | liens sur la page | 5,28 | 9,45 |

`subtle` / `well` passe aujourd'hui avec 0,07 de marge : c'est la paire qu'un réglage de `well` ou de
`ink-subtle` cassera en premier, et le registre ne la surveille pas.

### F.3 Effet du mapping de l'habillage (§2.3), une valeur appliquée aux deux thèmes

| Couleur posée en `accent` | Clair : `on-accent` / `accent` | Sombre : `on-accent` / `accent` | Sombre : `accent` / `surface` |
|---|---|---|---|
| `#1f4b99` (exemple du contrat) | 8,32 | **2,02** | **2,14** |
| `#0b6e99` (accent clair d'origine) | 5,67 | **2,97** | **3,14** |
| `#112233` (donnée de test) | 16,15 | **1,04** | **1,10** |
| `#e11d48` | 4,70 | **3,58** | **3,79** |
| `#facc15` | **1,53** | 10,99 | 11,63 |

| Couleur posée en `accent-strong` | Sombre : `on-accent` / `accent-strong` | Sombre : `accent-strong` / `accent-wash` |
|---|---|---|
| `#1f4b99` | **2,02** | **1,78** |
| `#112233` | **1,04** | **1,09** |
| `#0b3d2e` | **1,38** | **1,21** |

Aucune couleur unique ne passe dans les deux thèmes avec un `on-accent` fixe par thème : une couleur
sombre échoue en sombre, une couleur claire échoue en clair. C'est la démonstration chiffrée de C.4.

---

## Annexe G — Incohérences internes et cas limites

1. **Le §0 se contredit.** La palette de la plateforme s'appliquerait aux « tenants qui n'ont pas la
   leur » ; or la résolution est jeton par jeton (§0, formule) : elle s'applique à **tous** les
   tenants, pour chaque jeton qu'ils ne portent pas.
2. **Une écriture de la plateforme peut casser un tenant sans que personne le voie.** Le §6.4 juge
   chaque tenant résolu sur la plateforme, mais rien ne relit les tenants après une écriture de la
   plateforme. Le récapitulatif de la plateforme doit annoncer « n tenants passent sous un seuil ».
3. **Un jeton retiré du registre n'a pas de sort défini.** Le `GET` renvoie-t-il encore cet écart
   dans `overrides` ? Si oui, un `PUT` qui renvoie ce qu'il a lu reçoit 400 (écriture stricte). Et
   la restauration d'une révision qui le contient échoue-t-elle, ou le perd-elle en silence ?
   Écrire : la lecture filtre, la restauration ignore les jetons inconnus et le dit.
4. **Le `DELETE` d'une palette de tenant est ambigu.** S'il supprime la ligne, `revision` repart à
   0, et la révision 1 suivante entre en collision avec l'index unique de l'historique. Écrire : il
   enregistre une révision `RESET` vide et garde la ligne.
5. **L'import et l'élision des écarts égaux se combinent mal.** Un fichier exporté d'un tenant,
   réimporté après un changement de la plateforme, perd en silence les jetons devenus égaux à
   l'héritage (§4) ; le tenant suit alors la plateforme à l'écriture suivante. Réglé par D.15.
6. **« Chaque étape se livre seule » est faux à l'étape 4.** Ses dix opérations font échouer
   `gate:ui` tant que l'écran de l'étape 5 ne les revendique pas. Soit l'étape 4 les déclare dans
   `ui-api-coverage.json` avec une raison provisoire, soit les étapes 4 et 5 ne font qu'une.
7. **Deux tests ne prouvent rien.**
   - Le scan avec une palette extrême (§9.2) passe par construction (C.2).
   - Le test « Effacement » (§9.1) teste une suppression de tenant que le code ne fait jamais (E.e).
8. **§2.1 promet « effet au chargement suivant », §12 aussi**, mais le `max-age=60` et le
   `staleTime` font qu'un rechargement peut encore montrer l'ancienne palette (D.8).
9. **Le personnel sans appartenance** (C.1) et **la personne sans produit choisi** (C.3) n'ont pas
   de palette tenant : la spec ne dit pas ce qu'ils voient. Réponse : la plateforme.

---

## Annexe H — Portes de qualité et documents à mettre à jour

La spec ne nomme aucune porte. Les opérations nouvelles (environ 13 en v1) font échouer :

| Porte | Ce qu'elle exige |
|---|---|
| `gate:ui` | chaque opération dans `docs/ui-api-coverage.json`, avec son autorité (`platform`, `tenant`, `public`) |
| `gate:screens` | les deux lectures faites par la coque n'ont pas de route : elles relèvent de `bootstrap`, dont la définition actuelle ne les couvre pas |
| `gate:roles` | `docs/three-roles-end-to-end.md` et son arithmétique (`composer.json:91`) |
| `gate:permissions` | une `NavEntry` de portée `platform` sur `staff.palette.manage`, présente dans `platform_permissions` |
| `gate:i18n` | les libellés, dans les langues du catalogue (D.12) |
| OpenAPI, `gate:client` | chaque route, ses en-têtes (`X-Product`, motif, précondition), le 304 si ETag |
| `JobScheduleTest` | seulement si l'élagage reste un job (D.16) |

Documents à mettre à jour dans la même livraison :
- `docs/identities-and-permissions.md` : la matrice, avec `staff.palette.manage` ;
- `docs/three-roles-end-to-end.md` ;
- `docs/ui-api-coverage.json` ;
- `frontend/src/index.css:36-38` (le commentaire faux du §2.3) et `:41-42` (le scan « in both
  themes ») ;
- `docs/ui-roadmap.md:775-778`, tant que le scan n'émule pas le sombre ;
- `deploy/siteground/htaccess.template:91-95` ;
- `docs/home-showcase-spec.md:152-157`, si la décision 6 dit non ;
- un **ADR** pour ce qui est nouveau dans le dépôt : la précondition d'écriture (quelle qu'elle soit),
  le cache public, l'historique restaurable, et l'accessibilité qui avertit au lieu de refuser si
  ce choix est maintenu.

---

## Annexe I — Proposition de recadrage

Ceci est un avis de relecture, au-delà des objections.

### I.1 La palette d'un tenant étend l'habillage

Plutôt qu'une table à côté : une colonne `overrides JSONB` dans `tenant_skins`, par (tenant,
produit). Cela règle d'un coup la portée produit (C.3), le conflit habillage / palette sur `accent`
(qui l'emporte n'a plus de sens : c'est un seul enregistrement), l'ETag tenant, et la garde v2 :
`white_label` se vérifie sur le produit même que l'on colore (C.5).

### I.2 La palette de la plateforme vit dans `platform_settings`

Clé `palette`, absence = origines, sans ligne créée d'avance (ADR-063). Cela évite la table
singleton et la remise à zéro de la démo (D.13). L'historique, s'il est gardé, va dans une table
`palette_revisions` dont `tenant_id` nul désigne la plateforme, en `NULLS NOT DISTINCT`, ajoutée à
`BUSINESS_TABLES`.

### I.3 Le serveur résout, le frontend applique

Le serveur renvoie les écarts fusionnés et leurs `sources`, jamais les origines (D.7), et fournit
un `preview` à blanc (D.5). Le frontend ne calcule ni un mélange ni un ratio.

### I.4 Ordre de travail révisé

| # | Étape | Livre |
|---|---|---|
| 0 | Projet Playwright `colorScheme: 'dark'` ; corriger `line-strong` ; commentaires faux d'`index.css` | une mesure qui dit vrai |
| 1 | Correspondance habillage → jetons tranchée (C.4) ; `GET /tenant/palette` résolue par le serveur, **sans table**, `X-Product` ; feuille posée par `AppShell` selon la route ; cache local contre le flash | **le défaut du §2.3 corrigé** |
| 2 | Palette de la plateforme dans `platform_settings`, `GET /public/palette`, routes `/staff/palette`, écran aux origines, portes de qualité, ADR | la palette admin |
| 3 | Palette par (tenant, produit) dans l'habillage, garde `white_label` | le libre-service, famille Accent seulement |

**Aucun besoin écrit ne justifie aujourd'hui les étapes 2 et 3.** Aucun document, ADR ni message
de commit (136 commits) ne demande une palette de 23 jetons ; les seuls besoins écrits sont
l'habillage à deux couleurs et un logo (`architecture-v2.md` §23, `ui-roadmap.md:168-177`, U2). Ce
qui est prouvé, c'est l'étape 1. Les suivantes attendent qu'un besoin soit écrit.

---

## Annexe J — Décisions à trancher

Elles remplacent le tableau du §11 de la spec.

| # | Question | Proposition de la revue | Voir |
|---|---|---|---|
| 1 | La palette suit-elle l'écran affiché ou la personne ? | L'écran : `/console/*` → plateforme, ailleurs → tenant | C.1 |
| 2 | Par tenant seul, ou par (tenant, produit) ? | (tenant, produit), dans `tenant_skins` | C.3, I.1 |
| 3 | Que veut dire `primary_color`, et qui l'emporte sur `accent` ? | `primary` → `accent` ; un seul enregistrement, donc pas de conflit | C.4, I.1 |
| 4 | Le sombre de l'habillage : ignoré, dérivé ou saisi ? | Ignoré en v1 (clair seulement), dérivé par le serveur ensuite | C.4, F.3 |
| 5 | Un contraste insuffisant : refuser ou avertir, et à quel niveau ? | Refuser pour le tenant ; dérogation motivée et journalisée pour la plateforme | D.4 |
| 6 | La page publique d'une organisation prend-elle sa couleur ? | Oui, via `GET /public/tenant` | D.22 |
| 7 | Le motif s'exige-t-il à l'écriture ? | Non, comme toute écriture du personnel ; oui à la lecture, avec un `PURPOSE` adapté | D.20 |
| 8 | Une palette posée par le personnel s'applique-t-elle sans `white_label` ? | Non : un octroi, tracé, s'il faut l'offrir | C.5 |
| 9 | Va-t-on au-delà de la correction du §2.3 sans besoin écrit ? | Non | I.4 |
