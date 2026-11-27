<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Notification\Domain\MailTemplates;
use App\Notification\Domain\Notification;
use App\Shared\Validation\Locale;

/**
 * What the notices that leave the platform say (2026-09-19).
 *
 * The generic rendering — the type as a subject, the payload as lines — is
 * enough for a screen and for a log. A mail is read by somebody who has no
 * screen in front of them: a reset link with "purpose: RESET" under it is
 * not a sentence they can act on. So the handful of types a person has to
 * act on from their inbox get words of their own, and since 2026-09-19 the
 * platform administrator may change them (Console → Setup → Mail): an
 * override per type on top of the defaults here, with `{link}`, `{email}`
 * and any other scalar of the payload as placeholders.
 *
 * **In the reader's language** (ADR-050, 2026-09-22). The defaults exist in
 * every language the platform speaks, so a person who chose French is
 * addressed in French with nobody having typed a word: until then a French
 * account received the English default — the one mail a person reads with
 * no screen in front of them, in a language they had not chosen. The
 * administrator's words for a language replace that language's default and
 * no other's: an English override is English's, and does not reach a
 * French inbox where the platform has French of its own.
 */
final class MailWording
{
    /**
     * The types with words of their own, their defaults, and what each may
     * say with a placeholder.
     *
     * @var array<string, array{subject: string, body: string, placeholders: list<string>, about: string}>
     */
    public const DEFAULTS = [
        'account.password_reset' => [
            'about' => 'Somebody asked to set a new password. The link works once, for thirty minutes.',
            'placeholders' => ['link', 'email'],
            'subject' => 'Set a new password',
            'body' => "Somebody — we hope you — asked to set a new password for this account.\n\n"
                . "Open this link to choose one; it works once and for thirty minutes:\n{link}\n\n"
                . 'If you did not ask, ignore this mail: nothing changes until the link is used.',
        ],
        'account.invitation' => [
            'about' => 'Somebody was added to a subscription by address and has no password yet. The link works once, for seven days.',
            'placeholders' => ['link', 'email'],
            'subject' => 'You have been added — choose your password',
            'body' => "You have been given access, and an account was made for this address.\n\n"
                . "Open this link to choose your password and sign in; it works once and for seven days:\n{link}\n\n"
                . 'If you were not expecting this, you can ignore it.',
        ],
        'account.password_changed' => [
            'about' => 'A password was just set from a link, and every earlier session was signed out.',
            'placeholders' => ['email'],
            'subject' => 'Your password was changed',
            'body' => 'The password for this account was just set through a link sent to this address, and every '
                . "earlier session was signed out.\n\n"
                . 'If that was not you, ask for a new link straight away from the sign-in page.',
        ],
        'account.email_verification' => [
            'about' => 'A sign-up asks the person to confirm their address.',
            'placeholders' => ['link', 'email'],
            'subject' => 'Confirm your email address',
            'body' => "Thanks for signing up. Open this link to confirm this address is yours:\n{link}\n\n"
                . 'If you did not sign up, ignore this mail.',
        ],
        'account.session_revoked' => [
            'about' => 'A sign-in was ended because its session token was used from two places — a sign it was copied. Security: it cannot be switched off.',
            'placeholders' => ['email'],
            'subject' => 'One of your sign-ins was ended for your safety',
            'body' => 'We ended one of your sign-ins because its session token was used from two places at once, '
                . "which usually means it was copied.\n\n"
                . 'If you are asked to sign in again on a device, that is why. If you do not recognise this, '
                . 'change your password from the sign-in page: that ends every sign-in on every device.',
        ],
        // A change of plan to an offer that sells fewer places (ADR-077):
        // the people beyond the new number stop being covered. The holder is
        // told who, by name, because it is their decision that did it.
        'subscription.places_withdrawn' => [
            'about' => 'A subscription moved to an offer that sells fewer places, so the people beyond the new number are no longer covered. Sent to the subscription’s holder, naming them.',
            'placeholders' => ['offer', 'places', 'count', 'people'],
            'subject' => 'Your plan changed — {count} of your people are no longer covered',
            'body' => "Your subscription is now on {offer}, which covers {places} in all, you included.\n\n"
                . "These people were on it beyond that number and are no longer covered: {people}.\n\n"
                . 'They keep their account and their role, and nothing they made is lost; they can no longer open the workspace. '
                . 'To cover them again, move to an offer with more places, or free a place and add them back from the subscription screen.',
        ],
        // And each of those people is told themselves, rather than finding
        // out from a refusal they cannot explain.
        'subscription.place_withdrawn' => [
            'about' => 'Somebody was covered by a colleague’s subscription, and that subscription moved to an offer with fewer places. Sent to the person who lost their place.',
            'placeholders' => ['offer'],
            'subject' => 'You are no longer covered by a subscription',
            'body' => "The subscription that covered you has moved to {offer}, which has fewer places, and yours was one of those withdrawn.\n\n"
                . 'Your account and your role are unchanged, and nothing you made is lost. You can no longer open the workspace until '
                . 'whoever holds the subscription gives you a place again, or you take out one of your own.',
        ],
        // A formal demand (spec §5.2, 2026-09-27), and the one mail here that
        // carries legal effect — so the rendered body is kept as it went out,
        // for the reason an invoice keeps its snapshot. It says what is owed,
        // since when, and that access is suspended until it is paid: a chase
        // that did not say the product was shut would leave somebody
        // diagnosing a fault instead of paying an invoice.
        'subscription.payment_overdue' => [
            'about' => 'An invoice for a subscription is unpaid past its due date, so access is suspended until it is settled. Sent on each step of the product’s collection schedule, and kept as sent because it is a formal demand.',
            'placeholders' => ['invoice_number', 'due_on', 'days_overdue'],
            'subject' => 'Payment failed — your access is suspended',
            'body' => "Invoice {invoice_number} was due on {due_on} and is still unpaid, {days_overdue} days later.\n\n"
                . 'Access to the product is suspended until it is settled. Your invoices and payments are still '
                . "available, so you can pay from the subscription screen.\n\n"
                . 'If you have already paid, nothing further is needed: access returns as soon as the payment is confirmed.',
        ],
        // The request to pay for the period about to start (2026-10-05) —
        // the operator's answer to R11: the customer is asked, with a link,
        // before the period, and pays by card or any other means from the
        // invoice screen. Kept as sent, because it is a demand for payment.
        'subscription.renewal_payment_request' => [
            'about' => 'A subscription’s next period has been invoiced, a number of days before it starts (the product’s renewal setting). The mail carries the invoice and a link to pay it. Kept as sent because it is a demand for payment.',
            'placeholders' => ['invoice_number', 'offer', 'due_on', 'period_end', 'link'],
            'subject' => 'Your subscription renews — invoice {invoice_number} to pay',
            'body' => "Your subscription to {offer} continues for another period, until {period_end}.\n\n"
                . "Invoice {invoice_number} is due on {due_on}. You can pay it by card or by another means from here:\n{link}\n\n"
                . 'If it is still unpaid after that date, access to the product may be suspended until it is settled.',
        ],
        // The pre-renewal notice (ADR-071): a term renews by itself only once
        // this has gone out `notice_days` before, so it says when, and how to
        // stop it. Kept as sent, because "what did we tell them?" is the half
        // of a tacit renewal a contract is judged on.
        'subscription.renewal_notice' => [
            'about' => 'A subscription’s term renews by itself on a date, on the same conditions, unless it is cancelled first. Sent before the term ends; the term only renews once this has gone out the offer’s notice days before. Kept as sent because it has legal effect.',
            'placeholders' => ['renews_on', 'cancel_by', 'notice_days'],
            'subject' => 'Your subscription renews on {renews_on}',
            'body' => "Your subscription reaches the end of its term, and on {renews_on} it renews by itself for a new term, on the same conditions.\n\n"
                . "Nothing is needed to keep it. To stop it, cancel it from the subscription screen before {cancel_by}: that is the day the renewal is decided.\n\n"
                . 'You are told {notice_days} days or more before that day, as your offer provides.',
        ],
        // A purchase, said to the person who made it (ADR-079). Until
        // 2026-11-27 buying was silent from end to end; the only mail about
        // money was the one asking for it. None of these three is kept as
        // sent: the invoice and the credit note are the documents.
        'purchase.confirmed' => [
            'about' => 'An order completed and what it bought has started — a paid purchase once the money arrived, or a free one at once.',
            'placeholders' => ['offer', 'link'],
            'subject' => 'Your purchase is confirmed — {offer}',
            'body' => "Your purchase of {offer} is confirmed and your seat has started.\n\n"
                . "What you hold, its period and its documents are here:\n{link}",
        ],
        'purchase.payment_failed' => [
            'about' => 'A payment attempt for a purchase still in progress was refused. Nothing was charged and the order is intact. Not sent for a renewal or an overdue invoice, which the collection schedule speaks for.',
            'placeholders' => ['offer', 'link'],
            'subject' => 'Your payment did not go through — {offer}',
            'body' => "The payment for {offer} was refused, so nothing was charged and nothing has started yet.\n\n"
                . "Your order is still there. You can pay again, with the same card or another means, from here:\n{link}\n\n"
                . 'If you no longer want it, you can cancel it from the same page.',
        ],
        'purchase.refunded' => [
            'about' => 'A payment arrived for a seat the person already held (two purchases opened at once), so nothing could be started against it. The payment has been given back and a credit note raised.',
            'placeholders' => ['offer', 'link'],
            'subject' => 'Your payment has been refunded — {offer}',
            'body' => "We received a payment for {offer}, but you already held a seat on this product when it arrived, so nothing was started against it.\n\n"
                . "The payment has been refunded in full to the means it was made with, and a credit note has been issued. Depending on your bank it can take a few days to appear.\n\n"
                . "Your seat and your documents are here:\n{link}",
        ],
        // The truthful counterpart of the pre-renewal notice (ADR-079): when
        // the notice the offer promises can no longer be given in time, the
        // term will not renew by itself, so saying "it renews on X" would be
        // a promise the platform is about to break. Kept as sent.
        'subscription.term_ending' => [
            'about' => 'A subscription reaches the end of its term and will NOT renew by itself, because the notice its offer promises could no longer be given in time. Sent instead of the pre-renewal notice. Kept as sent because it has legal effect.',
            'placeholders' => ['ends_on', 'notice_days'],
            'subject' => 'Your subscription ends on {ends_on}',
            'body' => "Your subscription reaches the end of its term on {ends_on}, and it will end on that day: it does not renew by itself this time.\n\n"
                . "Your offer provides that you are told {notice_days} days before a renewal, and that notice could not be given in time, so no new term is started on your behalf.\n\n"
                . 'To keep using the product after that date, take a new subscription from the catalogue.',
        ],
    ];

