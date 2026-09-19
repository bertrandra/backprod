import { usePublicDemo, type DemoPage as DemoContents } from '@/queries/storefront';
import { EmptyState } from '@/ui/EmptyState';
import { ErrorSurface } from '@/ui/ErrorSurface';
import { Amount } from '@/ui/Money';
import { SkeletonRows } from '@/ui/Skeleton';
import { t } from '@/i18n';

/**
 * `/demo` — the demonstration page (2026-09-18).
 *
 * What the platform hosts, laid out for somebody being shown around: the
 * products with what is on sale for each, then every organisation with its
 * URL root, its holdings, what it subscribes to, and who is in it as what.
 * Outside both shells and outside the sign-in gate, like the storefront —
 * the person reading it may have no session at all.
 *
 * Everything here is a membership's answer everywhere else, which is why
 * the page exists only while a platform administrator has switched it on
 * (Console → Demo). Off is a 404 from the API and one sentence here;
 * nothing is cached, so the switch takes effect at once.
 */
export function DemoPage() {
  const demo = usePublicDemo();

  if (demo.isPending) {
    return (
      <main className="mx-auto max-w-4xl space-y-8 p-4 py-10">
        <SkeletonRows rows={6} />
      </main>
    );
  }

  if (demo.error !== null) {
    return (
      <main className="mx-auto max-w-4xl space-y-8 p-4 py-10">
        <ErrorSurface error={demo.error} onRetry={() => void demo.refetch()} />
      </main>
    );
  }

  if (demo.data === null) {
    return (
      <main className="mx-auto max-w-4xl space-y-8 p-4 py-10" data-testid="demo-off">
        <EmptyState
          title={t("No demonstration page")}
          description={t("This deployment does not show one. A platform administrator can switch it on from Console → Demo.")}
        />
        <p className="text-center text-sm">
          <a href="/" className="underline underline-offset-2">
            {t("Home page")}</a>
        </p>
      </main>
    );
  }

  return <DemoScreen contents={demo.data} />;
}

function DemoScreen({ contents }: { contents: DemoContents }) {
  return (
    <main className="mx-auto max-w-4xl space-y-10 p-4 py-10" data-testid="demo-page">
      <header className="space-y-2">
        <p className="text-xs font-semibold uppercase tracking-wide text-subtle">{t("Demonstration")}</p>
        <h1 className="text-3xl font-semibold">{t("What this platform hosts")}</h1>
        <p className="max-w-prose text-sm text-muted">
          {t("Every product with what is on sale for it, and every organisation with its address, its products, its subscriptions and its people. Open an organisation to see its own window; sign in as one of its people to see the application from inside.")}</p>
      </header>

      <section className="space-y-4" data-testid="demo-products">
        <h2 className="text-xl font-semibold">{t("Products and offers")}</h2>
        {contents.products.length === 0 ? (
          <EmptyState title={t("No product yet")} description={t("Nothing is hosted here.")} />
        ) : (
          <ul className="grid gap-4 sm:grid-cols-2">
            {contents.products.map((product) => (
              <li
                key={product.code}
                data-demo-product={product.code}
                className="space-y-3 rounded-card border border-line bg-surface p-5 shadow-raise"
              >
                <div className="flex items-baseline justify-between gap-2">
                  <h3 className="text-lg font-semibold">{product.name}</h3>
                  <code className="text-xs text-subtle">{product.code}</code>
                </div>
                {product.offers.length === 0 ? (
                  <p className="text-sm text-subtle">{t("Nothing on sale.")}</p>
                ) : (
                  <ul className="space-y-1.5 text-sm">
                    {product.offers.map((offer) => (
                      <li key={offer.code} className="flex flex-wrap items-baseline justify-between gap-2">
                        <span>
                          {offer.name}
                          <span className="ml-1 text-xs text-subtle">
                            {offer.plan} · {offer.billing_period.toLowerCase()}
                            {!offer.publicly_listed && ' · not advertised'}
                          </span>
                        </span>
                        <Amount money={offer.price} className="font-medium" />
                      </li>
                    ))}
                  </ul>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="space-y-4" data-testid="demo-tenants">
        <h2 className="text-xl font-semibold">{t("Organisations")}</h2>
        {contents.tenants.length === 0 ? (
          <EmptyState title={t("No organisation yet")} description={t("The platform has made none.")} />
        ) : (
          <ul className="space-y-4">
            {contents.tenants.map((tenant) => {
              const home = tenant.is_default ? '/' : `/${tenant.slug}/`;

              return (
                <li
                  key={tenant.slug}
                  data-demo-tenant={tenant.slug}
                  className="space-y-4 rounded-card border border-line bg-surface p-5 shadow-raise"
                >
                  <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 className="text-lg font-semibold">
                      <a href={home} className="underline underline-offset-2" data-testid={`demo-home-${tenant.slug}`}>
                        {tenant.name}
                      </a>
                      {tenant.is_default && (
                        <span className="ml-2 text-xs font-normal text-subtle">{t("default — the bare host")}</span>
                      )}
                    </h3>
                    <span className="text-xs text-subtle">
                      <code>{home}</code> {t("· joins by")}{' '}{tenant.join_policy.toLowerCase()}
                    </span>
                  </div>

                  <div className="grid gap-4 md:grid-cols-2">
                    <div className="space-y-1 text-sm">
                      <p className="text-xs font-semibold uppercase tracking-wide text-subtle">{t("Products")}</p>
                      {tenant.products.length === 0 ? (
                        <p className="text-subtle">{t("None assigned.")}</p>
                      ) : (
                        <p>{tenant.products.join(', ')}</p>
                      )}
                      <p className="pt-2 text-xs font-semibold uppercase tracking-wide text-subtle">{t("Subscriptions")}</p>
                      {tenant.subscriptions.length === 0 ? (
                        <p className="text-subtle">{t("None.")}</p>
                      ) : (
                        <ul className="space-y-0.5">
                          {tenant.subscriptions.map((subscription, index) => (
                            <li key={index}>
                              <code className="text-xs">{subscription.product}</code> · {subscription.offer}{' '}
                              <span className="text-subtle">({subscription.plan}, {subscription.status.toLowerCase()})</span>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>

                    <div className="space-y-1 text-sm">
                      <p className="text-xs font-semibold uppercase tracking-wide text-subtle">{t("People")}</p>
                      {tenant.members.length === 0 ? (
                        <p className="text-subtle">{t("Nobody yet.")}</p>
                      ) : (
                        <ul className="space-y-1">
                          {tenant.members.map((member, index) => (
                            <li key={index} className="flex flex-wrap items-baseline gap-x-2">
                              <span>{member.display_name ?? member.email ?? t("Unnamed")}</span>
                              {member.email !== null && member.display_name !== null && (
                                <span className="text-xs text-subtle">{member.email}</span>
                              )}
                              <span className="ml-auto text-xs">{member.roles.join(', ')}</span>
                            </li>
                          ))}
                        </ul>
                      )}
                    </div>
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </section>
    </main>
  );
}
