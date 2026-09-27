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

### 3.3 Les deux décisions, tranchées

**L'engagement survit inchangé** (tranché 2026-09-26). Réinitialiser l'ancre
de facturation ne le ré-arme pas et ne le raccourcit pas : un upgrade n'est
pas un nouveau contrat, et « le renouvellement ne ré-arme pas l'engagement en
silence » (§13.1) vaut ici pour la même raison. Un client engagé 24 mois qui
monte en gamme reste engagé jusqu'à **sa date d'origine** ; il ne se ré-engage
pas pour 24 mois sans l'avoir dit.

L'engagement de la nouvelle offre ne s'applique que s'il finit **plus tard** —
sinon monter en gamme raccourcirait un engagement, ce qui est la même faute
dans l'autre sens.

`commitment_months` et `commitment_ends_at` sont donc les deux seules
conditions que la ré-instantanéisation du §3.2 **ne recopie pas**. Il faut
l'écrire là où le code le fait, parce que c'est une exception à une règle
juste à côté.

**Le crédit revient sur la carte** (tranché 2026-09-26). La valeur non
consommée n'est pas déduite d'un document : elle est **remboursée au moyen de
paiement**, une ligne dans `refunds` contre le paiement d'origine. C'est le
plus lisible pour le client — sa carte voit le crédit — et cela évite la
facture à ligne négative.

> **Ce que cette réponse ne tranche pas, et qu'il faut trancher avec elle.**
> Un remboursement est un mouvement d'argent, pas un document. Et
> `Payments::refund` **n'écrit aucun avoir** : la transaction de TVA du
> document d'origine reste déclarée alors que l'argent est reparti. Constaté
> en lisant le code, indépendamment de cette spécification — sauf qu'ici cela
> cesse d'être un défaut dormant : **chaque upgrade y passerait**.

Le remboursement doit donc porter son document. Deux formes, et le code
n'en permet qu'une aujourd'hui :

| | Ce que ça donne | Ce que ça demande |
|---|---|---|
| **avoir partiel** sur la facture d'origine, du montant non consommé | un document, un remboursement, une TVA inverse du bon montant | `CreditNotes::issue()` **ne crédite qu'en totalité**, et refuse le partiel exprès : « la TVA doit être ventilée entre les taux plutôt que copiée, et deviner produirait un document légal que personne n'a demandé » |
| **avoir total** puis refacturation | n'utilise que l'existant | trois documents par upgrade, dont une facture des jours consommés que le client n'a jamais demandée |

> **Recommandé : l'avoir partiel, borné à un seul taux.** La ventilation que
> `CreditNotes` refuse de deviner n'existe pas dans le cas qui nous occupe —
> un siège, un plan, un taux — et un avoir partiel sur une facture qui en
> porte plusieurs se refuse, plutôt que de se deviner. La règle que le code
> pose reste intacte : on ne ventile pas au jugé ; on décline quand il
> faudrait le faire.
>
> C'est une **étape préalable** au prorata et non un détail d'implémentation :
> voir §8, étape 3 bis.

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

**L'accès est suspendu** (tranché 2026-09-26). Pas restreint : pas de lecture
seule, pas de demi-mesure. `requireSubscription()` refuse, et le produit est
fermé jusqu'au paiement.

C'est la réponse simple et c'est la dure, et les deux se tiennent : « restreint »
aurait demandé de décider **ce qui reste** ouvert, produit par produit — une
question sans réponse générale, qu'il aurait fallu reposer à chaque nouveau
produit, et à laquelle un oubli répond « ouvert ».

Ce qui est suspendu, ce sont les **droits**, jamais les documents : le client
garde l'accès à ses factures et à ses paiements, sans quoi on lui fermerait la
porte de l'écran où il vient régler. La suspension porte sur l'atelier.

### 5.2 Le recouvrement

