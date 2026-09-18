# Qui peut quoi — identités, rôles et permissions

**Statut :** référence descriptive. Ce document n'établit aucune règle ; il
décrit ce que le code applique. Les décisions sont dans les ADR et dans
`docs/architecture-v2.md`.

**Relève de :** non-négociables #21, #22, #25 ; architecture V2 §10.3, §10.6,
§12.1 ; [ADR-039](adr/ADR-039-the-installer-appoints-the-first-administrator.md) ;
[ADR-040](adr/ADR-040-offer-authoring-is-lent-to-a-tenant.md) ;
[ADR-041](adr/ADR-041-the-storefront-sells-to-strangers.md) ;
[ADR-042](adr/ADR-042-the-console-administers-products.md) ;
[ADR-044](adr/ADR-044-a-product-cannot-invoice-until-somebody-says-who-is-selling.md)

Il n'y a pas quatre types d'utilisateur sur cette plateforme. Il y a **deux
identités** qui ne se croisent jamais, **six rôles** répartis entre elles, et un
visiteur qui n'en a aucune. Les chiffres qui suivent sont lus dans le schéma :
31 permissions de tenant, 17 permissions de plateforme, 2 rôles de tenant,
4 rôles de plateforme.

## La frontière

| | Console (`/console`) | Application (`/`) |
| --- | --- | --- |
| Identité | `StaffContext` | `RequestContext` |
| Vient de | `platform_staff` → `platform_roles` | `tenant_members` + en-tête `X-Product`, à l'intérieur d'une attribution `tenant_products` (ADR-047) |
| Porte | un utilisateur et des rôles plateforme | un utilisateur, un tenant, un produit |
| Ne porte pas | ni tenant, ni produit | aucun rôle plateforme |

