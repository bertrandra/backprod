
# 1. Nature et statut du document

## 1.1 Nature

Ce document est avant tout une **Spécification Non Fonctionnelle (NFR)** et un **document de Décisions d'Architecture**.

Il définit :

- les contraintes techniques ;
- les exigences de qualité ;
- les exigences de sécurité ;
- les exigences de performance ;
- les exigences de scalabilité ;
- les exigences de disponibilité ;
- les exigences de maintenabilité ;
- les règles d'architecture ;
- les choix technologiques ;
- les principes de séparation des responsabilités ;
- les quality gates ;
- les règles d'observabilité ;
- les règles de gouvernance technique.

## 1.2 Ce que ce document n'est pas

Ce document **n'est pas la spécification fonctionnelle détaillée du produit**.

Il ne définit pas exhaustivement :

- les écrans ;
- les parcours UX détaillés ;
- les règles métier fonctionnelles complètes ;
- les maquettes ;
- les textes UI ;
- les user stories ;
- les critères d'acceptation fonctionnels détaillés.

Ces éléments feront l'objet d'une **Functional Specification / UX Specification** séparée.

## 1.3 Décisions d'architecte

Les choix explicitement identifiés comme décisions d'architecture constituent les références techniques de la V2.

Exemples :

```text
React + TypeScript + Vite
Core métier TypeScript indépendant de React
Zustand pour le client state
TanStack Query pour le server state
PHP 8.3+ + Composer sans framework applicatif
PostgreSQL + JSONB
REST + OpenAPI
Vitest / PHPUnit / Playwright
ESLint / PHPStan
dependency-cruiser / Deptrac
PostHog / Sentry / OpenTelemetry
```

Une modification de ces choix doit faire l'objet d'une nouvelle décision d'architecture documentée.

## 1.4 Exigences vs décisions

Le document distingue :

```text
NFR
 │
 ├── Requirement
 │      └── Ce que le système doit garantir
 │
 └── Architecture Decision
        └── Comment l'architecture choisie permet de le garantir
```

Une technologie n'est donc pas une exigence fonctionnelle.

Exemple :

```text
NFR:
Le système doit isoler les données de chaque tenant.

Decision:
Utiliser tenant_id + contrôles d'autorisation centralisés.
```


# Architecture V2 — React / TypeScript / PHP SaaS

**Version:** 1.1  
**Statut:** Architecture cible  
**Date:** 2026-09-02

---

# 1. Vision

La V2 doit être une refonte architecturale, pas simplement une nouvelle interface.

Objectifs :

- remplacer progressivement l'IHM historique par une UX React moderne ;
- conserver et consolider le moteur métier en TypeScript pur ;
- séparer strictement UI, état client, état serveur et métier ;
- fournir une API PHP robuste pour le SaaS ;
- supporter B2C, B2B et multi-tenant ;
- permettre l'évolution vers des traitements 3D, géométriques et data plus importants ;
- déployer initialement sur SiteGround lorsque ses capacités couvrent le besoin ;
- éviter de recréer un nouveau `legacy.ts`.

Principe fondamental :

> **React est la couche d'expérience utilisateur. Le Core TypeScript est le moteur métier. PHP est la couche backend/SaaS. PostgreSQL est la source de vérité des données persistantes.**

---

# 2. Architecture globale

```text
                         USER
                          │
                          ▼
                 ┌─────────────────┐
                 │ React + Vite    │
                 │ TypeScript      │
                 └────────┬────────┘
                          │
             ┌────────────┼────────────┐
             │            │            │
          Zustand    TanStack Query   Forms
             │            │         RHF + Zod
             │            │            │
             └────────────┼────────────┘
                          │
                    Core TypeScript
                ┌─────────┼─────────┐
                │         │         │
              Model    Geometry    Rules
                │         │         │
                └─────────┼─────────┘
                          │
                     REST / JSON
                          │
                          ▼
                 ┌─────────────────┐
                 │ PHP Backend     │
                 │ PHP natif modulaire         │
                 └────────┬────────┘
                          │
          ┌───────────────┼────────────────┐
          │               │                │
       PostgreSQL     Object Storage   External APIs
        + JSONB       media / GLB       IGN / PSP
          │
       PostgreSQL
       optionnel
```

---

# 3. Architecture en couches

## 3.1 Frontend

Responsabilités :

- rendu UI ;
- navigation ;
- interactions ;
- édition ;
- rendu 2D/3D ;
- appels API ;
- feedback utilisateur.

Technologies :

- React ;
- TypeScript ;
- Vite ;
- shadcn/ui ;
- Tailwind CSS ;
- Zustand ;
- TanStack Query ;
- React Hook Form ;
- Zod ;
- Three.js ;
- React Three Fiber.

## 3.2 Core

Le Core est **indépendant de React et du navigateur**.

```text
core/
├── model/
├── geometry/
├── rules/
├── calculations/
├── commands/
└── serialization/
```

Il ne doit importer :

- React ;
- Zustand ;
- TanStack Query ;
- Three.js ;
- DOM APIs.

Il peut être exécuté/testé avec Node.js.

---

# 4. Règle React / Core

Interdit :

```text
Core → React
Core → Zustand
Core → TanStack Query
```

Autorisé :

```text
React → Core
Zustand → Core
API adapter → Core
Tests → Core
```

Le Core expose des fonctions/classes/commandes métier.

Exemple :

```ts
const result = resizeTerrace(terrace, command);
```

React se contente de déclencher l'opération et d'afficher le résultat.

---

# 5. Architecture du Core TypeScript

```text
core/
├── model/
│   ├── Project
│   ├── Parcel
│   ├── Building
│   ├── Terrace
│   ├── ObjectPlan
│   └── Measurement
│
├── geometry/
│   ├── Point
│   ├── Polygon
│   ├── Segment
│   ├── area
│   ├── distance
│   ├── intersection
│   └── transform
│
├── rules/
│   ├── projectRules
│   ├── geometryRules
│   ├── terraceRules
│   └── constraints
│
├── calculations/
│   ├── shadows
│   ├── surfaces
│   ├── distances
│   └── optimization
│
├── commands/
│   ├── AddObject
│   ├── MoveObject
│   ├── RotateObject
│   ├── ResizeObject
│   ├── DeleteObject
│   └── UpdateProperty
│
└── serialization/
    ├── ProjectSchema
    └── VersionMigration
```

---

# 6. Command model

Les modifications métier passent par des commandes.

```text
User action
    ↓
React
    ↓
Command
    ↓
Core
    ↓
New model state
```

Exemples :

```text
AddTerrace
MoveObject
ResizeTerrace
RotateObject
DeleteObject
SetMaterial
```

Cette architecture prépare :

- Undo ;
- Redo ;
- historique ;
- autosave ;
- collaboration future ;
- replay ;
- audit métier.

---

# 7. Zustand

Zustand gère le **client state**.

Exemples :

```text
activeTool
selectedObjectId
viewMode
panelState
cameraMode
localEditorState
preferences
```

Zustand ne doit pas devenir le backend local de toute l'application.

À éviter :

```text
Zustand
 ├── API cache complet
 ├── toutes les factures
 ├── toutes les listes projets
 ├── calculs métier
 └── règles PLU
```

---

# 8. TanStack Query

TanStack Query gère le **server state**.

Il ne parle **jamais** directement à l'API. Entre les deux il y a le client
TypeScript généré depuis OpenAPI — voir §8.1, qui est la règle, ce diagramme
n'en étant que le résumé :

```text
React
  ↓
TanStack Query
  ↓
Client API TypeScript généré
  ↓
PHP API
  ↓
PostgreSQL
```

Utilisation :

- projets ;
- versions ;
- tenants ;
- utilisateurs ;
- abonnement ;
- factures ;
- jobs ;
- données serveur ;
- informations IGN fournies par le backend.

Fonctions exploitées :

- cache ;
- stale time ;
- refetch ;
- mutations ;
- invalidation ;
- retry ;
- loading/error state.

Règle :

> **Zustand = état local/client. TanStack Query = état serveur.**

---

## 8.1 Frontend API Data Flow

La séquence de construction, dans cet ordre et sans étape sautée :

```text
PHP API
   ↓
OpenAPI 3.1
   ↓
Types TypeScript + client API générés
   ↓
TanStack Query
   ↓
React
```

**OpenAPI est le contrat source.** Les types et le client API TypeScript sont
générés à partir d'OpenAPI. TanStack Query consomme exclusivement ce client
pour gérer le Server State. React consomme TanStack Query.

### Ce qui est interdit

```text
React → fetch()                        ❌
React → axios                          ❌
TanStack Query → fetch()               ❌
TanStack Query → URL écrite à la main  ❌
DTO recopié à la main en TypeScript    ❌
type Invoice = { … } écrit à la main   ❌
```

Chacune de ces lignes crée un **second contrat**, tenu à la main, qui peut
diverger du premier. Et il divergera : le backend renomme un champ, le gate
OpenAPI reste vert parce que le contrat et le routeur sont toujours d'accord,
la génération reste verte parce que personne ne l'a relancée, et c'est le
navigateur d'un client qui découvre la différence. Un contrat unique ne se
défend pas par la discipline de celui qui écrit le `fetch()`, il se défend en
n'ayant pas d'endroit où écrire le `fetch()`.

### Ce qui est autorisé

```text
React → TanStack Query → client généré → PHP API
```

Une seule couche connaît une URL, un verbe HTTP, un en-tête ou une forme de
réponse : le client généré. Elle n'est pas écrite, elle est produite.

### Pourquoi cette direction, et pas l'inverse

Le sens de la flèche est la décision. Partir de React et remonter vers l'API
donne un contrat déduit de ce dont un écran a eu besoin un mardi ; partir de
l'API et descendre donne des écrans contraints par ce que le backend garantit
réellement. Les deux produisent du code qui marche le premier jour. Un seul
survit au deuxième changement de schéma.

C'est le même raisonnement que §25 applique aux factures et
[ADR-035](adr/ADR-035-a-rendered-invoice-is-stored-not-re-rendered.md) au
document rendu : une source, et tout le reste dérivé d'elle plutôt que
re-saisi à côté.

### Conséquence sur la génération

Le code généré n'est pas modifié à la main. Un champ qui manque est un champ
qui manque **dans OpenAPI** : on corrige le contrat, on régénère. Corriger la
sortie plutôt que la source produit un fichier que la prochaine génération
écrase, et une correction qui disparaît sans bruit est pire que l'absence de
correction.

La régénération appartient à la chaîne de qualité (§ *Quality gates*) : si le
contrat a changé et que le client généré ne l'a pas suivi, l'écart doit faire
échouer la CI, pas attendre d'être découvert à l'exécution.

### Ce que cette règle ne dit pas

Elle ne dit rien du **client state**. Zustand, les formulaires, l'état d'un
panneau ouvert : rien de tout cela ne passe par cette chaîne, parce que rien
de tout cela ne vient du serveur. La règle porte sur la donnée serveur et sur
elle seule.

Elle ne fait pas non plus de TanStack Query un moteur métier. Le Core
TypeScript reste ce qu'il est (§5, §4) : TanStack Query transporte et met en
cache, il ne calcule pas.

---

# 9. Forms et validation

## React Hook Form

Pour :

- profil ;
- projet ;
- propriétés d'objet ;
- abonnement ;
- facturation ;
- paramètres.

## Zod

Zod valide les données aux frontières.

```text
API response
    ↓
Zod
    ↓
Typed data
    ↓
Application
```

Zod ne remplace pas la validation PHP.

La validation doit exister des deux côtés.

---

# 10. Contrat API

L'API est REST et versionnée :

```text
/api/v1/
```

Le contrat est décrit avec **OpenAPI 3.1**. OpenAPI constitue la source de vérité du contrat HTTP et permet de générer la documentation, les types TypeScript, les clients et les tests de contrat.

## 10.1 Catalogue API

Le backend expose un catalogue complet, organisé par domaines métier. Les endpoints sont regroupés par tags OpenAPI et suivent les conventions REST.

```text
API ROOT
├── /api/v1/health
├── /api/v1/me
│
├── Products / Product Context
│   ├── GET    /products
│   ├── GET    /products/{productId}
│   ├── GET    /products/{productId}/catalog
│   ├── GET    /products/{productId}/features
│   └── GET    /products/{productId}/configuration
│
├── Authentication / Identity
│   ├── GET    /me
│   └── POST   /auth/logout
│
├── Tenants
│   ├── GET    /tenants/current
│   ├── PATCH  /tenants/current
│   ├── GET    /tenants/current/members
│   ├── POST   /tenants/current/members
│   ├── PATCH  /tenants/current/members/{userId}
│   └── DELETE /tenants/current/members/{userId}
│
├── Users / Profile
│   ├── GET    /me
│   ├── PATCH  /me
│   └── GET    /me/permissions
│
├── Offers / Catalogue commercial
│   ├── GET    /offers
│   ├── GET    /offers/{id}
│   ├── GET    /offers/{id}/versions
│   ├── POST   /offers
│   ├── POST   /offers/{id}/versions
│   ├── PATCH  /offers/{id}
│   └── POST   /offers/{id}/publish
│
├── Plans / Features / Entitlements
│   ├── GET    /plans
│   ├── GET    /plans/{id}
│   ├── GET    /features
│   ├── GET    /entitlements
│   ├── GET    /me/entitlements
│   └── GET    /tenants/current/usage
│
├── Subscriptions
│   ├── GET    /subscription
│   ├── POST   /subscriptions
│   ├── GET    /subscriptions/{id}
│   ├── PATCH  /subscriptions/{id}
│   ├── POST   /subscriptions/{id}/change-offer
│   ├── POST   /subscriptions/{id}/cancel
│   └── POST   /subscriptions/{id}/resume
│
├── Projects
│   ├── GET    /projects
│   ├── POST   /projects
│   ├── GET    /projects/{id}
│   ├── PATCH  /projects/{id}
│   ├── DELETE /projects/{id}
│   ├── GET    /projects/{id}/versions
│   ├── POST   /projects/{id}/versions
│   ├── GET    /projects/{id}/versions/{versionId}
│   ├── POST   /projects/{id}/duplicate
│   └── POST   /projects/{id}/restore
│
├── Project data / Assets
│   ├── GET    /projects/{id}/assets
│   ├── POST   /projects/{id}/assets
│   ├── DELETE /projects/{id}/assets/{assetId}
│   ├── POST   /projects/{id}/exports
│   └── GET    /projects/{id}/exports/{exportId}
│
├── Geometry / GIS / Cadastre
│   ├── GET    /projects/{id}/parcel
│   ├── POST   /projects/{id}/parcel/resolve
│   ├── POST   /geometry/intersections
│   ├── POST   /geometry/buffer
│   └── POST   /geometry/measure
│
├── Photogrammetry / 3D
│   ├── POST   /projects/{id}/photogrammetry/jobs
│   ├── GET    /projects/{id}/photogrammetry/jobs/{jobId}
│   ├── POST   /projects/{id}/3d/exports
│   └── GET    /projects/{id}/3d/exports/{exportId}
│
├── Skins / White label
│   ├── GET    /tenant/skin
│   ├── PATCH  /tenant/skin
│   ├── POST   /tenant/skin/logo
│   └── DELETE /tenant/skin/logo
│
├── Billing
│   ├── GET    /billing/profile
│   ├── PATCH  /billing/profile
│   ├── GET    /invoices
│   ├── GET    /invoices/{id}
│   ├── GET    /invoices/{id}/pdf
│   └── GET    /payments
│
├── Checkout / Payments
│   ├── POST   /checkout/sessions
│   ├── GET    /checkout/sessions/{id}
│   └── POST   /payments/{id}/retry
│
├── Tax / VAT
│   ├── GET    /tax/profile
│   ├── PUT    /tax/profile
│   ├── GET    /tax/rates
│   ├── POST   /tax/calculate
│   ├── GET    /tax/transactions
│   ├── GET    /tax/reports
│   ├── GET    /tax/reports/{period}
│   ├── POST   /tax/reports/{period}/close
│   └── GET    /tax/export
│
├── E-invoicing / PDP
│   ├── POST   /invoices/{id}/electronic
│   ├── GET    /invoices/{id}/electronic
│   └── GET    /invoices/{id}/electronic/status
│
├── Conversations / Messages
│   ├── GET    /conversations
│   ├── POST   /conversations
│   ├── GET    /conversations/{id}
│   ├── POST   /conversations/{id}/messages
│   ├── GET    /conversations/{id}/messages
│   ├── POST   /conversations/{id}/read
│   ├── POST   /conversations/{id}/participants
│   ├── DELETE /conversations/{id}/participants/{userId}
│   ├── POST   /conversations/{id}/close
│   └── DELETE /messages/{id}
│
├── Staff / Support (platform roles, §12.2)
│   ├── GET    /staff/conversations
│   ├── GET    /staff/conversations/{id}
│   ├── POST   /staff/conversations/{id}/messages
│   └── POST   /staff/conversations/{id}/close
│
├── Webhooks
│   └── POST   /webhooks/{provider}
│
├── Jobs / Async operations
│   ├── GET    /jobs/{id}
│   └── POST   /jobs/{id}/cancel
│
└── Admin / Operations
    ├── GET    /admin/tenants
    ├── GET    /admin/users
    ├── GET    /admin/subscriptions
    ├── GET    /admin/invoices
    ├── GET    /admin/jobs
    ├── GET    /admin/audit
    └── GET    /admin/metrics
```

