# Cycle de vie d'un abonnement — statuts, changement de plan, freemium

*Spécification, 26 septembre 2026. Complète `docs/architecture-v2.md` §13.1,
qu'elle ne remplace pas : les cinq durées, l'instantané des conditions et la
résiliation-décision y restent la référence.*

---

## 0. Ce que ce document ajoute

Quatre choses, dont **trois n'existent pas du tout** aujourd'hui :

1. un **prorata** à l'upgrade, et la facture qui le porte ;
2. un **downgrade différé**, et le bouton qui l'annule ;
3. un statut **`PAST_DUE`** et le recouvrement qui va avec ;
4. un plan **freemium** borné dans le temps.

Et une chose qui existe et qui est **fausse** : voir §1.

Ce qu'il ne touche pas : ADR-055 (la surface locataire ne vend que des
sièges), ADR-053 (acheter couvre des gens, appartenir ne couvre personne),
ADR-033 (une version publiée est figée). Un changement de plan est un
changement de *version d'offre* souscrite, jamais une modification de l'offre.

---

## 1. Ce que le code fait aujourd'hui — mesuré, pas supposé

`PostgresSubscriptionRepository::changeOffer()` fait exactement ceci :

```sql
UPDATE subscriptions SET offer_version_id = :versionId, updated_at = now()
 WHERE id = :id
```

…puis révoque les droits de l'ancienne offre et accorde ceux de la nouvelle,
**immédiatement**, en laissant la période intacte. Son propre commentaire dit
la suite : *« Prorating is billing (M6). »* M6 n'a jamais atterri.

Trois conséquences, toutes vivantes en production :

**a. Un downgrade prend effet tout de suite.** Le client qui a payé Pro
jusqu'au 31 perd Pro à l'instant où il choisit Starter. C'est exactement ce
que la pratique du marché considère comme punitif, et c'est ce que ce document
corrige.

**b. Un upgrade est gratuit.** Rien n'est facturé. Un client sur *Lecture*
(5,00 €) passe sur *Pro* (39,00 €) et ne paie la différence… jamais : au
renouvellement il paiera Pro, et le mois en cours lui est offert. Ce n'est pas
un prorata mal calculé, c'est **aucune facturation du tout**.

**c. Les conditions ne sont pas ré-instantanéisées.** `subscriptions` porte
`term_months`, `commitment_months`, `commitment_ends_at`,
`cancellation_policy`, `renewal`, `early_termination`, `notice_days` —
copiées depuis la version d'offre à la souscription (§13.1). `changeOffer`
n'écrit que `offer_version_id`. Après un changement, **l'abonnement porte les
conditions de l'offre qu'il a quittée** tout en pointant sur la nouvelle :
l'engagement de l'ancienne s'applique à la nouvelle, et la politique de
résiliation aussi.

> **Le (c) est un défaut indépendant de tout ce document et il se corrige
> d'abord.** Tant qu'il tient, le prorata et le downgrade différé se
> construiraient sur des conditions qui ne décrivent pas l'offre en cours.

`PAST_DUE` n'existe nulle part : `grep` ne le trouve ni dans `src/`, ni dans
`migrations/`. Les seuls statuts sont `ACTIVE`, `CANCELLED`, `EXPIRED`.

---

## 2. Le modèle de statuts

### 2.1 Une correction au tableau proposé

Le tableau de départ donne quatre lignes dont l'une, `canceled` + *« accès
jusqu'à la fin de la période »*, **existe déjà et n'est pas un statut**.

