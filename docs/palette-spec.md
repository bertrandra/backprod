# Spécification — La palette de backprod : une pour la plateforme, une par tenant

**Statut :** proposition, à relire avant toute ligne de code.
**Public :** l'équipe backprod (serveur et `frontend/`).
**Rédigée le :** 3 octobre 2026, contre `backprod@30c5c50`.
**Périmètre :** l'interface de backprod elle-même (l'application des tenants et la console). Aucun
produit hébergé n'est concerné : chacun garde ses propres couleurs.
**S'appuie sur :** `frontend/src/index.css` (les jetons `--ds-*`), `src/Skin/*` (l'habillage),
`src/Staff/*` et `features/console/*` (la console), `docs/identities-and-permissions.md`.
**Annexes :** `docs/palette-spec-annexes.md` — la revue de cette proposition (4 octobre 2026) :
objections, contrastes calculés, cas limites, portes de qualité et décisions à trancher. À lire
avant de coder.

---

## 0. En une page

L'interface de backprod lit déjà toutes ses couleurs dans **23 jetons sémantiques** `--ds-*`,
déclarés en clair et en sombre dans `frontend/src/index.css`. Aujourd'hui, changer une couleur, c'est
changer ce fichier et redéployer. Cette spécification rend ces jetons réglables à chaud, sur deux
niveaux :

| Niveau | Qui la règle (v1) | S'applique à |
|---|---|---|
| **Palette de la plateforme** (« palette admin ») | `PLATFORM_ADMIN` | la console, les pages publiques, la connexion, et tous les tenants qui n'ont pas la leur |
| **Palette d'un tenant** | `PLATFORM_ADMIN` (v1) ; `TENANT_ADMIN` en v2, avec `skin.manage` + `white_label` | les membres de ce tenant, dans l'application |

Sous les deux, les **couleurs d'origine** restent celles d'`index.css`. Chaque jeton se résout
séparément, du plus particulier au plus général :

```text
couleur(jeton, thème) = palette du tenant      [si elle porte ce jeton]
                      ? habillage du tenant    [pour les deux jetons d'accent, §2.3]
                      ? palette de la plateforme
                      ? origine (index.css)
```

Une palette n'enregistre **que ses écarts**. Un jeton ajouté demain à `index.css` hérite tout seul
de son origine, partout.

**La console ne prend jamais la palette d'un tenant.** Le personnel y travaille sur tous les
tenants, et ses repères (l'ambre de l'administration, le rouge du refus) ne doivent pas changer
selon le client qu'il regarde. La console suit la palette de la plateforme, et elle seule.

---

## 1. Le principe

### 1.1 Les jetons

Ce sont ceux qu'`index.css` déclare et que `@theme` rattache aux utilitaires Tailwind
(`bg-surface`, `text-ink`, `border-line`…). Les composants ne nomment jamais une couleur : ils
nomment un rôle.

| Famille | Jetons (`--ds-…`) | Rôle |
|---|---|---|
| Fonds | `canvas`, `surface`, `well`, `raised` | page, carte, creux, élévation |
| Encres | `ink`, `ink-muted`, `ink-subtle` | texte, texte secondaire, texte discret |
| Traits | `line`, `line-strong` | séparateurs, contours de commandes |
| Accent | `accent`, `accent-strong`, `accent-wash`, `on-accent` | la couleur de la marque, sa variante, son lavis, le texte posé dessus |
| Inverse | `inverse`, `on-inverse` | bandeaux et infobulles en négatif |
| États | `success`, `success-wash`, `warning`, `warning-wash`, `danger`, `danger-wash`, `info`, `info-wash` | réussi, attention, refus, information, chacun avec son fond |

**Hors palette en v1 :** les ombres (`--shadow-*`, qui ne sont pas des couleurs `#rrggbb`), les
polices, l'échelle typographique et les rayons. Leur format ne se valide pas aussi simplement
qu'une couleur, et c'est la simplicité de cette validation qui rend la fonctionnalité sûre (§1.3).

### 1.2 Le registre des jetons

Un seul fichier décrit les jetons, et le serveur comme le frontend le lisent :
`config/palette-tokens.json`.

