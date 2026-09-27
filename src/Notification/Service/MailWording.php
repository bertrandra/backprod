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
            'subscription.payment_overdue' => [
                'subject' => 'Échec de paiement — votre accès est suspendu',
                'body' => "La facture {invoice_number} était due le {due_on} et reste impayée, {days_overdue} jours plus tard.\n\n"
                    . "L'accès au produit est suspendu jusqu'à son règlement. Vos factures et vos paiements restent "
                    . "consultables : vous pouvez payer depuis l'écran d'abonnement.\n\n"
                    . "Si vous avez déjà payé, il n'y a rien à faire : l'accès revient dès que le paiement est confirmé.",
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
            'subscription.payment_overdue' => [
                'subject' => 'Pago fallido: su acceso está suspendido',
                'body' => "La factura {invoice_number} vencía el {due_on} y sigue sin pagar, {days_overdue} días después.\n\n"
                    . 'El acceso al producto está suspendido hasta que se liquide. Sus facturas y sus pagos siguen '
                    . "disponibles: puede pagar desde la pantalla de suscripción.\n\n"
                    . 'Si ya ha pagado, no hace falta nada más: el acceso vuelve en cuanto se confirme el pago.',
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
            'subscription.payment_overdue' => [
                'subject' => 'Zahlung fehlgeschlagen – Ihr Zugang ist gesperrt',
                'body' => "Die Rechnung {invoice_number} war am {due_on} fällig und ist {days_overdue} Tage später noch offen.\n\n"
                    . 'Der Zugang zum Produkt ist gesperrt, bis sie bezahlt ist. Ihre Rechnungen und Zahlungen bleiben '
                    . "einsehbar: Sie können über den Abonnement-Bildschirm bezahlen.\n\n"
                    . 'Falls Sie bereits bezahlt haben, ist nichts weiter zu tun: der Zugang kommt zurück, sobald die Zahlung bestätigt ist.',
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
            'subscription.payment_overdue' => [
                'subject' => 'Pagamento non riuscito: il tuo accesso è sospeso',
                'body' => "La fattura {invoice_number} era dovuta il {due_on} e risulta ancora non pagata, {days_overdue} giorni dopo.\n\n"
                    . "L'accesso al prodotto è sospeso fino al saldo. Le tue fatture e i tuoi pagamenti restano "
                    . "consultabili: puoi pagare dalla schermata dell'abbonamento.\n\n"
                    . "Se hai già pagato non serve altro: l'accesso torna appena il pagamento è confermato.",
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