§13.1 l'a résolue autrement, et mieux : une résiliation est une **décision**
(règle, effet, date d'effet, mois dus, motifs), et l'abonnement reste `ACTIVE`
avec `cancel_at_period_end = true` et `cancel_effective_at` renseignée jusqu'à
ce que la date arrive. Le service est dû tant qu'il est payé.

Introduire un statut `CANCELED` qui **accorde encore les droits** mettrait le
même fait à deux endroits : le statut dirait « résilié » et les droits
diraient « actif », et le premier code qui lirait `status` pour décider de
l'accès se tromperait. Un statut qui ne décrit pas l'accès n'est pas un
statut.

**Donc :** `status` reste `ACTIVE | CANCELLED | EXPIRED`, on ajoute
`PAST_DUE`, et l'indicateur de transition est une colonne à part.

### 2.2 Le tableau, tel qu'il sera implémenté

| `status` | Indicateur | Droits | À `current_period_end` | Écran |
|---|---|---|---|---|
| `ACTIVE` | — | le plan courant | renouvellement sur le même plan | « Votre abonnement se renouvellera le [date]. » |
| `ACTIVE` | `cancel_at_period_end` + `cancel_effective_at` | **le plan courant, entier** | fin des droits (`EXPIRED`) | « Votre accès prendra fin le [date]. » + **Réactiver** |
| `ACTIVE` | `pending_offer_version_id` + `pending_effective_at` | **le plan courant, entier** — le cher | bascule sur la version en attente, période réinitialisée | « Vous passerez au plan [nom] le [date]. » + **Annuler le changement** |
| `PAST_DUE` | `past_due_since` | **suspendus** — voir §5 | nouvelle tentative de prélèvement | bannière : « Échec de paiement. » |
| `CANCELLED` | — | aucun | — | terminal |
| `EXPIRED` | — | aucun | — | terminal |

Les deux indicateurs sont **exclusifs** : un abonnement qui se résilie et qui
descend de plan à la même échéance n'a qu'une fin, et c'est la résiliation.
Une contrainte de base l'impose plutôt qu'un service :

```sql
CONSTRAINT subscriptions_one_ending CHECK (
    NOT (cancel_at_period_end AND pending_offer_version_id IS NOT NULL)
)
```

---

## 3. L'upgrade immédiat, avec prorata

### 3.1 La règle

Les droits basculent **tout de suite** — c'est déjà le cas et c'est bien.
S'ajoutent :

1. la **valeur non consommée** de la période en cours devient un crédit ;
2. ce crédit est déduit du prix de la nouvelle période ;
3. la différence est **prélevée immédiatement** ;
4. l'ancre de facturation est réinitialisée : `current_period_start = now()`,
   `current_period_end = now() + période de la nouvelle offre`.

### 3.2 Ce que la maison impose et que le marché ne dit pas

**Le calcul est du serveur, en unités mineures entières.** Jamais dans React :
« ne jamais additionner deux montants dans le frontend ; chaque total à
l'écran est celui du serveur » (CLAUDE.md, §4, §25). L'écran affiche un
aperçu **que le serveur a calculé**, comme `showSchedule` le fait déjà pour la
résiliation.

**La différence est facturée par la chaîne normale**, jamais par un chemin
spécial — la même règle que le rachat d'engagement. Elle alloue donc un
**numéro légal dans une série sans trou**, et cette facture ne se supprime
pas.

**Rien à payer ne lève aucun document.** Si le crédit couvre la nouvelle
période, on ne lève pas de facture à 0 € : « la numérotation est sans trou,
donc une facture à 0 € est l'enregistrement permanent et ineffaçable d'une
absence de transaction ».

**Les conditions sont ré-instantanéisées** au moment du changement — le défaut
§1(c).

### 3.3 Les deux décisions que le tableau de départ ne tranche pas

**L'engagement.** `commitment_ends_at` a été calculé depuis le démarrage
d'origine. Réinitialiser l'ancre ne doit ni le ré-armer en silence ni le
raccourcir : « le renouvellement ne ré-arme pas l'engagement en silence »
(§13.1), et un upgrade n'est pas un nouveau contrat.

> **Proposition :** l'engagement survit inchangé, et celui de la nouvelle
> offre ne s'applique que s'il finit **plus tard**. Un client engagé 24 mois
> qui monte en gamme reste engagé jusqu'à sa date d'origine ; il ne se
> ré-engage pas pour 24 mois de plus sans l'avoir dit.
> **À confirmer** — c'est une règle commerciale, pas technique.

**La TVA du crédit.** Un crédit de prorata **n'est pas un avoir**. Un avoir
défait un document et écrit la transaction de TVA inverse (ADR-057) ; ici on
tarife un document neuf, net de ce qui n'a pas été consommé. Deux montages :

| | Ce que ça donne | Coût |
|---|---|---|
| **(a) une ligne négative** sur la facture d'upgrade | ce que fait Stripe ; un seul document | une facture avec une ligne négative, à vérifier vis-à-vis du §25 et du PDP |
| **(b) un avoir** sur l'ancienne facture **+** une facture pleine | strictement conforme à ADR-057 : le fait fiscal d'origine est défait, le neuf est écrit | deux documents, deux numéros, à chaque upgrade |

> **(b) est recommandé** : il ne demande aucune règle fiscale nouvelle, et
> `CreditNotes` sait déjà négocier les faits de TVA d'une facture. Le coût est
> deux numéros par upgrade, ce qui est le prix de la conformité.
> **À confirmer par l'exploitant.**

### 3.4 Le piège n° 1 — les upgrades successifs

Upgrade le 1er, le 5, le 10. Chaque upgrade **réinitialise l'ancre**, donc au
5 la « période en cours » est celle ouverte le 1er, et le crédit se calcule
sur elle et non sur la période d'origine. La chaîne fonctionne **par
construction** dès lors que l'ancre est réinitialisée à chaque fois et que le
crédit se lit sur `current_period_start` / `current_period_end` de l'instant.

C'est la raison de la réinitialisation, et elle mérite d'être écrite : sans
elle, il faudrait tenir un solde de crédits, et un solde est un objet qu'il
faut expirer, rembourser et déclarer.

---

## 4. Le downgrade différé

### 4.1 La règle

Choisir un plan de rang inférieur **ne change rien tout de suite**. Cela écrit
une intention :

```sql
ALTER TABLE subscriptions
    ADD COLUMN pending_offer_version_id uuid REFERENCES offer_versions(id),
    ADD COLUMN pending_effective_at     timestamptz,
    ADD COLUMN pending_requested_at     timestamptz,
    ADD COLUMN pending_requested_by     uuid REFERENCES users(id);
```

`pending_effective_at` vaut `current_period_end` — le client a payé jusque-là.

Des colonnes et non une table de planification : il y a **au plus un**
changement en attente par abonnement, et une table en autoriserait plusieurs,
qu'il faudrait ensuite interdire par une contrainte. Une seule intention, une
seule ligne.

### 4.2 Annuler le changement programmé

`DELETE /api/v1/subscription/pending` — les trois colonnes repassent à NULL, un
`SubscriptionEvent` l'enregistre. C'est le **piège n° 2**, et c'est une
fonctionnalité de rétention avant d'être une fonctionnalité technique : le
client qui a vingt jours de Pro devant lui change souvent d'avis.

Le bouton est **obligatoire** dès lors que le downgrade est programmé. Un
changement futur qu'on ne peut pas défaire est une résiliation déguisée.

### 4.3 Le renouvellement doit l'appliquer

Le job de renouvellement ne peut plus se contenter de reconduire l'offre en
cours. À l'échéance, dans l'ordre :

```text
1. une résiliation est due      → on termine, et rien d'autre
2. un changement est en attente → on bascule dessus, période réinitialisée,
                                  conditions ré-instantanéisées, droits échangés
3. sinon                        → reconduction à l'identique
```

C'est la même forme que la règle existante : « le renouvellement ne peut pas
passer outre une résiliation déjà due ».

---

## 5. `PAST_DUE` et le recouvrement

### 5.1 Le statut

Un abonnement passe `PAST_DUE` quand une facture de renouvellement reste
impayée à son échéance. `past_due_since` date l'entrée.

**Ce que ça fait aux droits est la question qui compte.** `requireSubscription()`
décide si le travail est à portée (ADR-053) ; un abonnement `PAST_DUE` ne
couvre plus. Le refus reste `SUBSCRIPTION_REQUIRED` — mais l'écran doit dire
*pourquoi*, sans quoi l'utilisateur ira demander une place à un collègue alors
que c'est sa carte qu'il faut changer.

> **Décision demandée :** accès **restreint** (lecture seule) ou **suspendu**
> ? Le tableau de départ dit « restreint ou suspendu ». Suspendu est plus
> simple et plus dur ; restreint demande de décider ce qui reste, ce qui est
> une question par produit.

### 5.2 Le recouvrement

Les relances passent par la file M7 — **jamais dans la requête HTTP**. Chaque
tentative est un paiement à part entière : « une nouvelle tentative, jamais la
même relancée ».

Les avis sont des **notifications** (§27.1), pas des messages. Et la mise en
demeure a un **effet juridique**, donc on conserve le corps rendu, pour la
raison qu'une facture garde son instantané.

Un calendrier de relance (J+1, J+3, J+7, puis fin) est une valeur de
configuration du produit, pas une constante dans le code.

---

## 6. Le freemium

### 6.1 Ce qui a été demandé

Un plan freemium : **1 utilisateur, 1 projet, 5 jours** dans la démonstration.

### 6.2 Le rang 10 est déjà pris

Mesuré, dans chaque produit :

```text
plan   : lecture 5 · starter 10 · pro 20 · scale 30
atlas  :             starter 10 · pro 20 · scale 30
boreas :             starter 10 · pro 20 · scale 30
```

`Subscriptions::directionBetween()` décide upgrade ou downgrade **en comparant
les rangs**. Un freemium au rang 10 :

- entrerait en collision avec `starter` dans les quatre produits ;
- et, sur `plan`, se classerait **au-dessus** de *Lecture* (rang 5) — passer
  de Lecture à Freemium serait compté comme un **upgrade**, donc facturé avec
  un prorata.

**La convention de la démonstration est : le plus petit rang vaut 10, et les
rangs montent de 10 en 10.** Le freemium étant le plan le plus bas, il prend
donc **10**, et le reste monte d'un cran :

```text
avant                          après
  lecture   5                    freemium  10     nouveau
  starter  10                    lecture   20
  pro      20                    starter   30
  scale    30                    pro       40
                                 scale     50
```

*Lecture* était déjà hors convention à 5 — c'est un plan en lecture seule
ajouté après les trois autres, glissé sous `starter` faute de place. La
renumérotation lui en donne une.

**Rien ne s'y oppose en base** : `plans` ne porte aucune contrainte d'unicité
ni de vérification sur `rank` (seulement `plans_code_unique` sur
`(product_id, code)`). Le rang n'est qu'un ordre, lu par
`directionBetween()`, et la démonstration se sème depuis `DemoWorld.php` —
**donc aucune migration**, une renumérotation de constantes.

> **Ce qui reste à confirmer** est l'ordre entre *Freemium* et *Lecture*. Le
> tableau ci-dessus met le gratuit sous le payant, ce qui fait de
> Freemium → Lecture un upgrade facturé au prorata et de Lecture → Freemium un
> downgrade différé. C'est cohérent, mais *Lecture* se vend 5,00 € et ne
> stocke rien : si la descente de Lecture vers Freemium ne doit rien coûter ni
> rien attendre, ces deux-là sont au même niveau commercial et c'est une autre
> conversation.

### 6.3 Pas de renouvellement, et pas de facture

`renewal` porte déjà `AUTO_RENEW`. Il lui faut une valeur voisine signifiant
que l'abonnement **s'arrête à son terme** : à `current_period_end`, le job
passe l'abonnement `EXPIRED` au lieu de le reconduire.

Le prix est 0. **Aucune facture n'est levée** — « rien à payer ne lève aucun
document », et une facture à 0 € est un trou permanent dans une série qui ne
doit pas en avoir. Ce qui veut dire qu'un freemium ne passe **pas** par
`openCheckoutSession` : il lui faut son propre chemin de souscription, sans
commande, sans facture et sans paiement.

`term_months` ne sait pas dire « 5 jours ». Deux possibilités : une colonne
`trial_days`, ou `current_period_end = now() + interval` posée à la
souscription sans terme. **La seconde est recommandée** — elle n'ajoute aucune
colonne et le freemium devient un abonnement d'une seule période qui ne se
reconduit pas.

### 6.4 Le freemium se prend **une seule fois**, jamais deux

Tranché (2026-09-26) : un compte a droit au freemium **une fois**, et une
fois pour toutes. Sans cette règle, cinq jours de gratuit se reprennent tous
les cinq jours et le produit est gratuit pour toujours par récurrence.

**Une fois pour toutes veut dire y compris terminé.** L'index qui l'impose ne
filtre donc **pas** sur le statut — c'est ce qui le distingue de
`subscriptions_one_active_per_scope`, qui ne regarde que les vivants. Un
freemium `EXPIRED` il y a six mois interdit toujours d'en reprendre un.

```sql
-- Le fait est recopié sur l'abonnement à la souscription, comme les
-- conditions (§13.1) : jamais une jointure vers la version d'offre, qui
-- dit ce que le plan est *aujourd'hui* et non ce qui a été vendu.
ALTER TABLE subscriptions ADD COLUMN is_freemium boolean NOT NULL DEFAULT false;

CREATE UNIQUE INDEX subscriptions_one_freemium_ever
    ON subscriptions (product_id, coalesce(subscriber_user_id, tenant_id))
 WHERE is_freemium;
```

`coalesce` porte les deux genres de souscripteur : la surface locataire ne
vend que des sièges (ADR-055), mais `TENANT` reste une colonne et porte les
lignes qu'un déploiement a déjà.

**Un index et pas une vérification applicative** — la même raison qu'à
§13.1 : deux souscriptions simultanées passent à travers un `SELECT` puis
`INSERT`, et pas à travers un index.

**Par produit**, parce qu'un abonnement nomme toujours un produit : goûter
Plan n'a jamais rien dit de Boreas.

L'écran doit le dire avant le refus. Le catalogue n'offre pas *Souscrire* sur
le freemium à quelqu'un qui l'a déjà eu, et le serveur refuse quand même —
`FREEMIUM_ALREADY_USED`, avec sa formulation dans `ErrorSurface` — parce que
masquer est une politesse et l'API décide.

---

## 7. L'écran catalogue

Les quatre actions, par offre, dérivées de l'état et du **rang** — jamais du
nom d'un plan (`gate:plans` l'interdit en PHP, et la règle vaut dans le
frontend) :