## 10.2 Matrice de responsabilité API

| Domaine | API | Autorité métier | Product scope | Tenant scope | Entitlement typique |
|---|---|---|---|---|---|
| Product | `/products/*` | PHP Product Registry | Oui | Selon ressource | `product.access` |
| Identity | `/me`, auth | Auth provider + PHP context | Contextual | Oui | `account.read` |
| Tenant | `/tenants/*` | PHP | Contextual | Oui | `tenant.admin` |
| Offers | `/offers/*` | PHP | Oui | Selon offre | `catalog.manage` |
| Plans/features | `/plans`, `/features` | PHP | Oui | Oui | `catalog.read` |
| Entitlements | `/entitlements/*` | PHP PDP | Oui | Oui | `entitlements.read` |
| Subscription | `/subscription*` | PHP | Oui | Oui | `subscription.manage` |
| Projects | `/projects*` | Core + PHP | Oui | Oui | `projects.read/write` |
| GIS | `/geometry/*`, `/parcel*` | Core/Geo service | Oui | Oui | `gis.access` |
| 3D | `/3d/*`, `/photogrammetry/*` | Job/Core services | Oui | Oui | `advanced_3d` / `photogrammetry` |
| Billing | `/billing/*`, `/invoices*` | PHP | Oui | Oui | `billing.read/manage` |
| Tax / VAT | `/tax/*` | PHP Tax module (§25.3) | Oui | Oui | `tax.read/manage` |
| Payment | `/checkout/*`, `/payments/*` | PSP + PHP | Oui | Oui | `billing.manage` |
| E-invoice | `/invoices/*/electronic` | EInvoice/PDP adapter | Oui | Oui | `einvoice.access` |
| Messaging | `/conversations/*`, `/messages/*` | PHP Messaging module (§12.3) | Oui | Oui | `messages.read/write` |
| Support | `/staff/*` | PHP, platform roles (§12.2) | Explicit param | **Cross-tenant, audited** | `support.read/respond` |
| Webhooks | `/webhooks/*` | PHP | Provider-scoped | Provider-scoped | Signature required |
| Admin | `/admin/*` | PHP | Global | Global | `admin.*` |

## 10.3 Règles du catalogue

- Toute API publique est versionnée sous `/api/v1`.
- Toute API est décrite dans OpenAPI avant son implémentation.
- Les réponses utilisent JSON sauf téléchargement explicitement documenté.
- Les ressources sont toujours filtrées par le tenant authentifié lorsqu'elles sont tenant-scoped.
- L'identité est authentifiée par le provider d'authentification ; l'autorisation métier reste sous contrôle du backend PHP.
- Les entitlements sont contrôlés côté backend et ne doivent jamais dépendre uniquement de React.
- Les opérations longues retournent un `job` ou un identifiant d'opération asynchrone plutôt que de bloquer la requête HTTP.
- Les endpoints d'écriture sont idempotents lorsque cela est nécessaire, notamment paiement, facturation et webhooks.
- Les webhooks vérifient systématiquement la signature du provider et sont rejouables sans effet de bord.
- Les erreurs suivent un format commun et documenté.
- Pagination, filtrage, tri et recherche sont standardisés pour les collections.

## 10.4 Format d'erreur

```json
{
  "error": {
    "code": "ENTITLEMENT_REQUIRED",
    "message": "This feature is not enabled for the tenant.",
    "details": {},
    "request_id": "..."
  }
}
```

## 10.5 Publication du catalogue

Le catalogue doit être exposé sous forme de :

```text
/api/openapi.json
/api/openapi.yaml
/api/docs
```

La documentation interactive est générée à partir du même contrat OpenAPI que celui utilisé par les tests et la génération éventuelle des clients.

Le catalogue distingue au minimum :

```text
Public API
Tenant API
Admin API
Internal API
Webhook endpoints
```

Aucune route interne ne doit être publiée dans le catalogue public.

## 10.6 Product Context

Le `product_id` est un contexte de premier niveau du backend. Il permet au même backend de servir plusieurs produits.

```text
Request
 ├── product_id
 ├── tenant_id
 ├── user_id
 └── authorization context
```

Le backend résout et valide le contexte dans cet ordre :

```text
Authentication
      ↓
Product resolution
      ↓
Tenant resolution
      ↓
Role / permissions
      ↓
Entitlements
      ↓
Resource authorization
```

Les fonctionnalités spécifiques à un produit doivent être encapsulées dans des modules, configurations, capabilities ou entitlements. Il est interdit de multiplier les conditions métier basées sur des noms de produits :

```php
// Interdit
if ($product === 'product-a') { ... }
```

Le backend partagé doit pouvoir accueillir un nouveau produit sans duplication du backend complet. Les services transverses — Auth, Tenant, Billing, Payment, Invoice, Storage, Jobs, Audit et Webhooks — restent mutualisés lorsque leur comportement est commun.

Le frontend doit transmettre ou établir le contexte produit de manière explicite ; le backend reste l'autorité finale pour déterminer si l'utilisateur et son tenant ont accès au produit et à ses fonctionnalités.

---

## 10.6 Génération TypeScript

Le contrat OpenAPI **génère** les types et le client TypeScript consommés par
le frontend. Ce n'est pas une possibilité offerte, c'est le seul chemin
autorisé : la règle complète, avec ce qu'elle interdit, est en §8.1.

```text
OpenAPI
   │
   ├── PHP API contract tests
   ├── Swagger / Redoc
   ├── TypeScript types            ← généré, jamais écrit
   └── TypeScript API client       ← généré, jamais écrit
```

Une version antérieure de cette section disait que le contrat « peut »
générer ces artefacts et que le frontend « ne doit pas » recopier les DTO à la
main. Formulé ainsi, cela laissait la génération optionnelle et la recopie
simplement déconseillée — soit exactement la marge par laquelle un `fetch()`
écrit à la main devient normal.

# 11. Backend PHP

## Choix

PHP 8.3+ lorsque disponible.

Framework recommandé :

**PHP natif modulaire**

Pourquoi :

- architecture modulaire ;
- sécurité ;
- dependency injection ;
- validation ;
- Doctrine ;
- Messenger ;
- console ;
- tests ;
- évolutivité B2B.

Architecture :

```text
backend/
├── src/
│   ├── Auth/
│   ├── User/
│   ├── Tenant/
│   ├── Project/
│   ├── Billing/
│   ├── Sales/
│   ├── Quote/
│   ├── Order/
│   ├── Payment/
│   ├── Subscription/
│   ├── Entitlement/
│   ├── Invoice/
│   ├── Skin/
│   ├── Data/
│   └── Job/
│
├── public/
├── config/
├── migrations/
└── tests/
```

---

# 12. SaaS multi-tenant

Modèle :

```text
Tenant
├── Users
├── Roles
├── Offers
├── Subscription
├── Entitlements
└── Projects

Offer
├── id
├── code
├── name
├── type                  # FREE / PRO / BUSINESS / ENTERPRISE
├── billing_period        # MONTHLY / YEARLY / CUSTOM
├── price
├── currency
├── max_projects
├── max_users
├── max_storage
├── feature_set
├── version
├── valid_from            # début de validité commerciale
├── valid_until           # fin de validité commerciale
└── status                # DRAFT / ACTIVE / EXPIRED / ARCHIVED

Offer → Subscription → Entitlements → Tenant

Les dates valid_from et valid_until définissent la période de commercialisation de l'offre.
La validité commerciale de l'offre est distincte de la période de l'abonnement du tenant.

Une offre expirée n'est pas supprimée : elle reste historisée afin de préserver l'historique commercial et financier.

Les offres sont versionnées. Une modification importante du prix, des quotas ou des fonctionnalités crée une nouvelle version plutôt que de réécrire l'historique.

Principe :
- Offer = ce qui est vendu
- Subscription = ce qui est souscrit
- Entitlements = ce que le tenant peut réellement utiliser

Le backend est l'autorité pour le contrôle des entitlements.
```

B2C :

```text
Tenant
└── User
```

B2B :

```text
Tenant
├── Admin
├── Members
├── Projects
└── Subscription
```

Toutes les données métier importantes portent un `tenant_id`.

Le backend doit toujours déterminer le tenant depuis le contexte authentifié et contrôler l'accès avant toute opération.

---

# 12.1 Product Layer / Backend multi-produit

Le backend est une **Shared SaaS Platform**, pas le backend d'un seul produit.

```text
                    Backend Platform
                           │
          ┌────────────────┼────────────────┐
          │                │                │
       Product A        Product B        Product C
          │                │                │
          └────────────────┼────────────────┘
                           │
                    Shared Services

Auth · Tenant · User · Billing · Payment · Invoice
Entitlement · Storage · Jobs · Audit · Webhooks
```

### Product context

Le contexte d'une requête est conceptuellement :

```text
Request
 ├── product_id
 ├── tenant_id
 ├── user_id
 └── authorization context
```

Ordre de résolution :

```text
Authentication
      ↓
Product resolution
      ↓
Tenant resolution
      ↓
Role / permissions
      ↓
Entitlements
      ↓
Resource authorization
```

### Isolation

Une ressource doit être rattachée au produit lorsqu'elle est spécifique à un produit. Les ressources strictement plateforme peuvent être partagées.

Exemples :

```text
Platform-level
├── User identity
├── Payment provider
├── Invoice engine
├── Audit
└── Storage service

Product-level
├── Product catalog
├── Features
├── Offers
├── Entitlements
├── Project types
└── Domain modules
```

### Règle fondamentale

Un nouveau produit doit pouvoir être ajouté au backend avec **configuration + modules**, sans dupliquer le backend complet.

Le backend ne doit pas être couplé au frontend d'un produit particulier.

---


# 12.2 Identité plateforme (staff)

§12 isole chaque tenant. Cette section décrit la **seule** identité autorisée
à travailler *au travers* de cette isolation, et ce que cela coûte.

## Deux axes, jamais fusionnés

Un membre du personnel de la plateforme est un **utilisateur** comme un
autre : même table `users`, même authentification, une seule identité. Ce
qui le distingue n'est pas *qui il est* mais *ce qu'il détient*, et cela vit
en dehors de l'appartenance à un tenant :

```text
tenant membership          →  « que puis-je faire dans MON entreprise ? »
   (tenant, user, product)     TENANT_ADMIN, USER

platform staff role        →  « que puis-je faire à TRAVERS les entreprises ? »
   (user, platform_role)       PLATFORM_ADMIN, SUPPORT_ADMIN,
                               FINANCE_ADMIN, SALES_ADMIN
```

Les deux axes sont **indépendants et ne se convertissent jamais l'un en
l'autre** :

- un rôle plateforme **n'accorde aucune appartenance** à un tenant et n'en
  fabrique pas une à la volée ;
- une appartenance, fût-elle `TENANT_ADMIN`, **n'accorde aucun rôle
  plateforme** ;
- `TENANT_ADMIN` désigne l'administrateur **du client**, pas l'exploitant de
  la plateforme. La confusion des deux est la faille la plus coûteuse que
  ce modèle puisse produire.

## Tables

```text
platform_roles          code, name
platform_staff          user_id, platform_role_id, granted_by, granted_at
platform_role_permissions
```

`platform_staff` est délibérément **séparée** de `tenant_members`. Une seule
table portant les deux ferait de l'oubli d'un filtre une élévation de
privilège ; deux tables font de la même erreur une requête qui ne renvoie
rien.

## Ce que coûte un accès staff

> Non-négociable #21 — **tout accès du personnel plateforme à la donnée d'un
> tenant est tracé, motivé et jamais silencieux.**

Concrètement :

- chaque accès staff à de la donnée tenant écrit une ligne d'audit : qui,
  quand, quel tenant, quelle ressource, à quel titre ;
- l'audit est écrit **dans la même transaction** que la lecture qu'il
  justifie lorsque cette lecture est un acte (répondre, clore, agir), selon
  le même principe que les webhooks de §24 : ce qui doit être vrai ensemble
  est écrit ensemble ;