```json
{
  "version": 1,
  "themes": ["light", "dark"],
  "tokens": [
    { "name": "canvas",  "group": "grounds", "label": "Page background",
      "defaults": { "light": "#f5f7f9", "dark": "#0a0e14" } },
    { "name": "ink",     "group": "inks",    "label": "Body text",
      "defaults": { "light": "#0e1520", "dark": "#e9eef6" } },
    "… 21 autres …"
  ],
  "contrast_pairs": [
    { "text": "ink",        "ground": "canvas",       "min": 4.5 },
    { "text": "ink",        "ground": "surface",      "min": 4.5 },
    { "text": "ink-muted",  "ground": "surface",      "min": 4.5 },
    { "text": "ink-subtle", "ground": "canvas",       "min": 4.5 },
    { "text": "on-accent",  "ground": "accent",       "min": 4.5 },
    { "text": "accent",     "ground": "surface",      "min": 4.5 },
    { "text": "on-inverse", "ground": "inverse",      "min": 4.5 },
    { "text": "success",    "ground": "success-wash", "min": 4.5 },
    { "text": "warning",    "ground": "warning-wash", "min": 4.5 },
    { "text": "danger",     "ground": "danger-wash",  "min": 4.5 },
    { "text": "info",       "ground": "info-wash",    "min": 4.5 },
    { "text": "line-strong","ground": "surface",      "min": 3 }
  ]
}
```

- **Un test du frontend confronte le registre à `index.css`.** Chaque jeton y est déclaré, avec
  exactement cette valeur, dans les deux thèmes, et `index.css` n'en déclare aucun autre. Changer
  une couleur d'origine, c'est changer les deux, et le test dit si l'un a été oublié.
- Les paires de contraste sont **une proposition à aligner** sur ce que mesure aujourd'hui
  `accessibility.spec.ts` (axe sur chaque route, dans les deux thèmes).
- Les noms de jetons respectent `^[a-z][a-z0-9-]{0,47}$`.

### 1.3 Les quatre règles

1. **Une couleur est `#rrggbb` et rien d'autre.** Six chiffres hexadécimaux ne peuvent pas exprimer
   `red;} body{display:none}` : la validation est aussi la protection contre l'injection CSS.
   C'est la règle que `SkinRoute::colour` applique déjà, en minuscules.
2. **L'écriture est stricte.** Le serveur refuse :
   - un thème inconnu ;
   - un jeton absent du registre ;
   - une valeur hors `#rrggbb` ;
   - un corps de plus de 64 Ko.
3. **La lecture est tolérante.** Le frontend part des origines et ne remplace que ce qui est
   valide. Un jeton inconnu (registre plus récent côté serveur, ou plus ancien côté client) est
   ignoré, une valeur mal formée aussi. **Une palette abîmée ne casse pas l'interface** : au pire,
   elle n'a pas d'effet.
4. **On n'applique que les écarts.** Le frontend pose une feuille `<style id="ds-palette">`
   **après** `index.css`, qui ne contient que les jetons différents de l'origine.
   - Une palette égale à l'origine donne une feuille vide.
   - Le bloc clair est enfermé dans `@media not all and (prefers-color-scheme: dark)`. Sinon un
     `:root` nu, posé après, l'emporterait aussi sur le bloc sombre d'`index.css`, et un réglage
     clair déteindrait sur le thème sombre.
   - Comme `@theme` rattache les utilitaires aux variables `--ds-*`, redéfinir une variable suffit :
     aucun composant ne change.

### 1.4 Le document d'échange

C'est le format de l'export et de l'import, et le corps des écritures :

```json
{
  "format": "backprod-palette",
  "version": 1,
  "overrides": {
    "light": { "accent": "#1f7a4d", "accent-strong": "#155c39" },
    "dark":  { "accent": "#5fd39a" }
  }
}
```

Un fichier exporté ne contient que les écarts **du niveau exporté**. Importer remplace l'édition en
cours, sans enregistrer.

---

## 2. Ce qui existe, et ce qui change

### 2.1 Aujourd'hui → demain

| | Aujourd'hui | Avec la palette |
|---|---|---|
| Changer une couleur | modifier `index.css`, redéployer | régler dans la console, effet au chargement suivant |
| Portée | une seule apparence pour tous | plateforme, puis tenant, résolues jeton par jeton |
| Ce qu'un tenant peut changer | deux couleurs (habillage), **non appliquées** (§2.3) | les 23 jetons, par thème |
| Stockage | le code | PostgreSQL : écarts seulement, avec historique restaurable |
| Trace | git | audit à chaque écriture ; motif `X-Access-*` quand le personnel entre chez un tenant |
| Contrôle des contrastes | axe, en CI, sur les origines | le même jugement à l'enregistrement, en avertissement (§6.4) |

