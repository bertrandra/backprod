<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The reason a staff member gives for crossing a tenant boundary (R14).
 *
 * Non-negotiable #21 requires such an access to be *traced, motivated and never
 * silent*. It was traced: `staff_access_log` records who looked, at what, and
 * under which `permission` — which is the answer to "on what grounds?", the
 * *authority* for the read. It was not **motivated**: nothing recorded why this
 * particular read happened, and no endpoint accepted one. U8 surfaced that and
 * filed it as R14 rather than adding a free-text box that went nowhere.
 *
 * **Two columns, because one of them alone is useless.**
 *
 * `purpose` is a small enumeration, so the log can be *counted*: how many reads
 * were billing investigations last month, which staff member opens tenants for
 * "security review" three times a day. A free-text field cannot answer either
 * question — R14's own words were that *"a mandatory free-text field collects
 * 'support' a thousand times and proves nothing"*.
 *
 * `reason` is the specific reference — a ticket id, a sentence naming what is
 * being investigated. R14's other half was that *"a structured one is only as
 * good as the system it points at"*, and the honest answer to both halves is to
 * take both: the enumeration is analysable and the text is specific. A minimum
 * length is enforced here rather than in the controller, so a reason of "x"
 * cannot enter the table by any route.
 *
 * **Nullable, and that is not a loophole.** Existing rows have no reason and
 * inventing one for them would be forging an audit record. Reads that do not
 * cross into a tenant's own data — listing the queue, reading the platform's own
 * access log — do not require one either, and the API is where that line is
 * drawn. What the column guarantees is that a row *claiming* a reason has a
 * usable one.
 */
final class Version20260910180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record why a staff read happened, not only under what authority (R14).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE staff_access_log
                ADD COLUMN purpose TEXT
                    CHECK (purpose IN (
                        'SUPPORT_REQUEST',
                        'BILLING_INVESTIGATION',
                        'INCIDENT',
                        'SECURITY_REVIEW',
                        'LEGAL_REQUEST'
                    ))
            SQL);

        // Long enough to say something. "x" and "asdf" are not reasons, and a
        // check here means no route into this table can accept one.
        $this->addSql(<<<'SQL'
            ALTER TABLE staff_access_log
                ADD COLUMN reason TEXT
                    CHECK (reason IS NULL OR length(btrim(reason)) BETWEEN 8 AND 500)
            SQL);

        // Either both or neither. A purpose with no reference is a category with
        // nothing in it; a reference with no category cannot be counted.
        $this->addSql(<<<'SQL'
            ALTER TABLE staff_access_log
                ADD CONSTRAINT staff_access_log_motivated
             CHECK ((purpose IS NULL) = (reason IS NULL))
            SQL);

        // The question this column exists to answer is "what did staff look at,
        // and why", asked over a period.
        $this->addSql(<<<'SQL'
            CREATE INDEX staff_access_log_purpose_idx
                ON staff_access_log (purpose, occurred_at DESC)
             WHERE purpose IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX staff_access_log_purpose_idx');
        $this->addSql('ALTER TABLE staff_access_log DROP CONSTRAINT staff_access_log_motivated');
        $this->addSql('ALTER TABLE staff_access_log DROP COLUMN reason');
        $this->addSql('ALTER TABLE staff_access_log DROP COLUMN purpose');
    }
}