Les relances passent par la file M7 — **jamais dans la requête HTTP**. Chaque
tentative est un paiement à part entière : « une nouvelle tentative, jamais la
même relancée ».

**La relance est une notification, délivrée par courriel** (tranché
2026-09-26) — au sens de §27.1 et pas au sens courant : un événement, une
intention d'informer quelqu'un, et une **livraison par canal** avec son propre
sort. Jamais un message (§12.3) : une conversation a des participants, un
ordre et une réponse ; une relance est à sens unique, et la mettre dans un fil
de support y mettrait du bruit système.

Ce que cela apporte gratuitement : la livraison est écrite **avant** l'envoi,
donc « les a-t-on prévenus ? » a une réponse même quand la réponse est non.

Et la **mise en demeure a un effet juridique**, donc on conserve le corps
rendu plutôt que la seule charge utile — pour la raison qu'une facture garde
son instantané.

**Le calendrier est réglé sur le produit** (tranché 2026-09-26), pas dans le
code. `product_configuration` le porte comme elle porte l'identité de
facturation : une constante obligerait à un déploiement pour changer un délai
commercial, et deux produits n'ont aucune raison de relancer au même rythme.

Une valeur par défaut raisonnable (J+1, J+3, J+7, puis fin) sert de départ, et
le fait qu'elle soit un défaut plutôt qu'une règle est ce qui compte.

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

**L'ordre est confirmé** (2026-09-26) : le gratuit sous le payant.
Freemium → Lecture est donc un **upgrade**, immédiat et facturé au prorata —
qui ne coûte rien de plus que le premier mois de Lecture, puisqu'il n'y a
aucune valeur non consommée à créditer sur un plan à 0. Et Lecture → Freemium
est un **downgrade différé** : le client garde Lecture jusqu'au bout de ce
qu'il a payé, puis retombe sur le gratuit.

> Une conséquence à voir venir : descendre vers le freemium n'est possible que
> pour qui n'y a jamais eu droit (§6.4). Pour tous les autres, descendre de
> Lecture, c'est résilier. Le catalogue doit le dire à cet endroit-là plutôt
> que de laisser découvrir un `FREEMIUM_ALREADY_USED` au moment du clic.

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
`term_months`, `term_ends_at`, `cancellation_policy`, `renewal`,
`early_termination`, `notice_days` depuis la nouvelle version — **et pas
`commitment_months` ni `commitment_ends_at`**, qui survivent inchangés (§3.3).
Cette ligne listait l'engagement parmi les conditions recopiées et
contredisait §3.3 ; c'est §3.3 qui a raison, parce que c'est là que la règle
est raisonnée. Test : changer d'offre puis lire les conditions et constater
qu'elles décrivent la nouvelle, et que l'engagement est toujours celui
d'origine.
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

**Étape 3 bis — un remboursement porte son avoir.** Aujourd'hui
`Payments::refund` rend l'argent et n'écrit aucun avoir : le fait fiscal reste
déclaré alors que l'argent est reparti. C'est un défaut vivant, indépendant de
cette spécification — et le prorata le rendrait systématique, puisque le
crédit revient sur la carte (§3.3). Donc : l'avoir partiel, borné à un seul
taux de TVA, refusé sur une facture qui en porte plusieurs. Tests : un
remboursement écrit la transaction de TVA inverse du bon montant ; une facture
à deux taux refuse le partiel plutôt que de ventiler au jugé ; un
remboursement total reste ce qu'il est.
*À livrer avant l'étape 4, et elle vaut d'être livrée même si le prorata
attend.*

> **Livrée** le 26 septembre 2026, ADR-058. Le document est **décidé avant
> que le prestataire soit sollicité** — un refus ne coûte alors rien, et
> l'argent parti ne revient pas — et **écrit sur la transaction du
> remboursement**. L'avoir partiel se borne à un seul taux et décline
> `CREDIT_NOTE_MULTIPLE_RATES` au-delà ; il ne se demande pas en nommant un
> montant, et rien ne se crédite deux fois (`CREDIT_EXCEEDS_INVOICE`). Le
> montant remboursé est le **brut exact** de l'avoir : la base est reprise au
> taux **enregistré** par la facture et la TVA est le **reste**, jamais un
> second arrondi.