### 2.2 Palette et habillage

L'habillage (`tenant_skins`) existe : `primary_color`, `accent_color`, `logo_asset_id`, par
(tenant, produit). Il est lu par tout membre et écrit avec `skin.manage` **et** `white_label`.

| | Habillage | Palette |
|---|---|---|
| Granularité | 2 couleurs génériques et un logo | 23 jetons × 2 thèmes |
| Clé | (tenant, produit) | tenant seul : c'est l'interface de la plateforme |
| Niveau plateforme | non | oui |
| Logo | oui | non : il reste à l'habillage |

**La palette ne remplace pas l'habillage. Elle s'appuie dessus.** Le logo reste où il est, et les
deux couleurs servent de raccourci (§2.3).

### 2.3 Un défaut trouvé en préparant cette spec

Le commentaire d'`index.css` affirme : *« `primary_color` and `accent_color` on `tenant.branding`
(U2) set `--ds-accent` and `--ds-accent-strong` at runtime »*. **C'est faux aujourd'hui.** Aucun code
du frontend ne pose ces variables. `useSkin()` n'est appelé que par `BrandingScreen`, qui affiche
et enregistre les couleurs sans jamais les appliquer. Un tenant qui règle sa marque ne voit donc
rien changer.

La palette corrige cela sans route nouvelle pour l'habillage :
- `accent_color` alimente `accent` ;
- `primary_color` alimente `accent-strong` ;

dans les deux thèmes, et seulement quand la palette du tenant ne porte pas déjà ces jetons
(résolution du §0). Le commentaire d'`index.css` est corrigé dans la même livraison.

---

## 3. Qui peut quoi

### 3.1 Permissions

| Permission | Nouvelle ? | Rôles | Ce qu'elle ouvre |
|---|---|---|---|
| `staff.palette.manage` | **oui** | `PLATFORM_ADMIN` seulement | lire et écrire la palette de la plateforme et celle de n'importe quel tenant, consulter et restaurer l'historique |
| `staff.tenants.read` | non | les 4 rôles plateforme | lister les tenants pour en choisir un |
| `skin.manage` + capacité `white_label` | non | `TENANT_ADMIN` | **v2** : régler la palette de son tenant |
| *(appartenance)* | — | tout membre | lire la palette résolue de son tenant |
| *(aucune)* | — | public | lire la palette de la plateforme |

- **Pourquoi une permission neuve.** `staff.products.manage` ouvre la facturation, les clés et les
  webhooks ; confier les couleurs à quelqu'un ne doit pas lui confier les clés. La permission est
  créée par migration et rattachée au seul `PLATFORM_ADMIN`, comme `skin.manage` l'a été
  (`Version20260909120000.php`).
- **Le frontend lit la permission, jamais le rôle.** L'entrée « Palette » de la console apparaît si
  `staff.palette.manage` figure dans `GET /staff/me`.

### 3.2 Les arêtes vives

- **Le personnel n'a pas de `RequestContext`.** Il passe par `/staff/*`, qui **nomme** le tenant ;
  la permission autorise, l'accès est journalisé (`StaffContext`).
- **Entrer chez un tenant laisse une trace motivée.** Lire **ou écrire** la palette d'un tenant
  précis exige `X-Access-Purpose` et `X-Access-Reason`, comme toute lecture chez un client. La
  console a déjà le composant pour le demander (`AccessMotiveGate`). La palette de la plateforme
  n'en demande pas.
- **`white_label` ne bride pas le personnel.** La capacité borne le libre-service du tenant (v2).
  Une palette posée par le `PLATFORM_ADMIN` s'applique même sans elle, puisque la lecture ne demande
  que l'appartenance, comme pour l'habillage. *Décision ouverte n° 1.*

---

## 4. Modèle de données (PostgreSQL)

