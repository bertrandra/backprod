/**
 * Meaning, expressed once.
 *
 * The design system gave the application semantic colour tokens; twenty screens
 * had not heard about it. `bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40
 * dark:text-emerald-200` was written out **eleven** times, `bg-amber-100 …`
 * seventeen, and each copy was a pair of Tailwind palette steps somebody picked
 * by eye. They drifted: a paid invoice, a running job and a verified VAT number
 * were three different greens, and none of them was the green the tokens define.
 *
 * So a status has a *tone*, and a tone has exactly one rendering. Screens map
 * their own vocabulary onto these five — that mapping is domain knowledge and
 * belongs to the screen — but no screen picks colours any more.
 *
 * **Five and no more.** A sixth tone is a request to say something the palette
 * cannot, and the answer is almost always that two states share a meaning:
 * `SENT` and `RUNNING` are both `info`, and the word beside the dot is what
 * distinguishes them. Colour carries urgency; the label carries the fact.
 */
export type Tone =
  /** Finished, and finished well: paid, active, verified, succeeded. */
  | 'success'
  /** Waiting on somebody or something: due, pending, unverified, retrying. */
  | 'warning'
  /** Refused, failed, cancelled — something a person has to deal with. */
  | 'danger'
  /** In flight, and neither good nor bad yet: sent, running, queued. */
  | 'info'
  /** No state worth colouring: a draft, an archive, a value not applicable. */
  | 'neutral';

const PILL: Record<Tone, string> = {
  success: 'bg-success-wash text-success',
  warning: 'bg-warning-wash text-warning',
  danger: 'bg-danger-wash text-danger',
  info: 'bg-info-wash text-info',
  // `bg-line` and not `bg-well`, because a pill sits on a *card*. In the dark
  // theme the well is darker than the surface by three hundredths of a step,
  // which made a cancelled invoice's badge disappear into the card it was
  // labelling — grey is the right meaning, invisible is not. The line colour is
  // the one value that reads as a chip against a surface in both themes.
  neutral: 'bg-line text-muted',
};

const PANEL: Record<Tone, string> = {
  success: 'border-success/30 bg-success-wash',
  warning: 'border-warning/30 bg-warning-wash',
  danger: 'border-danger/30 bg-danger-wash',
  info: 'border-info/30 bg-info-wash',
  neutral: 'border-line bg-well',
};

const INK: Record<Tone, string> = {
  success: 'text-success',
  warning: 'text-warning',
  danger: 'text-danger',
  info: 'text-info',
  neutral: 'text-muted',
};

const DOT: Record<Tone, string> = {
  success: 'bg-success',
  warning: 'bg-warning',
  danger: 'bg-danger',
  info: 'bg-info',
  neutral: 'bg-line-strong',
};

/**
 * A status badge: the shape a state wears when it sits beside a row's subject.
 *
 * Small caps rather than the raw enum, because `PAST_DUE` shouted and
 * `Past due` reads. Screens pass the label; this decides how it looks.
 */
export function pill(tone: Tone): string {
  return `inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs font-semibold uppercase ${PILL[tone]}`;
}

/**
 * A bordered block in a tone: a refusal, a caution, a set of retention grounds.
 *
 * **Surface and border only, deliberately.** The first version also set the
 * ink, which was right for a paragraph and wrong for everything else: the
 * access-motive gate is a warning panel *containing a form*, and tinting the
 * container repainted its labels, its options and its help text amber. A
 * container states its tone with its edge; what is inside it keeps the ink its
 * own role asks for.
 *
 * **One ink does not survive a wash: `text-subtle`.** It is tuned to clear
 * 4.5:1 against the *canvas*, and a tone wash is darker than the canvas — on
 * `bg-success-wash` it measures 4.33 in the light theme, which the contrast scan
 * caught on the queue screen's liveness panel. Inside a panel, `text-muted` is
 * the quietest ink available (6.3:1 on every wash, in both themes).
 */
export function panel(tone: Tone): string {
  return `rounded-card border p-4 text-sm ${PANEL[tone]}`;
}

/**
 * A panel that *is* the message, so its ink is the tone too.
 *
 * The distinction is whether anything inside has a voice of its own. “This
 * product cannot invoice yet” is one sentence and takes `notice`; a panel
 * holding a heading, a list and a form takes `panel`.
 */
export function notice(tone: Tone): string {
  return `${panel(tone)} ${INK[tone]}`;
}

/** Just the ink, for a sentence that carries its own tone inside other text. */
export function ink(tone: Tone): string {
  return INK[tone];
}

/**
 * Six pixels of colour, for when the tone is context rather than a verdict.
 *
 * The platform band and the readiness banner both learned the same thing: a
 * full wash behind a paragraph that is always on screen reads as an alarm going
 * off permanently. A dot beside ordinary text says the same and stops shouting.
 */
export function dot(tone: Tone): string {
  return `mt-1.5 size-1.5 shrink-0 rounded-full ${DOT[tone]}`;
}
