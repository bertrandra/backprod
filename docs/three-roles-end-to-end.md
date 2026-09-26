# Trois rôles, de bout en bout — ce qu'on vit, ce qu'on fait, ce que ça change

**Statut :** référence descriptive, 2026-09-26. Ce document n'établit aucune
règle ; il décrit ce que le code applique aujourd'hui. Les décisions sont dans
les ADR et dans `docs/architecture-v2.md`.

**Complément de** [`identities-and-permissions.md`](identities-and-permissions.md),
qui dit *qui peut quoi*. Celui-ci dit **ce que chacun traverse**, dans l'ordre,
et **ce que chaque action change** une fois faite.

**Relève de :** non-négociables #21, #22 ; architecture V2 §10.6, §12.1, §12.2,
§13.1 ; [ADR-053](adr/ADR-053-buying-covers-people-membership-does-not.md),
[ADR-054](adr/ADR-054-a-gapless-series-belongs-to-its-issuer.md),
[ADR-055](adr/ADR-055-the-tenant-surface-sells-seats-and-nothing-else.md),
[ADR-057](adr/ADR-057-a-seat-is-taxed-by-whoever-sells-it.md).

---

## Ce que « 100 % » veut dire ici

Le contrat déclare **219 opérations**. Chacune apparaît dans ce document,
attribuée à qui peut l'atteindre. Pas un échantillon, pas les principales :
toutes, y compris les sept que personne n'atteint à la main et les quatre qu'une
machine seule appelle.

`composer run gate:roles` le vérifie dans les deux sens : une opération du
contrat absente d'ici fait échouer le build, et une opération nommée ici que le
contrat ne déclare plus aussi. Un document de couverture que rien ne vérifie
dérive en une semaine, et il est alors pire que rien, parce qu'on le croit.

L'arithmétique est en bas de page.

---

## Les trois rôles en une page