```sql
-- La palette de la plateforme : une seule ligne.
CREATE TABLE platform_palette (
    id          BOOLEAN PRIMARY KEY DEFAULT TRUE CHECK (id),
    overrides   JSONB   NOT NULL DEFAULT '{}'::jsonb,   -- {"light": {"ink": "#…"}, "dark": {…}}
    revision    INTEGER NOT NULL DEFAULT 0,
    updated_by  UUID REFERENCES users (id) ON DELETE SET NULL,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT platform_palette_size CHECK (pg_column_size(overrides) <= 65536)
);
INSERT INTO platform_palette DEFAULT VALUES;

-- La palette d'un tenant : une ligne au plus, absente tant qu'il n'en a pas.
CREATE TABLE tenant_palettes (
    tenant_id   UUID PRIMARY KEY REFERENCES tenants (id) ON DELETE CASCADE,
    overrides   JSONB   NOT NULL DEFAULT '{}'::jsonb,
    revision    INTEGER NOT NULL DEFAULT 0,
    updated_by  UUID REFERENCES users (id) ON DELETE SET NULL,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT tenant_palettes_size CHECK (pg_column_size(overrides) <= 65536)
);

-- L'historique : chaque ecriture, restaurable.
CREATE TABLE palette_revisions (
    id          BIGSERIAL PRIMARY KEY,
    tenant_id   UUID REFERENCES tenants (id) ON DELETE CASCADE,   -- NULL = plateforme
    revision    INTEGER NOT NULL,
    overrides   JSONB   NOT NULL,
    origin      TEXT    NOT NULL CHECK (origin IN ('STAFF', 'TENANT', 'RESTORE', 'RESET')),
    actor_id    UUID REFERENCES users (id) ON DELETE SET NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX palette_revisions_scope ON palette_revisions (COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'::uuid), revision);
```

**Invariants :**
- `overrides` ne contient que les thèmes et les jetons du registre, et des valeurs
  `^#[0-9a-f]{6}$` en minuscules. Le service valide, et un `CHECK` n'est pas possible sur les clés
  d'un JSONB : un test le prouve en écrivant sans passer par le service.
- Un écart égal à la valeur qu'il recouvre n'est pas stocké : « revenir » sur un jeton, c'est
  l'ôter.
- `revision` augmente de 1 à chaque écriture effective. Une écriture qui ne change rien ne crée ni
  révision ni historique, et répond `200` avec la révision inchangée.
- **Rétention :** les 50 dernières révisions par portée, élaguées par la file de jobs. À 64 Ko au
  plus chacune, c'est au pire 3,2 Mo par portée ; en pratique quelques Ko, puisque seuls les écarts
  sont stockés.
- `ON DELETE CASCADE` sur le tenant : sa palette et son historique disparaissent avec lui, sans
  rien qui le nomme ailleurs (effacement RGPD).

---

## 5. Résolution et lecture

### 5.1 Ce que le serveur renvoie

```json
{
  "palette": {
    "scope": "tenant",
    "tenant_id": "…",
    "registry_version": 1,
    "revision": 4,
    "updated_at": "2026-10-03T12:00:00Z",
    "overrides": { "light": { "accent": "#1f7a4d" }, "dark": {} },
    "resolved":  { "light": { "canvas": "#f5f7f9", "…": "…" }, "dark": { "…": "…" } },
    "sources":   { "light": { "accent": "tenant", "accent-strong": "skin", "ink": "platform", "canvas": "origin" }, "dark": { "…": "…" } },
    "contrast_warnings": [
      { "theme": "light", "text": "ink-subtle", "ground": "canvas", "ratio": 3.8, "min": 4.5 }
    ]
  }
}
```

`sources` permet à l'écran d'écrire « hérité de la plateforme » ou « vient de l'habillage » sous un
jeton : c'est ce qui rend les niveaux lisibles. Le serveur résout lui-même, à partir du registre :
le frontend applique `resolved` et n'a rien à recalculer.

### 5.2 Routes de lecture

| Méthode | Chemin | Politique | Pour |
|---|---|---|---|
| `GET` | `/api/v1/public/palette` | PUBLIC | la palette de la plateforme, résolue. Connexion, pages publiques, console |
| `GET` | `/api/v1/tenant/palette` | membre (`RequestContext`) | la palette du tenant courant, résolue sur l'habillage, la plateforme et l'origine |

- **Cache.** Le `GET` public : `ETag: "<révision plateforme>"`, `Cache-Control: public, max-age=60,
  must-revalidate`. Le `GET` tenant : `ETag: "<plateforme>.<tenant>.<habillage>"`,
  `Cache-Control: private, no-cache`. Un `304` ne coûte presque rien.
- **Le tenant vient de la session, jamais de la requête.** Un tenant étranger n'a pas de route pour
  être nommé.
- **Pas dans `/me/context`** : c'est la réponse d'autorisation, et une palette n'y a pas sa place.

### 5.3 Côté frontend