- le staff **ne lit jamais** les conversations internes d'un tenant (§12.3) ;
- §25.2 exige déjà que le reporting admin soit séparé de ce qu'un tenant
  voit (non-négociable #19) : la même séparation s'applique ici.

Le pipeline de contexte (§10.6) résout les deux axes séparément. Une route
tenant reste une route tenant : elle exige une appartenance, et un rôle
plateforme ne la débloque pas. Les routes staff sont un ensemble distinct,
sous `/api/v1/staff/*`, qui exigent un rôle plateforme et **prennent le
tenant en paramètre explicite** — parce qu'ici, contrairement à tout le
reste de la plateforme, il n'y a pas d'appartenance d'où le déduire.

C'est la seule exception à « ne jamais faire confiance à un tenant fourni
par le client », et elle n'en est une qu'en apparence : le tenant est fourni,
mais l'autorisation ne vient pas de lui — elle vient du rôle plateforme, et
l'accès est audité.

---

# 12.3 Messagerie / Conversations

Deux besoins distincts, un seul modèle :

```text
INTERNAL   les membres d'un tenant se parlent entre eux
SUPPORT    le tenant et la plateforme se parlent
```

## Modèle

```text
conversations
├── id
├── tenant_id            à qui appartient le fil
├── product_id           contexte racine (non-négociable : §12.1)
├── kind                 INTERNAL | SUPPORT
├── subject
├── status               OPEN | CLOSED
├── created_by
└── created_at / updated_at

conversation_participants
├── conversation_id
├── user_id
├── participant_kind     MEMBER | STAFF
├── joined_at / left_at
└── last_read_seq        filigrane de lecture

messages
├── id
├── conversation_id
├── seq                  ordre stable dans le fil
├── author_user_id
├── author_kind          MEMBER | STAFF | SYSTEM
├── body
├── created_at
├── edited_at
└── deleted_at
```

## Invariants, en base plutôt qu'en convention

- **Une conversation appartient à un couple (tenant, produit).** Le produit
  est le contexte racine ; une conversation qui n'en nomme pas un serait
  lisible depuis n'importe quel produit du même tenant.
- **L'auteur d'un message est un participant de la conversation** — clé
  étrangère vers `conversation_participants`, pas un `CHECK` applicatif.
  Écrire dans un fil dont on ne fait pas partie doit être refusé par la base.
- **`UNIQUE (conversation_id, seq)`** : l'ordre d'un fil est stable et la
  pagination reprend où elle s'est arrêtée. `seq` est monotone par
  conversation ; il n'a pas besoin d'être sans trou, contrairement à une
  numérotation légale (§25).
- **Un STAFF ne participe qu'à une conversation SUPPORT.** Invariant de
  base : `participant_kind = 'STAFF'` exige `kind = 'SUPPORT'`. Sans lui, un
  membre du personnel peut apparaître dans un fil interne — exactement ce que
  §12.2 interdit.
- **Le filigrane de lecture est par participant**, monotone, jamais
  décroissant. Une table de jointure par message lu coûterait une ligne par
  message et par lecteur pour la même information.

## Suppression : ici le RGPD prime

§26 distingue rétention légale et suppression RGPD, et un message n'est pas
une pièce comptable. Un message supprimé est donc **réellement supprimé** —
le corps est effacé, la ligne subsiste comme pierre tombale pour que l'ordre
du fil reste lisible.

C'est l'inverse d'une facture, que la loi impose de conserver. Le contraste
est volontaire et doit rester explicite dans le code : les deux règles
coexistent parce qu'elles s'appliquent à des objets différents, et non parce
que l'une aurait été oubliée.

## Pas de temps réel, et c'est un choix

R2 du plan : l'hébergement mutualisé n'autorise pas de processus persistant.
Il n'y aura donc **ni WebSocket ni SSE tenu ouvert**. La lecture se fait par
interrogation avec `since_seq`, ce qui est exactement ce que le filigrane
rend efficace : le client demande ce qui a suivi ce qu'il a déjà lu.

La notification d'un message non lu (courriel) relève des jobs de §27, donc
de M7 — pas d'une boucle qui attend. Elle est une **notification** au sens de
§27.1, pas un message : elle prévient qu'un fil a bougé, elle n'entre pas
dedans.

## Pièces jointes

Différées. Elles appartiennent au `StorageProvider` de §15 : un message
référencera un asset une fois M7 livré. Stocker une pièce jointe dans
PostgreSQL contredirait le non-négociable #9.

## API

```text
Conversations (tenant)
├── GET    /api/v1/conversations
├── POST   /api/v1/conversations
├── GET    /api/v1/conversations/{id}
├── POST   /api/v1/conversations/{id}/messages
├── GET    /api/v1/conversations/{id}/messages?since_seq=
├── POST   /api/v1/conversations/{id}/read
├── POST   /api/v1/conversations/{id}/participants
├── DELETE /api/v1/conversations/{id}/participants/{userId}
├── POST   /api/v1/conversations/{id}/close
└── DELETE /api/v1/messages/{id}

Support (staff, §12.2)
├── GET    /api/v1/staff/conversations
├── GET    /api/v1/staff/conversations/{id}
├── POST   /api/v1/staff/conversations/{id}/messages
└── POST   /api/v1/staff/conversations/{id}/close
```

Permissions : `messages.read` / `messages.write` côté tenant,
`support.read` / `support.respond` côté plateforme.

Les deux surfaces sont **séparées de bout en bout** — routes, permissions,
contrôleurs — plutôt qu'une seule surface qui se comporterait différemment
selon l'appelant. Une branche `if (isStaff)` au milieu d'un contrôleur tenant
est précisément la forme que prend une fuite inter-tenant.

---


# 13. Plans / features / quotas

Le système utilise des entitlements.

```text
Plan
  ↓
Features
  ↓
Entitlements
  ↓
Usage
```

Exemples :

```text
max_projects
max_users
max_storage
max_photos
max_3d_exports
photogrammetry
advanced_3d
api_access
white_label
```

Ne pas coder les droits avec des conditions dispersées :

```php
if ($plan === 'PRO') ...
```

Les règles d'accès doivent être centralisées.

Les conditions de durée, d'engagement et de résiliation d'un abonnement
sont spécifiées en **§13.1**.

---

# 13.1 Abonnements — durée, périodicité, engagement, résiliation

Un abonnement B2B ne se résume pas à « il paie tous les mois ». Quatre durées
coexistent, elles ne coïncident pas, et les confondre est la faute qui coûte
le plus cher : c'est elle qui laisse résilier au bout d'un mois un engagement
de vingt-quatre.

## Les cinq durées à ne pas confondre

```text
Périodicité de facturation   à quel rythme le client paie        MONTHLY | YEARLY
Durée totale (terme)         combien de temps l'abonnement court 12 mois, 24 mois, indéterminée
Période d'engagement         pendant combien de temps il ne      0 = sans engagement
                             peut pas être résilié
Période payée en cours       jusqu'à quand le service est dû     current_period_end
Préavis                      délai entre la demande et l'effet   0 = effet immédiat à l'échéance
```

**La périodicité de paiement n'est pas la durée de l'engagement.** Un
abonnement de vingt-quatre mois payé mensuellement est *un* engagement de
vingt-quatre mois facturé vingt-quatre fois — pas vingt-quatre abonnements
d'un mois qui se suivent. C'est la même distinction que §12 tient déjà entre
la période d'abonnement et la fenêtre commerciale de l'offre : deux faits
séparés, deux colonnes séparées.

Et deux règles s'y ajoutent, qui ne se déduisent pas l'une de l'autre :

```text
Politique de résiliation   ce qui se passe quand le client résilie
Reconduction               ce qui se passe quand le terme arrive
```

## Qui souscrit : un tenant, ou une personne

Le souscripteur est la partie qui s'engage. Ce peut être l'organisation, ou
une personne nommée :

```text
subscriber_kind = TENANT   l'organisation souscrit ; l'entitlement vaut pour tous ses membres
subscriber_kind = USER     une personne souscrit (siège) ; l'entitlement ne vaut que pour elle
```

**Un abonnement nomme toujours un tenant et un produit, même quand le
souscripteur est une personne.** Le tenant est le contexte d'isolation
(non-négociable #8), le produit est le contexte racine (§12.1) ; le
souscripteur est la partie contractante, ce qui est une autre question. Un
professionnel isolé n'échappe pas à la règle : il a son propre tenant, dont
il est le seul membre.

Un souscripteur `USER` doit être membre du tenant au moment de la
souscription. Sinon l'abonnement entitlerait quelqu'un qui n'a pas accès au
tenant qui le paie.

## L'unicité est un index, jamais un contrôle

M5 pose « un seul abonnement actif par (tenant, produit) ». Avec les sièges,
cette règle se dédouble, et les deux moitiés restent des index partiels :

```sql
-- l'abonnement de l'organisation : un seul par produit
UNIQUE (tenant_id, product_id)
    WHERE status = 'ACTIVE' AND subscriber_kind = 'TENANT'

-- le siège : un seul par personne et par produit
UNIQUE (tenant_id, product_id, subscriber_user_id)
    WHERE status = 'ACTIVE' AND subscriber_kind = 'USER'
```

Un contrôle applicatif « existe-t-il déjà un abonnement actif ? » se perd
dans la course entre deux souscriptions simultanées. L'index, non.

## Ce que vend l'offre, ce que retient l'abonnement

Les conditions sont **portées par la version d'offre** — c'est elle qui est
vendue — puis **figées dans l'abonnement** au moment de souscrire :

```text
offer_versions  (ce qui est vendu)
├── billing_period        MONTHLY | YEARLY | CUSTOM        (existant)
├── term_months           durée totale vendue, NULL = indéterminée
├── commitment_months     engagement vendu, 0 = sans engagement
├── cancellation_policy   ANYTIME | AT_COMMITMENT_END | AT_TERM
├── renewal               AUTO_RENEW | ENDS_AT_TERM
├── early_termination     FORBIDDEN | CHARGE_REMAINING | FREE
└── notice_days           préavis de résiliation, 0 = aucun

subscriptions  (ce à quoi le client s'est engagé)
├── subscriber_kind       TENANT | USER
├── subscriber_user_id    NULL sauf si USER
├── billing_period        copié de la version
├── term_months / term_ends_at
├── commitment_months / commitment_ends_at
├── cancellation_policy / renewal / early_termination / notice_days
├── current_period_start / current_period_end                   (existant)
├── cancel_at_period_end / cancel_effective_at                   (existant, étendu)
└── status                ACTIVE | CANCELLED | EXPIRED           (existant)
```

**Une offre re-tarifée ou re-durée demain ne change rien à ce qu'un client a
déjà signé.** C'est le snapshot de la facture (§25) et le snapshot fiscal
(§25.3), appliqués au contrat : les conditions sont recopiées comme des
valeurs, pas référencées par une clé étrangère vers une ligne qui bouge.

## Invariants, en base plutôt qu'en convention

```sql
-- l'engagement ne dépasse pas le terme
CHECK (term_months IS NULL OR commitment_months <= term_months)

-- une date d'engagement existe exactement quand il y a un engagement
CHECK ((commitment_months > 0) = (commitment_ends_at IS NOT NULL))

-- un souscripteur nommé existe exactement quand le souscripteur est une personne
CHECK ((subscriber_kind = 'USER') = (subscriber_user_id IS NOT NULL))

-- un terme se termine après avoir commencé
CHECK (term_ends_at IS NULL OR term_ends_at > started_at)

-- les ensembles fermés restent fermés
CHECK (cancellation_policy IN ('ANYTIME', 'AT_COMMITMENT_END', 'AT_TERM'))
CHECK (renewal            IN ('AUTO_RENEW', 'ENDS_AT_TERM'))
CHECK (early_termination  IN ('FORBIDDEN', 'CHARGE_REMAINING', 'FREE'))
```

La deuxième a la même forme que le bail d'un job (§27, ADR-027) : une
colonne dérivée qui existe *exactement* quand l'état le dit. Sans elle, un
abonnement peut porter un engagement que rien ne date, donc que rien ne
termine.

**`commitment_months` doit être `NOT NULL`** — et c'est la contrainte
elle-même qui l'exige, pas le confort. Sur une colonne nullable,
`(NULL > 0) = (commitment_ends_at IS NOT NULL)` vaut `NULL`, et une
contrainte `CHECK` **accepte** `NULL` : la règle laisserait passer
exactement la ligne qu'elle existe pour refuser. Vérifié sur PostgreSQL, pas
supposé. Zéro veut dire « sans engagement » ; l'absence ne veut rien dire.

## Résilier : c'est l'horloge qui trie

Deux règles, qui se cumulent.

**Le service reste dû jusqu'à la fin de la période payée.** Résilier le
2 du mois ne retire rien avant la fin du mois. C'est déjà la règle de M5 —
`cancel_at_period_end` — et elle ne change pas.

**Sous engagement, la demande est refusée ou différée, jamais silencieusement
acceptée puis ignorée.** Ce que fait la plateforme dépend de la politique
vendue :

| Politique | Demande pendant l'engagement | Demande après |
|---|---|---|
| `ANYTIME` | acceptée, effet à la fin de la période payée | idem |
| `AT_COMMITMENT_END` | acceptée, effet à la fin de l'engagement | effet fin de période payée |
| `AT_TERM` | acceptée, effet à la fin du terme | idem |

Dans les trois cas la demande est **enregistrée** et l'abonnement porte sa
date d'effet (`cancel_effective_at`). Une demande qui ne laisserait pas de
trace ferait du « j'ai résilié » / « nous n'avons rien reçu » un litige sans
arbitre.

`early_termination` dit ce qu'on peut acheter pour sortir plus tôt :

- `FORBIDDEN` — la sortie anticipée n'est pas vendue ; la demande est
  différée selon la politique ci-dessus ;
- `CHARGE_REMAINING` — la sortie est possible contre les périodes restant
  dues, qui sont **facturées par la chaîne de facturation normale** (§25),
  jamais par un chemin spécial ;
- `FREE` — la sortie est possible sans contrepartie.

**L'échéance est un fait d'horloge, jamais un fait de balayage.**
`isLiveAt()` interroge l'horloge et non le statut, exactement comme pour les
offres, les entitlements, les devis et les baux de jobs. Un abonnement dont
la période est passée n'entitle plus, que le job de balayage ait tourné ou
non ; le job met la colonne d'accord avec la réalité, il ne la crée pas.

## Reconduction

À l'échéance du terme :

- `AUTO_RENEW` — une nouvelle période commence, et s'il y a un terme, un
  nouveau terme. **L'engagement ne se réarme pas silencieusement** : il ne
  repart que si l'offre le stipule, et cette stipulation est une valeur, pas
  un effet de bord.
- `ENDS_AT_TERM` — l'abonnement s'arrête ; l'entitlement tombe avec lui.

La reconduction tacite oblige à informer le client **avant** l'échéance. Ce
préavis est une notification (§27.1), déclenchée depuis `term_ends_at` et
`commitment_ends_at`, et sa tentative est conservée : « avons-nous prévenu ?
» doit avoir une réponse. Les délais légaux exacts, en particulier vers les
consommateurs, sont à confirmer auprès des sources officielles — c'est un
risque suivi, au même titre que les échéances de facturation électronique.

## API

```text
POST   /api/v1/subscriptions                  souscrire (offre, souscripteur)
GET    /api/v1/subscriptions
GET    /api/v1/subscriptions/{id}
GET    /api/v1/subscriptions/{id}/schedule    échéancier : périodes, engagement, terme
POST   /api/v1/subscriptions/{id}/cancel      demande de résiliation
POST   /api/v1/subscriptions/{id}/resume      annule une résiliation programmée
```

`schedule` répond aux trois questions que le client pose réellement :
jusqu'à quand est-ce payé, jusqu'à quand suis-je engagé, quand puis-je
partir.

Codes d'erreur :

```text
COMMITMENT_NOT_ELAPSED      409  résiliation demandée sous engagement FORBIDDEN
CANCELLATION_NOT_PERMITTED  409  la politique de l'offre ne le permet pas
SUBSCRIBER_NOT_A_MEMBER     422  souscripteur USER hors du tenant
ALREADY_SUBSCRIBED          409  un abonnement actif existe déjà pour ce périmètre
```

Aucun de ces refus ne dépend d'un nom de plan : ce sont les conditions de
l'abonnement qui décident, jamais `if ($plan === 'PRO')`.

---

# 14. PostgreSQL

PostgreSQL est la base principale.

Utilisations :

- utilisateurs ;
- tenants ;
- projets ;
- versions ;
- abonnements ;
- factures ;
- paiements ;
- entitlements ;
- audit ;
- JSONB ;
- métadonnées géographiques.

---

# 15. Stockage des projets

## Décision

Architecture hybride.

### PostgreSQL

Stocke :

```text
project metadata
project JSONB
versions
tenant ownership
permissions
searchable properties
```

### Object Storage

Stocke :

```text
photos
GLB / GLTF
textures
PDF
exports
large snapshots
```

Ne pas mettre de base64 de photos/GLB dans JSONB.

---

# 16. Format du projet

Exemple :

```json
{
  "schemaVersion": 1,
  "project": {
    "id": "...",
    "name": "Projet maison"
  },
  "parcel": {
    "id": "AE101",
    "geometry": {}
  },
  "objects": [],
  "scene": {},
  "settings": {}
}
```

Le `schemaVersion` est obligatoire.

Il permettra :

```text
V1
 ↓ migration
V2
 ↓ migration
V3
```

---

# 17. Versioning

MVP :

```text
Project
├── Version 1
├── Version 2
├── Version 3
└── Version N
```

Chaque version peut être un snapshot JSONB complet.

Évolution possible :

```text
snapshot + deltas/events
```

Uniquement lorsque le volume le justifie.

---

# 18. IndexedDB

IndexedDB est utilisé comme cache/draft local.

```text
Server
  ↓
TanStack Query
  ↓
Editor
  ↓
IndexedDB
```

Objectif :

- autosave local ;
- restauration après crash ;
- expérience offline partielle ;
- édition rapide.

IndexedDB n'est pas la source de vérité du SaaS.

---

# 19. Géospatial

PostgreSQL n'est **pas une dépendance initiale**.

Phase 1 :

```text
PostgreSQL
   +
GeoJSON
   +
Core TypeScript
```

Les calculs du projet courant restent dans le Core.

Phase 2 :

```text
PHP
  ├── PostgreSQL
  │
  └── Geo Service
        └── PostgreSQL + PostgreSQL
```

PostgreSQL devient pertinent pour :

- intersections ;
- buffers ;
- proximité ;
- containment ;
- recherches spatiales massives ;
- analyses de parcelles.

Cette architecture évite de dépendre d'une extension non confirmée sur SiteGround.

---

# 20. 2D / 3D

Le modèle métier est indépendant du rendu.

```text
Core Model
    │
    ├── 2D Renderer
    │      └── SVG / Canvas
    │
    └── 3D Renderer
           └── React Three Fiber
                  └── Three.js
```

Three.js ne doit pas devenir la source de vérité du projet.

Le Core contient la géométrie.

Three.js la représente.

---

# 21. Design System

Construire d'abord un système UI cohérent.

```text
ui/
├── Button
├── Input
├── Select
├── Slider
├── Tabs
├── Dialog
├── Drawer
├── Tooltip
├── Toolbar
├── PropertyPanel
├── Card
└── Toast
```

Technologies :

- shadcn/ui ;
- Tailwind CSS ;
- design tokens ;
- CSS variables.

Le design system doit supporter le skin tenant.

---

# 22. App Shell

L'application est conçue comme un workspace.

```text
┌──────────────────────────────────────────────────────┐
│ TopBar                                                │
├─────────────┬──────────────────────────┬─────────────┤
│ Tools       │                          │ Properties  │
│             │          Canvas          │             │
│ Parcel      │          2D / 3D         │ Object      │
│ House       │                          │ Position    │
│ Terrace     │                          │ Dimensions  │
│ Objects     │                          │ Material    │
├─────────────┴──────────────────────────┴─────────────┤
│ Status / Measurements / Zoom                         │
└──────────────────────────────────────────────────────┘
```

Les objets métier sont des features/outils, pas des pages.

---

# 23. Skin / White Label

Chaque tenant peut avoir :

```text
logo
favicon
colors
font
application name
customization
```

Les couleurs sont exposées par CSS variables.

```css
:root {
  --brand-primary: ...;
  --brand-secondary: ...;
}
```

Pas de build React différent par client.

---

# 24. Paiement

Le PSP externe gère les moyens de paiement.

Selon le PSP et le pays :

- carte ;
- Apple Pay ;
- Google Pay ;
- SEPA ;
- virement.

Ne jamais stocker les données carte dans PostgreSQL.

Activation :

```text
Checkout
  ↓
PSP
  ↓
Webhook PHP
  ↓
Payment confirmed
  ↓
Subscription active
  ↓
Entitlements activated
```

Le webhook serveur est la source de vérité.

---

# 25. Facturation / TVA

Tables :

```text
billing_profiles
invoices
invoice_lines
payments
tax_records
```

Une facture doit conserver son propre snapshot :

```text
customer
address
VAT
lines
prices
tax
currency
```

Une facture historique ne doit pas dépendre des valeurs actuelles du plan.

Le volet fiscal de ce snapshot — quel taux, quel régime, quel numéro de TVA
vérifié, et selon quelle règle — est spécifié en **§25.3**, qui ajoute le
module `Tax` et la table `vat_transactions`. `tax_records` reste la
ventilation par taux **à l'intérieur** d'une facture ; `vat_transactions`
porte le fait fiscal **déclarable**, avec le pays de taxation, le régime et
l'autoliquidation.

---


# 25.1 Facturation électronique — conformité France

La plateforme doit intégrer dès la conception la réforme française de la facturation électronique.

À partir du **1er septembre 2026**, toutes les entreprises devront être capables de recevoir des factures électroniques. Les grandes entreprises et ETI devront également les émettre électroniquement à cette date. Les microentreprises et PME devront émettre électroniquement au plus tard le **1er septembre 2027**. citeturn0search0turn0search25

La réforme concerne également l'**e-reporting** selon la nature des opérations et le calendrier applicable. citeturn0search1

## Architecture

Ne pas construire directement une passerelle fiscale propriétaire dans le SaaS.

Prévoir une abstraction :

```text
Invoice
   ↓
EInvoice Service
   ↓
Approved Platform / PDP
   ↓
Customer / Administration
```

La transmission des factures et données réglementaires doit passer par une **plateforme agréée / immatriculée** conformément au dispositif en vigueur. citeturn0search4turn0search10

## Modèle de facture

Une facture doit conserver :

```text
invoice_id
invoice_number
issue_date
supplier
customer
customer_siren
billing_address
lines
quantity
unit_price
discount
net_amount
vat_rate
vat_amount
gross_amount
currency
payment_terms
payment_status
credit_note_reference
```

Pour les clients professionnels français, prévoir les données structurées nécessaires au dispositif.

Un simple PDF envoyé par email ne constitue pas, à lui seul, une facture électronique au sens de la réforme. citeturn0search28

## E-invoicing / e-reporting

Séparer les concepts :

```text
B2B France
   → e-invoicing

B2C
   → e-reporting selon opération

B2B international
   → e-reporting selon opération et règles applicables

Payment data
   → e-reporting de paiement lorsque requis
```

Le cas exact doit être déterminé par la nature de l'opération, le statut TVA et le pays du client. citeturn0search12

## Statuts

```text
DRAFT
ISSUED
READY_FOR_EINVOICE
SUBMITTED
ACCEPTED
REJECTED
PAID
CANCELLED
CREDITED
```

Conserver les identifiants et statuts de transmission fournis par la plateforme.

## Architecture technique

```text
Billing Service
      ↓
Invoice Service
      ↓
EInvoice Adapter
      ↓
PDP / plateforme agréée
      ↓
Webhooks / status
      ↓
Billing database
```

Le choix de la plateforme agréée doit rester interchangeable.

## Archivage

Les factures et pièces comptables doivent être conservées selon les obligations légales applicables, indépendamment de la politique RGPD de suppression des données utilisateur.

Le service de rétention doit donc distinguer :

```text
RGPD deletion
       ≠
Legal accounting retention
```



# 25.2 Tableau de bord financier / Administration

La plateforme doit disposer d'un **Financial & Sales Dashboard** réservé aux administrateurs autorisés.

Objectif :

> Donner une vision consolidée de l'activité commerciale, des offres, devis, ventes, abonnements, revenus, paiements et tenants.

Le dashboard ne doit pas être uniquement un écran de reporting : il doit s'appuyer sur des données financières historisées et auditables.

## Vue globale

```text
ADMIN FINANCE / SALES
        │
        ▼
Financial Dashboard
        │
 ┌──────┼────────┬──────────┬──────────┐
 │      │        │          │          │
Ventes  Devis   Offres   Abonnements Paiements
 │      │        │          │          │
 └──────┴────────┴──────────┴──────────┘
                    │
                  Tenants
```

## KPI principaux

Afficher au minimum :

```text
Revenue
MRR
ARR
New MRR
Expansion MRR
Churn MRR
ARPU
Nombre de clients
Nombre de tenants
Nombre de projets
Nombre de ventes
Panier moyen
Taux de conversion devis → vente
Taux de renouvellement
Factures impayées
Montant des créances
TVA collectée
```

Les montants doivent pouvoir être affichés :

- HT ;
- TVA ;
- TTC ;
- par devise ;
- par période.

## Filtres

```text
Période
Plan / Offre
B2C / B2B
Tenant
Pays
Devise
Statut abonnement
Statut facture
Canal de vente
```

## Vue ventes

```text
Sales
├── Orders
├── Subscriptions
├── One-shot purchases
├── Renewals
├── Upgrades
├── Downgrades
└── Cancellations
```

Indicateurs :

- ventes par jour/semaine/mois ;
- CA HT/TTC ;
- évolution ;
- ventes par offre ;
- ventes par pays ;
- ventes par canal ;
- nouveaux vs existants.

## Vue offres

Chaque offre doit avoir son propre reporting :

```text
Offer
├── price
├── billing_period
├── active_subscriptions
├── new_sales
├── renewals
├── upgrades
├── downgrades
├── churn
├── revenue
└── conversion_rate
```

Exemple :

```text
FREE
PRO
BUSINESS
ENTERPRISE
```

Les offres doivent être versionnées : une modification tarifaire ne doit pas réécrire l'historique financier.

## Vue devis

Ajouter un véritable pipeline commercial :

```text
DRAFT
   ↓
SENT
   ↓
VIEWED
   ↓
ACCEPTED
   ↓
CONVERTED
   ↓
INVOICED
   ↓
PAID
```

Autres statuts :

```text
EXPIRED
REJECTED
CANCELLED
```

KPI :

- nombre de devis ;
- valeur totale des devis ;
- valeur pondérée ;
- taux d'acceptation ;
- délai moyen de conversion ;
- devis expirés ;
- devis par offre ;
- devis par commercial ;
- devis par tenant/client.

## Vue tenant

Chaque tenant dispose d'une fiche commerciale et financière :

```text
Tenant
├── Company / Customer
├── Contacts
├── Plan
├── Subscription
├── MRR / ARR
├── Projects
├── Quotes
├── Orders
├── Invoices
├── Payments
├── Credits
└── Usage
```

Le dashboard tenant doit permettre de passer rapidement :

```text
Tenant
  ↓
Quote
  ↓
Order
  ↓
Subscription
  ↓
Invoice
  ↓
Payment
```

## Vue devis → vente

Le système doit conserver la relation :

```text
Quote
   ↓
Order
   ↓
Subscription / Purchase
   ↓
Invoice
   ↓
Payment
```

Cela permet de mesurer précisément le funnel commercial.

Exemple :

```text
100 devis
   ↓
65 acceptés
   ↓
60 commandes
   ↓
55 activations
   ↓
50 paiements complets
```

## Revenus récurrents

Pour les abonnements :

```text
MRR
ARR
New MRR
Expansion MRR
Contraction MRR
Churn MRR
Net New MRR
```

Les métriques doivent être calculées à partir d'événements financiers historisés, et non uniquement à partir de l'état courant des abonnements.

## Encaissements

Vue :

```text
Payments
├── Paid
├── Pending
├── Failed
├── Refunded
├── Partially refunded
└── Chargeback
```

Avec :

- moyen de paiement ;
- date ;
- montant ;
- devise ;
- tenant ;
- facture ;
- commande ;
- transaction PSP.

## TVA

Dashboard :

```text
VAT
├── TVA collectée
├── TVA par pays
├── TVA par taux
├── TVA par période
└── opérations concernées par e-reporting
```

Ces données servent au pilotage et à la préparation des flux réglementaires ; elles ne remplacent pas les traitements comptables/fiscaux requis.

## Facturation électronique

Le dashboard doit exposer les statuts :

```text
Invoice
   ↓
E-invoice
   ├── READY
   ├── SUBMITTED
   ├── ACCEPTED
   ├── REJECTED
   └── ERROR
```

Les entreprises doivent recourir à une plateforme agréée pour les flux concernés par la réforme française de facturation électronique et d'e-reporting. citeturn0search0turn0search7

Le dashboard doit donc permettre de détecter :

- factures non transmises ;
- rejets ;
- erreurs de données ;
- données de paiement à transmettre ;
- anomalies de TVA ;
- absence d'identifiants réglementaires.

## Architecture technique

```text
Admin React
    │
    ▼
TanStack Query
    │
    ▼
PHP Financial API
    │
    ├── Sales Service
    ├── Quote Service
    ├── Billing Service
    ├── Subscription Service
    ├── Payment Service
    ├── Tax Service
    └── Reporting Service
             │
             ▼
        PostgreSQL
```

Pour les gros volumes, les KPI sont calculés à partir de tables d'événements / agrégats plutôt que de recalculer toutes les transactions à chaque affichage.

## Modèle de données

Ajouter notamment :

```text
quotes
quote_lines
orders
order_lines
subscriptions
subscription_events
invoices
invoice_lines
payments
payment_events
refunds
credits
financial_events
sales_metrics
```

Relations :

```text
Tenant
  │
  ├── Quote
  │      ↓
  │    Order
  │      ↓
  │ Subscription
  │      ↓
  │   Invoice
  │      ↓
  │   Payment
  │
  └── Financial Events
```

## Sécurité

Le Financial Dashboard est strictement séparé du dashboard utilisateur.

Rôles possibles :

```text
SUPER_ADMIN
FINANCE_ADMIN
SALES_ADMIN
SUPPORT_ADMIN
TENANT_ADMIN
USER
```

Un `TENANT_ADMIN` ne peut voir que les données de son tenant.

Un `FINANCE_ADMIN` peut voir les données financières globales selon ses permissions.

Toutes les opérations sensibles sont auditées.


# 25.3 Fiscalité / TVA — profils, calcul, déclaration

§25 impose qu'une facture conserve son propre snapshot. Cette section dit
**quelles données fiscales** ce snapshot doit contenir, **qui décide** du
régime applicable, et **ce qui est produit** pour la déclaration.

## Périmètre : produire la donnée fiscale, pas tenir la comptabilité

Le SaaS n'est pas un logiciel de comptabilité et ne doit pas le devenir.

```text
Le backend DOIT                          Le backend NE DOIT PAS
─────────────────────────────────        ──────────────────────────────────
calculer la TVA d'une vente              tenir un plan comptable
enregistrer la règle appliquée           produire un grand livre
conserver l'historique fiscal            télédéclarer à l'administration
agréger par période et par pays          remplacer un expert-comptable
exporter vers comptable / PDP            décider de l'assujettissement
```

La frontière est nette : le backend produit et conserve des **données
fiscales fiables et exportables**, puis les remet à un logiciel comptable ou
à une PDP. Tout ce qui relève de la qualification fiscale de l'entreprise
elle-même reste une décision humaine, paramétrée, jamais devinée.

## Les six objets à ne pas confondre

Une seule notion de « TVA » dans le modèle produit des factures fausses. Il
en faut six, distinctes :

| # | Objet | Question à laquelle il répond |
|---|---|---|
| 1 | **TVA du client** | Qui est l'acheteur ? Pays, numéro intracommunautaire, B2B ou B2C |
| 2 | **TVA appliquée à la vente** | Quel taux, sur quelle base, pour quel montant, sous quel régime |
| 3 | **Déclaration de TVA** | Que doit-on déclarer, pour quelle période, dans quel pays |
| 4 | **Historique fiscal** | Quelle règle et quel taux s'appliquaient **au moment** de la facture |
| 5 | **TVA intracommunautaire** | Autoliquidation B2B, et OSS pour le B2C transfrontalier |
| 6 | **Facturation électronique** | Quelles données fiscales partent vers la PDP (§25.1) |

Le point 4 est le plus facile à perdre et le plus coûteux à retrouver.

## Module Tax

```text
App\Tax\
├── Domain\
│   ├── CustomerTaxProfile      qui est le client, fiscalement
│   ├── TaxIdentification       le numéro de TVA et sa vérification
│   ├── TaxRate                 un taux, pour un pays, sur une fenêtre
│   ├── TaxRule                 quel régime s'applique, et pourquoi
│   ├── TaxCalculation          le résultat motivé d'une application
│   ├── VATTransaction          le fait fiscal, immuable
│   ├── VATReportingPeriod      une période déclarative, par juridiction
│   ├── VATDeclaration          ce qui est déclaré pour cette période
│   ├── VATReconciliation       facturé vs encaissé vs déclaré
│   └── VatNumberValidator      port de vérification (VIES)
├── Service\
├── Infrastructure\
└── Controller\
```

Le module est distinct de `Billing` : Billing produit un **document**, Tax
produit un **fait fiscal déclarable**. Ils partagent la facture et rien
d'autre.

## VATTransaction

Le fait fiscal, écrit une fois, jamais recalculé :

```text
VATTransaction
├── invoice_id            la facture qui l'a produit
├── tenant_id
├── customer_id
├── country               pays de taxation retenu
├── customer_tax_number   tel que présenté, tel que vérifié
├── supply_type           GOODS | SERVICES | DIGITAL_SERVICES
├── taxable_base          base HT, en unités mineures
├── vat_rate              taux appliqué, en points de base
├── vat_amount            montant de TVA, en unités mineures
├── currency              ISO 4217
├── vat_regime            régime retenu (ci-dessous)
├── reverse_charge        autoliquidation : oui / non
└── transaction_date      date du fait générateur
```

Règles structurelles :

- une facture produit **une ligne par couple (taux, régime)** ;
- la somme des `vat_amount` d'une facture **égale** le total de TVA de cette
  facture — invariant vérifiable en base, pas par convention ;
- `taxable_base` et `vat_amount` sont des entiers en unités mineures, comme
  partout ailleurs (§25) ;
- une VATTransaction n'est jamais modifiée. Une correction est une nouvelle
  transaction rattachée à un avoir, comme une facture se corrige par un avoir
  et jamais par une réécriture.

`vat_regime` est un ensemble fermé :

```text
STANDARD           TVA du pays de taxation
REVERSE_CHARGE     autoliquidation B2B intracommunautaire
OSS                guichet unique, taux du pays du client
EXEMPT             exonération (avec mention légale obligatoire)
ZERO_RATED         taux zéro
OUT_OF_SCOPE       hors champ
```

## Le snapshot fiscal

§25 dit qu'une facture conserve son snapshot. Le corollaire fiscal :

> **Ne jamais recalculer l'historique avec les taux actuels.**

Une VATTransaction enregistre le **taux** et l'**identifiant de la règle**
appliqués, comme valeurs — jamais comme clé étrangère vers une ligne de taux
susceptible de bouger. Un taux qui change par la loi ne doit pas déplacer un
euro de TVA déjà facturé, et une déclaration rejouée deux ans plus tard doit
rendre le même chiffre.

La chaîne complète :

```text
Offer → Subscription → Invoice → VATTransaction
                          │            │
                    snapshot      snapshot fiscal
                    commercial    (taux, règle, régime,
                    (§25)          numéro vérifié)
```

## Un taux est valide sur une fenêtre, et c'est l'horloge qui tranche

`TaxRate` porte `valid_from` / `valid_until`, et le taux applicable est celui
en vigueur **à la date du fait générateur** — jamais « le taux courant ».
C'est la cinquième application de la règle qui gouverne déjà les fenêtres
d'offre, la validité des droits, les périodes d'abonnement et l'expiration
d'un devis :

> Une échéance est un fait d'horloge, jamais un fait de traitement.

Un taux annoncé pour le 1er janvier s'insère à l'avance avec sa fenêtre ; il
s'applique tout seul le jour venu, sans déploiement et sans script.

## Autoliquidation intracommunautaire

Pour une prestation B2B intracommunautaire, la TVA est **autoliquidée par le
preneur** : la facture porte 0 et la mention obligatoire d'autoliquidation.

La condition n'est pas « le client a saisi un numéro » mais « le numéro a été
**vérifié** » :

- la vérification passe par un port `VatNumberValidator`, adaptateur VIES,
  jamais un appel direct depuis le domaine (§ indépendance des fournisseurs) ;
- le résultat est **conservé avec sa date** : c'est la preuve opposable en
  contrôle, et §26 la conserve au titre de la rétention comptable, pas du
  RGPD ;
- **fail-closed** : un numéro non vérifié n'est pas un numéro vérifié. En cas
  d'indisponibilité de VIES, la vente n'est pas requalifiée en autoliquidation
  par défaut — elle est facturée au régime standard, ou mise en attente, selon
  le paramétrage, et l'anomalie est visible (§25.2).

Deux pièges d'identifiants, structurels et non cosmétiques :

- la **Grèce** est `GR` en ISO 3166 et `EL` en préfixe de numéro de TVA ;
- l'**Irlande du Nord** est `XI` en préfixe de TVA pour les biens depuis le
  Brexit, sans être un code pays ISO.

Un modèle qui suppose « préfixe TVA = code pays ISO » est faux pour les deux.

## OSS — B2C transfrontalier

Pour un SaaS, la vente B2C intracommunautaire de services numériques est
taxée **dans le pays du client**, déclarée via le guichet unique OSS, sauf
application du seuil de minimis en dessous duquel le taux du pays du vendeur
s'applique.

Conséquences pour le modèle :

- le pays de taxation est une **donnée calculée et conservée**, pas le pays du
  vendeur par défaut ;
- il faut donc conserver les **éléments de preuve de localisation** du client
  utilisés au moment de la vente ;
- le franchissement du seuil est un événement daté qui change le régime des
  ventes suivantes, jamais des précédentes.

## Période déclarative et clôture

```text
VATReportingPeriod
├── juridiction
├── période (mois | trimestre)
├── statut : OPEN → CLOSED
└── totaux par taux et par régime
```

**Une période close est immuable.** La clôture est une transition à sens
unique, comme la numérotation légale est sans trou : une correction portant
sur une période close est une écriture corrective **dans une période
ultérieure**, jamais une modification rétroactive.

`VATReconciliation` rapproche trois grandeurs qui n'ont aucune raison d'être
égales et dont l'écart doit être expliqué plutôt que masqué :

```text
TVA facturée   (VATTransaction)
TVA encaissée  (paiements rapprochés)
TVA déclarée   (VATDeclaration)
```

## API

```text
GET    /api/v1/tax/profile               profil fiscal du tenant
PUT    /api/v1/tax/profile               dont numéro de TVA (déclenche vérification)
GET    /api/v1/tax/rates                 taux applicables, à une date
POST   /api/v1/tax/calculate             simulation motivée, sans effet de bord
GET    /api/v1/tax/transactions          faits fiscaux, filtrables
GET    /api/v1/tax/reports               périodes déclaratives
GET    /api/v1/tax/reports/{period}      totaux d'une période
POST   /api/v1/tax/reports/{period}/close    clôture (sens unique)
GET    /api/v1/tax/export                export comptable / PDP
```

`POST /tax/calculate` est **sans effet de bord** : il répond ce qui serait
appliqué et **pourquoi** (règle retenue, taux, régime, mentions obligatoires),
ce qui en fait l'outil de diagnostic quand une facture surprend son
destinataire.

`GET /tax/export` produit un format neutre destiné à être repris par un
logiciel comptable ou une PDP. Comme pour les PDP (§25.1, non-négociable
#17), le format d'export est un **adaptateur** : aucun format propriétaire ne
doit remonter dans le domaine.

Permissions : `tax.read` pour la lecture, `tax.manage` pour le profil et la
clôture. La clôture d'une période est une opération auditée (§25.2).

## Profils TVA — États membres de l'UE

Taux **standard** par État membre, en points de base, avec le préfixe de
numéro de TVA lorsqu'il diffère du code ISO :

| Pays | ISO | Préfixe TVA | Taux standard | Points de base |
|---|---|---|---|---|
| Allemagne | DE | DE | 19 % | 1900 |
| Autriche | AT | AT | 20 % | 2000 |
| Belgique | BE | BE | 21 % | 2100 |
| Bulgarie | BG | BG | 20 % | 2000 |
| Chypre | CY | CY | 19 % | 1900 |
| Croatie | HR | HR | 25 % | 2500 |
| Danemark | DK | DK | 25 % | 2500 |
| Espagne | ES | ES | 21 % | 2100 |
| Estonie | EE | EE | 24 % | 2400 |
| Finlande | FI | FI | 25,5 % | 2550 |
| France | FR | FR | 20 % | 2000 |
| Grèce | GR | **EL** | 24 % | 2400 |
| Hongrie | HU | HU | 27 % | 2700 |
| Irlande | IE | IE | 23 % | 2300 |
| Italie | IT | IT | 22 % | 2200 |
| Lettonie | LV | LV | 21 % | 2100 |
| Lituanie | LT | LT | 21 % | 2100 |
| Luxembourg | LU | LU | 17 % | 1700 |
| Malte | MT | MT | 18 % | 1800 |
| Pays-Bas | NL | NL | 21 % | 2100 |
| Pologne | PL | PL | 23 % | 2300 |
| Portugal | PT | PT | 23 % | 2300 |
| Roumanie | RO | RO | 21 % | 2100 |
| Slovaquie | SK | SK | 23 % | 2300 |
| Slovénie | SI | SI | 22 % | 2200 |
| Suède | SE | SE | 25 % | 2500 |
| Tchéquie | CZ | CZ | 21 % | 2100 |

Hors UE mais pertinents pour la facturation : `XI` (Irlande du Nord, biens),
`CH`, `GB`, `NO` — traités comme export ou hors champ selon l'opération.

**Statut de cette table.** C'est une **amorce de paramétrage, pas une
autorité fiscale.** Les 27 taux ont été recoupés contre des sources publiques
le **3 septembre 2026** — dont les quatre qui ont bougé récemment et qu'une
table écrite de mémoire aurait ratés :

```text
Estonie    22 → 24    1er juillet 2025
Roumanie   19 → 21    1er août 2025
Slovaquie  20 → 23    1er janvier 2025
Finlande   24 → 25,5  1er septembre 2024
```

Trois précautions restent structurelles :

1. Un recoupement contre des agrégateurs n'est pas une vérification contre la
   source officielle. Avant mise en production, la table doit être confirmée
   auprès de la Commission européenne et des administrations nationales,
   exactement comme les échéances de §25.1 (risques R3 et R7 du plan).
2. Chaque taux est chargé **avec sa fenêtre de validité**, jamais comme une
   valeur courante. Corriger un taux consiste à fermer la fenêtre en cours et
   à en ouvrir une nouvelle — jamais à écraser une valeur.
3. Seul le **taux standard** figure ici. Taux réduits, super-réduits et
   parking existent et dépendent de la nature du bien ou du service ; ils
   relèvent du paramétrage par produit, pas d'une table figée dans le code.

Ce que le système ne doit jamais faire : **déduire un régime du seul code
pays**. Le régime dépend du statut B2B/B2C, de la vérification du numéro, de
la nature de l'opération et du lieu de taxation. Un pays ne suffit pas, et
une table de taux n'est pas une règle.

---


# 26. Rétention

Cycle :

```text
ACTIVE
 ↓
CANCELLED
 ↓
RETENTION
 ↓
DELETION_SCHEDULED
 ↓
DELETED
```

La politique est configurable.

```text
retention_policy
- project_data
- media_data
- account_data
- billing_data
```

Les obligations légales de conservation sont traitées séparément.

---


# 26.1 RGPD / Protection des données personnelles

La plateforme doit être conçue **RGPD by design et by default**. La CNIL rappelle notamment les principes de minimisation, protection dès la conception et responsabilité démontrable. citeturn0search3

## Données concernées

Le système peut traiter :

- identité et coordonnées des utilisateurs ;
- comptes et authentification ;
- données de facturation ;
- données de paiement limitées aux informations nécessaires ;
- données de projets ;
- adresses et données géographiques ;
- photos/imports ;
- logs et données techniques ;
- données de support.

## Principes

- minimisation ;
- finalité documentée ;
- durée de conservation définie par catégorie ;
- droit d'accès ;
- rectification ;
- suppression lorsque légalement possible ;
- portabilité lorsque applicable ;
- opposition/restriction lorsque applicable ;
- sécurité ;
- traçabilité ;
- privacy by design.

## Registre des traitements

Prévoir un registre des traitements couvrant au minimum :

```text
Account
Authentication
Project
Billing
Payment
Marketing
Support
Analytics
Security logs
```

Chaque traitement doit définir :

```text
purpose
legal_basis
data_categories
retention_period
recipients
subprocessors
transfer_location
security_measures
```

## Responsable de traitement / sous-traitant

Pour les données traitées pour le compte d'un client B2B, le service peut agir comme sous-traitant. Les contrats doivent encadrer les obligations de l'article 28 du RGPD, notamment sécurité, assistance, traçabilité et sous-traitants ultérieurs. citeturn0search5turn0search11

Prévoir :

- DPA / accord de traitement des données ;
- liste des sous-traitants ;
- localisation des données ;
- mécanisme de gestion des transferts hors UE ;
- procédure de violation de données ;
- procédure de demande d'exercice des droits.

## Conservation

La suppression utilisateur et la suppression projet ne doivent pas supprimer aveuglément les données soumises à une obligation légale de conservation.

Architecture :

```text
User deletion request
        ↓
Privacy Service
        ├── personal data eligible for deletion
        ├── project data according to contract
        ├── security logs according to retention
        └── accounting records kept where legally required
```

## Sécurité

Prévoir :

- chiffrement TLS ;
- chiffrement au repos lorsque disponible ;
- hash sécurisé des mots de passe ;
- MFA éventuellement ;
- contrôle d'accès par tenant ;
- journaux d'audit ;
- sauvegardes sécurisées ;
- accès administrateur tracé ;
- suppression sécurisée ;
- procédure de gestion des incidents.

Les sous-traitants cloud doivent être évalués et contractuellement encadrés, notamment sur sécurité et localisation des données. citeturn0search6

## Cookies / analytics

Séparer :

```text
Essential
Analytics
Marketing
```

Les traceurs non nécessaires doivent être gérés selon les règles applicables, avec mécanisme de consentement lorsque requis.

## Privacy Center

Prévoir dans le compte utilisateur :

```text
Privacy
├── Personal data
├── Download my data
├── Delete account
├── Consent management
└── Privacy policy
```


# 27. Jobs

Les traitements longs sont asynchrones.

Exemples :

- photogrammétrie ;
- génération 3D ;
- export ;
- PDF ;
- import ;
- suppression ;
- emails.

API :

```text
POST /api/v1/jobs
GET  /api/v1/jobs/{id}
```

Statuts :

```text
QUEUED
RUNNING
SUCCEEDED
FAILED
CANCELLED
```

Les notifications — un événement, plusieurs canaux — sont spécifiées en
**§27.1** et livrées par cette file.

---

# 27.1 Notifications — un événement, plusieurs canaux

La plateforme doit prévenir : un paiement a échoué, un abonnement se termine,
un engagement arrive à son terme, un export est prêt. Le canal — écran, email,
SMS, WhatsApp — est un détail de livraison, jamais le sujet.

## Quatre objets à ne pas confondre

```text
Événement métier   ce qui s'est passé                payment.failed
Notification       l'intention d'informer quelqu'un  (destinataire, événement)
Livraison          une tentative sur un canal        avec son résultat
Message (§12.3)    une conversation entre humains    ce n'est pas une notification
```

**Une notification n'est pas un message.** Une conversation est un échange :
il a des participants, un ordre, un filigrane de lecture, et quelqu'un
répond. Une notification est un sens unique : la plateforme informe, personne
ne répond. Les confondre remplirait les fils de support de bruit système, et
donnerait à un message humain le sort d'une notification désactivable.

C'est la même règle que §12.2 tient entre rôle plateforme et appartenance
tenant : deux axes qui ne se convertissent jamais l'un dans l'autre.

## Les canaux sont des adaptateurs derrière un port

```text
Notifier  (port, dans le domaine)
├── ScreenChannel     dans l'application, lu par l'API, aucun tiers
├── EmailChannel      fournisseur SMTP / API
├── SmsChannel        fournisseur SMS
└── WhatsAppChannel   fournisseur WhatsApp Business
```

Aucun nom de fournisseur dans le domaine — c'est la règle déjà appliquée à
`PaymentProvider` (§24), `EInvoiceProvider` (§25.1), `StorageProvider` (§15)
et `VatNumberValidator` (§25.3). Un cinquième port, la même discipline :
changer de routeur SMS est un changement de câblage, pas de code métier.

Le canal écran est le seul qui ne sorte pas de la plateforme. C'est aussi
celui qui n'échoue pas, ce qui en fait le repli naturel quand tous les autres
sont refusés ou impossibles.

## Modèle

```text
notifications
├── id
├── tenant_id / product_id        contexte racine (§12.1), comme tout le reste
├── recipient_user_id
├── type                          payment.failed, subscription.ending, ...
├── category                      BILLING | ACCOUNT | SECURITY | SUPPORT | MARKETING
├── payload            jsonb      les données du gabarit, pas le texte rendu
├── dedup_key                     ce qui empêche la rafale
├── created_at
└── read_at                       canal écran uniquement

notification_deliveries
├── notification_id
├── channel                       SCREEN | EMAIL | SMS | WHATSAPP
├── status                        PENDING | SENT | DELIVERED | FAILED | SUPPRESSED
├── suppression_reason            NO_CONSENT | OPTED_OUT | NO_ADDRESS
├── provider_message_id           identifiant rendu par le fournisseur
├── attempts
├── failure_reason                classe d'erreur, jamais le message brut (§31)
├── rendered_body                 conservé pour les notifications à effet juridique
└── sent_at / delivered_at

notification_preferences
├── user_id / product_id
├── category / channel
└── enabled

notification_consents             SMS, WhatsApp, prospection
├── user_id / channel / purpose
├── granted_at / revoked_at
├── source                        d'où vient le consentement
└── evidence          jsonb       la preuve, datée
```

## Règles

**Un événement, plusieurs livraisons.** La notification porte l'intention ;
chaque canal porte son propre état. Un SMS qui échoue ne doit pas faire
disparaître l'email qui a réussi, ni l'inverse.

**L'envoi passe par la file (§27).** Envoyer dans la requête HTTP ferait d'un
fournisseur SMS lent une API lente, et d'un fournisseur en panne une API en
panne. Le paiement, l'abonnement et l'export produisent la notification dans
leur transaction ; la file la livre après.

**Exactement-une-fois est un index unique, jamais un contrôle.** ADR-027 exige
des handlers idempotents, parce qu'un bail expiré pendant qu'un job travaille
encore fait terminer les deux exemplaires. Pour une notification, un doublon
n'est pas un détail : c'est un SMS payé deux fois et un destinataire agacé.
D'où :

```sql
UNIQUE (notification_id, channel)
```

Un contrôle « a-t-on déjà envoyé ? » se perd dans la course entre deux
passages du runner. L'index, non.

**Le contenu est rendu à l'envoi, à partir de données.** La notification
stocke le `payload`, pas la phrase : la langue du destinataire, le gabarit et
le format dépendent du canal et du moment. **Exception : ce qui a un effet
juridique conserve son texte rendu** — préavis de reconduction (§13.1), mise
en demeure, avis de suspension. Pour la même raison qu'une facture garde son
snapshot : ce qui pourra être opposé doit être relisible tel qu'il a été
envoyé.

**Consentement : le SMS et WhatsApp ne sont pas l'email.** Ces canaux
supposent une adhésion préalable, prouvable et révocable, et la distinction
entre message transactionnel et prospection est celle du §26.1. La règle est
fermée par défaut : **sans consentement enregistré, la livraison n'est pas
tentée** — elle est inscrite `SUPPRESSED` avec son motif. C'est le même
principe que VIES injoignable qui n'accorde pas l'autoliquidation (§25.3) et
qu'un secret absent qui ne valide aucun lien signé (§31).

**Une suppression est un résultat, pas un silence.** Ne rien écrire quand une
notification n'est pas envoyée rend « l'avons-nous prévenu ? » sans réponse —
précisément la question qu'un préavis de reconduction doit pouvoir trancher.

**La catégorie `SECURITY` ne se désactive pas.** Changement de mot de passe,
nouvelle connexion, accès du personnel plateforme à des données du tenant
(§12.2) : une notification de sécurité que le destinataire peut couper est
une notification qu'un attaquant peut couper. C'est le pendant du
non-négociable #21 — un accès staff n'est jamais silencieux.

**Jamais de secret ni de donnée de paiement dans une notification.** Ni jeton,
ni mot de passe, ni numéro de carte, ni trace d'exception (§24, §31). Un canal
sort de la plateforme et se stocke chez des tiers : ce qui y entre est
public au sens du risque.

**Une rafale n'est pas une information.** `dedup_key` regroupe ce qui
mériterait un seul avis : trois échecs de paiement le même jour préviennent
une fois, pas trois.

## Les cas, tels qu'ils existent déjà dans la plateforme

| Événement | Catégorie | Canaux typiques | Source |
|---|---|---|---|
| `payment.failed` | BILLING | écran, email | §24 |
| `payment.succeeded` | BILLING | écran, email | §24 |
| `invoice.issued` | BILLING | email | §25 |
| `subscription.activated` | BILLING | écran, email | §13.1 |
| `subscription.ending` | BILLING | écran, email, SMS | §13.1 |
| `subscription.renewing` | BILLING | email | §13.1 — préavis de reconduction |
| `subscription.commitment_ending` | BILLING | écran, email | §13.1 |
| `quote.expiring` | BILLING | email | §25.2 |
| `order.awaiting_payment` | BILLING | email | §24 |
| `message.unread` | SUPPORT | email | §12.3 |
| `export.ready` | ACCOUNT | écran, email | §15 |
| `security.sign_in` | SECURITY | email | non désactivable |
| `security.staff_access` | SECURITY | écran, email | §12.2, non-négociable #21 |

La liste est ouverte ; la forme ne l'est pas. Un nouveau type est une ligne
de catalogue et un gabarit, jamais un chemin d'envoi de plus.

## API

```text
GET    /api/v1/notifications                  le canal écran
GET    /api/v1/notifications/unread-count
POST   /api/v1/notifications/{id}/read
POST   /api/v1/notifications/read-all
GET    /api/v1/notifications/preferences
PUT    /api/v1/notifications/preferences
POST   /api/v1/notifications/consents         adhésion SMS / WhatsApp
DELETE /api/v1/notifications/consents/{id}    révocation
```

Une préférence porte sur un couple (catégorie, canal) : « les avis de
facturation par email oui, par SMS non » est une réponse légitime, « plus
rien du tout » n'en est pas une pour la catégorie `SECURITY`.

L'état de lecture du canal écran est un `read_at` par notification, et non un
filigrane de flux comme `last_read_seq` en messagerie (§12.3). La différence
est voulue : un fil se lit en avançant, une liste d'avis se traite un par un
et pas forcément dans l'ordre.

---

# 28. Qualité du code

La qualité est intégrée au build.

```text
ESLint
   ↓
Typecheck
   ↓
Unit tests
   ↓
Build
```

## ESLint

Contrôle :

- erreurs JS/TS ;
- hooks React ;
- imports ;
- code inutilisé ;
- règles d'architecture ;
- qualité.

## TypeScript

Commande :

```text
tsc --noEmit
```

Le build ne doit pas passer si le typecheck échoue.

## Prettier

Responsable du formatage.

## Vitest

Tests unitaires du Core en priorité.

---

# 29. Tests

## Unit tests

Priorité :

```text
Core
├── geometry
├── calculations
├── rules
├── commands
└── serialization
```

## Integration tests

```text
API
Database
Authentication
Tenant isolation
Billing
```

## End-to-end

Playwright :

```text
login
 ↓
create project
 ↓
load parcel
 ↓
create terrace
 ↓
edit
 ↓
3D
 ↓
save
```

---

# 30. Observabilité

Prévoir :

```text
logs
error tracking
audit logs
request IDs
job logs
payment events
```

Les événements importants doivent pouvoir être corrélés avec :

```text
tenant_id
user_id
project_id
request_id
```

Sentry ou équivalent peut être utilisé côté frontend/backend.

---

# 31. Sécurité

Minimum :

- HTTPS ;
- Argon2id ;
- validation serveur ;
- Zod côté frontend ;
- CORS strict ;
- rate limiting ;
- contrôle tenant ;
- contrôle entitlement ;
- audit ;
- secrets hors Git ;
- sauvegardes ;
- validation uploads ;
- accès privé aux assets ;
- URLs signées si Object Storage privé.

---

# 32. CI/CD

Pipeline recommandé :

```text
commit
  ↓
install
  ↓
lint
  ↓
typecheck
  ↓
unit tests
  ↓
integration tests
  ↓
build
  ↓
E2E
  ↓
deploy
```

Le frontend produit :

```text
dist/
```

Le backend PHP est déployé séparément.

---

# 33. Déploiement initial

## SiteGround

Possible pour :

```text
React/Vite static build
PHP API
PostgreSQL
```

Architecture :

```text
SiteGround
├── React dist/
├── PHP API
└── PostgreSQL
```

Les capacités exactes de PostgreSQL, PHP et des jobs doivent être vérifiées sur l'offre choisie.

## Évolution

```text
Phase 1
SiteGround
       ↓
Phase 2
Backend / DB séparés
       ↓
Phase 3
Workers + Geo Service + Object Storage managé
```

Ne pas commencer par des microservices.

---

# 34. Structure frontend finale

```text
frontend/
├── public/
├── src/
│   ├── app/
│   │   ├── router/
│   │   └── providers/
│   │
│   ├── core/
│   │   ├── model/
│   │   ├── geometry/
│   │   ├── rules/
│   │   ├── calculations/
│   │   ├── commands/
│   │   └── serialization/
│   │
│   ├── features/
│   │   ├── project/
│   │   ├── parcel/
│   │   ├── house/
│   │   ├── terrace/
│   │   ├── objects/
│   │   ├── measurements/
│   │   └── photogrammetry/
│   │
│   ├── ui/
│   ├── state/
│   ├── queries/
│   ├── api/
│   ├── 3d/
│   └── utils/
│
├── package.json
├── tsconfig.json
├── vite.config.ts
└── eslint.config.js
```

---

# 35. Scripts npm

```json
{
  "scripts": {
    "dev": "vite",
    "lint": "eslint .",
    "lint:fix": "eslint . --fix",
    "typecheck": "tsc --noEmit",
    "test": "vitest run",
    "test:watch": "vitest",
    "e2e": "playwright test",
    "build": "npm run lint && npm run typecheck && npm run test && vite build",
    "preview": "vite preview"
  }
}
```

---



# Developer Experience & AI-Assisted Engineering

## Objectif

Le projet est conçu pour être développé et maintenu avec une approche **AI-assisted engineering**, notamment avec Claude Code. Claude Code est un **outil de développement** et ne constitue jamais une dépendance runtime de l'application.

Les règles importantes du projet doivent être persistées dans le repository, les tests, les contrôles d'architecture et la CI. Elles ne doivent pas dépendre uniquement du contexte d'une conversation.

## Environnement Claude Code

```text
CLAUDE.md
.claude/
├── settings.json
├── commands/
├── skills/
└── agents/

scripts/
├── setup
├── dev
├── test
├── test-unit
├── test-integration
├── test-e2e
├── lint
├── typecheck
├── architecture
├── quality
└── build
```

## CLAUDE.md

`CLAUDE.md` constitue le guide permanent du projet pour l'agent. Il doit documenter notamment les règles d'architecture, les contraintes techniques, les commandes de validation et les interdictions structurantes.

Règles minimales :

```text
React + TypeScript + Vite
Core métier indépendant de React
Zustand = client state
TanStack Query = server state
PHP = backend/API
PostgreSQL = persistence
No Symfony
No PostGIS
TypeScript strict
No business logic in React
No SQL direct dans les controllers
Tenant isolation obligatoire
Business rules covered by tests
```

## Skills et agents

Prévoir des skills spécialisés :

```text
.claude/skills/
├── architecture/
├── typescript/
├── react/
├── php/
├── testing/
├── database/
├── security/
├── billing/
└── legacy-migration/
```

Limiter les agents spécialisés à quelques responsabilités claires : Architecture, Core/TypeScript, React, PHP/API, Test/Quality et Security.

## Commands, hooks et MCP

Les commandes reproductibles doivent couvrir `/test`, `/quality`, `/architecture`, `/migrate`, `/review` et `/build`. Les hooks peuvent déclencher typecheck, lint, architecture checks et tests ciblés, sans remplacer la CI.

MCP peut connecter Claude Code à Git, Browser/Playwright, PostgreSQL et la documentation. Les accès suivent le principe du moindre privilège : lecture par défaut, écriture seulement lorsque nécessaire et opérations destructrices protégées.

## Boucle AI-assisted engineering

```text
Requirement
    ↓
Repository investigation
    ↓
Architecture impact analysis
    ↓
Test / characterization test
    ↓
Implementation
    ↓
Typecheck + Lint + Architecture checks
    ↓
Tests
    ↓
Review
    ↓
Build
```

Pour la migration de `legacy.ts`, les characterization tests capturent le comportement existant avant extraction.

## Source of truth et garde-fous

Une règle importante ne doit pas exister uniquement dans un prompt conversationnel. Claude Code doit inspecter le code avant une modification importante, rechercher les usages avant suppression, respecter les règles d'architecture, ne pas contourner les tests et ajouter les tests lorsque le comportement change.

## Principe directeur

> **Claude Code accélère l'ingénierie ; il ne remplace ni l'architecture, ni les tests, ni les quality gates.**

# 37. Quality Engineering & Architecture Governance

La qualité est un **quality gate obligatoire** de la V2. Le code ne doit pas être considéré comme livrable uniquement parce qu'il compile.

La stratégie combine :

```text
Static Analysis
      +
Type Safety
      +
Architecture Rules
      +
Unit Tests
      +
Integration Tests
      +
E2E
      +
Coverage
      +
Build
```

## 37.1 Outillage

### Frontend / Core TypeScript

| Besoin | Outil |
|---|---|
| Type checking | TypeScript (`tsc --noEmit`) |
| Lint | ESLint |
| Formatting | Prettier |
| Unit tests | Vitest |
| React tests | React Testing Library |
| E2E | Playwright |
| Architecture dependencies | dependency-cruiser |
| Coverage | Vitest coverage + CI reporting |

### Backend PHP

| Besoin | Outil |
|---|---|
| Tests | PHPUnit |
| Static analysis | PHPStan |
| Coding standards | PHP-CS-Fixer |
| Architecture | Deptrac |
| Coverage | PHPUnit / PCOV ou Xdebug |
| API contract | OpenAPI |

### Reporting qualité

Selon l'infrastructure retenue :

```text
Codecov
ou
SonarQube / SonarCloud
```

Le reporting doit conserver l'historique de :

- couverture ;
- bugs ;
- code smells ;
- duplications ;
- dette technique ;
- violations d'architecture.

---

# 37.2 Pyramide de tests

```text
                    E2E
                 Playwright
                    ▲
                    │
             Integration
          API / PostgreSQL
                    ▲
                    │
              Component
        React Testing Library
                    ▲
                    │
                 Unit
          Vitest / PHPUnit
                    ▲
                    │
            Static Analysis
 ESLint / TypeScript / PHPStan
```

La majorité des tests doit être constituée de tests unitaires rapides.

Les E2E sont réservés aux parcours métier critiques.

---

# 37.3 Priorité de couverture

Les seuils sont différenciés selon le risque.

```text
Core Geometry / Rules / Calculations     ≥ 90 %
Core Commands                            ≥ 90 %
Serialization / migrations               ≥ 90 %
API application services                 ≥ 80 %
Repositories                             ≥ 80 %
Billing / Payment / Entitlements         ≥ 90 %
Tenant isolation / Security              ≥ 90 %
React components                          ≥ 70 %
E2E parcours critiques                    100 %
```

Ces seuils sont des **quality gates minimaux** et peuvent être augmentés.

Une baisse de couverture sur une Pull Request doit être détectée par le CI.

## Coverage différentielle

Le CI doit également contrôler la couverture du code nouvellement modifié.

Principe :

```text
Existing code
      +
New / changed code
      ↓
Coverage threshold
```

Une nouvelle fonctionnalité ne doit pas diminuer progressivement la qualité globale.

---

# 37.4 Ce qui doit obligatoirement être testé

## Core métier

Tester systématiquement :

- géométrie ;
- intersections ;
- surfaces ;
- distances ;
- transformations ;
- contraintes ;
- règles PLU lorsqu'elles sont implémentées ;
- calculs d'ombres ;
- optimisation ;
- commandes ;
- undo/redo ;
- sérialisation ;
- migration de versions.

Exemple :

```text
Terrace
   ↓
Resize
   ↓
Geometry recalculation
   ↓
Constraint validation
   ↓
Expected result
```

## SaaS

Tester :

- création tenant ;
- isolation tenant ;
- rôles ;
- permissions ;
- quotas ;
- features ;
- abonnement ;
- activation ;
- expiration ;
- renouvellement ;
- upgrade ;
- downgrade ;
- annulation.

## Billing

Tester :

```text
Quote
 ↓
Order
 ↓
Invoice
 ↓
Payment
 ↓
Activation
```

et les cas d'échec :

```text
Payment failed
Webhook duplicated
Webhook delayed
Refund
Chargeback
Invoice rejected
```

## Messagerie & accès staff (§12.2, §12.3)

Tester l'isolation avant la fonctionnalité :

```text
Un membre ne lit pas la conversation d'un autre tenant
Un membre ne lit pas la conversation d'un autre produit du même tenant
Écrire dans un fil dont on n'est pas participant est refusé
Un STAFF ne peut pas rejoindre une conversation INTERNAL
Un rôle plateforme n'ouvre aucune route tenant
Une appartenance TENANT_ADMIN n'ouvre aucune route staff
Tout accès staff écrit sa ligne d'audit
```

puis le comportement :

```text
Le filigrane de lecture ne recule jamais
since_seq ne rend que ce qui a suivi
Un message supprimé perd son corps, le fil garde son ordre
```

## Fiscalité / TVA (§25.3)

Tester :

```text
B2C national            → taux du pays, régime STANDARD
B2B intra-UE vérifié    → 0, REVERSE_CHARGE, mention obligatoire
B2B intra-UE non vérifié→ pas d'autoliquidation par défaut
B2C intra-UE            → taux du pays du client (OSS)
Export hors UE          → hors champ
```

et les invariants qui protègent l'historique :

```text
Un changement de taux ne déplace aucune TVA déjà facturée
Une facture rejouée deux ans plus tard rend le même chiffre
La somme des VATTransaction d'une facture = la TVA de cette facture
Une période close ne se modifie pas : la correction va dans la suivante
VIES indisponible n'accorde pas l'autoliquidation
```

## Abonnements — durée et engagement (§13.1)

Tester ce que l'engagement doit refuser, avant ce qu'il autorise :

```text
Résiliation sous engagement, politique FORBIDDEN   → refusée, l'abonnement reste actif
Résiliation sous engagement, AT_COMMITMENT_END     → acceptée, effet à la fin de l'engagement
Résiliation hors engagement                        → effet à la fin de la période payée
Résiliation le jour 2 d'un mois payé               → le service reste dû jusqu'à la fin du mois
Sortie anticipée CHARGE_REMAINING                  → facture les périodes restantes, par la chaîne normale
```

et les invariants du contrat :

```text
24 mois payés mensuellement = un engagement, pas 24 abonnements d'un mois
Une offre re-tarifée ne change aucune condition déjà souscrite
Un abonnement dont la période est passée n'entitle plus, balayage ou non
La reconduction ne réarme pas l'engagement sauf stipulation de l'offre
Deux souscriptions simultanées : l'index tranche, une seule passe
Un souscripteur USER hors du tenant est refusé
Un abonnement USER n'entitle que cette personne, pas tout le tenant
```

## Notifications (§27.1)

Tester d'abord ce qui ne doit pas partir :

```text
SMS sans consentement            → SUPPRESSED avec motif, aucun envoi tenté
Consentement révoqué             → SUPPRESSED, pas « envoyé quand même »
Préférence coupée sur un canal   → SUPPRESSED sur ce canal, livré sur les autres
Catégorie SECURITY               → livrée même préférence coupée
Aucun secret, aucune donnée de paiement, aucune trace d'exception dans un payload
```

puis ce qui protège le destinataire et la facture du fournisseur :

```text
Job rejoué après bail expiré     → l'index (notification_id, channel) empêche le doublon
Un canal en échec                → n'empêche pas les autres livraisons
Rafale du même événement         → dedup_key : un avis, pas trois
Préavis de reconduction          → sa tentative et son texte rendu sont conservés
Une notification n'est pas un message : elle n'entre dans aucune conversation
```

---

# 37.5 Tests d'architecture TypeScript

`dependency-cruiser` doit contrôler les dépendances entre couches.

Architecture autorisée :

```text
app
 ↓
features
 ↓
core
```

et :

```text
features
 ↓
api / queries
```

Interdictions :

```text
core → React             ❌
core → Zustand           ❌
core → TanStack Query    ❌
core → Three.js          ❌

model → UI               ❌
geometry → UI            ❌
rules → UI               ❌
```

Les règles sont exécutées automatiquement en CI.

---

# 37.6 Tests d'architecture PHP

`Deptrac` contrôle les dépendances entre modules.

Architecture :

```text
HTTP / Controllers
        ↓
Application Services
        ↓
Domain
        ↓
Repositories
        ↓
Infrastructure / Database
```

Interdictions :

```text
Repository → Controller       ❌
Domain → HTTP                 ❌
Domain → SQL                  ❌
Controller → SQL direct       ❌
```

Les modules doivent également respecter l'isolation tenant.

---

# 37.7 Tests React

Utiliser React Testing Library pour tester le comportement utilisateur plutôt que les détails internes.

Tester par exemple :

```text
select object
      ↓
property panel opens
      ↓
change dimension
      ↓
Core command
      ↓
UI updates
```

Éviter les tests excessivement couplés à :

- structure HTML exacte ;
- classes CSS ;
- détails internes des hooks ;
- implementation details.

---

# 37.8 Tests E2E Playwright

Les parcours critiques doivent être automatisés.

### Parcours utilisateur

```text
Login
 ↓
Create project
 ↓
Load parcel
 ↓
Create terrace
 ↓
Edit dimensions
 ↓
Switch 2D / 3D
 ↓
Save
 ↓
Reload
 ↓
Verify project
```

### Parcours SaaS

```text
Signup
 ↓
Choose offer
 ↓
Checkout
 ↓
Payment confirmation
 ↓
Activation
 ↓
Feature entitlement
```

### Parcours B2B

```text
Create tenant
 ↓
Invite user
 ↓
Assign role
 ↓
Create project
 ↓
Verify tenant isolation
```

Les tests E2E doivent couvrir les scénarios critiques, pas toutes les combinaisons possibles.

---

# 37.9 Tests API

Les contrats OpenAPI servent de référence.

Tester :

```text
Request
 ↓
Authentication
 ↓
Authorization
 ↓
Validation
 ↓
Service
 ↓
Database
 ↓
Response
```

Cas obligatoires :

- 200/201 ;
- 400 ;
- 401 ;
- 403 ;
- 404 ;
- 409 ;
- 422 ;
- 429 ;
- 500 contrôlé.

Les réponses doivent respecter le contrat OpenAPI.

---

# 37.10 Tests de sécurité

Tester automatiquement :

### Tenant isolation

```text
Tenant A
   X
   ↓
Project Tenant B
```

Résultat attendu :

```text
403 / 404
```

jamais accès aux données.

### Authorization

Tester chaque rôle :

```text
SUPER_ADMIN
FINANCE_ADMIN
SALES_ADMIN
TENANT_ADMIN
USER
```

### Authentication

Tester :

- session expirée ;
- token invalide ;
- brute force / rate limiting ;
- reset password ;
- changement de mot de passe ;
- logout.

---

# 37.11 Tests de régression

Chaque bug métier corrigé doit produire un test de régression.

Principe :

```text
Bug
 ↓
Reproduction
 ↓
Test failing
 ↓
Fix
 ↓
Test passing
```

Le test reste ensuite dans la suite permanente.

Cela est particulièrement important pendant la migration de `legacy.ts`.

---

# 37.12 Contract tests frontend / backend

Le contrat API est partagé.

```text
OpenAPI
   ↓
PHP API
   +
TypeScript client/types
```

Objectif :

> empêcher qu'une modification PHP casse silencieusement React.

Les changements incompatibles d'API doivent être détectés avant déploiement.

---

# 37.13 CI Quality Gates

Une Pull Request doit passer :

```text
1. Install dependencies
2. ESLint
3. Prettier check
4. Typecheck
5. dependency-cruiser
6. PHPStan
7. PHP-CS-Fixer check
8. Deptrac
9. Vitest
10. PHPUnit
11. Coverage
12. Integration tests
13. Playwright
14. Build
```

Si une étape échoue :

```text
PR = NOT READY
```

Le déploiement automatique est bloqué.

---

# 37.14 Build de production

Le build final est :

```text
lint
 ↓
typecheck
 ↓
architecture checks
 ↓
unit tests
 ↓
integration tests
 ↓
coverage
 ↓
E2E
 ↓
build
 ↓
deploy
```

Le `dist/` React ne doit être généré comme artefact de production qu'après validation des quality gates.

---

# 37.15 Branch / PR strategy

Chaque fonctionnalité doit être développée dans une branche courte.

```text
feature/terrace-editor
feature/billing
fix/geometry-intersection
```

Une Pull Request doit contenir :

```text
Code
+
Tests
+
Impact architecture
+
Migration éventuelle
```

Une modification du Core métier sans test associé doit être considérée comme exceptionnelle.

---

# 37.16 Definition of Done

Une fonctionnalité est terminée uniquement si :

- code TypeScript/PHP typé ;
- ESLint sans erreur ;
- formatage validé ;
- architecture respectée ;
- tests unitaires ajoutés ;
- tests d'intégration ajoutés si nécessaire ;
- E2E ajouté si parcours critique ;
- couverture respectée ;
- API OpenAPI mise à jour ;
- migrations documentées ;
- sécurité/tenant isolation vérifiée ;
- documentation mise à jour.

---

# 37.17 Suivi de la dette technique

Le CI et le reporting doivent permettre de suivre :

```text
Technical Debt
├── TypeScript errors
├── ESLint violations
├── PHPStan issues
├── Architecture violations
├── Test coverage
├── Duplications
├── TODO/FIXME
└── Legacy dependencies
```

Objectif :

> La dette technique doit être mesurée et visible, pas seulement ressentie.

---

# 37.18 Quality Dashboard

Le dashboard technique peut exposer :

```text
Build status             ✓
Typecheck                ✓
Architecture             ✓
Unit tests               1,248
Coverage                 88 %
E2E                      142
Critical failures        0
Architecture violations  0
Security alerts          0
Technical debt           ...
```

Pour l'administration produit, ces métriques sont séparées du dashboard financier.

---

# 37.19 Stratégie de migration du legacy

La migration ne doit pas être un "big bang".

```text
legacy.ts
   ↓
Identify domain
   ↓
Extract Core TS
   ↓
Write characterization tests
   ↓
Move logic
   ↓
Typecheck
   ↓
Architecture check
   ↓
Remove legacy dependency
```

Avant de déplacer une logique complexe du legacy, créer des **characterization tests** afin de capturer le comportement existant.

Puis :

```text
Legacy behavior
       ↓
Tests
       ↓
New Core implementation
       ↓
Same expected results
```

Cette stratégie réduit fortement le risque de régression fonctionnelle.

---

# 37.20 Principe directeur

> **Chaque règle métier importante doit être testable sans React, chaque dépendance d'architecture doit être vérifiable automatiquement, et chaque régression corrigée doit devenir un test permanent.**


# 38. Product Usage, Observability & Performance

## 38.1 Objectifs

L'observabilité doit permettre de répondre à quatre questions :

```text
1. Qui utilise le produit ?
2. Quelles fonctionnalités sont utilisées ?
3. Le produit est-il performant ?
4. Où se trouvent les problèmes ?
```

La mesure d'usage et la mesure technique sont volontairement séparées.

```text
Product Analytics
      +
Technical Observability
      +
Business / SaaS Metrics
```

## 38.2 Outillage cible

| Domaine | Outil recommandé | Usage |
|---|---|---|
| Product analytics | PostHog | événements, funnels, adoption, features |
| Frontend monitoring | Sentry | erreurs, Web Vitals, traces |
| Distributed tracing | OpenTelemetry | traces frontend/backend et corrélation |
| Backend | PHP metrics + logs | latence, erreurs, throughput |
| Database | PostgreSQL metrics | requêtes, connexions, taille, performance |
| Business | PostgreSQL | ventes, abonnements, tenants, quotas |
| Reporting | Admin React | dashboard consolidé |

Les outils externes ne doivent pas devenir la source de vérité des données métier.

## 38.3 Product Usage

Les événements d'usage doivent permettre de mesurer :

```text
sessions
active users
active tenants
projects created
projects opened
projects modified
exports
3D views
calculations
optimizations
photogrammetry jobs
API usage
storage usage
```

Mesurer également l'adoption par offre :

```text
FREE
PRO
BUSINESS
ENTERPRISE
```

## 38.4 Usage par tenant

Le système doit permettre une vue :

```text
Tenant
├── users
├── active users
├── projects
├── active projects
├── storage
├── API calls
├── feature usage
├── calculations
├── exports
├── last activity
└── subscription
```

Cette vue sert notamment à :

- suivre l'adoption ;
- contrôler les quotas ;
- détecter les anomalies ;
- identifier les fonctionnalités à forte valeur ;
- préparer les évolutions d'offres ;
- déclencher des alertes d'usage ou d'upsell.

## 38.5 Usage Events

Ajouter un modèle d'événements :

```text
usage_events
----------------
id
tenant_id
user_id
project_id
event_type
feature
timestamp
duration_ms
application_version
metadata
```

Exemples :

```text
PROJECT_OPENED
TERRACE_CREATED
GEOMETRY_CALCULATED
3D_VIEW_OPENED
PHOTOGRAMMETRY_STARTED
EXPORT_GENERATED
QUOTE_CREATED
SUBSCRIPTION_ACTIVATED
```

Les événements doivent rester minimaux et ne pas contenir de données personnelles ou métier inutiles.

## 38.6 Performance

Mesurer séparément :

### Frontend

```text
FCP
LCP
INP
CLS
JS errors
bundle size
route load time
API latency
```

### Core métier

```text
geometry calculation
constraint validation
3D preparation
optimization
photogrammetry
export
serialization
```

Chaque opération lourde doit pouvoir être chronométrée.

Exemple :

```text
GEOMETRY_CALCULATION
duration_ms = 184
```

### Backend

```text
request latency
p50
p95
p99
throughput
error rate
PHP execution time
database latency
```

### Infrastructure

Suivre lorsque disponible :

```text
CPU
memory
disk
network
HTTP errors
database connections
storage
```

## 38.7 Performance Budgets

Définir des objectifs mesurables, à confirmer par benchmark :

```text
API p95                 < 500 ms
API p99                 < 1.5 s

Core calculation        < 100 ms
Interactive UI          < 100 ms

Project load            < 2 s
Initial application     < 3 s

Critical E2E            < 5 s
```

Les budgets sont suivis dans le temps et peuvent devenir des quality gates.

## 38.8 Sentry

Sentry est utilisé pour :

- exceptions frontend ;
- exceptions backend ;
- erreurs API ;
- traces ;
- Web Vitals ;
- régressions de performance ;
- corrélation avec la version applicative.

Chaque erreur doit pouvoir être reliée à :

```text
application version
environment
route
request ID
tenant pseudonymisé
user pseudonymisé lorsque nécessaire
```

Ne jamais envoyer le contenu complet d'un projet dans les événements Sentry.

## 38.9 OpenTelemetry

OpenTelemetry fournit une instrumentation standardisée pour les traces.

Exemple :

```text
User action
   ↓
React
   ↓
HTTP request
   ↓
PHP Controller
   ↓
Service
   ↓
PostgreSQL
```

Une trace permet alors d'identifier :

```text
Frontend       120 ms
API             80 ms
Service         20 ms
PostgreSQL      45 ms
Serialization   15 ms
```

L'objectif est d'identifier rapidement le composant responsable d'une dégradation.

## 38.10 Product Analytics et confidentialité

La télémétrie doit respecter le principe de minimisation.

À envoyer :

```text
✓ feature
✓ action
✓ durée
✓ version
✓ événement technique
✓ identifiant tenant pseudonymisé
```

À ne pas envoyer inutilement :

```text
✗ adresse client
✗ géométrie complète
✗ photos
✗ projet JSON complet
✗ données personnelles non nécessaires
```

Les données métier restent dans les systèmes de données de la plateforme.

## 38.11 Quotas et entitlements

L'usage doit être comparé aux droits de l'offre :

```text
Usage
  ↓
Entitlement
  ↓
Quota
  ├── < 80 % → normal
  ├── ≥ 80 % → warning
  ├── ≥ 90 % → notification / upsell
  └── ≥ 100 % → limitation selon règle d'offre
```

Le contrôle des quotas est réalisé côté backend.

React ne doit jamais être la seule autorité pour autoriser une fonctionnalité.

## 38.12 Alerting

Définir des alertes sur :

```text
API error rate
API p95
API p99
database latency
payment failures
e-invoice failures
queue/job failures
storage limits
quota anomalies
security events
```

Les alertes critiques doivent être indépendantes du navigateur de l'administrateur.

## 38.13 Dashboard Admin

Ajouter une section :

```text
ADMIN
├── Financial
├── Sales
├── Tenants
├── Usage
├── Performance
├── Errors
├── Jobs
└── Security
```

### Usage dashboard

```text
DAU
WAU
MAU
Active Tenants
Projects / tenant
Feature adoption
API usage
Storage
Exports
Calculations
```

### Performance dashboard

```text
API p50 / p95 / p99
Core calculation time
Page load
Web Vitals
Error rate
Database latency
Slow endpoints
```

## 38.14 Corrélation usage / performance

Le système doit permettre de rechercher une dégradation par :

```text
tenant
feature
version
endpoint
project size
operation
time period
```

Exemple :

```text
Photogrammetry
    ↓
Project > 200 MB
    ↓
Duration p95 = 8.2 s
    ↓
Version 2.4.0
```

Cette corrélation est essentielle pour optimiser le produit sans se limiter à des moyennes globales.


# 40. Architecture Decision Records

## ADR-000 — PHP sans framework

**Décision:** PHP 8.3+ + Composer, sans framework applicatif monolithique (Laravel/Symfony full-stack). Le backend est un **modular monolith** utilisant des composants open source indépendants lorsque nécessaire.

**Raison:** conserver un backend léger, maîtrisé et portable sans réimplémenter les briques d'infrastructure éprouvées.

Composants de référence :

- FastRoute pour le routing ;
- PSR-7 / PSR-15 pour les contrats HTTP et middleware ;
- PHP-DI ou équivalent pour l'injection de dépendances ;
- Doctrine DBAL et Doctrine ORM lorsque l'ORM est justifié ;
- Symfony Validator / Serializer / Cache / Console lorsque leurs composants sont pertinents ;
- Monolog pour les logs ;
- PHPUnit pour les tests ;
- PHPStan pour l'analyse statique.

La règle est de **réutiliser des composants éprouvés plutôt que de développer une plomberie propriétaire**. Aucun composant ne doit imposer une dépendance au domaine métier.

**Raison:** conserver un backend léger, maîtrisé et adapté à l'hébergement PHP, tout en structurant le code par modules, services, repositories et middleware.

Le backend est un modular monolith et non un ensemble de scripts PHP.

---

## ADR-001 — React

**Décision:** React.

**Raison:** excellente capacité à construire une UX riche et modulaire, adaptée au workspace 2D/3D.

---

## ADR-002 — TypeScript

**Décision:** TypeScript strict.

**Raison:** modèle métier complexe, géométrie, API et nombreuses structures de données.

---

## ADR-003 — Vite

**Décision:** Vite.

**Raison:** build rapide, configuration simple, adapté à une SPA React.

---

## ADR-004 — Core indépendant

**Décision:** Core TypeScript sans dépendance React.

**Raison:** réutilisabilité, testabilité et protection contre un nouveau monolithe frontend.

---

## ADR-005 — Zustand

**Décision:** Zustand pour client state.

**Raison:** simple, léger, adapté aux interactions d'un workspace.

---

## ADR-006 — TanStack Query

**Décision:** TanStack Query pour server state.

**Raison:** cache, mutations, synchronisation et gestion du cycle de vie des données API.

**Conséquence:** TanStack Query n'atteint l'API qu'à travers le client
TypeScript généré depuis OpenAPI (§8.1). Une `queryFn` qui appelle `fetch()`
directement recrée un contrat à la main à côté de celui qui fait autorité, et
c'est ce second contrat qui dérivera.

---

## ADR-007 — Zod

**Décision:** Zod aux frontières API.

**Raison:** validation runtime des données externes.

---

## ADR-008 — OpenAPI

**Décision:** API documentée par OpenAPI.

**Raison:** contrat explicite frontend/backend.

---

## ADR-009 — PostgreSQL JSONB

**Décision:** projet JSON versionné en JSONB.

**Raison:** transactions, versioning, recherche et cohérence.

---

## ADR-010 — Object Storage

**Décision:** fichiers lourds hors PostgreSQL.

**Raison:** photos, GLB, textures et exports ne doivent pas être embarqués dans les documents JSON.

---

## ADR-011 — PostgreSQL

**Décision:** ne pas rendre PostgreSQL obligatoire en phase 1.

**Raison:** compatibilité SiteGround et simplicité initiale. Ajouter un Geo Service si les besoins spatiaux serveur deviennent importants.

---

## ADR-012 — Modular Monolith

**Décision:** modular monolith PHP avant microservices.

**Raison:** complexité opérationnelle plus faible et frontières de modules déjà définies.

---

# 37. Principes non négociables

1. **Core métier indépendant de React.**
2. **Aucune règle métier critique dans les composants React.**
3. **Zustand n'est pas le cache API.**
4. **TanStack Query n'est pas le moteur métier.**
5. **Three.js n'est pas la source de vérité du modèle.**
6. **PHP reste l'autorité pour sécurité, quotas, abonnement et droits.**
7. **Le frontend ne fait pas confiance aux données serveur sans validation adaptée.**
8. **Chaque donnée métier est isolée par tenant.**
9. **Les gros assets sont hors PostgreSQL.**
10. **Le projet possède un `schemaVersion`.**
11. **Lint + typecheck + tests doivent passer avant build.**
12. **Pas de nouveau `legacy.ts`.**
13. **Pas de microservices prématurés.**
14. **RGPD by design et by default.**
15. **La conservation légale prime sur une suppression RGPD lorsque la loi impose la conservation d'une donnée.**
16. **La facturation électronique doit être compatible avec les plateformes agréées et l'e-reporting français.**
17. **Le module de facturation ne doit pas dépendre d'un fournisseur de PDP unique.**
18. **Les données financières et commerciales sont historisées et auditables.**
19. **Le reporting financier admin est séparé des données visibles par un tenant.**
20. **La chaîne devis → vente → abonnement/commande → facture → paiement doit être traçable.**
21. **Tout accès du personnel plateforme à la donnée d'un tenant est tracé, motivé et jamais silencieux.**
22. **Un rôle plateforme n'accorde jamais une appartenance à un tenant, et réciproquement.**
23. **La périodicité de paiement d'un abonnement n'est pas sa durée d'engagement.**
24. **Aucun envoi SMS ou WhatsApp sans consentement prouvable et révocable, et aucune notification de sécurité désactivable.**
25. **OpenAPI est le contrat source du frontend : les types et le client API TypeScript en sont générés, TanStack Query ne consomme que ce client, et aucun `fetch()` ni DTO n'est écrit à la main (§8.1).**

---

# 38. Roadmap technique

## Phase 1 — Foundation

```text
Core TS
Typecheck
ESLint
Prettier
Vitest
React
Vite
Zustand
TanStack Query
Zod
```

## Phase 2 — UX shell

```text
AppShell
Toolbar
Canvas
Property Panel
2D
3D
Design System
```

## Phase 3 — Project engine

```text
Project model
Commands
Geometry
Serialization
Versioning
IndexedDB
Autosave
```

## Phase 4 — Backend

```text
PHP/PHP natif modulaire
Auth
Tenant
Project API
PostgreSQL
JSONB
OpenAPI
```

## Phase 5 — SaaS

```text
Plans
Features
Entitlements
Billing
Payment
Invoices
TVA
Activation
Retention
```

## Phase 6 — Data / 3D

```text
IGN
Cadastre
Terrain
PLU
Object Storage
Photogrammetry
3D export
```

## Phase 7 — Industrialisation

```text
CI/CD
E2E
Monitoring
Jobs
Workers
Geo Service
PostgreSQL si nécessaire
```

---

# 41.1 Structure PHP cible

```text
src/
├── Shared/
│   ├── Http/
│   ├── Database/
│   ├── Security/
│   ├── Validation/
│   └── Exceptions/
│
├── Auth/
│   ├── AuthController.php
│   ├── AuthService.php
│   └── AuthRepository.php
│
├── Tenant/
├── User/
├── Project/
├── Billing/
├── Payment/
├── Subscription/
├── Entitlement/
├── Invoice/
├── Skin/
├── Data/
└── Job/
```

Chaque module suit autant que possible :

```text
Module/
├── Controller/
├── Service/
├── Repository/
├── DTO/
├── Validator/
└── ...
```

La structure peut rester plus légère pour les petits modules.

# 42. Architecture cible résumée

```text
                        ┌───────────────┐
                        │     USER      │
                        └───────┬───────┘
                                │
                        React + TypeScript
                                │
              ┌─────────────────┼─────────────────┐
              │                 │                 │
          Zustand         TanStack Query      RHF + Zod
              │                 │                 │
              └─────────────────┼─────────────────┘
                                │
                         CORE TYPESCRIPT
                                │
             ┌──────────────────┼──────────────────┐
             │                  │                  │
           Model             Geometry             Rules
             │                  │                  │
             └──────────────────┼──────────────────┘
                                │
                     React Three Fiber
                                │
                             Three.js
                                │
                              HTTPS
                                │
                         PHP 8.3+ / Composer
                                │
        ┌───────────────────────┼────────────────────────┐
        │                       │                        │
    PostgreSQL              Object Storage          External APIs
      + JSONB               photos / GLB            IGN / PSP
        │
   PostgreSQL
```

# 43. Conclusion

Cette architecture permet de faire de la V2 une véritable plateforme SaaS plutôt qu'une nouvelle version du legacy.

La séparation fondamentale est :

```text
React
  = expérience utilisateur

Zustand
  = état client

TanStack Query
  = état serveur

Core TypeScript
  = métier + géométrie + règles

PHP
  = API + sécurité + SaaS

PostgreSQL
  = données persistantes

Object Storage
  = gros fichiers

Three.js
  = rendu 3D
```

Cette séparation doit être considérée comme le principal garde-fou architectural de la V2.
