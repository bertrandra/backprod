<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The invariants the catalogue needs before anybody may write to it.
 *
 * Until now every offer was inserted by hand in SQL, and the schema could
 * afford to trust whoever was typing. An HTTP endpoint cannot: §12's central
 * rule — "une modification importante du prix, des quotas ou des
 * fonctionnalités crée une nouvelle version plutôt que de réécrire
 * l'historique" — has never been enforced by anything, and the moment there
 * is a `PATCH` it has to be.
 *
 * **A published version is frozen, and the trigger is what freezes it.** A
 * subscription points at a version precisely so that what a tenant bought
 * stays legible; an UPDATE on a sold version's price rewrites the terms of
 * a contract that is already running, and every invoice raised against it
 * becomes unexplainable. Two things stay mutable on purpose: `status`, so a
 * version can expire or be archived, and `valid_until`, so an offer can be
 * withdrawn from sale. Neither changes what anyone bought.
 *
 * The grants move with the version, so `offer_version_features` is frozen on
 * the same condition — a quota edited after the sale is exactly the case §12
 * names, and freezing the price while leaving the quota editable would guard
 * the smaller half. Its one exception is the cascade from a draft's own
 * deletion, where the parent row has already gone: the version's trigger has
 * decided by then, and a second opinion here would only make a deletable
 * draft undeletable.
 *
 * **Two versions of one offer may not be on sale at once.** `OfferCandidate`
 * resolves an overlap by taking the newest, which is the right way to read
 * data that already exists and the wrong way to let new data be written: the
 * question "what does this offer cost today" would have two defensible
 * answers, and the one a customer saw would depend on which code path asked.
 * The exclusion constraint makes the overlap impossible instead, so the
 * tie-break becomes unreachable rather than load-bearing.
 */
final class Version20260909100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Freeze published offer versions, forbid overlapping sale windows, add catalog.manage';
    }

    public function up(Schema $schema): void
    {
        // Reading the catalogue is something every member does; writing it is
        // not. §10.2 names `catalog.manage` for exactly this, and only
        // TENANT_ADMIN gets it — a USER who could publish an offer could
        // change what their own colleagues are charged.
        $this->addSql(<<<'SQL'
            INSERT INTO permissions (code, description) VALUES
                ('catalog.manage', 'Create offers, add versions and publish them')
            SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO role_permissions (role_id, permission_id)
            SELECT r.id, p.id
              FROM roles r
              CROSS JOIN permissions p
             WHERE r.code = 'TENANT_ADMIN'
               AND p.code = 'catalog.manage'
            SQL);

        $this->addSql(<<<'SQL'
            CREATE FUNCTION offer_version_is_frozen_once_published() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'DRAFT' THEN
                    RETURN NEW;
                END IF;

                IF NEW.version           IS DISTINCT FROM OLD.version
                   OR NEW.offer_id       IS DISTINCT FROM OLD.offer_id
                   OR NEW.billing_period IS DISTINCT FROM OLD.billing_period
                   OR NEW.price_minor_units IS DISTINCT FROM OLD.price_minor_units
                   OR NEW.currency       IS DISTINCT FROM OLD.currency
                   OR NEW.valid_from     IS DISTINCT FROM OLD.valid_from
                   OR NEW.term_months    IS DISTINCT FROM OLD.term_months
                   OR NEW.commitment_months IS DISTINCT FROM OLD.commitment_months
                   OR NEW.cancellation_policy IS DISTINCT FROM OLD.cancellation_policy
                   OR NEW.renewal        IS DISTINCT FROM OLD.renewal
                   OR NEW.early_termination IS DISTINCT FROM OLD.early_termination
                   OR NEW.notice_days    IS DISTINCT FROM OLD.notice_days
                THEN
                    RAISE EXCEPTION
                        'offer_versions: a published version is what somebody bought; '
                        'change the terms in a new version';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER offer_versions_frozen_once_published
                BEFORE UPDATE ON offer_versions
                FOR EACH ROW EXECUTE FUNCTION offer_version_is_frozen_once_published()
            SQL);

        // A DELETE would take the version a subscription names with it. The
        // foreign keys already RESTRICT that where a subscription exists;
        // this covers the version nobody has bought yet but which has been
        // published, and which some later reader will expect to find.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION offer_version_published_is_not_deletable() RETURNS trigger AS $$
            BEGIN
                IF OLD.status <> 'DRAFT' THEN
                    RAISE EXCEPTION
                        'offer_versions: a published version is history; archive it instead of deleting it';
                END IF;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER offer_versions_published_not_deletable
                BEFORE DELETE ON offer_versions
                FOR EACH ROW EXECUTE FUNCTION offer_version_published_is_not_deletable()
            SQL);

        // The grants are half the terms. Freezing the price and leaving the
        // quota editable would guard the smaller half of what §12 protects.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION offer_version_grants_are_frozen() RETURNS trigger AS $$
            DECLARE
                version_status TEXT;
                subject UUID;
            BEGIN
                subject := CASE WHEN TG_OP = 'DELETE' THEN OLD.offer_version_id
                                ELSE NEW.offer_version_id END;

                SELECT status INTO version_status
                  FROM offer_versions WHERE id = subject;

                -- The version is already gone: this is the CASCADE from its
                -- own delete, and that delete was vetted by the version's own
                -- trigger before it got here. Raising now would make a
                -- deletable draft undeletable, which is the opposite of what
                -- this guards.
                IF version_status IS NULL THEN
                    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
                END IF;

                IF version_status <> 'DRAFT' THEN
                    RAISE EXCEPTION
                        'offer_version_features: what a published version grants is what somebody bought';
                END IF;

                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TRIGGER offer_version_features_frozen
                BEFORE INSERT OR UPDATE OR DELETE ON offer_version_features
                FOR EACH ROW EXECUTE FUNCTION offer_version_grants_are_frozen()
            SQL);

        // btree_gist is already installed by the tax migration; the guard
        // keeps this migration standalone rather than ordering-dependent.
        $this->addSql('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // Partial, because only an ACTIVE version is on sale. Two DRAFTs may
        // sit in the same window — that is a plan, not a contradiction — and
        // EXPIRED versions overlap by their nature, since a window that has
        // closed still records when it was open.
        $this->addSql(<<<'SQL'
            ALTER TABLE offer_versions
                ADD CONSTRAINT offer_versions_one_on_sale_at_a_time
                    EXCLUDE USING gist (
                        offer_id WITH =,
                        tstzrange(valid_from, valid_until) WITH &&
                    ) WHERE (status = 'ACTIVE')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE offer_versions DROP CONSTRAINT IF EXISTS offer_versions_one_on_sale_at_a_time',
        );

        $this->addSql('DROP TRIGGER IF EXISTS offer_version_features_frozen ON offer_version_features');
        $this->addSql('DROP FUNCTION IF EXISTS offer_version_grants_are_frozen()');

        $this->addSql('DROP TRIGGER IF EXISTS offer_versions_published_not_deletable ON offer_versions');
        $this->addSql('DROP FUNCTION IF EXISTS offer_version_published_is_not_deletable()');

        $this->addSql('DROP TRIGGER IF EXISTS offer_versions_frozen_once_published ON offer_versions');
        $this->addSql('DROP FUNCTION IF EXISTS offer_version_is_frozen_once_published()');

        $this->addSql(<<<'SQL'
            DELETE FROM role_permissions
             WHERE permission_id IN (SELECT id FROM permissions WHERE code = 'catalog.manage')
            SQL);

        $this->addSql("DELETE FROM permissions WHERE code = 'catalog.manage'");
    }
}
