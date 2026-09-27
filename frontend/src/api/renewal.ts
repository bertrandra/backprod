/**
 * Renewing a session: once per browser at a time, with every tab told the
 * outcome (ADR-062).
 *
 * The refresh cookie is one credential shared by every tab — and, with
 * `AUTH_COOKIE_DOMAIN`, by the product beside the platform. There used to be
 * three independent ways to spend it in each tab (the restore on load, the
 * renewal timer, the 401 retry) and one of each per tab, so a browser
 * restoring five tabs sent fifteen refreshes with one cookie. The server now
 * answers that safely — a rotation is derived, so the same token always gets
 * the same replacement — but a server that is safe under a stampede is no
 * reason to cause one. This is the one door.
 *
 * - **One at a time per browser.** A Web Lock, which every tab of this origin
 *   shares. A tab that waited for it while another renewed takes the answer
 *   that tab broadcast instead of asking again.
 * - **Every tab hears the outcome.** A BroadcastChannel carries a renewed
 *   access token to tabs that did not ask, and a refusal or a sign-out to all
 *   of them, so nobody keeps working on a session the server has ended.
 * - **Only a refusal is a refusal.** A 401 from `/auth/refresh` ends the
 *   session. A 429, a 5xx or a request that never arrived does not: the cookie
 *   is as good as it was, and treating an outage as a sign-out is what put
 *   people on the sign-in form when their laptop woke before its Wi-Fi did.
 *
 * Deliberately free of React, the store and `fetch`: how to ask is passed in,
 * so the request middleware and the lifecycle hook share one instance, and a
 * test can drive it with no network and no browser.
 */

/** What a successful exchange gives this browser to work with. */
export interface Grant {
  readonly accessToken: string;
  /** Seconds, from now. */
  readonly expiresIn: number;
}

export type Renewal =
  | { readonly kind: 'renewed'; readonly grant: Grant }
  /** The server ended the session: sign in again. */
  | { readonly kind: 'refused' }
  /** No answer worth acting on — rate-limited, failing or unreachable. The session stands. */
  | { readonly kind: 'unavailable' };

/** What travels between tabs. `expiresAt` is absolute, so a late reader computes what is left. */
export type SessionMessage =
  | { readonly kind: 'renewed'; readonly accessToken: string; readonly expiresAt: number; readonly at: number }
  | { readonly kind: 'ended' };

export interface RenewalEnvironment {
  readonly locks?: { request<T>(name: string, callback: () => Promise<T>): Promise<T> };
  readonly channel?: {
    postMessage(message: unknown): void;
    addEventListener(type: 'message', listener: (event: MessageEvent) => void): void;
  };
  readonly now?: () => number;
}

const LOCK = 'backprod.session.renewal';
const CHANNEL = 'backprod.session';

/**
 * What this browser offers, each guarded: an old browser, a test runner or a
 * sandboxed frame may lack either, and the renewal then works as it did —
 * within one tab — rather than not at all.
 */
export function browserEnvironment(): RenewalEnvironment {
  const locks = typeof navigator !== 'undefined' && 'locks' in navigator ? navigator.locks : undefined;
  let channel: BroadcastChannel | undefined;

  try {
    channel = typeof BroadcastChannel === 'undefined' ? undefined : new BroadcastChannel(CHANNEL);
  } catch {
    channel = undefined;
  }

  return {
    ...(locks === undefined
      ? {}
      : { locks: { request: <T>(name: string, callback: () => Promise<T>) => locks.request(name, callback) as Promise<T> } }),
    ...(channel === undefined ? {} : { channel }),
  };
}

function isMessage(value: unknown): value is SessionMessage {
  if (typeof value !== 'object' || value === null || !('kind' in value)) {
    return false;
  }

  const message = value as Record<string, unknown>;

  return (
    message.kind === 'ended' ||
    (message.kind === 'renewed' &&
      typeof message.accessToken === 'string' &&
      typeof message.expiresAt === 'number' &&
      typeof message.at === 'number')
  );
}

export class SessionRenewal {
  private inFlight: Promise<Renewal> | null = null;
  /** The last renewal another tab broadcast. */
  private latest: { accessToken: string; expiresAt: number; at: number } | null = null;
  private readonly listeners = new Set<(message: SessionMessage) => void>();
  private readonly now: () => number;