```text
démarrage
  → GET /public/palette            → feuille ds-palette (plateforme)   ; la connexion est aux couleurs
  → session, contexte
      application (tenant)         → GET /tenant/palette → la feuille est remplacée (une seule)
      console (personnel)          → on garde la palette de la plateforme, jamais celle d'un tenant
  changement de tenant (sélecteur) → GET /tenant/palette du nouveau tenant
```

- Un module unique, `frontend/src/ui/palette.ts`, sans dépendance à React :
  `appliquerPalette(resolved)`, `cssPalette(resolved, origines)`, `lirePalette(json)`
  (la lecture tolérante du §1.3). Une requête TanStack Query (`queries/palette.ts`) l'appelle.
- **Le flash.** La première peinture se fait aux couleurs d'origine ; la palette se pose dès
  qu'elle arrive. C'est accepté en v1. L'éviter demanderait de bloquer le rendu sur un appel
  réseau, ou d'injecter la palette dans le HTML servi (décision ouverte n° 4).
- **Sans réponse, rien ne casse** : ce sont les couleurs d'origine. On ne bloque jamais le
  démarrage sur la palette.
- **Le CSP ne bouge pas** : la feuille est posée par un script de la même origine, et elle ne
  contient que des variables et des `#rrggbb`. Si la politique interdit les styles en ligne, la
  feuille passe par `adoptedStyleSheets` (constructible stylesheets), autorisés sans
  `'unsafe-inline'`. *À vérifier contre le CSP du déploiement.*

---

## 6. Écriture

### 6.1 Routes du personnel (`staff.palette.manage`)

| Méthode | Chemin | Motif `X-Access-*` | Effet |
|---|---|---|---|
| `GET` | `/api/v1/staff/palette` | non | palette de la plateforme, résolue, avec avertissements |
| `PUT` | `/api/v1/staff/palette` | non | remplace les écarts de la plateforme |
| `DELETE` | `/api/v1/staff/palette` | non | revient aux origines (révision `RESET`) |
| `GET` | `/api/v1/staff/palettes` | non | les tenants qui ont une palette : `tenant_id`, `slug`, `name`, `revision`, `updated_at`, nombre d'écarts — **sans les couleurs** |
| `GET` | `/api/v1/staff/tenants/{tenantId}/palette` | **oui** | la palette du tenant : ses écarts, son habillage, la résolue |
| `PUT` | `/api/v1/staff/tenants/{tenantId}/palette` | **oui** | remplace les écarts du tenant |
| `DELETE` | `/api/v1/staff/tenants/{tenantId}/palette` | **oui** | le tenant revient à la plateforme |
| `GET` | `…/palette/revisions` (sur les deux portées) | comme la portée | l'historique, du plus récent au plus ancien, paginé |
| `POST` | `…/palette/revisions/{revision}/restore` | comme la portée | recopie une révision en une **nouvelle** (`RESTORE`) ; l'historique ne se réécrit jamais |
| `GET` | `/api/v1/staff/palette/registry` | non | le registre (§1.2), pour que la console construise son écran |

Corps d'un `PUT` : le document du §1.4. C'est l'**ensemble** des écarts du niveau, pas un `PATCH`
jeton à jeton : on envoie ce que le récapitulatif a montré, ni plus ni moins.

### 6.2 Audit

Chaque écriture enregistre un `StaffAccess` avec l'action `CONFIGURE_PALETTE`, `RESET_PALETTE` ou
`RESTORE_PALETTE`. Les métadonnées portent :
- la portée (plateforme ou `tenant_id`) ;
- la révision obtenue ;
- la liste `{theme, token, before, after}`.

Sur la portée tenant, l'accès porte aussi le motif. La liste est exactement le récapitulatif que
l'écran a montré avant l'envoi.

### 6.3 Concurrence

Les `PUT`, `DELETE` et `restore` exigent `If-Match: "<révision>"`.
- Sans lui : `428 PRECONDITION_REQUIRED`.
- Périmé : `409 PALETTE_STALE`, avec la révision tenue dans `details`. L'écran recharge alors,
  montre ce qui a changé entre-temps, puis repropose d'enregistrer.

### 6.4 Contrastes : avertir, ne pas refuser

Le serveur calcule les paires du registre sur la palette **résolue** (WCAG 2.x, luminance
relative), et les renvoie dans `contrast_warnings` à chaque lecture et chaque écriture.
- **Il n'en refuse aucune.** L'opérateur peut avoir une raison (charte imposée), et l'écran prévient
  avant d'envoyer.