    /**
     * The same words in the other languages the platform speaks. English
     * is `DEFAULTS`, which also carries what the editor needs to say about
     * each type; a language missing a type here falls back to English's
     * default, which `gate:mail-wording` in `MailWordingTest` forbids.
     *
     * @var array<string, array<string, array{subject: string, body: string}>> by locale, then by type
     */
    public const WORDS = [
        'fr' => [
            'account.password_reset' => [
                'subject' => 'Choisir un nouveau mot de passe',
                'body' => "Quelqu'un — vous, espérons-le — a demandé un nouveau mot de passe pour ce compte.\n\n"
                    . "Ouvrez ce lien pour en choisir un ; il ne fonctionne qu'une fois, pendant trente minutes :\n{link}\n\n"
                    . "Si vous n'avez rien demandé, ignorez ce message : rien ne change tant que le lien n'est pas utilisé.",
            ],
            'account.invitation' => [
                'subject' => 'Vous avez été ajouté — choisissez votre mot de passe',
                'body' => "Un accès vous a été donné, et un compte a été créé pour cette adresse.\n\n"
                    . "Ouvrez ce lien pour choisir votre mot de passe et vous connecter ; il ne fonctionne qu'une fois, pendant sept jours :\n{link}\n\n"
                    . "Si vous n'attendiez pas ce message, vous pouvez l'ignorer.",
            ],
            'account.password_changed' => [
                'subject' => 'Votre mot de passe a été modifié',
                'body' => 'Le mot de passe de ce compte vient d\'être défini depuis un lien envoyé à cette adresse, et toutes les '
                    . "sessions précédentes ont été fermées.\n\n"
                    . "Si ce n'était pas vous, demandez tout de suite un nouveau lien depuis la page de connexion.",
            ],
            'account.email_verification' => [
                'subject' => 'Confirmez votre adresse e-mail',
                'body' => "Merci de votre inscription. Ouvrez ce lien pour confirmer que cette adresse est bien la vôtre :\n{link}\n\n"
                    . 'Si vous ne vous êtes pas inscrit, ignorez ce message.',
            ],
            'account.session_revoked' => [
                'subject' => 'Une de vos connexions a été fermée par sécurité',
                'body' => 'Nous avons fermé une de vos connexions parce que son jeton de session a été utilisé depuis deux endroits à la fois, '
                    . "ce qui signifie en général qu'il a été copié.\n\n"
                    . "Si l'on vous redemande de vous connecter sur un appareil, c'est pour cette raison. Si vous ne reconnaissez pas cela, "
                    . 'changez votre mot de passe depuis la page de connexion : cela ferme toutes les connexions sur tous les appareils.',
            ],
            'subscription.places_withdrawn' => [
                'subject' => 'Votre offre a changé — {count} personne(s) ne sont plus couvertes',
                'body' => "Votre abonnement est désormais sur {offer}, qui couvre {places} personne(s) en tout, vous compris.\n\n"
                    . "Ces personnes y figuraient au-delà de ce nombre et ne sont plus couvertes : {people}.\n\n"
                    . "Elles gardent leur compte et leur rôle, et rien de ce qu'elles ont fait n'est perdu ; elles ne peuvent plus ouvrir l'atelier. "
                    . "Pour les couvrir à nouveau, passez à une offre avec plus de places, ou libérez une place et rajoutez-les depuis l'écran d'abonnement.",
            ],
            'subscription.place_withdrawn' => [
                'subject' => "Vous n'êtes plus couvert par un abonnement",
                'body' => "L'abonnement qui vous couvrait est passé à {offer}, qui compte moins de places, et la vôtre fait partie de celles qui ont été retirées.\n\n"
                    . "Votre compte et votre rôle ne changent pas, et rien de ce que vous avez fait n'est perdu. Vous ne pouvez plus ouvrir l'atelier tant que "
                    . "la personne qui tient l'abonnement ne vous redonne pas une place, ou que vous n'en prenez pas une à votre nom.",
            ],
            'subscription.payment_overdue' => [
                'subject' => 'Échec de paiement — votre accès est suspendu',
                'body' => "La facture {invoice_number} était due le {due_on} et reste impayée, {days_overdue} jours plus tard.\n\n"
                    . "L'accès au produit est suspendu jusqu'à son règlement. Vos factures et vos paiements restent "
                    . "consultables : vous pouvez payer depuis l'écran d'abonnement.\n\n"
                    . "Si vous avez déjà payé, il n'y a rien à faire : l'accès revient dès que le paiement est confirmé.",
            ],
            'subscription.renewal_payment_request' => [
                'subject' => 'Votre abonnement est renouvelé — facture {invoice_number} à régler',
                'body' => "Votre abonnement à {offer} continue pour une nouvelle période, jusqu'au {period_end}.\n\n"
                    . "La facture {invoice_number} est à régler avant le {due_on}. Vous pouvez la payer par carte ou par un autre moyen ici :\n{link}\n\n"
                    . "Si elle reste impayée après cette date, l'accès au produit pourra être suspendu jusqu'à son règlement.",
            ],
            'subscription.renewal_notice' => [
                'subject' => 'Votre abonnement est reconduit le {renews_on}',
                'body' => "Votre abonnement arrive au terme de sa durée : le {renews_on}, il est reconduit automatiquement pour une nouvelle durée, aux mêmes conditions.\n\n"
                    . "Il n'y a rien à faire pour le garder. Pour l'arrêter, résiliez-le depuis l'écran d'abonnement avant le {cancel_by} : c'est le jour où la reconduction est décidée.\n\n"
                    . 'Vous êtes prévenu au moins {notice_days} jours avant cette date, comme le prévoit votre offre.',
            ],
            'purchase.confirmed' => [
                'subject' => 'Votre achat est confirmé — {offer}',
                'body' => "Votre achat de {offer} est confirmé et votre place est ouverte.\n\n"
                    . "Ce que vous détenez, sa période et ses documents sont ici :\n{link}",
            ],
            'purchase.payment_failed' => [
                'subject' => "Votre paiement n'a pas abouti — {offer}",
                'body' => "Le paiement de {offer} a été refusé : rien n'a été débité et rien n'a encore commencé.\n\n"
                    . "Votre commande est toujours là. Vous pouvez payer de nouveau, avec la même carte ou un autre moyen, ici :\n{link}\n\n"
                    . "Si vous n'en voulez plus, vous pouvez l'annuler depuis la même page.",
            ],
            'purchase.refunded' => [
                'subject' => 'Votre paiement a été remboursé — {offer}',
                'body' => "Nous avons reçu un paiement pour {offer}, mais vous déteniez déjà une place sur ce produit à son arrivée : rien n'a donc été ouvert en face.\n\n"
                    . "Le paiement a été remboursé en totalité sur le moyen utilisé, et un avoir a été émis. Selon votre banque, il peut mettre quelques jours à apparaître.\n\n"
                    . "Votre place et vos documents sont ici :\n{link}",
            ],
            'subscription.term_ending' => [
                'subject' => 'Votre abonnement prend fin le {ends_on}',
                'body' => "Votre abonnement arrive au terme de sa durée le {ends_on}, et il prendra fin ce jour-là : il n'est pas reconduit automatiquement cette fois.\n\n"
                    . "Votre offre prévoit que vous soyez prévenu {notice_days} jours avant une reconduction ; ce préavis n'a pas pu être donné à temps, aucune nouvelle durée n'est donc engagée en votre nom.\n\n"
                    . 'Pour continuer à utiliser le produit après cette date, souscrivez un nouvel abonnement depuis le catalogue.',
            ],
        ],
        'es' => [
            'account.password_reset' => [
                'subject' => 'Elegir una nueva contraseña',
                'body' => "Alguien —esperamos que usted— pidió una nueva contraseña para esta cuenta.\n\n"
                    . "Abra este enlace para elegirla; funciona una sola vez y durante treinta minutos:\n{link}\n\n"
                    . 'Si no lo pidió, ignore este mensaje: nada cambia hasta que se use el enlace.',
            ],
            'account.invitation' => [
                'subject' => 'Le han añadido: elija su contraseña',
                'body' => "Le han dado acceso y se ha creado una cuenta para esta dirección.\n\n"
                    . "Abra este enlace para elegir su contraseña e iniciar sesión; funciona una sola vez y durante siete días:\n{link}\n\n"
                    . 'Si no esperaba este mensaje, puede ignorarlo.',
            ],
            'account.password_changed' => [
                'subject' => 'Su contraseña ha cambiado',
                'body' => 'La contraseña de esta cuenta se acaba de establecer desde un enlace enviado a esta dirección, y todas las '
                    . "sesiones anteriores se han cerrado.\n\n"
                    . 'Si no fue usted, pida un nuevo enlace ahora mismo desde la página de inicio de sesión.',
            ],
            'account.email_verification' => [
                'subject' => 'Confirme su dirección de correo',
                'body' => "Gracias por registrarse. Abra este enlace para confirmar que esta dirección es suya:\n{link}\n\n"
                    . 'Si no se registró, ignore este mensaje.',
            ],
            'account.session_revoked' => [
                'subject' => 'Hemos cerrado uno de sus inicios de sesión por seguridad',
                'body' => 'Hemos cerrado uno de sus inicios de sesión porque su token de sesión se usó desde dos lugares a la vez, '
                    . "lo que suele significar que fue copiado.\n\n"
                    . 'Si se le pide que vuelva a iniciar sesión en un dispositivo, es por eso. Si no lo reconoce, '
                    . 'cambie su contraseña desde la página de inicio de sesión: eso cierra todas las sesiones en todos los dispositivos.',
            ],
            'subscription.places_withdrawn' => [
                'subject' => 'Su plan ha cambiado: {count} persona(s) ya no están cubiertas',
                'body' => "Su suscripción está ahora en {offer}, que cubre a {places} persona(s) en total, usted incluido.\n\n"
                    . "Estas personas figuraban por encima de ese número y ya no están cubiertas: {people}.\n\n"
                    . 'Conservan su cuenta y su rol, y nada de lo que hicieron se pierde; ya no pueden abrir el taller. '
                    . 'Para volver a cubrirlas, pase a una oferta con más plazas, o libere una plaza y añádalas de nuevo desde la pantalla de suscripción.',
            ],
            'subscription.place_withdrawn' => [
                'subject' => 'Ya no está cubierto por una suscripción',
                'body' => "La suscripción que le cubría ha pasado a {offer}, que tiene menos plazas, y la suya es una de las retiradas.\n\n"
                    . 'Su cuenta y su rol no cambian, y nada de lo que hizo se pierde. No podrá abrir el taller hasta que '
                    . 'quien tiene la suscripción le dé una plaza de nuevo, o contrate una a su nombre.',
            ],
            'subscription.payment_overdue' => [
                'subject' => 'Pago fallido: su acceso está suspendido',
                'body' => "La factura {invoice_number} vencía el {due_on} y sigue sin pagar, {days_overdue} días después.\n\n"
                    . 'El acceso al producto está suspendido hasta que se liquide. Sus facturas y sus pagos siguen '
                    . "disponibles: puede pagar desde la pantalla de suscripción.\n\n"
                    . 'Si ya ha pagado, no hace falta nada más: el acceso vuelve en cuanto se confirme el pago.',
            ],
            'subscription.renewal_payment_request' => [
                'subject' => 'Su suscripción se renueva: factura {invoice_number} pendiente de pago',
                'body' => "Su suscripción a {offer} continúa un periodo más, hasta el {period_end}.\n\n"
                    . "La factura {invoice_number} vence el {due_on}. Puede pagarla con tarjeta o por otro medio aquí:\n{link}\n\n"
                    . 'Si sigue sin pagar después de esa fecha, el acceso al producto podrá suspenderse hasta que se liquide.',
            ],
            'subscription.renewal_notice' => [
                'subject' => 'Su suscripción se renueva el {renews_on}',
                'body' => "Su suscripción llega al final de su duración: el {renews_on} se renueva automáticamente por una nueva duración, en las mismas condiciones.\n\n"
                    . "No necesita hacer nada para conservarla. Para detenerla, cancélela desde la pantalla de suscripción antes del {cancel_by}: ese día se decide la renovación.\n\n"
                    . 'Se le avisa con al menos {notice_days} días de antelación respecto a esa fecha, como prevé su oferta.',
            ],
            'purchase.confirmed' => [
                'subject' => 'Su compra está confirmada: {offer}',
                'body' => "Su compra de {offer} está confirmada y su plaza ya está activa.\n\n"
                    . "Lo que tiene contratado, su periodo y sus documentos están aquí:\n{link}",
            ],
            'purchase.payment_failed' => [
                'subject' => 'Su pago no se ha completado: {offer}',
                'body' => "El pago de {offer} fue rechazado: no se ha cobrado nada y todavía no ha empezado nada.\n\n"
                    . "Su pedido sigue ahí. Puede pagar de nuevo, con la misma tarjeta o con otro medio, aquí:\n{link}\n\n"
                    . 'Si ya no lo quiere, puede cancelarlo desde la misma página.',
            ],
            'purchase.refunded' => [
                'subject' => 'Su pago ha sido reembolsado: {offer}',
                'body' => "Recibimos un pago por {offer}, pero usted ya tenía una plaza en este producto cuando llegó, así que no se inició nada con él.\n\n"
                    . "El pago se ha reembolsado íntegramente al medio con el que se hizo y se ha emitido una nota de crédito. Según su banco, puede tardar unos días en aparecer.\n\n"
                    . "Su plaza y sus documentos están aquí:\n{link}",
            ],
            'subscription.term_ending' => [
                'subject' => 'Su suscripción termina el {ends_on}',
                'body' => "Su suscripción llega al final de su duración el {ends_on} y terminará ese día: esta vez no se renueva automáticamente.\n\n"
                    . "Su oferta prevé que se le avise {notice_days} días antes de una renovación; ese aviso no pudo darse a tiempo, así que no se inicia ninguna nueva duración en su nombre.\n\n"
                    . 'Para seguir usando el producto después de esa fecha, contrate una nueva suscripción desde el catálogo.',
            ],
        ],
        'de' => [
            'account.password_reset' => [
                'subject' => 'Neues Passwort wählen',
                'body' => "Jemand – hoffentlich Sie – hat ein neues Passwort für dieses Konto angefordert.\n\n"
                    . "Öffnen Sie diesen Link, um eines zu wählen; er gilt einmal und dreißig Minuten lang:\n{link}\n\n"
                    . 'Falls Sie nichts angefordert haben, ignorieren Sie diese Mail: Nichts ändert sich, bis der Link verwendet wird.',
            ],
            'account.invitation' => [
                'subject' => 'Sie wurden hinzugefügt – wählen Sie Ihr Passwort',
                'body' => "Ihnen wurde Zugang gewährt, und für diese Adresse wurde ein Konto angelegt.\n\n"
                    . "Öffnen Sie diesen Link, um Ihr Passwort zu wählen und sich anzumelden; er gilt einmal und sieben Tage lang:\n{link}\n\n"
                    . 'Falls Sie das nicht erwartet haben, können Sie diese Mail ignorieren.',
            ],
            'account.password_changed' => [
                'subject' => 'Ihr Passwort wurde geändert',
                'body' => 'Das Passwort dieses Kontos wurde soeben über einen an diese Adresse gesendeten Link gesetzt, und alle '
                    . "früheren Sitzungen wurden abgemeldet.\n\n"
                    . 'Falls das nicht Sie waren, fordern Sie sofort einen neuen Link über die Anmeldeseite an.',
            ],
            'account.email_verification' => [
                'subject' => 'Bestätigen Sie Ihre E-Mail-Adresse',
                'body' => "Danke für Ihre Registrierung. Öffnen Sie diesen Link, um zu bestätigen, dass diese Adresse Ihnen gehört:\n{link}\n\n"
                    . 'Falls Sie sich nicht registriert haben, ignorieren Sie diese Mail.',
            ],
            'account.session_revoked' => [
                'subject' => 'Eine Ihrer Anmeldungen wurde zu Ihrer Sicherheit beendet',
                'body' => 'Wir haben eine Ihrer Anmeldungen beendet, weil ihr Sitzungstoken gleichzeitig von zwei Orten verwendet wurde, '
                    . "was meist bedeutet, dass es kopiert wurde.\n\n"
                    . 'Wenn Sie auf einem Gerät erneut zur Anmeldung aufgefordert werden, ist das der Grund. Wenn Ihnen das unbekannt vorkommt, '
                    . 'ändern Sie Ihr Passwort über die Anmeldeseite: Damit werden alle Anmeldungen auf allen Geräten beendet.',
            ],
            'subscription.places_withdrawn' => [
                'subject' => 'Ihr Tarif hat sich geändert – {count} Person(en) sind nicht mehr abgedeckt',
                'body' => "Ihr Abonnement läuft jetzt auf {offer} und deckt insgesamt {places} Person(en) ab, Sie eingeschlossen.\n\n"
                    . "Diese Personen standen über dieser Zahl und sind nicht mehr abgedeckt: {people}.\n\n"
                    . 'Sie behalten ihr Konto und ihre Rolle, und nichts von dem, was sie erstellt haben, geht verloren; den Arbeitsbereich können sie nicht mehr öffnen. '
                    . 'Um sie wieder abzudecken, wechseln Sie zu einem Angebot mit mehr Plätzen, oder geben Sie einen Platz frei und fügen Sie sie im Abonnement-Bildschirm wieder hinzu.',
            ],
            'subscription.place_withdrawn' => [
                'subject' => 'Sie sind nicht mehr durch ein Abonnement abgedeckt',
                'body' => "Das Abonnement, das Sie abgedeckt hat, ist zu {offer} gewechselt, das weniger Plätze hat, und Ihrer gehört zu den entzogenen.\n\n"
                    . 'Ihr Konto und Ihre Rolle bleiben unverändert, und nichts von dem, was Sie erstellt haben, geht verloren. Den Arbeitsbereich können Sie erst wieder öffnen, '
                    . 'wenn die Person, die das Abonnement hält, Ihnen wieder einen Platz gibt oder Sie selbst eines abschließen.',
            ],
            'subscription.payment_overdue' => [
                'subject' => 'Zahlung fehlgeschlagen – Ihr Zugang ist gesperrt',
                'body' => "Die Rechnung {invoice_number} war am {due_on} fällig und ist {days_overdue} Tage später noch offen.\n\n"
                    . 'Der Zugang zum Produkt ist gesperrt, bis sie bezahlt ist. Ihre Rechnungen und Zahlungen bleiben '
                    . "einsehbar: Sie können über den Abonnement-Bildschirm bezahlen.\n\n"
                    . 'Falls Sie bereits bezahlt haben, ist nichts weiter zu tun: der Zugang kommt zurück, sobald die Zahlung bestätigt ist.',
            ],
            'subscription.renewal_payment_request' => [
                'subject' => 'Ihr Abonnement verlängert sich – Rechnung {invoice_number} zu bezahlen',
                'body' => "Ihr Abonnement für {offer} läuft eine weitere Periode, bis zum {period_end}.\n\n"
                    . "Die Rechnung {invoice_number} ist am {due_on} fällig. Sie können sie hier per Karte oder auf anderem Weg bezahlen:\n{link}\n\n"
                    . 'Bleibt sie nach diesem Datum unbezahlt, kann der Zugang zum Produkt bis zur Begleichung gesperrt werden.',
            ],
            'subscription.renewal_notice' => [
                'subject' => 'Ihr Abonnement verlängert sich am {renews_on}',
                'body' => "Ihr Abonnement erreicht das Ende seiner Laufzeit: Am {renews_on} verlängert es sich automatisch um eine neue Laufzeit, zu denselben Bedingungen.\n\n"
                    . "Um es zu behalten, ist nichts zu tun. Um es zu beenden, kündigen Sie es vor dem {cancel_by} über die Abonnementseite: An diesem Tag wird über die Verlängerung entschieden.\n\n"
                    . 'Sie werden mindestens {notice_days} Tage vor diesem Datum benachrichtigt, wie es Ihr Angebot vorsieht.',
            ],
            'purchase.confirmed' => [
                'subject' => 'Ihr Kauf ist bestätigt – {offer}',
                'body' => "Ihr Kauf von {offer} ist bestätigt und Ihr Platz ist aktiv.\n\n"
                    . "Was Sie gebucht haben, die Periode und Ihre Belege finden Sie hier:\n{link}",
            ],
            'purchase.payment_failed' => [
                'subject' => 'Ihre Zahlung ist nicht durchgegangen – {offer}',
                'body' => "Die Zahlung für {offer} wurde abgelehnt: Es wurde nichts abgebucht und noch nichts gestartet.\n\n"
                    . "Ihre Bestellung besteht weiter. Sie können hier erneut bezahlen, mit derselben Karte oder auf anderem Weg:\n{link}\n\n"
                    . 'Wenn Sie sie nicht mehr möchten, können Sie sie auf derselben Seite stornieren.',
            ],
            'purchase.refunded' => [
                'subject' => 'Ihre Zahlung wurde erstattet – {offer}',
                'body' => "Wir haben eine Zahlung für {offer} erhalten, aber Sie hatten bei ihrem Eingang bereits einen Platz für dieses Produkt, sodass nichts dafür gestartet wurde.\n\n"
                    . "Die Zahlung wurde vollständig auf das verwendete Zahlungsmittel erstattet, und eine Gutschrift wurde ausgestellt. Je nach Bank kann es einige Tage dauern, bis sie erscheint.\n\n"
                    . "Ihren Platz und Ihre Belege finden Sie hier:\n{link}",
            ],
            'subscription.term_ending' => [
                'subject' => 'Ihr Abonnement endet am {ends_on}',
                'body' => "Ihr Abonnement erreicht am {ends_on} das Ende seiner Laufzeit und endet an diesem Tag: Es verlängert sich diesmal nicht automatisch.\n\n"
                    . "Ihr Angebot sieht vor, dass Sie {notice_days} Tage vor einer Verlängerung benachrichtigt werden; diese Benachrichtigung konnte nicht rechtzeitig erfolgen, daher wird in Ihrem Namen keine neue Laufzeit begonnen.\n\n"
                    . 'Um das Produkt danach weiter zu nutzen, schließen Sie im Katalog ein neues Abonnement ab.',
            ],
        ],
        'it' => [
            'account.password_reset' => [
                'subject' => 'Scegli una nuova password',
                'body' => "Qualcuno — speriamo tu — ha chiesto una nuova password per questo account.\n\n"
                    . "Apri questo link per sceglierla; funziona una sola volta, per trenta minuti:\n{link}\n\n"
                    . 'Se non l\'hai chiesta, ignora questo messaggio: nulla cambia finché il link non viene usato.',
            ],
            'account.invitation' => [
                'subject' => 'Sei stato aggiunto: scegli la tua password',
                'body' => "Ti è stato dato accesso, e per questo indirizzo è stato creato un account.\n\n"
                    . "Apri questo link per scegliere la tua password e accedere; funziona una sola volta, per sette giorni:\n{link}\n\n"
                    . 'Se non te lo aspettavi, puoi ignorarlo.',
            ],
            'account.password_changed' => [
                'subject' => 'La tua password è stata cambiata',
                'body' => 'La password di questo account è appena stata impostata da un link inviato a questo indirizzo, e tutte le '
                    . "sessioni precedenti sono state chiuse.\n\n"
                    . 'Se non sei stato tu, chiedi subito un nuovo link dalla pagina di accesso.',
            ],
            'account.email_verification' => [
                'subject' => 'Conferma il tuo indirizzo e-mail',
                'body' => "Grazie per esserti registrato. Apri questo link per confermare che questo indirizzo è tuo:\n{link}\n\n"
                    . 'Se non ti sei registrato, ignora questo messaggio.',
            ],
            'account.session_revoked' => [
                'subject' => 'Uno dei tuoi accessi è stato chiuso per sicurezza',
                'body' => 'Abbiamo chiuso uno dei tuoi accessi perché il suo token di sessione è stato usato da due luoghi contemporaneamente, '
                    . "il che di solito significa che è stato copiato.\n\n"
                    . 'Se ti viene chiesto di accedere di nuovo su un dispositivo, il motivo è questo. Se non lo riconosci, '
                    . 'cambia la password dalla pagina di accesso: così si chiudono tutti gli accessi su tutti i dispositivi.',
            ],
            'subscription.places_withdrawn' => [
                'subject' => 'Il tuo piano è cambiato: {count} persona/e non sono più coperte',
                'body' => "Il tuo abbonamento è ora su {offer}, che copre {places} persona/e in tutto, te compreso.\n\n"
                    . "Queste persone vi figuravano oltre quel numero e non sono più coperte: {people}.\n\n"
                    . "Mantengono il loro account e il loro ruolo, e nulla di ciò che hanno fatto va perso; non possono più aprire l'area di lavoro. "
                    . "Per coprirle di nuovo, passa a un'offerta con più posti, oppure libera un posto e aggiungile di nuovo dalla schermata dell'abbonamento.",
            ],
            'subscription.place_withdrawn' => [
                'subject' => 'Non sei più coperto da un abbonamento',
                'body' => "L'abbonamento che ti copriva è passato a {offer}, che ha meno posti, e il tuo è tra quelli ritirati.\n\n"
                    . "Il tuo account e il tuo ruolo non cambiano, e nulla di ciò che hai fatto va perso. Non potrai aprire l'area di lavoro finché "
                    . "chi tiene l'abbonamento non ti ridà un posto, o non ne prendi uno a tuo nome.",
            ],
            'subscription.payment_overdue' => [
                'subject' => 'Pagamento non riuscito: il tuo accesso è sospeso',
                'body' => "La fattura {invoice_number} era dovuta il {due_on} e risulta ancora non pagata, {days_overdue} giorni dopo.\n\n"
                    . "L'accesso al prodotto è sospeso fino al saldo. Le tue fatture e i tuoi pagamenti restano "
                    . "consultabili: puoi pagare dalla schermata dell'abbonamento.\n\n"
                    . "Se hai già pagato non serve altro: l'accesso torna appena il pagamento è confermato.",
            ],
            'subscription.renewal_payment_request' => [
                'subject' => 'Il tuo abbonamento si rinnova: fattura {invoice_number} da pagare',
                'body' => "Il tuo abbonamento a {offer} continua per un altro periodo, fino al {period_end}.\n\n"
                    . "La fattura {invoice_number} scade il {due_on}. Puoi pagarla con carta o con un altro mezzo da qui:\n{link}\n\n"
                    . "Se risulta ancora non pagata dopo quella data, l'accesso al prodotto potrà essere sospeso fino al saldo.",
            ],
            'subscription.renewal_notice' => [
                'subject' => 'Il tuo abbonamento si rinnova il {renews_on}',
                'body' => "Il tuo abbonamento arriva alla fine della sua durata: il {renews_on} si rinnova automaticamente per una nuova durata, alle stesse condizioni.\n\n"
                    . "Non serve fare nulla per mantenerlo. Per fermarlo, disdicilo dalla schermata dell'abbonamento prima del {cancel_by}: è il giorno in cui si decide il rinnovo.\n\n"
                    . 'Sei avvisato con almeno {notice_days} giorni di anticipo rispetto a quella data, come prevede la tua offerta.',
            ],
            'purchase.confirmed' => [
                'subject' => 'Il tuo acquisto è confermato — {offer}',
                'body' => "Il tuo acquisto di {offer} è confermato e il tuo posto è attivo.\n\n"
                    . "Ciò che hai sottoscritto, il periodo e i documenti sono qui:\n{link}",
            ],
            'purchase.payment_failed' => [
                'subject' => 'Il pagamento non è andato a buon fine — {offer}',
                'body' => "Il pagamento di {offer} è stato rifiutato: non è stato addebitato nulla e nulla è ancora iniziato.\n\n"
                    . "Il tuo ordine è ancora lì. Puoi pagare di nuovo, con la stessa carta o con un altro mezzo, da qui:\n{link}\n\n"
                    . 'Se non lo vuoi più, puoi annullarlo dalla stessa pagina.',
            ],
            'purchase.refunded' => [
                'subject' => 'Il tuo pagamento è stato rimborsato — {offer}',
                'body' => "Abbiamo ricevuto un pagamento per {offer}, ma al suo arrivo avevi già un posto su questo prodotto, quindi non è stato avviato nulla a fronte di esso.\n\n"
                    . "Il pagamento è stato rimborsato per intero sul mezzo utilizzato ed è stata emessa una nota di credito. A seconda della banca, possono servire alcuni giorni perché compaia.\n\n"
                    . "Il tuo posto e i tuoi documenti sono qui:\n{link}",
            ],
            'subscription.term_ending' => [
                'subject' => 'Il tuo abbonamento termina il {ends_on}',
                'body' => "Il tuo abbonamento arriva al termine della sua durata il {ends_on} e terminerà quel giorno: questa volta non si rinnova automaticamente.\n\n"
                    . "La tua offerta prevede che tu sia avvisato {notice_days} giorni prima di un rinnovo; l'avviso non ha potuto essere dato in tempo, quindi nessuna nuova durata viene avviata a tuo nome.\n\n"
                    . 'Per continuare a usare il prodotto dopo quella data, sottoscrivi un nuovo abbonamento dal catalogo.',
            ],
        ],
    ];

