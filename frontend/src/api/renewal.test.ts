import { describe, expect, it } from 'vitest';

import { classify, type Renewal, type RenewalEnvironment, SessionRenewal, type SessionMessage } from './renewal';

/**
 * The one door a session is renewed through (ADR-062).
 *
 * Several tabs are several `SessionRenewal`s sharing one lock and one
 * channel, which is exactly what a browser gives them — so that is what is
 * built here, with no browser: a lock that runs callers one at a time, and a
 * channel that delivers to every instance but the sender.
 */
function browser() {
  let queue: Promise<unknown> = Promise.resolve();
  const members: ((event: MessageEvent) => void)[] = [];

  return (): RenewalEnvironment => {
    let mine: ((event: MessageEvent) => void) | undefined;

    return {
      locks: {
        request: <T>(_name: string, callback: () => Promise<T>): Promise<T> => {
          const run = queue.then(callback);
          queue = run.catch(() => undefined);

          return run;
        },
      },
      channel: {
        postMessage: (message: unknown) => {
          for (const member of members) {
            if (member !== mine) {
              member(new MessageEvent('message', { data: message }));
            }
          }
        },
        addEventListener: (_type: 'message', listener: (event: MessageEvent) => void) => {
          mine = listener;
          members.push(listener);
        },
      },
    };
  };
}

const RENEWED: Renewal = { kind: 'renewed', grant: { accessToken: 'fresh', expiresIn: 3600 } };

function deferred<T>() {
  let resolve: (value: T) => void = () => undefined;
  const promise = new Promise<T>((r) => {
    resolve = r;
  });

  return { promise, resolve };
}

describe('within one tab', () => {
  it('shares one attempt between everybody who asks at once', async () => {
    let asked = 0;
    const renewal = new SessionRenewal(() => {
      asked += 1;

      return Promise.resolve(RENEWED);
    });

    const answers = await Promise.all([renewal.renew(), renewal.renew(), renewal.renew()]);

    expect(asked).toBe(1);
    expect(answers.every((answer) => answer.kind === 'renewed')).toBe(true);
  });

  it('asks again once the previous attempt is over', async () => {
    let asked = 0;
    const renewal = new SessionRenewal(() => {
      asked += 1;

      return Promise.resolve(RENEWED);
    });

    await renewal.renew();
    await renewal.renew();

    expect(asked).toBe(2);
  });

  it('turns a request that threw into "unavailable", never into a refusal', async () => {
    const renewal = new SessionRenewal(() => Promise.reject(new TypeError('Failed to fetch')));

    expect(await renewal.renew()).toEqual({ kind: 'unavailable' });
  });
});

describe('across tabs', () => {
  it('asks the server once when two tabs renew together, and both get the answer', async () => {
    const tab = browser();
    let asked = 0;
    const gate = deferred<Renewal>();
    const ask = () => {
      asked += 1;

      return gate.promise;
    };

    const first = new SessionRenewal(ask, tab());
    const second = new SessionRenewal(ask, tab());

    const both = Promise.all([first.renew(), second.renew()]);
    gate.resolve(RENEWED);
    const [a, b] = await both;

    // The second waited for the lock, found the first tab's broadcast newer
    // than its own question, and took it rather than rotating again.
    expect(asked).toBe(1);
    expect(a).toEqual(RENEWED);
    expect(b.kind).toBe('renewed');
    expect(b.kind === 'renewed' ? b.grant.accessToken : null).toBe('fresh');
  });

  it('tells the other tabs a token was renewed, and not the tab that did it', async () => {
    const tab = browser();
    const heard: SessionMessage[] = [];
    const renewing = new SessionRenewal(() => Promise.resolve(RENEWED), tab());
    const other = new SessionRenewal(() => Promise.resolve(RENEWED), tab());

    renewing.subscribe(() => heard.push({ kind: 'ended' }));
    other.subscribe((message) => heard.push(message));

    await renewing.renew();

    expect(heard).toHaveLength(1);
    expect(heard[0]?.kind).toBe('renewed');
  });

  it('tells the other tabs when the server refused, so none keeps working on a dead session', async () => {
    const tab = browser();
    const heard: SessionMessage[] = [];
    const refused = new SessionRenewal(() => Promise.resolve({ kind: 'refused' }), tab());
    const other = new SessionRenewal(() => Promise.resolve(RENEWED), tab());

    other.subscribe((message) => heard.push(message));

    await refused.renew();

    expect(heard).toEqual([{ kind: 'ended' }]);
  });

  it('says nothing to the others when the server could not answer', async () => {
    const tab = browser();
    const heard: SessionMessage[] = [];
    const failing = new SessionRenewal(() => Promise.resolve({ kind: 'unavailable' }), tab());
    const other = new SessionRenewal(() => Promise.resolve(RENEWED), tab());

    other.subscribe((message) => heard.push(message));

    await failing.renew();

    expect(heard).toEqual([]);
  });
});

describe('what an answer from /auth/refresh means', () => {
  it('is a refusal only when the server says 401', () => {
    expect(classify(401, null)).toEqual({ kind: 'refused' });
  });

  it('is unavailable for a rate limit, a server error or a proxy page', () => {
    expect(classify(429, null)).toEqual({ kind: 'unavailable' });
    expect(classify(502, null)).toEqual({ kind: 'unavailable' });
    expect(classify(503, { error: {} })).toEqual({ kind: 'unavailable' });
    expect(classify(200, 'an HTML page')).toEqual({ kind: 'unavailable' });
  });

  it('is renewed when a session came back', () => {
    expect(classify(200, { access_token: 't', token_type: 'Bearer', expires_in: 60 })).toEqual({
      kind: 'renewed',
      grant: { accessToken: 't', expiresIn: 60 },
    });
  });
});