| Situation | Bouton | Ce qu'il dit |
|---|---|---|
| aucun abonnement | **Souscrire** | prix, période, engagement |
| rang supérieur | **Passer à ce plan** | « immédiat, [montant] à payer aujourd'hui » — montant **du serveur** |
| rang inférieur | **Descendre à ce plan** | « le [date], à la fin de votre période payée » |
| offre courante | — | « votre plan actuel » |
| changement en attente | **Annuler le changement** | « vous passerez à [plan] le [date] » |
| abonnement en cours | **Résilier** | la décision, avec sa règle et ses mois dus |

L'aperçu du prorata vient de l'API, comme `showSchedule` donne déjà
`if_cancelled_now`. Une nouvelle opération le rend :
`POST /api/v1/subscription/preview-change` → le crédit, le net à payer, la
nouvelle date d'échéance, et la règle qui a décidé.

---

## 8. Étapes de création

Chacune laisse `composer run gates` et `npm run build` au vert, et se livre
seule.

**Étape 1 — corriger l'instantané des conditions.** `changeOffer` recopie
`term_months`, `commitment_months`, `cancellation_policy`, `renewal`,
`early_termination`, `notice_days` depuis la nouvelle version. Test : changer
d'offre puis lire les conditions et constater qu'elles décrivent la nouvelle.
*Indépendant de tout le reste, et c'est un défaut vivant.*