    public function __construct(private readonly MailTemplates $templates)
    {
    }

    /**
     * The platform's own words for a type in a language: the language's,
     * else English's.
     *
     * @return array{subject: string, body: string}|null
     */
    public static function defaultFor(string $type, string $locale): ?array
    {
        $english = self::DEFAULTS[$type] ?? null;

        if ($english === null) {
            return null;
        }

        $own = self::WORDS[$locale][$type] ?? null;

        return $own ?? ['subject' => $english['subject'], 'body' => $english['body']];
    }

    /**
     * @return array{string, string}|null subject and body, or null for a type with no words of its own
     */
    public function for(Notification $notification, string $locale = Locale::DEFAULT): ?array
    {
        $template = $this->templateFor($notification->type, $locale);

        if ($template === null) {
            return null;
        }

        return [
            self::fill($template['subject'], $notification->payload),
            self::fill($template['body'], $notification->payload),
        ];
    }

    /**
     * Every editable type in one language: its default in that language,
     * what stands today, and its placeholders. What stands is the
     * administrator's words for this language, else the platform's own in
     * it — never another language's, which is what the person receives.
     *
     * @return list<array{type: string, about: string, placeholders: list<string>, default: array{subject: string, body: string}, subject: string, body: string, customised: bool}>
     */
    public function catalogue(string $locale = Locale::DEFAULT): array
    {
        $own = $this->templates->overrides()[$locale] ?? [];
        $rows = [];

        foreach (self::DEFAULTS as $type => $english) {
            $default = self::defaultFor($type, $locale) ?? ['subject' => $english['subject'], 'body' => $english['body']];
            $override = $own[$type] ?? null;

            $rows[] = [
                'type' => $type,
                'about' => $english['about'],
                'placeholders' => $english['placeholders'],
                'default' => $default,
                'subject' => $override['subject'] ?? $default['subject'],
                'body' => $override['body'] ?? $default['body'],
                'customised' => $override !== null,
            ];
        }

        return $rows;
    }