- Une palette de tenant se juge **résolue sur la plateforme** : un tenant qui ne change que
  `canvas` peut casser une paire dont l'autre moitié vient d'au-dessus.
- La CI garde son scan axe sur les origines : il juge le code. Le serveur, lui, juge les réglages.

### 6.5 Erreurs

Toutes les réponses gardent l'enveloppe `{ error: { code, message, details, request_id } }`.

| Code | HTTP | Quand | `details` |
|---|---|---|---|
| `VALIDATION_FAILED` | 400 | thème inconnu, jeton absent du registre, valeur hors `#rrggbb`, format autre que `backprod-palette` | `field` (`overrides.light.ink`), `requirement` |
| `ACCESS_MOTIVE_REQUIRED` | 400 | route tenant sans motif | ce que `AccessMotive` dit déjà |
| `PERMISSION_DENIED` | 403 | sans `staff.palette.manage` (v2 : sans `skin.manage`) | `permission` |
| `ENTITLEMENT_REQUIRED` | 403 | v2 : tenant sans `white_label` | `capability` |
| `NOT_FOUND` | 404 | tenant inconnu, révision inconnue | — |
| `PALETTE_STALE` | 409 | `If-Match` périmé | `revision` |
| `PAYLOAD_TOO_LARGE` | 413 | corps > 64 Ko, refusé avant lecture complète | `limit` |
| `PRECONDITION_REQUIRED` | 428 | écriture sans `If-Match` | — |

### 6.6 v2 — le `TENANT_ADMIN` règle sa palette

| Méthode | Chemin | Garde |
|---|---|---|
| `PUT` | `/api/v1/tenant/palette` | `skin.manage` **et** `white_label` |
| `DELETE` | `/api/v1/tenant/palette` | idem |
| `GET` | `/api/v1/tenant/palette/revisions` | `skin.manage` |

Mêmes corps, mêmes erreurs, même audit (`origin = 'TENANT'`). L'écran est celui du §7, ouvert
depuis « Organisation → Habillage », en portée tenant seulement. Les deux refus se montrent
différemment, comme `ui-roadmap.md` l'exige déjà pour l'habillage :
- sans la permission : « un administrateur de l'organisation peut vous donner ce droit » ;
- sans la capacité : l'offre à prendre.

---

## 7. L'écran « Palette » de la console

Il vit dans `features/console/PaletteScreen.tsx`, dans le menu de la console, visible avec
`staff.palette.manage`. Il est construit **depuis le registre** (`GET /staff/palette/registry`) : un
jeton ajouté à `index.css` et au registre y apparaît sans toucher à l'écran.

### 7.1 Disposition

```text
┌ Palette ──────────────────────────────── [ Plateforme ▾ | Tenant : Acme ▾ ]  [Clair|Sombre|Les deux] ┐
│ Barre : Modèles ▾  Fichier ▾  Revenir ▾  Historique ▾   « 2 contrastes insuffisants »  [Enregistrer…] │
├──────────────────────────────────────────────────────────────┬──────────────────────────────┤
│ Onglets : Couleurs · Contrastes · Variables                  │ Aperçu (collant)             │
│ Filtres : Toutes · Propres à ce niveau · Contraste insuffisant │  – vraies commandes de       │
│ Recherche : [ jeton ou rôle ]                                │    l'application, par thème  │
│                                                              │  – carte, tableau, bandeau,  │
│ FONDS          CLAIR                 SOMBRE           ÉTAT   │    badge d'état, champ,      │
│ --ds-canvas    [■] [⚙] #f5f7f9 [↺]  [■] [⚙] #0a0e14   hérité │    boutons, toast            │
│ --ds-surface   …                                             │                              │
└──────────────────────────────────────────────────────────────┴──────────────────────────────┘
```

### 7.2 Fonctions

1. **Portée.**
   - « Plateforme », par défaut.
   - « Tenant » : un sélecteur avec recherche par nom ou slug ; ceux qui ont déjà une palette
     viennent en tête (`GET /staff/palettes`).
   - Choisir un tenant passe par `AccessMotiveGate` : le motif est demandé une fois par séance
     d'écran, puis voyage sur chaque appel. Il n'est gardé qu'en mémoire.
