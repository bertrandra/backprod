# Qui peut quoi — identités, rôles et permissions

**Statut :** référence descriptive. Ce document n'établit aucune règle ; il
décrit ce que le code applique. Les décisions sont dans les ADR et dans
`docs/architecture-v2.md`.

**Relève de :** non-négociables #22, #25 (#21 est levé par
[ADR-069](adr/ADR-069-the-console-reads-without-a-reason-and-records-nothing.md)) ; architecture V2 §10.3, §10.6,
§12.1 ; [ADR-039](adr/ADR-039-the-installer-appoints-the-first-administrator.md) ;
[ADR-040](adr/ADR-040-offer-authoring-is-lent-to-a-tenant.md) ;
[ADR-041](adr/ADR-041-the-storefront-sells-to-strangers.md) ;
[ADR-042](adr/ADR-042-the-console-administers-products.md) ;
[ADR-044](adr/ADR-044-a-product-cannot-invoice-until-somebody-says-who-is-selling.md) ;
[ADR-053](adr/ADR-053-buying-covers-people-membership-does-not.md) ;
[ADR-055](adr/ADR-055-the-tenant-surface-sells-seats-and-nothing-else.md) ;
[ADR-066](adr/ADR-066-every-subscription-is-a-seat.md)

Il n'y a pas quatre types d'utilisateur sur cette plateforme. Il y a **deux
identités** qui ne se croisent jamais, **six rôles** répartis entre elles, et un
visiteur qui n'en a aucune. Les chiffres qui suivent sont lus dans le schéma :
31 permissions de tenant, 22 permissions de plateforme, 2 rôles de tenant,
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