    /**
     * Saves the words for one language; a type left out, or given empty,
     * goes back to its default. Unknown types are refused by omission, and
     * the other languages are left as they were.
     *
     * @param array<string, array{subject: string, body: string}> $templates
     */
    public function save(array $templates, string $locale = Locale::DEFAULT): void
    {
        $overrides = [];

        foreach ($templates as $type => $template) {
            $default = self::defaultFor($type, $locale);

            if ($default === null) {
                continue;
            }

            $subject = trim($template['subject']);
            $body = trim($template['body']);

            if ($subject === '' && $body === '') {
                continue;
            }

            // Half given: the other half is this language's default, not
            // English's.
            $overrides[$type] = [
                'subject' => $subject === '' ? $default['subject'] : $subject,
                'body' => $body === '' ? $default['body'] : $body,
            ];
        }

        $all = $this->templates->overrides();
        $all[$locale] = $overrides;

        $this->templates->save(array_filter($all, static fn (array $byType): bool => $byType !== []));
    }

    /**
     * A sample payload for a type, so a test mail reads as the real one would.
     *
     * @return array<string, mixed>
     */
    public static function sample(string $type, string $email, string $appUrl): array
    {
        if ($type === 'subscription.places_withdrawn' || $type === 'subscription.place_withdrawn') {
            return ['email' => $email, 'offer' => 'Sample offer', 'places' => 1, 'count' => 2, 'people' => 'Ada Lovelace, Grace Hopper', 'subscription_id' => 'SAMPLE'];
        }

        if ($type === 'subscription.renewal_notice') {
            return ['email' => $email, 'renews_on' => '2027-01-31', 'cancel_by' => '2027-01-24', 'notice_days' => 30, 'subscription_id' => 'SAMPLE'];
        }

        if ($type === 'subscription.renewal_payment_request') {
            return [
                'link' => rtrim($appUrl, '/') . '/invoices/SAMPLE',
                'email' => $email,
                'invoice_number' => '2026-000001',
                'offer' => 'Sample offer',
                'due_on' => '2026-01-08',
                'period_end' => '2026-02-08',
            ];
        }

        return [
            'link' => rtrim($appUrl, '/') . '/sign-in?reset=SAMPLE-TOKEN',
            'email' => $email,
            'purpose' => $type === 'account.invitation' ? 'INVITATION' : 'RESET',
        ];
    }

    /**
     * The words for a type in a language: the administrator's for that
     * language, else the platform's own in it.
     *
     * Not English's override. A person who chose French is addressed in
     * French; the administrator who rewrote the English mail rewrote the
     * English mail, and the French one is theirs to rewrite separately.
     *
     * @return array{subject: string, body: string}|null
     */
    private function templateFor(string $type, string $locale): ?array
    {
        $default = self::defaultFor($type, Locale::of($locale));

        if ($default === null) {
            return null;
        }

        $override = $this->templates->overrides()[Locale::of($locale)][$type] ?? null;

        return [
            'subject' => $override['subject'] ?? $default['subject'],
            'body' => $override['body'] ?? $default['body'],
        ];
    }

    /**
     * `{key}` becomes the payload's scalar for that key; an unknown key stays
     * as written, which is what tells an editor they misspelt one.
     *
     * @param array<string, mixed> $payload
     */
    public static function fill(string $text, array $payload): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static function (array $match) use ($payload): string {
                $value = $payload[$match[1]] ?? null;

                return is_scalar($value) ? (string) $value : $match[0];
            },
            $text,
        );
    }
}