  constructor(
    private readonly ask: () => Promise<Renewal>,
    private readonly environment: RenewalEnvironment = {},
  ) {
    this.now = environment.now ?? (() => Date.now());

    environment.channel?.addEventListener('message', (event: MessageEvent) => {
      if (!isMessage(event.data)) {
        return;
      }

      if (event.data.kind === 'renewed') {
        this.latest = { accessToken: event.data.accessToken, expiresAt: event.data.expiresAt, at: event.data.at };
      } else {
        this.latest = null;
      }

      for (const listener of this.listeners) {
        listener(event.data);
      }
    });
  }

  /**
   * A renewed session, or why not. Callers in this tab share one attempt;
   * tabs share one at a time.
   */
  renew(): Promise<Renewal> {
    this.inFlight ??= this.underLock().finally(() => {
      this.inFlight = null;
    });

    return this.inFlight;
  }

  /** What other tabs say happened. The tab that did it is not told. */
  subscribe(listener: (message: SessionMessage) => void): () => void {
    this.listeners.add(listener);

    return () => {
      this.listeners.delete(listener);
    };
  }

  /** Tells every other tab this session is over — a sign-out, or a refusal. */
  ended(): void {
    this.latest = null;
    this.environment.channel?.postMessage({ kind: 'ended' } satisfies SessionMessage);
  }

  private underLock(): Promise<Renewal> {
    const asked = this.now();
    const exchange = () => this.exchange(asked);

    return this.environment.locks === undefined ? exchange() : this.environment.locks.request(LOCK, exchange);
  }

  private async exchange(asked: number): Promise<Renewal> {
    // Another tab renewed while this one waited for the lock: its answer is
    // this one's too, and asking again would only rotate the cookie for
    // nothing.
    const latest = this.latest;

    if (latest !== null && latest.at >= asked && latest.expiresAt > this.now()) {
      return {
        kind: 'renewed',
        grant: { accessToken: latest.accessToken, expiresIn: Math.floor((latest.expiresAt - this.now()) / 1000) },
      };
    }

    let answer: Renewal;

    try {
      answer = await this.ask();
    } catch {
      answer = { kind: 'unavailable' };
    }

    if (answer.kind === 'renewed') {
      const at = this.now();
      const message: SessionMessage = {
        kind: 'renewed',
        accessToken: answer.grant.accessToken,
        expiresAt: at + answer.grant.expiresIn * 1000,
        at,
      };

      // Broadcast, not remembered: `latest` is what *other* tabs renewed. A
      // tab asking again after its own renewal has a reason to — its fresh
      // token was refused — and must reach the server.
      this.environment.channel?.postMessage(message);
    }

    if (answer.kind === 'refused') {
      this.ended();
    }

    return answer;
  }
}

/**
 * What an answer from `/auth/refresh` means. The status decides, never the
 * absence of a body: a 429 has no session in it and is not a sign-out.
 */
export function classify(status: number, body: unknown): Renewal {
  if (status === 401) {
    return { kind: 'refused' };
  }

  if (status >= 200 && status < 300 && typeof body === 'object' && body !== null) {
    const grant = body as { access_token?: unknown; expires_in?: unknown };

    if (typeof grant.access_token === 'string' && typeof grant.expires_in === 'number') {
      return { kind: 'renewed', grant: { accessToken: grant.access_token, expiresIn: grant.expires_in } };
    }
  }

  return { kind: 'unavailable' };
}

/**
 * One renewal per API client, so the middleware inside the client and the
 * hook outside it spend the cookie through the same door. Keyed by client so
 * two providers — every test renders its own — never share one.
 */
const registry = new WeakMap<object, SessionRenewal>();

export function registerRenewal(client: object, renewal: SessionRenewal): void {
  registry.set(client, renewal);
}

export function renewalFor(client: object, fallback: () => SessionRenewal): SessionRenewal {
  let renewal = registry.get(client);

  if (renewal === undefined) {
    renewal = fallback();
    registry.set(client, renewal);
  }

  return renewal;
}