Coquille : console. 22 permissions sur 22.

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
- La **liste des features** de la plateforme (ADR-052), l'**inscription**
  (exiger ou non la preuve de l'adresse, ADR-063), les menus, les gabarits de
  mail, les palettes, le monde de démonstration.
- La **déclaration de TVA de la plateforme** (`/api/v1/admin/vat-reports`) :
  la lire et en clore une période (2026-10-26).
- L'annuaire, les métriques financières, la file de jobs, le journal d'audit,
  les conversations de support, l'effacement RGPD.

**Ne peut pas**

- Ouvrir un projet, une facture ou un abonnement *en tant que* tenant : il n'a
  pas de `RequestContext`.
- Se révoquer s'il est le dernier : la base garde au moins un administrateur
  actif.

### Les trois autres rôles plateforme

| Rôle | Permissions | Ce qu'il fait |
| --- | --- | --- |
| `SUPPORT_ADMIN` | 5 | Lit les tenants, lit et répond aux conversations, voit si la file de jobs tourne encore. |
| `FINANCE_ADMIN` | 5 | Lit les tenants et le tableau de bord financier ; lit la déclaration de TVA de la plateforme et en clôt les périodes (`admin.tax.read`, `admin.tax.manage`, 2026-10-26). |
| `SALES_ADMIN` | 3 | Lit les tenants et le tableau de bord financier — Finance sans la TVA : il ne déclare pas l'impôt. |

Aucun des trois ne touche au catalogue, à la vitrine ou à la configuration d'un
produit : `staff.catalog.manage` et `staff.products.manage` sont réservés à
`PLATFORM_ADMIN`. Aucun ne peut nommer qui que ce soit : `staff.grant` est la
permission qui transforme n'importe quel rôle en tous les rôles, par personne
interposée.

### Administrateur de tenant — `TENANT_ADMIN`

Coquille : application. 30 permissions sur 31 — toutes sauf `billing.pay` —,
**29 effectives**, voir `catalog.manage` ci-dessous.

**Objets** : son tenant, ses membres, son habillage ; les abonnements de
ses gens (il les lit tous, et peut s'ajouter lui-même à l'un d'eux puis s'en
retirer — `POST`/`DELETE /organisation/subscriptions/{id}/me`, `tenant.manage`) ; son argent (profil de
facturation, factures, avoirs, paiements, remboursements, commandes, TVA) ; son métier (projets et versions, assets, conversations, notifications,
jobs).

**Ce qu'il a de plus qu'un membre** — exactement dix permissions :
`tenant.manage`, `members.manage`, `billing.manage`, `payments.manage`,
`sales.manage`, `tax.read`, `tax.manage`, `jobs.manage`, `skin.manage`,
`catalog.manage`. **Et une de moins** : `billing.pay` (2026-09-25) — il
administre, il n'achète pas.

**Il ne prend pas de place** (2026-09-30). Le quota `users` d'un abonnement
borne les personnes qu'il couvre, et quiconque tient `TENANT_ADMIN` sur son
`(tenant, produit)` n'y compte pas, titulaire compris (`PlacesUsedSql`) :
l'ajouter à l'abonnement d'un collègue pour aider ne consomme rien, et le
retirer ne libère rien.

**Ne peut pas** voir un autre tenant, même du même produit ; écrire une offre
par défaut ; modifier une palette — il en choisit une, l'administrateur de la
plateforme les écrit.

### Membre d'un tenant — `USER`

Coquille : application. 21 permissions sur 31.

Il travaille : projets en **lecture et écriture** (créer, modifier, dupliquer,
versionner, exporter, supprimer), assets en lecture et écriture, conversations
en lecture et écriture, ses notifications et son propre compte. **Il achète
pour lui-même** (depuis le 18 septembre 2026) : ouvrir un checkout, payer une
facture, relancer un paiement (`billing.pay`) ; souscrire, résilier
(`subscription.manage`) — un **siège** (§13.1), payé avec sa propre carte.
C'est le seul abonnement qui existe : l'organisation n'en achète aucun
(ADR-055, ADR-066). Tout le reste —
catalogue, membres, tenant, jobs — en **lecture seule**.

**Il ne voit que ce qui le concerne.** Factures, paiements, avoirs et commandes
lui sont montrés **restreints aux siens** : les commandes qui ont acheté son
siège, les factures qu'elles ont levées, les paiements dessus. Le document d'un autre est un 404, pas un
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
l'administration de l'argent à l'administrateur.

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

**L'adresse se prouve, et ce qu'elle prouve décide de ce qui l'attend**
(ADR-061, 27 septembre 2026). L'inscription ne fait jamais attendre : compte,
mot de passe et session sont créés tout de suite, et sous `OPEN` on paie dans
la foulée. Mais :

- sous `DOMAIN`, le domaine est la seule preuve demandée, donc l'appartenance
  naît `UNCONFIRMED` et ne vit qu'une fois l'adresse prouvée — tranchée de
  nouveau selon la liste du moment. `nimporte-qui@acme.example` n'est plus
  membre d'Acme ;
- **si l'opérateur l'exige** (`platform_settings.sign_up.confirm_email`,
  `staff.sign_up.manage`, écran Storefront de la console ; **désactivé par
  défaut**, ADR-063), toute inscription en libre-service a **sept jours** pour
  prouver son adresse (`AUTH_EMAIL_CONFIRMATION_GRACE`). Passé ce délai, la
  surface locataire répond `EMAIL_UNCONFIRMED` jusqu'au clic ; rien n'est
  annulé, et un clic rétablit tout. Les comptes invités, installés ou
  antérieurs n'ont pas de délai, et désactiver le réglage libère tout le monde.

Le lien de confirmation prouve l'adresse, et un lien de mot de passe aussi — il
arrive à la même boîte. `POST /auth/verify-email/resend` en envoie un nouveau.

Il n'existe toujours aucun type « B2C ». Un particulier est un `USER` du tenant
à la racine duquel il s'est inscrit, et **il paie dans la foulée** : la
politique d'adhésion par défaut est `OPEN` (membre immédiat) et `billing.pay`
est à tout membre. Ce que
la plateforme peut aussi faire : **donner** à un tenant son droit d'usage d'un
produit sans vente (`entitlements.source = GRANT`), pour un pilote, un
partenaire ou son propre tenant par défaut. Un tel don ne couvre des personnes
que s'il le dit (`covers_people`, ADR-056) : sinon il allume des features et
n'ouvre l'atelier à personne.

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

Les 31 permissions de tenant. `oui` / `non` ; `prêté` marque celle qui est
accordée au rôle puis retirée à la résolution.

| Permission | `TENANT_ADMIN` | `USER` | Objet |
| --- | --- | --- | --- |
| `account.read` | oui | oui | son propre compte |
| `account.manage` | oui | oui | son propre compte — nom affiché, produit par défaut, langue (ADR-050) |
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
| `billing.pay` | **non** | oui | **le checkout**, placer une commande, payer une facture, relancer un paiement — l'acte de l'acheteur (2026-09-25) |
| `billing.manage` | oui | non | émettre, annuler, créditer, marquer payée à la main, profil de facturation ; **la vue de l'organisation** |
| `payments.read` | oui | oui | paiements — un membre, **les siens** |
| `payments.manage` | oui | non | encaisser, rembourser |
| `sales.read` | oui | oui | commandes — un membre, **les siennes** (les devis côté tenant sont retirés, ADR-055) |
| `sales.manage` | oui | non | exécuter, annuler une commande |
| `tax.read` | oui | non | profil TVA, périodes, taux — le dossier fiscal est celui de l'organisation |
| `tax.manage` | oui | non | régler le profil, clore une période |
| `jobs.read` | oui | oui | travaux de fond |
| `jobs.manage` | oui | non | lancer, annuler |
| `skin.manage` | oui | non | choisir la palette de l'organisation, jamais la modifier (2026-10-04). Liée à aucune offre : la marque blanche (couleurs et logo par produit, capacité `white_label`) a disparu le 2026-10-05, et le logo est celui de l'organisation, sous `tenant.manage` |

## La matrice, côté plateforme

Les 22 permissions de plateforme. (`staff.jobs.read` et `staff.jobs.manage`
ont existé du 3 au 22 septembre 2026 sans qu'aucune route ne les exige — la
file est lue derrière `admin.health.read`, pour que le support la voie ; elles
ont été retirées. `staff.access_log.read` a été retirée le 5 octobre 2026 avec
le journal d'accès qu'elle lisait, ADR-069.)

| Permission | `PLATFORM` | `SUPPORT` | `FINANCE` | `SALES` |
| --- | --- | --- | --- | --- |
| `staff.self.read` | oui | oui | oui | oui |
| `staff.tenants.read` | oui | oui | oui | oui |
| `support.read` | oui | oui | non | non |
| `support.respond` | oui | oui | non | non |
| `admin.health.read` | oui | oui | non | non |
| `admin.finance.read` | oui | non | oui | oui |
| `admin.tax.read` (lire la déclaration de TVA de la plateforme, `/admin/vat-reports`) | oui | non | oui | non |
| `admin.tax.manage` (clore une période de cette déclaration) | oui | non | oui | non |
| `staff.tenants.manage` (attribuer un produit, prêter le catalogue) | oui | non | non | non |
| `staff.products.manage` | oui | non | non | non |
| `staff.catalog.manage` | oui | non | non | non |
| `staff.features.manage` (tenir la liste unique des features, ADR-052) | oui | non | non | non |
| `staff.grant` | oui | non | non | non |
| `staff.navigation.manage` (choisir les entrées de menu par public) | oui | non | non | non |
| `staff.mail.manage` (modifier les gabarits de mail, envoyer un test) | oui | non | non | non |
| `staff.sign_up.manage` (exiger ou non qu'un nouveau compte prouve son adresse, ADR-063) | oui | non | non | non |
| `staff.design.manage` (modifier les palettes, et choisir celle que porte chaque organisation dans chaque produit) | oui | non | non | non |
| `staff.demo.reset` (vider et réensemencer le monde de démonstration) | oui | non | non | non |
| `staff.demo.publish` (afficher ou masquer la page publique `/demo`) | oui | non | non | non |
| `admin.directory.read` | oui | non | non | non |
| `admin.audit.read` | oui | non | non | non |
| `admin.privacy.erase` | oui | non | non | non |

## Les arêtes vives

**Un membre achète pour lui, et ne voit que le sien.** Le checkout, placer une
commande, le paiement d'une facture et la relance sont derrière `billing.pay`,
que **le membre seul** porte depuis le 25 septembre 2026 — un administrateur
administre : il lit ce que ses gens détiennent et enregistre l'argent qui
arrive (`billing.manage`, `markPaid`), il n'achète pas et ne paie pas par
carte. Il l'avait parce que le rôle est un sur-ensemble, pas parce que
quelqu'un l'avait décidé, et cela se voyait : *Buy for yourself* lui était
offert sur chaque offre du catalogue ; ce qu'un membre achète est **un siège** (§13.1), et ce qu'il lit —
factures, paiements, avoirs, commandes — est restreint aux documents de
ce siège par les services de lecture, sans permission nouvelle : la vue de
l'organisation est `billing.manage`. Émettre, annuler, créditer, rembourser
restent derrière `billing.manage` et `payments.manage`, que seul
`TENANT_ADMIN` a. Jusqu'au
17 septembre 2026 c'était l'inverse — le checkout vivait sous `billing.manage`
et un membre voyait les prix sans pouvoir acheter ; l'opérateur a tranché :
quelqu'un qui s'inscrit à la racine d'une organisation paie dans la foulée.

**Ce que le catalogue propose, à qui.** Un seul achat par offre, le siège,
derrière `billing.pay` ; changer de plan est `subscription.manage`. Ce qui est
détenu se lit dans `showSubscription`, qui répond `seat` — le siège de la
personne — et `coverage` — ce qui la couvre, quel qu'en soit le titulaire
(ADR-066) :

| Qui | Boutons visibles |
| --- | --- |
| `USER` sans siège | *Buy for yourself* — sa carte, son siège ; *Start the free period* sur une offre gratuite pas encore prise |
| `USER` avec un siège actif | aucun achat ; « Your current plan » sur sa ligne, *Move to this plan* / *Move down to this plan* sur les autres |
| `TENANT_ADMIN` | aucun achat : il ne porte pas `billing.pay` ; pour travailler, il s'ajoute à un abonnement de son organisation (sans y prendre de place) |
| lecteur (`catalog.read` seul) | aucun bouton et aucune explication : les prix, c'est ce que sa permission lui donne |

Un bouton retiré est **toujours expliqué** — bandeau en haut nommant ce qui est
actif, phrase dans la ligne à la place du bouton — parce qu'un bouton qui
disparaît sans un mot se lit comme un écran qui a perdu quelque chose, pas
comme un achat déjà fait (retour de l'opérateur, 18 septembre 2026).

**Un achat non payé se règle ou s'abandonne.** Depuis l'écran de la commande
(`/checkout/{id}`), tant que l'argent n'est pas arrivé — en attente, ou
dernière tentative échouée — `billing.pay` offre *Pay now* (nouvelle tentative
sur la facture, formulaire de carte sur place) et *Cancel this purchase*
(`cancelCheckoutSession` : la commande et sa facture passent à `CANCELLED`, le
numéro de facture reste, séquence sans trou oblige). Un membre n'abandonne que
sa propre commande — celle d'un collègue est un 404 pour lui. Refusé dès qu'un
paiement a abouti.

**Un mot de passe oublié se réinitialise, et une invitation se règle de même**
(19 septembre 2026). `POST /auth/password/forgot` répond toujours 202 — dire
« pas de compte » serait l'énumération que le formulaire de connexion refuse —
et, s'il y a un compte, envoie un lien à usage unique (30 minutes, stocké en
SHA-256) en notification `SECURITY`, la catégorie que personne ne peut couper.
`POST /auth/password/reset` pose le nouveau mot de passe, **révoque toutes les
sessions** du compte et n'en ouvre aucune : la personne se reconnecte. Le même
lien (7 jours, `purpose = INVITATION`) sert à qui a été ajouté à un abonnement
par adresse sans avoir jamais eu de mot de passe.

**Un abonnement a un propriétaire et ses personnes** (19 septembre 2026). Qui
l'active en est propriétaire (`subscriptions.owner_user_id`) — la personne
dont c'est le siège ; il n'y a plus d'abonnement de l'organisation (ADR-066). L'offre peut vendre un nombre
d'utilisateurs (feature `users`, quota, propriétaire compris ; 1 si elle n'en
vend pas — un `TENANT_ADMIN` n'y compte jamais, voir plus haut) et le propriétaire seul ajoute ou retire des personnes dans cette
limite (`NOT_THE_OWNER` sinon, quelle que soit la permission) : un membre de
l'organisation par identifiant, ou n'importe qui par adresse — un compte est
créé au besoin, membre `USER` actif de l'organisation sur tous ses produits,
avec le lien d'invitation. Les personnes d'un siège sont habilitées par lui
(`subscription_members`, comptés à la résolution des droits).

**Le profil de facturation est à l'administrateur.** L'entrée de menu est
derrière `billing.manage` (comme Organisation et Membres) ; l'API le lit encore
avec `billing.read`, l'écrit avec `billing.manage`. Sur la
vitrine, après l'inscription, il n'y a pas de choix : c'est toujours un siège.
Un administrateur ne s'achète pas de siège — il n'a pas `billing.pay` depuis le
25 septembre 2026 ; il s'ajoute à un abonnement existant de son organisation
(`joinSubscription`, `tenant.manage`) et s'en retire de même, lui et personne
d'autre.

**`catalog.manage` est accordé, puis retiré.** Le rôle `TENANT_ADMIN` porte
cette permission dans `role_permissions`, et la requête qui résout une
appartenance la retire par un `LEFT JOIN` conditionnel tant que
`tenants.may_author_offers` est faux. Un tenant n'écrit ses propres offres que
si la plateforme le lui a explicitement prêté — cas du revendeur qui maintient
sa propre grille tarifaire (ADR-040).

**Une permission n'est pas un droit.** `tenant.manage` dit « cette personne peut
configurer l'organisation » ; une capacité dit « l'offre souscrite inclut la
fonctionnalité ». Les deux ne s'impliquent pas. L'exemple qui illustrait cette
phrase — la marque blanche, `skin.manage` **et** `white_label` — a disparu le
2026-10-05 : le logo fait partie de ce qu'est l'organisation, comme son nom, et
aucune offre ne le vend. La capacité est retirée, jamais supprimée (ADR-052).

**Une lecture staff chez un client ne demande pas de motif, et rien n'est
enregistré** (ADR-069, 5 octobre 2026). Les en-têtes `X-Access-Purpose` /
`X-Access-Reason`, le journal `staff_access_log`, son écran et
`staff.access_log.read` ont disparu ; ni les lectures ni les modifications du
personnel ne s'écrivent nulle part. Ce qui reste, c'est la permission de
plateforme vérifiée sur chaque route staff, et #22. Le `product_access_log`
(ce que la clé d'un produit a atteint) et le journal d'audit de la plateforme
(`admin.audit.read`) restent.

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
- La **palette** de l'organisation se lit avec une simple appartenance et se
  choisit avec `skin.manage` seul, sans capacité (la marque blanche a disparu
  le 2026-10-05) ; le **logo** est sous `tenant.manage`.

## Provenance

Les rôles et permissions de ce document viennent d'une requête sur le schéma
appliqué par les migrations ; les objets et actions, d'une lecture de
`config/routes.php` et des gardes de chaque contrôleur. Rien ici n'est une
intention : c'est ce que le code applique.

Ce document est descriptif et peut donc se désynchroniser. Les sources
d'autorité restent les migrations (pour les rôles et les permissions),
`config/routes.php` et les `*Route.php` de chaque module (pour les gardes),
`docs/ui-api-coverage.json` (pour les écrans), et les ADR (pour les décisions).