2. **Une ligne par jeton**, groupée par famille, avec pour chaque thème montré :
   - le sélecteur du système ;
   - un sélecteur avancé (teinte, saturation, TSL, RVB, contrastes où la couleur intervient) ;
   - le code `#rrggbb`, saisi en brouillon et appliqué seulement s'il est valide ;
   - un bouton « revenir », qui retire l'écart de ce niveau.
3. **État écrit en toutes lettres**, jamais par la seule couleur : « contraste 3,80 »,
   « non enregistrée », « propre à ce niveau », « hérité de la plateforme », « vient de
   l'habillage ».
4. **Filtres** : Toutes / Propres à ce niveau / Contraste insuffisant, avec leurs nombres, et une
   recherche par nom ou rôle.
5. **Aperçu collé à droite**, par thème : de vrais composants de `frontend/src/ui` peints par la
   palette en cours. On règle et on voit sans défiler. Sous 1 024 px, l'aperçu devient une feuille.
6. **Onglet Contrastes** : chaque paire du registre, son rapport par thème, le minimum, et le
   verdict en mots. La pastille « n contrastes insuffisants » de la barre y mène, filtrée.
7. **Onglet Variables** : la feuille CSS qui sera posée, à copier.
8. **Modèles** : quelques palettes toutes faites, fichiers JSON dans `frontend/src/ui/palettes/`,
   chacune vérifiée complète et lisible par un test. Charger un modèle remplace l'édition en cours,
   après confirmation si elle contient des changements.
9. **Fichier** : exporter le JSON des écarts de ce niveau (`palette-plateforme.json`,
   `palette-<slug>.json`) ; importer un JSON, sans enregistrer.
10. **Revenir** :
    - annuler les modifications non enregistrées ;
    - effacer tous les écarts de ce niveau, ce qui envoie un `DELETE` après confirmation.
11. **Enregistrer…** ouvre un **récapitulatif** avant tout envoi :
    - chaque jeton qui change, avant et après, par thème ;
    - les avertissements de contraste ;
    - en portée tenant, le nom du tenant et le motif.

    Rien ne part avant « Enregistrer ». Un `409` recharge et montre la différence.