**Étape 2 — le downgrade différé.** Migration (quatre colonnes + la
contrainte d'exclusivité), `Subscriptions::scheduleChange()` /
`cancelScheduledChange()`, deux opérations au contrat, le renouvellement qui
applique dans l'ordre du §4.3, l'écran. Tests : un downgrade ne touche aucun
droit ; le renouvellement bascule ; l'annulation remet à NULL ; résiliation et
downgrade ne coexistent pas.

**Étape 3 — l'aperçu du changement.** `preview-change`, en lecture seule : le
crédit, le net, la date. Se teste sans rien facturer, et l'écran peut être
écrit dessus avant que l'étape 4 n'existe.

**Étape 4 — l'upgrade avec prorata.** Le calcul dans le domaine (unités
mineures entières), la facture par la chaîne normale, l'ancre réinitialisée,
l'engagement traité selon la décision §3.3. Tests : upgrades successifs
(1er/5/10) ; rien à payer ne lève aucun document ; l'engagement ne se ré-arme
pas ; la TVA suit le montage retenu.

**Étape 5 — `PAST_DUE` et le recouvrement.** Le statut, l'effet sur
`requireSubscription()`, la file de relance, les notifications, la bannière.
Tests : un impayé suspend ; le refus se distingue de « votre organisation ne
vous couvre pas » ; une relance est une nouvelle tentative.