Entre les deux, aucun pont. **Un rôle plateforme n'accorde aucune
appartenance** (non-négociable #22) : un administrateur de la plateforme ne
peut pas ouvrir les projets d'un client, ni facturer à sa place, ni lire ses
données métier — non pas parce qu'une règle l'interdit, mais parce qu'il n'a
pas d'identité qui permette de formuler la demande. C'est aussi la raison pour
laquelle `if (isStaff)` n'apparaît nulle part : les deux surfaces sont séparées
en amont de toute décision.

En dehors des deux coquilles se tient le **visiteur** : pas de session, pas de
produit, pas de permissions.

## Les identités

### Administrateur plateforme — `PLATFORM_ADMIN`

Coquille : console. 16 permissions sur 16.

**Objets**

- Les **produits** : en créer, renommer, retirer — jamais supprimer (un produit
  porte des tenants, des abonnements et des factures, et une facture est un
  document légal).
- La **configuration de facturation** d'un produit : l'émetteur que les
  factures nomment, et la position fiscale du fournisseur.
- Le **catalogue de la plateforme** : plans, features, offres, versions,
  publication.
- La **vitrine publique** : quelle offre est advertisée.
- Les **tenants** : les lire, et changer le seul drapeau qui les concerne —
  l'autorisation d'écrire leurs propres offres.
- Le **personnel plateforme** : nommer et révoquer des rôles.
- L'annuaire, les métriques financières, la file de jobs, le journal d'audit,
  le journal d'accès, les conversations de support, l'effacement RGPD.

**Ne peut pas**

- Ouvrir un projet, une facture ou un abonnement *en tant que* tenant : il n'a
  pas de `RequestContext`.
- Lire un tenant sans laisser de trace : les lectures qui entrent chez un
  client exigent un motif dans `X-Access-Purpose` et `X-Access-Reason`, et sont
  journalisées (non-négociable #21). Les listes n'en demandent pas ; seules les
  lectures qui révèlent les données d'un client précis.
- Se révoquer s'il est le dernier : la base garde au moins un administrateur
  actif.

### Les trois autres rôles plateforme

| Rôle | Permissions | Ce qu'il fait |
| --- | --- | --- |
| `SUPPORT_ADMIN` | 5 | Lit les tenants, lit et répond aux conversations, voit si la file de jobs tourne encore. |
| `FINANCE_ADMIN` | 3 | Lit les tenants et le tableau de bord financier. |
| `SALES_ADMIN` | 3 | Identique à Finance. |

Aucun des trois ne touche au catalogue, à la vitrine ou à la configuration d'un
produit : `staff.catalog.manage` et `staff.products.manage` sont réservés à
`PLATFORM_ADMIN`. Aucun ne peut nommer qui que ce soit : `staff.grant` est la
permission qui transforme n'importe quel rôle en tous les rôles, par personne
interposée.

### Administrateur de tenant — `TENANT_ADMIN`

Coquille : application. 31 permissions sur 31 — **30 effectives**, voir
`catalog.manage` ci-dessous.

**Objets** : son tenant, ses membres, son habillage ; son abonnement
(souscrire, changer d'offre, résilier, reprendre) ; son argent (profil de
facturation, factures, avoirs, paiements, remboursements, devis, commandes,
TVA) ; son métier (projets et versions, assets, conversations, notifications,
jobs).

**Ce qu'il a de plus qu'un membre** — exactement dix permissions :
`tenant.manage`, `members.manage`, `billing.manage`, `payments.manage`,
`sales.manage`, `subscription.manage`, `tax.manage`, `jobs.manage`,
`skin.manage`, `catalog.manage`.

**Ne peut pas** voir un autre tenant, même du même produit ; écrire une offre
par défaut ; changer l'habillage sans la capacité `white_label`.

### Membre d'un tenant — `USER`

Coquille : application. 22 permissions sur 31.

Il travaille : projets en **lecture et écriture** (créer, modifier, dupliquer,
versionner, exporter, supprimer), assets en lecture et écriture, conversations
en lecture et écriture, ses notifications et son propre compte. **Il achète
pour lui-même** (depuis le 18 septembre 2026) : ouvrir un checkout, payer une
facture, relancer un paiement (`billing.pay`) ; souscrire, résilier
(`subscription.manage`) — un **siège** (§13.1), payé avec sa propre carte,
à côté de l'abonnement de l'organisation et sans le gêner. Tout le reste —
catalogue, membres, tenant, jobs — en **lecture seule**.

**Il ne voit que ce qui le concerne.** Factures, paiements, avoirs, commandes
et devis lui sont montrés **restreints aux siens** : les commandes qui ont
acheté son siège, les factures qu'elles ont levées, les paiements dessus, les
devis qu'il a lui-même demandés. Le document d'un autre est un 404, pas un
403 — l'identifiant n'est pas le sien à connaître. La vue large — tout ce que
l'organisation a jamais levé — vient avec `billing.manage`, celle de
l'administrateur (`OwnDocumentsTest`). Le dossier fiscal (profil TVA, taux,
périodes) est celui de l'organisation et rien n'y est adressé à une personne :
`tax.read` n'est plus au membre.

Ce qui lui manque est exactement les neuf `.manage` administratifs et
`tax.read`, et `UserRoleMatrixTest` le tient : un `USER` ne peut ni
rembourser, ni émettre une facture ou un avoir, ni marquer une facture payée à
la main, ni ajouter un membre, ni clore une période. La conséquence à l'usage :
**quelqu'un qui s'inscrit à la racine d'une organisation paie son siège dans
la foulée** — la vitrine enchaîne l'inscription et le checkout — et laisse
l'abonnement de l'organisation et l'administration de l'argent à
l'administrateur.

### Celui qui arrive par lui-même — un `USER` (ADR-049)

Depuis le 17 septembre 2026, **l'inscription ne crée plus de tenant**. Un
tenant est créé par la plateforme (`POST /staff/tenants`, `staff.tenants.manage`),
avec ses produits et son premier administrateur, et vit à sa propre racine
d'URL (`hostname/acme/` ; la racine nue est celle du tenant par défaut).

Quelqu'un qui s'inscrit à `hostname/acme/` demande à Acme de l'accueillir :
l'inscription écrit un utilisateur, son mot de passe et une appartenance
`USER` sur chaque produit qu'Acme détient — et rien d'autre. La **politique
d'adhésion** d'Acme (`tenants.join_policy`) décide si l'appartenance est active :

- `OPEN` (défaut depuis le 18 septembre 2026) : membre immédiatement ;
- `APPROVAL` : en attente ; les administrateurs sont notifiés et
  acceptent ou déclinent depuis l'écran Membres ;
- `DOMAIN` : une adresse sur un domaine listé est admise immédiatement, toute
  autre est refusée ;
- `INVITATION` : personne n'arrive par lui-même.

Une appartenance en attente **n'est pas une appartenance** : aucun contexte ne
se résout, `/me` répond 403, et la coquille dit « en attente d'Acme ».

Il n'existe toujours aucun type « B2C ». Un particulier est un `USER` du tenant
à la racine duquel il s'est inscrit, et **il paie dans la foulée** : la
politique d'adhésion par défaut est `OPEN` (membre immédiat) et `billing.pay`
est à tout membre. Ce que
la plateforme peut aussi faire : **donner** à un tenant son droit d'usage d'un
produit sans vente (`entitlements.source = GRANT`), pour un pilote, un
partenaire ou son propre tenant par défaut.

### Visiteur

Aucune coquille, aucune session.

Il voit la liste des produits **qui ont quelque chose d'advertisé** — et rien
des autres (ADR-047) —, les offres d'un produit **explicitement advertisées** —
filtrées en SQL, jamais chargées en mémoire puis masquées — pour le tenant à
la racine duquel il se trouve, et peut demander à le rejoindre puis vérifier
son adresse. La page d'accueil est la vitrine de ce tenant ; se connecter est
le chemin secondaire (ADR-041, ADR-049).

Il ne voit jamais une offre en vente mais non advertisée — être vendable et être
montré sont deux décisions distinctes — ni quels produits la plateforme héberge :
un code de produit inconnu et un produit sans offre publique donnent **exactement
la même réponse vide**.

## La matrice, côté tenant

Les 30 permissions de tenant. `oui` / `non` ; `prêté` marque celle qui est
accordée au rôle puis retirée à la résolution.

| Permission | `TENANT_ADMIN` | `USER` | Objet |
| --- | --- | --- | --- |
| `account.read` | oui | oui | son propre compte |
| `account.manage` | oui | oui | son propre compte |
| `tenant.read` | oui | oui | le tenant courant |
| `tenant.manage` | oui | non | le tenant courant |
| `members.read` | oui | oui | les membres |
| `members.manage` | oui | non | les membres |
| `projects.read` | oui | oui | projets, versions |
| `projects.write` | oui | oui | projets, versions, exports |
| `assets.read` | oui | oui | fichiers |
| `assets.manage` | oui | oui | fichiers |
| `messages.read` | oui | oui | conversations |
| `messages.write` | oui | oui | conversations |
| `notifications.read` | oui | oui | notifications |
| `notifications.manage` | oui | oui | préférences, consentements |
| `entitlements.read` | oui | oui | droits et consommation |
| `catalog.read` | oui | oui | plans, features, offres |
| `catalog.manage` | **prêté** | non | écrire et publier des offres |
| `subscription.read` | oui | oui | abonnement, échéancier |
| `subscription.manage` | oui | oui | souscrire, changer, résilier — un membre, son siège |
| `billing.read` | oui | oui | factures, avoirs, profil — un membre, **les siens** |
| `billing.pay` | oui | oui | **le checkout**, payer une facture, relancer un paiement |
| `billing.manage` | oui | non | émettre, annuler, créditer, marquer payée à la main, profil de facturation ; **la vue de l'organisation** |
| `payments.read` | oui | oui | paiements — un membre, **les siens** |
| `payments.manage` | oui | non | encaisser, rembourser |
| `sales.read` | oui | oui | devis, commandes — un membre, **les siens** |
| `sales.manage` | oui | non | créer, accepter, exécuter |
| `tax.read` | oui | non | profil TVA, périodes, taux — le dossier fiscal est celui de l'organisation |
| `tax.manage` | oui | non | régler le profil, clore une période |
| `jobs.read` | oui | oui | travaux de fond |
| `jobs.manage` | oui | non | lancer, annuler |
| `skin.manage` | oui | non | habillage — **+ capacité `white_label`** |

## La matrice, côté plateforme

Les 17 permissions de plateforme.

| Permission | `PLATFORM` | `SUPPORT` | `FINANCE` | `SALES` |
| --- | --- | --- | --- | --- |
| `staff.self.read` | oui | oui | oui | oui |
| `staff.tenants.read` | oui | oui | oui | oui |
| `support.read` | oui | oui | non | non |
| `support.respond` | oui | oui | non | non |
| `admin.health.read` | oui | oui | non | non |
| `admin.finance.read` | oui | non | oui | oui |
| `staff.tenants.manage` (attribuer un produit, prêter le catalogue) | oui | non | non | non |
| `staff.products.manage` | oui | non | non | non |
| `staff.catalog.manage` | oui | non | non | non |
| `staff.grant` | oui | non | non | non |
| `staff.access_log.read` | oui | non | non | non |
| `staff.jobs.read` | oui | non | non | non |
| `staff.jobs.manage` | oui | non | non | non |
| `staff.demo.reset` (vider et réensemencer le monde de démonstration) | oui | non | non | non |
| `staff.demo.publish` (afficher ou masquer la page publique `/demo`) | oui | non | non | non |
| `admin.directory.read` | oui | non | non | non |
| `admin.audit.read` | oui | non | non | non |
| `admin.privacy.erase` | oui | non | non | non |

## Les arêtes vives

**Un membre achète pour lui, et ne voit que le sien.** Le checkout, le paiement
d'une facture et la relance sont derrière `billing.pay`, que les deux rôles
portent ; ce qu'un membre achète est **un siège** (§13.1), et ce qu'il lit —
factures, paiements, avoirs, commandes, devis — est restreint aux documents de
ce siège par les services de lecture, sans permission nouvelle : la vue de
l'organisation est `billing.manage`. Émettre, annuler, créditer, rembourser
restent derrière `billing.manage` et `payments.manage`, que seul
`TENANT_ADMIN` a. Jusqu'au
17 septembre 2026 c'était l'inverse — le checkout vivait sous `billing.manage`
et un membre voyait les prix sans pouvoir acheter ; l'opérateur a tranché :
quelqu'un qui s'inscrit à la racine d'une organisation paie dans la foulée.

**`catalog.manage` est accordé, puis retiré.** Le rôle `TENANT_ADMIN` porte
cette permission dans `role_permissions`, et la requête qui résout une
appartenance la retire par un `LEFT JOIN` conditionnel tant que
`tenants.may_author_offers` est faux. Un tenant n'écrit ses propres offres que
si la plateforme le lui a explicitement prêté — cas du revendeur qui maintient
sa propre grille tarifaire (ADR-040).

**Une permission n'est pas un droit.** `skin.manage` dit « cette personne peut
configurer le tenant ». La capacité `white_label` dit « l'offre souscrite inclut
la fonctionnalité ». Les deux sont nécessaires et aucune n'implique l'autre : un
administrateur sur une offre sans marque blanche est refusé, et un membre
ordinaire sur une offre qui l'inclut aussi.

**Une lecture staff chez un client laisse une trace motivée.** Ouvrir un tenant
ou une conversation depuis la console exige un motif structuré et une référence,
transportés dans les en-têtes de la requête elle-même — pas dans un second appel
que l'on pourrait oublier de faire.

**Il reste toujours au moins un administrateur plateforme.** L'invariant est
tenu par la base, pas par un écran. La console affiche le dernier administrateur
comme protégé plutôt que de proposer une action qu'elle devrait ensuite refuser
(ADR-039).

**Un produit neuf ne peut pas facturer.** Créer un produit et lui donner un prix
ne suffit pas : une facture doit nommer son émetteur. Tant que l'identité de
facturation n'est pas renseignée dans la console, tout achat s'arrête sur
`BILLING_NOT_CONFIGURED` — l'écran *Invoicing* le dit et nomme les champs
manquants (ADR-044).

## Deux nuances que les matrices ne montrent pas

- La **session de paiement**, le **paiement d'une facture** et la **relance d'un
  paiement** passent par `billing.pay`, bien qu'ils vivent sous `/checkout`,
  `/billing` et `/payments` ; le remboursement reste sous `payments.manage`.
- L'**habillage** se lit avec une simple appartenance et ne s'écrit qu'avec
  `skin.manage` *et* la capacité.

## Provenance

Les rôles et permissions de ce document viennent d'une requête sur le schéma
appliqué par les migrations ; les objets et actions, d'une lecture de
`config/routes.php` et des gardes de chaque contrôleur. Rien ici n'est une
intention : c'est ce que le code applique.

Ce document est descriptif et peut donc se désynchroniser. Les sources
d'autorité restent les migrations (pour les rôles et les permissions),
`config/routes.php` et les `*Route.php` de chaque module (pour les gardes),
`docs/ui-api-coverage.json` (pour les écrans), et les ADR (pour les décisions).