**Étape 4 — l'upgrade avec prorata.** Le calcul dans le domaine (unités
mineures entières), la facture par la chaîne normale, le remboursement du non
consommé sur la carte avec son avoir (étape 3 bis), l'ancre réinitialisée, et
l'engagement **recopié tel quel** — la seule condition que la
ré-instantanéisation ne reprend pas (§3.3). Tests : upgrades successifs
(1er/5/10) ; rien à payer ne lève aucun document ; l'engagement ne se ré-arme
ni ne se raccourcit ; le crédit arrive sur le moyen de paiement.

**Étape 5 — `PAST_DUE` et le recouvrement.** Le statut, l'effet sur
`requireSubscription()`, la file de relance, les notifications, la bannière.
Tests : un impayé suspend l'atelier et laisse les documents lisibles ; le
refus se distingue de « votre organisation ne vous couvre pas » ; une relance
est une nouvelle tentative ; le calendrier vient de la configuration du
produit et non d'une constante.

**Étape 6 — le freemium.** La renumérotation des rangs (10, puis de 10 en
10), l'unicité à vie du §6.4 — colonne et index, jamais une vérification
applicative — la valeur de `renewal`, le chemin de souscription sans facture,
et le plan dans la démonstration (1 utilisateur, 1 projet, 5 jours) avec sa
vérification dans `DemoFixtures::verify`. Test : un freemium terminé interdit
toujours d'en reprendre un.

> **Livrée** le 27 septembre 2026, ADR-059. Le freemium a **sa propre porte**
> (`POST /api/v1/subscription/freemium`, `billing.pay`) parce qu'il ne lève
> aucun document, et `Sales::order` refuse la même offre de l'autre côté
> (`FREEMIUM_IS_NOT_SOLD`) : une porte, pas une et demie. On le reconnaît à
> ses **propriétés** — `OfferVersion::isFreemium()` = prix 0 **et**
> `ENDS_AT_TERM` — jamais au code d'un plan. `renewal` existait déjà et rien
> ne le lisait : `Subscriptions::renew()` a désormais une troisième étape, et
> elle vaut pour tout contrat à durée déterminée, pas seulement pour le
> gratuit. La durée est dans la **configuration du produit**
> (`{"days": 5}`), pas une constante : `term_months` ne sait pas dire cinq
> jours et un opérateur doit pouvoir changer la sienne. `is_freemium` survit
> à une montée en gamme — le droit a été consommé — donc **pas de CHECK**
> reliant la colonne à `renewal`, qui refuserait justement cette montée.
>
> Deux choses restent hors de cette étape : l'écran catalogue (§7), donc le
> refus se découvre encore au clic ; et le changement d'offre d'un **siège**,
> que `change-offer` ne sait pas atteindre — il résout l'abonnement de
> l'organisation — ce qui rend Freemium → Lecture (§6.2) inatteignable
> aujourd'hui. C'est un manque antérieur, et il appartient aux étapes 3 et 4.

**Étape 7 — l'écran catalogue**, une fois que 2, 3 et 4 répondent.