12. **Historique** : les révisions (date, auteur, nombre d'écarts), un aperçu avant/après, et
    « Restaurer cette version ».

### 7.3 Règles d'interface

- Couleurs de l'écran : les jetons, comme partout ; la palette éditée n'est posée que sur
  l'aperçu, **jamais sur la console elle-même**. Une palette ratée ne doit pas rendre illisible
  l'outil qui sert à la réparer.
- Cibles de 44 px au doigt ; la barre passe en bas de l'écran sur téléphone ; Échap ferme menus et
  sélecteurs.
- Textes en anglais, langue clé du catalogue, et traduits par le mécanisme `i18n` existant.

---

## 8. Sécurité

| Risque | Parade |
|---|---|
| Injection CSS par une valeur | `#rrggbb` validé à l'écriture, et revalidé par le frontend à la lecture |
| Injection par un nom de jeton | noms pris dans le registre, validés par `^[a-z][a-z0-9-]{0,47}$` ; le frontend ne pose que les noms qu'il connaît |
| Un tenant voit la palette d'un autre | le tenant vient de la session ; aucune route tenant ne prend d'identifiant |
| Le personnel entre chez un tenant sans trace | motif obligatoire, en lecture comme en écriture, écrit avec l'accès |
| Une palette illisible bloque l'outil | la console ne prend jamais une palette éditée ni une palette de tenant ; restaurer se fait en un geste |
| Requête énorme | 64 Ko, refusés `413` avant lecture complète |
| La palette en ligne heurte le CSP | `adoptedStyleSheets` si `style-src` refuse l'en ligne (§5.3) |

---

## 9. Tests

### 9.1 Serveur

- **Résolution** : tenant, puis habillage, puis plateforme, puis origine, jeton par jeton et thème
  par thème. Un jeton retiré du registre n'est plus servi ; un jeton ajouté hérite de son origine.
- **Validation** : chaque ligne du tableau §6.5 a son test.
- **Gardes** :
  - sans `staff.palette.manage` → `403` ;
  - `SUPPORT_ADMIN`, `FINANCE_ADMIN` et `SALES_ADMIN` refusés ;
  - `TENANT_ADMIN` refusé sur `/staff/*` ;
  - en v2, refusé sans `white_label`, avec un message **différent** du refus de permission.
- **Motif** : route tenant sans motif → `ACCESS_MOTIVE_REQUIRED`, et rien n'est journalisé comme lu.
- **Concurrence** : deux `PUT` sur la même révision → `409` pour le second.
- **Historique** : une restauration crée une révision ; la 51e et les suivantes sont élaguées.
- **Isolation** : la suite d'isolation gagne `GET /tenant/palette` (A ne voit jamais B).
- **Effacement** : supprimer un tenant supprime sa palette et son historique.
- **Contrat** : `openapi.json` décrit chaque route ; le client généré du frontend suit.

### 9.2 Frontend

- Le registre concorde avec `index.css` (valeurs, deux thèmes, aucun jeton en plus ou en moins).
- `lirePalette` : réponse partielle, jeton inconnu, valeur mal formée → origines conservées.
- `cssPalette` : palette égale à l'origine → feuille vide ; le bloc clair ne déteint pas en sombre.
- La console ignore la palette d'un tenant ; l'application la prend ; changer de tenant la change.
- `PaletteScreen` : portée, motif demandé une fois, héritage affiché, retrait d'un écart,
  récapitulatif, `409`, restauration.
- `accessibility.spec.ts` rejoue son scan avec un modèle de palette extrême : l'écran de réglage
  reste lisible, puisqu'il ne prend pas la palette éditée.
- L'habillage : `accent_color` réglé dans « Habillage » colore bien l'application (le défaut du
  §2.3, couvert par un test qui échoue aujourd'hui).

---

## 10. Ordre de travail

Chaque étape se livre seule et laisse l'interface utilisable.

| # | Étape | Livre |
|---|---|---|
| 1 | `config/palette-tokens.json` et le test qui le confronte à `index.css` | le registre |
| 2 | `ui/palette.ts` et `queries/palette.ts`, branchés sur l'habillage seul ; correction du commentaire d'`index.css` | **le défaut du §2.3 corrigé**, sans base de données |
| 3 | Migration : 3 tables, permission `staff.palette.manage` | rien de visible |
| 4 | Palette de la plateforme : `GET /public/palette`, routes `/staff/palette`, audit, historique | la palette admin, par API |
| 5 | `PaletteScreen`, portée plateforme | la palette admin, à l'écran |
| 6 | Palette du tenant : routes `/staff/tenants/{id}/palette` avec motif, `GET /tenant/palette` ; portée tenant dans l'écran | **la v1 demandée** |
| 7 | v2 : `PUT /tenant/palette` pour le `TENANT_ADMIN` | le libre-service |

---

## 11. Décisions ouvertes

| # | Question | Proposition |
|---|---|---|
| 1 | Une palette posée par le personnel s'applique-t-elle à un tenant sans `white_label` ? | Oui : la capacité borne le libre-service, pas la décision de l'opérateur ; c'est cohérent avec l'habillage, lu sans capacité |
| 2 | La palette du tenant est-elle par tenant seul, ou par (tenant, produit) comme l'habillage ? | Par tenant seul : elle colore l'interface de la plateforme, pas un produit |
| 3 | La palette de la plateforme s'applique-t-elle aussi à la console, ou la console garde-t-elle ses origines ? | Elle s'applique ; seule une palette de **tenant** est exclue de la console |
| 4 | Faut-il supprimer le flash au chargement en injectant la palette dans le HTML servi ? | Pas en v1 : le HTML est statique, et l'injecter demande un rendu serveur de la page |
| 5 | Les ombres, polices et rayons entrent-ils un jour dans la palette ? | Pas en v1 : il faudra une liste fermée de valeurs par jeton, faute d'un format aussi sûr que `#rrggbb` |

---

## 12. Définition de « fini » (v1)

- Un `PLATFORM_ADMIN` ouvre « Palette » dans la console, règle des jetons avec l'aperçu, voit le
  récapitulatif, enregistre. La connexion, les pages publiques, la console et tous les tenants sans
  palette propre prennent ces couleurs au chargement suivant.
- Il choisit un tenant, donne un motif, règle quelques jetons. Les membres de ce tenant les voient
  dans l'application, par-dessus la palette de la plateforme ; les autres tenants et la console ne
  changent pas.
- Chaque écriture est dans l'audit avec ses différences, et toute révision se restaure en un geste.
- Un `SUPPORT_ADMIN`, un `TENANT_ADMIN` ou un membre ne voit pas l'entrée « Palette », et la route
  le refuse.
- L'habillage d'un tenant colore enfin son application.