Il n'y a pas trois types d'utilisateur mais **deux axes qui ne se convertissent
jamais l'un dans l'autre** (non-négociable #22) :

```text
appartenance à un tenant   (tenant, personne, produit) → TENANT_ADMIN | USER
rôle plateforme            (personne, rôle)            → PLATFORM_ADMIN | …
```

Un rôle plateforme n'accorde aucune appartenance et n'en fabrique jamais une ;
une appartenance n'accorde aucun rôle plateforme. `platform_staff` et
`tenant_members` sont deux tables, et une seule table pour les deux ferait d'un
filtre oublié une élévation de privilège.

| | **USER** | **TENANT_ADMIN** | **PLATFORM_ADMIN** |
|---|---|---|---|
| Ce qu'il est | un membre de l'organisation | l'administrateur **du client** | l'opérateur **de la plateforme** |
| Où il vit | l'application du produit | la même application, plus les écrans d'administration | la console, `/console/*` |
| Il achète | **oui** — et lui seul | non (ADR-055 §2b) | non |
| Il facture | non | **oui** — c'est son organisation qui émet | non, sauf les ventes de la plateforme |
| Il travaille dans le produit | oui, si un abonnement le couvre | oui, aux mêmes conditions | non |
| Permissions | 21 | 30 | 19 (dont 5 côté `admin.*`) |
| Opérations atteignables | 6 propres + 67 partagées + 16 sans permission + 2 par capacité = **91** | 34 propres + 67 partagées + 16 sans permission + 2 par capacité = **119** | **76** |

Les 10 permissions que l'administrateur a en plus sont `billing.manage`,
`catalog.manage`, `jobs.manage`, `members.manage`, `payments.manage`,
`sales.manage`, `skin.manage`, `tax.manage`, `tax.read`, `tenant.manage`. La
seule qui aille dans l'autre sens est `billing.pay`, et elle suffit à décrire le
modèle.

Deux surprises que les matrices ne montrent pas, et qui expliquent la moitié de
ce document :

**Un administrateur qui n'a rien acheté n'est titulaire de rien.** Administrer
Acme ne donne aucun droit sur Plan. Le monde de démonstration l'affirme
explicitement, parce que c'est la conséquence la plus contre-intuitive du
modèle.

**Acheter couvre des gens ; appartenir ne couvre personne** (ADR-053). Rejoindre
une organisation donne un rôle, jamais un droit d'usage.

---

# 1. USER — la personne qui utilise le produit

## Qui c'est

Quelqu'un que son organisation a ajouté, ou qui s'est inscrit lui-même depuis la
vitrine (ADR-041, ADR-049). Il tient 21 permissions, dont une que
l'administrateur n'a pas : **`billing.pay`**. C'est la seule asymétrie dans ce
sens, et c'est le cœur du modèle — sur cette plateforme, **la personne achète,
l'organisation administre**.

## Le déroulé

### 1. Arriver

Trois portes, et une seule est publique.

| Il vient de | Ce qui se passe |
|---|---|
| la vitrine `/` | il lit l'histoire du produit, les offres, les prix — sans compte |
| une inscription | `signUp` crée la personne, le tenant si besoin, et le connecte |
| une invitation | un administrateur ou un titulaire de siège l'a ajouté ; il reçoit un lien pour choisir un mot de passe |

Ce qu'il voit de la vitrine dépend de la politique d'adhésion de
l'organisation (`OPEN`, `APPROVAL`, `DOMAIN`, `INVITATION`) : ouverte, il est
membre aussitôt et peut acheter dans la minute.

### 2. Se connecter

`POST /auth/token` rend un jeton d'accès que la page garde **en mémoire** et pose
le jeton de rafraîchissement dans un cookie `HttpOnly` que le script ne peut pas
lire. Aucun stockage du navigateur ne contient de justificatif. Un rechargement
reprend en demandant `/auth/refresh`, pas en lisant le disque.

Un rafraîchissement fait tourner le jeton et révoque celui qu'il a reçu.
Présenter un jeton déjà dépensé révoque **toutes les sessions du compte** : du
point de vue du serveur, un rejeu et un vol sont indiscernables, et leurs coûts
ne le sont pas (ADR-038).

### 3. Atterrir

Le shell décide où ouvrir, dans cet ordre :

```text
?product=                  une adresse qui dit lequel on veut maintenant
localStorage               ce que ce navigateur a retenu
son propre défaut          choisi sur son profil
celui de l'organisation    réglé par l'administrateur (2026-09-26)
VITE_DEFAULT_PRODUCT       la constante du bundle, en dernier
```

Puis il est déposé sur **le premier écran de son propre menu** — son travail
pour un membre — une seule fois par session de navigation, pour que `/` reste
une adresse qu'on peut relire.

Ce qui apparaît dans ce menu vient de `/me/permissions`, `/me/entitlements` et
`/me/navigation`. Masquer n'est qu'une politesse : **l'API refuse de toute
façon**, et le frontend n'est jamais l'autorité.

### 4. Choisir et acheter — les six opérations qui n'appartiennent qu'à lui

C'est ici que le modèle se voit. La surface locataire **ne vend qu'un siège** :
un abonnement qui appartient à une personne (ADR-055). Il n'y a pas de bouton
« acheter pour l'organisation », et il n'y en a pas parce que `Sales::order()`
n'a nulle part où l'exprimer — une signature sans le second cas, plutôt qu'un
bouton que personne n'affiche.

Le siège est **vendu par son organisation, à lui** : le fournisseur sur la
facture est Acme, le client est Ada, le numéro sort de la série d'Acme
(ADR-054), et la TVA est celle du pays d'Acme (ADR-057).

Ce qu'il lit avant de choisir, sous `catalog.read` — `listPlans`,
`listOffers`, `showOffer`, `listFeatures` : les plans dans l'ordre de leur
**rang**, qui est le seul ordre qui existe. Rien ici ne sait que Pro vaut mieux
que Starter, et rien ici ne doit le savoir : une montée en gamme est une
comparaison de deux entiers, jamais de deux mots.

### 5. Couvrir des collègues

Son offre vend un nombre de places, **lui compris**. Il ajoute qui il veut dans
cette limite, par identifiant ou par adresse ; quelqu'un sans compte en reçoit
un et un lien pour choisir son mot de passe. Seul **le propriétaire** décide qui
son abonnement couvre : ce n'est pas une permission qu'on accorde, c'est à qui
appartient la chose.

### 6. Travailler

L'atelier pose **deux questions et non une** :

```text
requireSubscription()   ce travail est-il à sa portée ?   → SUBSCRIPTION_REQUIRED
requirePermission()     qu'a-t-il le droit d'en faire ?   → PERMISSION_DENIED
```

Les deux refus se lisent différemment, et c'est délibéré : le premier se règle
avec un collègue qui vous donne une place, le second avec un administrateur.
Aucun des deux ne dit « achetez » à quelqu'un dont l'organisation paie déjà.

### 7. Être facturé, et payer

Il lit **ses** documents et pas ceux de ses collègues : les commandes qui ont
acheté son siège, les factures qu'elles ont levées, les paiements dessus. Un
administrateur voit tout ; lui voit ce qui le concerne.

### 8. Parler

Une conversation appartient à un couple (tenant, produit). Deux genres, et la
différence est un invariant de la base, pas une convention : `INTERNAL` entre
membres, `SUPPORT` où le personnel de la plateforme peut apparaître.

### 9. Être averti

Une notification n'est jamais un message. Il règle ses préférences et ses
consentements par canal ; une préférence absente vaut activée, sauf le
marketing. **Les notifications de sécurité ne se coupent pas** — une alerte que
le destinataire peut museler est une alerte qu'un attaquant peut museler.

### 10. Partir

Quand un administrateur le retire de l'organisation, ses places sur les
abonnements des autres sont rendues dans la même transaction (2026-09-26). Ce
qu'il possède lui-même — un siège qu'il a acheté — ne bouge pas : c'est une
décision commerciale, et la prendre en silence serait la prendre.

## Toutes ses actions, et ce qu'elles changent

### Ce qu'il peut faire et que l'administrateur ne peut pas — `billing.pay`

| Action | Écran | Ce que ça change |
|---|---|---|
| `placeOrder` | `/catalogue` | Crée une commande `PENDING` **au nom de l'acheteur**, tarifée sous le régime de TVA sous lequel elle sera facturée. Refuse `SEAT_ALREADY_ACTIVE` s'il tient déjà un siège vivant sur ce produit, et `BILLING_PROFILE_REQUIRED` si l'organisation n'a pas dit d'où elle vend. Rien n'est facturé. |
| `openCheckoutSession` | `/catalogue` | Commande **et** facture en un geste, et ouvre un paiement chez le prestataire. Le `client_secret` est rendu une fois et jamais récupérable (ADR-034). La facture porte un numéro légal dès cet instant. |
| `showCheckoutSession` | `/checkout/{id}` | Rien. La page d'état sur laquelle un rechargement retombe — sans le secret. |
| `cancelCheckoutSession` | `/checkout/{id}` | Annule la facture (`ISSUED` → `CANCELLED`, **numéro conservé**) puis abandonne la commande. Les deux ensemble ou aucun. |
| `startPayment` | `/invoices/{id}` | Ouvre une tentative de paiement chez le prestataire contre une facture. Aucune donnée de carte ne touche PostgreSQL (§24). |
| `retryPayment` | `/payments` | Une **nouvelle tentative**, jamais la même relancée : l'unicité côté prestataire est sur la clé de tentative. |

L'abonnement ne démarre **pas** à la commande. Il démarre quand la facture est
payée — une commande honorée à l'instant où elle est passée fait crédit à tous
ceux qui savent atteindre l'endpoint.

### Son abonnement et les gens qu'il couvre — `subscription.*`

| Action | Ce que ça change |
|---|---|
| `showSubscription`, `showSchedule`, `listEntitlements` | Rien. Ce qu'il tient, jusqu'à quand, et ce que ça ouvre. |
| `changeOffer` | Déplace l'abonnement vivant sur d'autres conditions **en gardant sa période**. Le prorata est de la facturation et n'est pas fait ici. |
| `cancelSubscription` | Enregistre une **décision** — identifiant de règle, effet, date d'effet, mois dus, motifs — jamais un `cancelled: true`. Sous engagement, la demande est refusée ou différée selon la politique de l'offre, et un rachat éventuel est facturé **sur la transaction de la résiliation**. |
| `resumeSubscription` | Retire une résiliation programmée, tant que l'abonnement est encore vivant. |
| `listSubscriptionPeople` | Rien. Qui son abonnement couvre, en plus de lui. |
| `addSubscriptionPerson` | Ajoute quelqu'un dans le quota `users`, **lui compris**. Refuse `PEOPLE_QUOTA_REACHED` quand les places sont prises, `ALREADY_THE_OWNER` pour lui-même. Un inconnu nommé par adresse reçoit un compte et un lien d'invitation. |
| `removeSubscriptionPerson` | Retire la couverture. La personne garde son appartenance et perd l'accès au travail. |

### Son travail — `projects.*`, `assets.*`, `jobs.read`

| Action | Ce que ça change |
|---|---|
| `listProjects`, `showProject`, `listProjectVersions`, `showProjectVersion` | Rien. |
| `createProject` | Crée un projet, compté contre le quota `max_projects` de **celui qui demande**, sous une `schema_version` que le produit déclare. |
| `updateProject` | Écrit le document. |
| `deleteProject` | Suppression réversible ; `undeleteProject` la défait. |
| `createProjectVersion` | Fige un instantané, pour y revenir. |
| `duplicateProject` | Un nouveau projet, compté contre le quota comme n'importe quel autre. |
| `restoreProject` | Ramène le document à l'une de ses versions. |
| `listAssets`, `showAsset` | Rien. |
| `uploadAsset` | Écrit un fichier et sa ligne, dans le périmètre du tenant. |
| `deleteAsset` | Le retire. |
| `createAssetLink` | Émet un lien **signé et daté** que le navigateur suit sans session. |
| `requestExport` | Met un export en file. Le fichier arrive comme un asset quand la tâche passe. |
| `listJobs`, `showJob` | Rien. Ce que la file fait de ses demandes. |
| `measureGeometry`, `intersectGeometries` | Rien n'est écrit. Gardés par la **capacité** `gis.access` et non par une permission : la géométrie ne touche aucune donnée du tenant, donc la question n'est pas ce qu'un rôle en fait mais ce que le plan vend. |

### Ses documents — `billing.read`, `payments.read`, `sales.read`

Lectures seules, et **cadrées sur lui** : `listOrders`, `showOrder`,
`listInvoices`, `showInvoice`, `showInvoicePdf`, `listCreditNotes`,
`listTransmissions`, `listPayments`, `showPayment`, `showBillingProfile`. Un
membre voit les documents de son propre siège ; l'administrateur voit ceux de
tout le monde. Le même endpoint, deux périmètres, décidés par la permission et
non par un paramètre.

### Ses conversations — `messages.*`

| Action | Ce que ça change |
|---|---|
| `listConversations`, `showConversation`, `listMessages` | Rien. La relecture se fait avec `since_seq`, par sondage : R2 n'autorise pas de processus persistant, donc ni WebSocket ni SSE tenu ouvert. |
| `startConversation` | Ouvre un fil sur (tenant, produit), `INTERNAL` ou `SUPPORT`. |
| `postMessage` | Écrit un message. L'auteur **doit** être participant — par clé étrangère, pas par vérification applicative. |
| `deleteMessage` | Efface vraiment le corps ; la ligne reste en pierre tombale pour que le fil garde son ordre. C'est l'inverse d'une facture, que la loi impose de garder (§26), et les deux règles sont voulues. |
| `markRead` | Avance un filigrane par participant (`last_read_seq`), monotone. Pas une ligne par message lu. |
| `addParticipant`, `removeParticipant` | Change qui lit le fil. Le personnel de la plateforme ne peut jamais apparaître dans un `INTERNAL`. |
| `closeConversation` | Ferme le fil. |

### Son compte — `account.*`, `notifications.*`

| Action | Ce que ça change |
|---|---|
| `showMe`, `showMyPermissions`, `showMyNavigation`, `showMyEntitlements` | Rien. Ce que le shell lit pour décider ce qui existe pour cette personne. |
| `updateMe` | Nom affiché, langue, **produit par défaut personnel** — celui qui l'emporte sur le réglage de l'organisation. |
| `listNotifications`, `unreadCount`, `showDeliveries` | Rien. Le compte de non-lues est dérivé à la lecture, jamais ajusté localement. |
| `readNotification`, `readAll` | Marque comme lu, côté serveur. |
| `showPreferences`, `listConsents` | Rien. |
| `savePreference` | Active ou coupe un canal pour une catégorie. Une préférence absente vaut activée — sauf le marketing. Les `SECURITY` ne se coupent pas. |
| `grantConsent`, `revokeConsent` | Écrit ou retire un consentement daté. Sans consentement, SMS et WhatsApp ne sont **pas tentés** : la livraison est écrite `SUPPRESSED` avec son motif, parce que « les a-t-on prévenus ? » doit avoir une réponse. |

### Ce qu'il lit sans permission particulière

`signIn`, `refreshSession`, `signOut`, `verifyEmail`, `forgotPassword`,
`resetPassword` — l'authentification elle-même. `showMe`, `listProducts`,
`showProduct`, `showProductCatalogue`, `listProductFeatures`,
`showProductConfiguration`, `showSkin` — ce qu'un membre voit du simple fait
d'être membre. Tout le reste passe par une permission.

`showProductConfiguration` mérite une ligne : la `schema_version` d'un projet en
sort, **jamais d'une constante**. Coder en dur le fait d'un produit dans un
client partagé, c'est `gate:products` déplacé là où les gardes du backend ne le
voient pas.

---

# 2. TENANT_ADMIN — celui qui administre l'organisation

## Qui c'est

L'administrateur **du client**, jamais l'opérateur de la plateforme. C'est un
rôle porté par une appartenance, pas une propriété d'une personne : la même
personne est administratrice chez Acme et simple membre chez Globex.

Il tient 30 permissions. Il **n'a pas** `billing.pay` depuis le 2026-09-25, et
c'est une décision, pas un oubli : un administrateur qui ne s'abonne à rien se
voyait proposer *Acheter pour vous-même* sur chaque offre du catalogue. Ce que
ce bouton faisait était cohérent — un siège à son nom, facturé par son
organisation à lui-même — et n'était pas le modèle.

Ce qui lui reste du côté de l'argent est **l'encaissement** : la somme arrive
hors plateforme et il l'enregistre (`billing.manage`).

## Le déroulé

### 1. Il reçoit une organisation

Soit l'installateur l'a nommé premier administrateur (ADR-039), soit le
personnel de la plateforme a créé le tenant et l'y a mis. La plateforme lui
**assigne des produits** (`tenant_products`, ADR-047) ; il ne se les donne pas.

### 2. Il donne une identité à l'organisation

Le nom, la politique d'adhésion, le **produit d'ouverture**, l'identité de
facturation, le profil fiscal, la marque. Rien de tout cela ne se devine :
l'organisation qui n'a pas dit de quel pays elle vend ne vend pas.

### 3. Il met des gens dedans

Ajouter, changer de rôle, retirer — **à travers tous les produits que le tenant
détient à la fois**. Une appartenance est celle du tenant et n'est que reflétée
sur chaque produit ; le produit qu'il regardait en tapant l'adresse d'un collègue
ne décide de rien.

### 4. Il regarde ce que ses gens tiennent

L'écran *Abonnements* de l'organisation : qui détient quoi, pour combien,
jusqu'à quand, et combien des places vendues sont prises. Il ne l'a pas acheté ;
il l'administre, et **il ne peut pas administrer ce qu'il ne voit pas**.

### 5. Il facture et il encaisse

C'est son organisation qui émet les factures de sièges. Il les lève, les marque
payées, les annule, les crédite ; il rembourse les paiements. Chacun de ces
gestes est irréversible d'une manière différente, et le tableau plus bas dit
laquelle.

### 6. Il tient la comptabilité de TVA

Profil fiscal, taux, transactions, périodes. Clôturer une période **dépose une
déclaration et la fige, définitivement**, par une règle que la base impose.

### 7. Il ne travaille pas, sauf s'il a acheté

Tous ses droits d'administration ne lui ouvrent aucun projet. S'il veut
travailler dans le produit, il achète un siège comme tout le monde — et il ne
peut pas, faute de `billing.pay`. En pratique : un collègue lui donne une place,
ou il porte aussi un rôle `USER` ailleurs.

C'est le point du modèle le plus facile à trouver surprenant, et le monde de
démonstration l'affirme à voix haute pour cette raison.

## Toutes ses actions, et ce qu'elles changent

### L'organisation — `tenant.manage`, `skin.manage`

| Action | Ce que ça change |
|---|---|
| `showCurrentTenant`, `showTenantUsage` | Rien. L'organisation et sa consommation contre les quotas. |
| `updateCurrentTenant` | Le nom, la politique d'adhésion et ses domaines, le **produit d'ouverture**. Le `slug` n'est pas modifiable : il figure peut-être déjà dans des références stockées. Un produit que l'organisation ne détient pas est refusé **400**. |
| `listOrganisationSubscriptions` | Rien. Le registre de ce que ses gens tiennent, paginé, les vivants d'abord. `tenant.manage` et non `subscription.manage` — ce dernier, un USER le tient aussi. |
| `updateSkin` | Couleurs et libellés de la marque. |
| `uploadSkinLogo`, `deleteSkinLogo` | Pose ou retire le logo. |

### Les gens — `members.manage`

| Action | Ce que ça change |
|---|---|
| `listMembers`, `listJoinRequests` | Rien. |
| `addMember` | Ajoute une personne **existante** par adresse, sur tous les produits du tenant. Quelqu'un qui ne s'est jamais connecté n'a pas de compte et ne peut pas être ajouté : inviter est un autre cycle de vie. Une adresse partagée par deux comptes est refusée `AMBIGUOUS_USER` plutôt que devinée. |
| `updateMember` | Remplace ses rôles — la liste envoyée est toute la vérité, un rôle omis est retiré. Refuse de retirer **le dernier administrateur** : un tenant sans administrateur ne peut plus en nommer un. |
| `removeMember` | Retire l'appartenance sur tous les produits **et rend les places** que l'organisation payait pour cette personne, dans la même transaction. Ne touche pas un abonnement dont elle est propriétaire. |
| `acceptJoinRequest` | Rend l'appartenance vivante, sur tous les produits. |
| `declineJoinRequest` | Refuse la demande. |

### L'argent — `billing.manage`, `payments.manage`

| Action | Ce que ça change |
|---|---|
| `saveBillingProfile` | L'identité légale à qui les documents sont adressés, **et** le pays depuis lequel l'organisation vend ses sièges. Sans pays, aucun siège ne se vend. |
| `issueInvoice` | Lève une facture contre l'abonnement de l'organisation et lui **alloue un numéro légal dans une série sans trou**. Irréversible : une facture levée par erreur ne se supprime pas, elle se crédite. |
| `markInvoicePaid` | Enregistre un virement reçu hors plateforme. C'est ce geste qui **démarre l'abonnement** quand la facture est celle d'une commande. Ne prend aucune donnée de carte et n'en prendra jamais. |
| `cancelInvoice` | `ISSUED` → `CANCELLED`, **numéro conservé**. Un trou dans la séquence est une question d'auditeur. |
| `issueCreditNote` | Crédite intégralement une facture, dans la série de **l'émetteur de cette facture**, et écrit la **transaction de TVA inverse** — les faits de l'original avec les deux montants négatifs, taux et règle copiés, jamais recalculés, datés du jour de la correction. |
| `refundPayment` | Rembourse un paiement chez le prestataire. `payments.manage`, l'administrateur — pas `billing.pay`, l'acheteur. |
| `submitInvoice` | Transmet la facture au PDP. La réponse arrive plus tard par webhook. |

### La fiscalité — `tax.read`, `tax.manage`

| Action | Ce que ça change |
|---|---|
| `showTaxProfile` | Rien. Qui est le client, fiscalement. |
| `saveTaxProfile` | Écrit le genre de client, le pays, l'assujettissement, et **fait vérifier** le numéro de TVA par l'adaptateur VIES. Le résultat est stocké avec sa date comme preuve d'audit, et il **échoue fermé** : VIES injoignable laisse le numéro non prouvé, jamais requalifié en autoliquidation. Un `B2C` assujetti est refusé **400** ; la base l'interdit depuis toujours et rien au-dessus ne le disait, ce qui donnait un 500 (2026-09-26). |
| `listTaxRates` | Rien. Les taux et leurs fenêtres de validité. |
| `calculateTax` | Rien n'est écrit. Ce qui s'appliquerait, et **pourquoi** — le même chemin de code que la facturation, pour que deux implémentations ne dérivent pas. |
| `listVatTransactions` | Rien. Les faits fiscaux de l'organisation, les siens seuls. |
| `listVatPeriods`, `showVatPeriod` | Rien. |
| `closeVatPeriod` | **Dépose une déclaration et fige la période, définitivement.** Une correction va dans une période ultérieure, jamais en arrière — la même règle que la numérotation sans trou et que l'avoir. |

### Les ventes — `sales.manage`

| Action | Ce que ça change |
|---|---|
| `listOrders`, `showOrder` | Rien. Toutes les commandes de l'organisation, pas seulement les siennes. |
| `fulfilOrder` | **Lève la facture** de la commande — un numéro légal est dépensé. N'accepte qu'une commande `PENDING` : une commande déjà en attente de paiement a déjà sa facture, et en lever une seconde parce que quelqu'un a cliqué deux fois donne un document qu'on ne peut plus retirer. Refuse si l'offre a été retirée ou reversionnée entre-temps. |
| `cancelOrder` | Annule une commande **sans facture**. Une commande facturée se défait en créditant sa facture, pas en l'annulant. |

### Le catalogue prêté — `catalog.manage` (ADR-040)

Seulement si la plateforme a **prêté** l'édition du catalogue à ce tenant. Par
défaut c'est non, et `catalog.manage` n'est alors pas résolu du tout pour ses
membres — les offres sont le tarif de la plateforme, et les éditer change ce
qu'on vend à tous les autres clients du produit.

| Action | Ce que ça change |
|---|---|
| `createOffer`, `renameOffer` | Crée ou renomme une offre. |
| `listOfferVersions` | Rien. |
| `addOfferVersion` | Ajoute une version `DRAFT`, modifiable. |
| `publishOfferVersion` | **Fige la version**, définitivement. Une version publiée ne se modifie jamais : c'est ce qui permet à un devis d'épingler la version qui l'a tarifé (ADR-033). Changer des conditions publiées veut dire ajouter une version. |

### La file — `jobs.manage`

`requestJob` met une tâche en file ; `cancelJob` la retire tant qu'elle n'a pas
démarré. Les lectures (`listJobs`, `showJob`) sont celles de tout membre.

### Et tout ce qu'il partage avec un USER

Les 67 opérations du chapitre précédent — son compte, ses notifications, les
conversations, l'abonnement qu'il détiendrait, le travail qu'il pourrait faire —
il les a toutes, aux mêmes conditions. Y compris `requireSubscription()` : **ses
permissions ne lui ouvrent aucun projet**.

---

# 3. PLATFORM_ADMIN — l'opérateur de la plateforme

## Qui c'est

Celui qui fait tourner la plateforme. Il tient 19 permissions plateforme, dont
les cinq `admin.*` de supervision. Il **n'est membre d'aucun tenant du fait de
ce rôle**, et son rôle n'en fabriquera jamais une.

Trois autres rôles plateforme existent et sont beaucoup plus étroits :

| Rôle | Ce qu'il tient | Pour |
|---|---|---|
| `SUPPORT_ADMIN` | `support.read`, `support.respond`, `staff.tenants.read`, `admin.health.read` | répondre, lire un dossier client |
| `FINANCE_ADMIN` | `admin.finance.read`, `staff.tenants.read` | les chiffres |
| `SALES_ADMIN` | `admin.finance.read`, `staff.tenants.read` | idem, pour l'instant |

Tous les trois tiennent `staff.self.read`. Aucun ne peut écrire chez un client.

Ses routes vivent sous `/api/v1/staff/*` et `/api/v1/admin/*`, prennent le
tenant en **paramètre explicite**, et sont autorisées par le rôle plateforme —
jamais par le paramètre. **Chaque accès à la donnée d'un client écrit une ligne
d'audit** : qui, quand, quel tenant, quelle ressource, à quel titre.

Il n'y a jamais de `if (isStaff)` dans un contrôleur locataire. Les deux
surfaces sont séparées de bout en bout : routes, permissions, contrôleurs.

## Le déroulé

### 1. Il installe

L'installateur crée le premier administrateur plateforme (ADR-039). Il atterrit
dans la console, pas dans une application de produit.

### 2. Il crée des produits

Un produit, son code, son nom, son ordre d'affichage, son URL d'application. Puis
ses **clés** pour que le produit parle à la plateforme, et son secret de webhook
pour qu'elle lui parle.

### 3. Il dit qui vend

L'identité de facturation et les réglages de TVA du produit. **Tant que personne
ne l'a dit, le produit ne peut pas facturer** (ADR-044) — l'écran *Préparation*
en fait la liste.

### 4. Il écrit le catalogue

Fonctionnalités (la liste est celle de la plateforme, pas d'un produit —
ADR-052), plans, offres, versions. Puis il choisit lesquelles la vitrine
publique met en avant.

### 5. Il raconte l'histoire

La page publique d'un produit : bandes, images, traductions, puis publication.

### 6. Il prend des clients

Créer un tenant, lui **assigner des produits**, lui prêter l'édition du
catalogue, lui **octroyer** des fonctionnalités sans vente — et dire si cet
octroi ouvre le produit à ses gens, ce qui est la différence entre un essai et
une exception de support (ADR-056).

### 7. Il répond

Les conversations de support, le journal d'accès, l'audit.

### 8. Il surveille

Métriques, file de tâches, annuaire, factures et abonnements de toute la
plateforme.

### 9. Il efface

Une demande d'effacement §26, qui est la seule opération vraiment destructive de
la console.

## Toutes ses actions, et ce qu'elles changent

### Lui-même — `staff.self.read`

`showStaffIdentity`, `showStaffNavigation` (ce que la console lit pour
construire son menu) et `updateStaffProfile` (son nom affiché et sa langue).

### Les produits — `staff.products.manage`

| Action | Ce que ça change |
|---|---|
| `listPlatformProducts` | Rien. Y compris les produits retirés, que toutes les autres vues filtrent déjà. |
| `createProduct` | Crée un produit. Son `code` est le mot que tout le reste emploie. |
| `updateProduct` | Nom, activité, ordre d'affichage, URL d'application, URL de webhook. Retirer un produit le rend absent partout ailleurs. |
| `listProductCredentials` | Rien — **jamais le secret**, seulement son identifiant et son état. |
| `issueProductCredential` | Émet une clé produit. Le secret est rendu **une fois**. |
| `revokeProductCredential` | La révoque. Les appels qui la présentent cessent. |
| `issueWebhookSecret` | Fait tourner le secret de signature. Les livraisons signées avec l'ancien échouent. |
| `listWebhookDeliveries` | Rien. L'enveloppe et son sort, **jamais la charge utile**. |
| `retryWebhookDelivery` | Remet une livraison en file. |
| `showStaffConfiguration` | Rien. |
| `setBillingIdentity` | Dit **qui vend** ce produit. Sans elle, aucune facture de la plateforme ne peut être levée. |
| `setTaxSettings` | Pays, OSS, nature de la prestation, devise — et depuis 2026-09-26, assujettissement. |
| `showProductReadiness` | Rien. Ce qui manque encore à un produit pour être vendable. |
| `showProductStory`, `writeProductStory` | Lit et écrit la page publique, en brouillon. |
| `publishProductStory` | La rend visible aux inconnus. |
| `uploadShowcaseAsset` | Pose une image de cette page. |

### Le catalogue — `staff.catalog.manage`, `staff.features.manage`

| Action | Ce que ça change |
|---|---|
| `listPlatformFeatures` | Rien. La liste est **celle de la plateforme**, pas d'un produit : `max_projects` ne peut pas vouloir dire une chose ici et une autre ailleurs. |
| `createFeature` | Ajoute un mot au vocabulaire commun. |
| `renameFeature` | Le renomme, ou le **retire** (`active = false`). Une fonctionnalité ne se supprime jamais : des versions d'offres et des droits vivants la nomment. Retirée, aucune nouvelle offre ne peut l'accorder et tous ceux qui l'ont gardent ce qu'ils ont acheté. |
| `listTranslations` | Rien. **Tout ce que l'exploitant a écrit**, dans toutes les langues où il l'a écrit : nom et description de chaque fonctionnalité, nom de chaque offre, avec l'anglais qui leur sert de clé. Les écrans d'origine traduisent déjà ligne par ligne ; ce que personne ne pouvait répondre, c'est « qu'est-ce qui manque en italien ? », parce que la réponse traverse des tables qui n'ont rien d'autre en commun. Ce n'est pas le vocabulaire de l'application — libellés, boutons, messages d'erreur vivent dans les catalogues JSON du bundle (ADR-050) et n'ont rien à voir avec ceci. Non paginée, exprès : une page ne peut pas compter ce qui manque. |
| `showStaffCatalogue` | Rien. |
| `createPlan`, `updatePlan` | Un plan et son rang. Le rang est le seul ordre qui existe : une montée en gamme est une comparaison de deux entiers, jamais de deux mots. |
| `createStaffOffer`, `renameStaffOffer` | Une offre. |
| `createStaffOfferVersion` | Une version `DRAFT`, avec ses conditions — prix, périodicité, durée, engagement, politique de résiliation, préavis, fonctionnalités accordées. |
| `publishStaffOfferVersion` | **La fige, définitivement** (ADR-033). |
| `listStorefrontOffers`, `setOfferPublicListing` | Décide ce que la vitrine montre à un inconnu. |
| `showStorefrontSettings`, `setStorefrontSettings` | Le tenant par défaut de l'hôte nu, et ce que la vitrine met en avant. |

### Les clients — `staff.tenants.read`, `staff.tenants.manage`, `staff.grant`

Chacune de ces opérations écrit une ligne dans `staff_access_log`.

| Action | Ce que ça change |
|---|---|
| `listTenantsForStaff`, `showTenantForStaff` | Rien, sauf la ligne d'audit. |
| `createTenantForStaff` | Crée une organisation. |
| `updateTenantForStaff` | Son nom. |
| `setTenantOfferAuthoring` | **Prête** ou reprend l'édition du catalogue (ADR-040). |
| `assignTenantProduct` | Donne un produit au tenant et **répercute l'appartenance de chaque membre** dessus, avec les rôles qu'il tient déjà. Refuse un produit retiré. |
| `unassignTenantProduct` | Le retire, avec les appartenances par cascade — et **efface le produit d'ouverture** s'il était celui-là. Refuse `PRODUCT_IN_USE` tant qu'un abonnement est encore dû en service : un produit retiré avant la fin de la période payée est un client coupé de ce qu'il a réglé. |
| `showTenantEntitlement` | Rien. Ce que la plateforme a donné sans vente. |
| `grantTenantEntitlement` | Écrit un octroi — des fonctionnalités, des limites, une échéance ou aucune — et dit s'il **couvre les gens** de l'organisation. Sans ce drapeau, les fonctionnalités s'allument et chaque atelier refuse : c'est une exception de support. Avec, c'est un essai. Faux par défaut : la réponse la plus large se choisit. |
| `withdrawTenantEntitlement` | Retire l'octroi. L'atelier se referme le cas échéant. |
| `listTenantMembersForStaff`, `listTenantPaymentsForStaff`, `listTenantOrdersForStaff`, `listTenantQuotesForStaff`, `listTenantProjectsForStaff`, `listTenantJobsForStaff`, `showTenantTaxProfileForStaff` | Rien, sauf la ligne d'audit. Le dossier d'un client, en lecture. |

### Le personnel — `staff.grant`

`listPlatformStaff` ne change rien ; `grantPlatformRole` et `revokePlatformRole`
donnent ou retirent un rôle plateforme. **Ni l'un ni l'autre ne touche une
appartenance à un tenant** — c'est la frontière, et elle est dans deux tables.

### Le support — `support.read`, `support.respond`, `staff.access_log.read`

`listSupportConversations` et `showSupportConversation` lisent les fils
`SUPPORT` — et **jamais un `INTERNAL`**, que la base interdit au personnel.
`postSupportMessage` répond, `closeSupportConversation` ferme.
`listAccessLog` lit le journal de ce que le personnel a consulté chez les
clients.

### La plateforme — `admin.*`

| Action | Permission | Ce que ça change |
|---|---|---|
| `listAudit` | `admin.audit.read` | Rien. |
| `showMetrics`, `listAdminSubscriptions`, `listAdminInvoices` | `admin.finance.read` | Rien. Les chiffres, tous tenants confondus. |
| `listAdminTenants`, `listAdminUsers` | `admin.directory.read` | Rien. L'annuaire. |
| `showQueue`, `listAdminJobs` | `admin.health.read` | Rien. La santé de la file. |
| `eraseUser` | `admin.privacy.erase` | **Efface une personne** (§26) : nom et adresse vidés, les lignes qui la nomment conservées. Ce que la loi impose de garder — les factures — reste. La seule opération vraiment destructive de la console. |

### Les menus, le courrier, la démonstration

| Action | Permission | Ce que ça change |
|---|---|---|
| `showNavigationSetup`, `setNavigationSetup` | `staff.navigation.manage` | Un **second** filtre du menu, appliqué après les permissions et jamais à leur place. |
| `showMailTemplates`, `setMailTemplates` | `staff.mail.manage` | Les gabarits d'e-mail. |
| `sendTestMail` | `staff.mail.manage` | Envoie un message de test. Refusé si aucun serveur n'est configuré, plutôt que mis en file en silence. |
| `resetDemoWorld` | `staff.demo.reset` | **Détruit et reconstruit** le monde de démonstration. |
| `showDemoPage`, `setDemoPage` | `staff.demo.publish` | La page publique qui présente les comptes de démonstration. |

---

# Ce qu'aucun des trois n'atteint à la main

### Publiques — sept opérations, aucun compte

`signUp`, `getPublicOffers`, `getPublicOffer`, `listPublicProducts`,
`getPublicShowcase`, `showPublicTenant`, `showPublicDemo`. La vitrine vend à des
inconnus (ADR-041) ; ce que la plateforme *fait tourner* reste privé, seuls les
produits qui annoncent quelque chose de vendable sont listés.

### Machines — sept opérations

`health` et `getJwks` (des sondes), `paymentWebhook` et `einvoiceWebhook`
(authentifiés par **la signature du prestataire sur le corps brut**, vérifiée
avant qu'un seul champ soit lu), `downloadAsset` et `getPublicShowcaseAsset`
(suivis par le navigateur sur une adresse que le serveur a composée),
`showMyContext` (une sonde de diagnostic).

### Clés produit — quatre opérations

`showProductTenantEntitlements`, `reportProductUsage`,
`listProductTenantMembers`, `declareProductCapabilities`. Un produit déployé à
côté de la plateforme s'authentifie par une clé et **n'a pas de personne**
(ADR-051 §4) : ni session, ni rôle, ni appartenance.

---

# L'arithmétique

```text
                                          opérations
  atteignables par un USER seul                    6    billing.pay
  atteignables par un TENANT_ADMIN seul           34
  partagées par les deux                          67
  sans permission, tout membre                    16
  par capacité (gis.access)                        2
  ─────────────────────────────────────────────────
  surface locataire                              125

  PLATFORM_ADMIN                                  76
  publiques, sans compte                           7
  machines et sondes                               7
  clés produit                                     4
  ─────────────────────────────────────────────────
  total                                          219
```

Dont **106 lectures** et **113 écritures**. Chaque écriture a sa ligne dans les
tableaux ci-dessus ; les lectures sont nommées dans la zone à laquelle elles
appartiennent.

`composer run gate:roles` recompte à chaque build, dans les deux sens.

---

## Provenance

Les permissions par rôle viennent des migrations, telles que la base les crée.
L'attribution par opération vient du contrôleur qui la sert — jamais du nom de
l'endpoint : six permissions étaient fausses dans la première table de
navigation et tous les tests passaient quand même. Les zones d'écran et les
autorités viennent de `docs/ui-api-coverage.json`, que `composer run gate:ui`
vérifie déjà dans les deux sens.