> **Livrée** le 27 septembre 2026. Chaque ligne du catalogue est l'une de sept
> situations, et c'est le **rang** qui tranche entre les deux dernières — donc
> deux opérations différentes, `changeOffer` et `scheduleOfferChange`, jamais la
> première pour une descente. Toutes portent `seat: true` : la surface locataire
> ne vend que des sièges (ADR-055), et sans le drapeau chaque bouton s'adressait
> à l'abonnement de l'organisation, que le client ne détient pas. Les montants
> sont **ceux du serveur**, lus dans `preview-change` ligne par ligne ; le test
> qui le prouve fabrique un `net` qui n'est pas `charge − credit`, parce qu'un
> jeu d'essai cohérent laisserait passer une soustraction faite ici.
>
> **Le freemium se dit avant le clic** (§6.4), et il a fallu deux choses pour
> cela. Un fait : `freemium_used` sur `GET /subscription`, la réponse du serveur
> et non une déduction du siège en main — l'index ne filtre pas sur le statut,
> et cette lecture non plus. Et une propriété : `OfferVersion.freemium` sur la
> vue de vente, parce que reconnaître la période gratuite à `price == 0` serait
> recopier une règle métier dans le frontend et la reconnaître au code d'un plan
> serait la branche qu'interdit le §13.
>
> **Ce qui a été trouvé en chemin** : la descente vers le freemium ne passait
> par **aucune** des deux portes qui connaissent la période gratuite —
> `Sales::order()` la refuse, `Freemium::take()` rencontre l'index — donc cinq
> jours dépensés se reprenaient en changeant de plan, `is_freemium` restant
> faux. Refusée désormais à l'endroit partagé, donc l'aperçu le dit avant le
> clic et l'acte après. Ce qui reste : une descente vers le freemium **autorisée**
> (pour qui n'y a jamais eu droit) ne pose toujours pas `is_freemium` quand le
> renouvellement l'applique, si bien que ce chemin-là consomme le droit sans
> l'enregistrer.
>
> La vue de vente porte aussi `terms` : « prix, période, engagement » demandait
> l'engagement, et il n'était que dans la vue d'édition.

> L'ordre n'est pas négociable entre 1 et 4 : prorater des conditions qui
> décrivent l'offre quittée donnerait un montant juste sur le mauvais contrat.

---

## 9. Les décisions de l'exploitant

Toutes tranchées le 26 septembre 2026. Aucune n'était technique ; toutes
changent le code, et chacune est écrite là où le code la lira.

| | Décision | Où |
|---|---|---|
| L'engagement à l'upgrade | **survit inchangé**, ne se ré-arme ni ne se raccourcit | §3.3 |
| Le crédit de prorata | **remboursé sur la carte**, une ligne dans `refunds` | §3.3 |
| `PAST_DUE` | **accès suspendu**, pas restreint | §5.1 |
| La relance | **notification + courriel**, calendrier réglé **sur le produit** | §5.2 |
| Les rangs | le plus petit vaut **10**, puis de 10 en 10 | §6.2 |
| Freemium / Lecture | le **gratuit sous le payant** | §6.2 |
| Le freemium | **une seule fois**, y compris terminé | §6.4 |

### Ce que ces réponses ont fait apparaître

Deux choses, toutes deux dans le code d'aujourd'hui et aucune inventée par
cette spécification :

**Un remboursement n'écrit aucun avoir.** Le fait fiscal reste déclaré alors
que l'argent est reparti. Dormant tant que les remboursements sont rares ;
systématique dès que le crédit de prorata passe par la carte. D'où l'étape
3 bis, qui vaut d'être livrée même si le prorata attend.

**`CreditNotes` ne crédite qu'en totalité**, et refuse le partiel exprès — la
TVA devrait être ventilée entre les taux et deviner produirait un document
légal que personne n'a demandé. La recommandation ne contourne pas cette
règle : elle la garde, et décline le partiel sur une facture à plusieurs taux
au lieu de ventiler au jugé.

### Ce qui reste, et qui n'est plus une question de cadrage

La portée de l'unicité du freemium est écrite **par produit**
(`product_id`, souscripteur) : goûter Plan n'a jamais rien dit de Boreas. Si
« une seule fois par compte » voulait dire *un seul freemium sur toute la
plateforme, tous produits confondus*, c'est l'index du §6.4 qui change, et lui
seul.