**Étape 6 — le freemium.** La renumérotation des rangs (10, puis de 10 en
10), l'unicité à vie du §6.4 — colonne et index, jamais une vérification
applicative — la valeur de `renewal`, le chemin de souscription sans facture,
et le plan dans la démonstration (1 utilisateur, 1 projet, 5 jours) avec sa
vérification dans `DemoFixtures::verify`. Test : un freemium terminé interdit
toujours d'en reprendre un.

**Étape 7 — l'écran catalogue**, une fois que 2, 3 et 4 répondent.

> L'ordre n'est pas négociable entre 1 et 4 : prorater des conditions qui
> décrivent l'offre quittée donnerait un montant juste sur le mauvais contrat.

---

## 9. Questions ouvertes pour l'exploitant

1. **L'engagement survit-il à un upgrade** sans se ré-armer ? (§3.3)
2. **Le crédit de prorata** : ligne négative, ou avoir + facture pleine ?
   (§3.3)
3. **`PAST_DUE`** : accès restreint ou suspendu ? (§5.1)
4. **L'ordre entre Freemium et Lecture** : le gratuit sous le payant, ou les
   deux au même niveau commercial ? La renumérotation est tranchée — 10, puis
   de 10 en 10. (§6.2)
5. Le **calendrier de relance** (J+1 / J+3 / J+7 ?) (§5.2)

Aucune n'est technique ; toutes changent le code.
