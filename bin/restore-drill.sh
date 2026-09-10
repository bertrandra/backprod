#!/usr/bin/env bash
#
# Rehearses a restore, and proves the backup is one.
#
#     bin/restore-drill.sh
#
# A backup nobody has restored is a file, not a backup. This dumps the database
# `DATABASE_DSN` points at, restores it into a scratch database, and then asks
# the restored copy the questions that would matter after a real incident: are
# the invoices there, are their legal numbers intact and still gapless, is the
# schema at the same migration, does the subscription still point at its offer.
#
# It cleans up after itself and leaves the source database untouched — it only
# ever reads from it. The scratch database is dropped whether the drill passes or
# fails, because a half-restored copy left lying around is the next person's
# confusing afternoon.
#
# **What it does not prove.** That a backup taken by whatever runs in production
# — a managed provider's snapshot, a cron job, a replica — is restorable. It
# proves the *procedure* works against this schema and this data, which is the
# part that can be rehearsed here. Pointing DATABASE_DSN at a restored production
# snapshot is what turns it into a real drill.

set -euo pipefail

: "${DATABASE_DSN:?DATABASE_DSN must be set — this drill reads the database it names}"

# Parse the DSN rather than asking for the parts again: two sources for one fact
# is how a drill ends up rehearsing against the wrong database.
proto_removed="${DATABASE_DSN#*://}"
credentials="${proto_removed%@*}"
host_and_db="${proto_removed#*@}"
db_user="${credentials%%:*}"
db_password="${credentials#*:}"
host_port="${host_and_db%%/*}"
source_db="${host_and_db##*/}"
source_db="${source_db%%\?*}"
db_host="${host_port%%:*}"
db_port="${host_port##*:}"
[ "$db_port" = "$db_host" ] && db_port=5432

scratch="${source_db}_restore_drill_$$"
dump_file="$(mktemp -t backprod-drill-XXXXXX.sql)"

export PGPASSWORD="$db_password"
psql_args=(-h "$db_host" -p "$db_port" -U "$db_user" -v ON_ERROR_STOP=1 -q)

cleanup() {
  rm -f "$dump_file"
  psql "${psql_args[@]}" -d postgres -c "DROP DATABASE IF EXISTS \"$scratch\"" >/dev/null 2>&1 || true
}
trap cleanup EXIT

printf 'Restore drill\n\n  source     %s on %s:%s\n' "$source_db" "$db_host" "$db_port"

# --- What the source says, so the restored copy can be compared to it ---------

read_one() {
  psql "${psql_args[@]}" -d "$1" -Atc "$2"
}

source_invoices=$(read_one "$source_db" 'SELECT count(*) FROM invoices')
source_numbers=$(read_one "$source_db" "SELECT coalesce(string_agg(number, ',' ORDER BY number), '') FROM invoices WHERE number IS NOT NULL")
source_migrations=$(read_one "$source_db" 'SELECT count(*) FROM schema_migrations')

printf '  contains   %s invoice(s), %s migration(s) applied\n\n' "$source_invoices" "$source_migrations"

# --- Dump, restore ------------------------------------------------------------

printf '  dumping…\n'
pg_dump -h "$db_host" -p "$db_port" -U "$db_user" --no-owner --no-privileges "$source_db" > "$dump_file"
printf '  dump is %s bytes\n' "$(wc -c < "$dump_file" | tr -d ' ')"

printf '  restoring into %s…\n' "$scratch"
psql "${psql_args[@]}" -d postgres -c "CREATE DATABASE \"$scratch\"" >/dev/null
psql "${psql_args[@]}" -d "$scratch" -f "$dump_file" >/dev/null

# --- The questions that matter after an incident ------------------------------

failures=0

check() {
  local what="$1" expected="$2" actual="$3"

  if [ "$expected" = "$actual" ]; then
    printf '  ok    %s\n' "$what"
  else
    printf '  FAIL  %s\n        expected %s\n        got      %s\n' "$what" "$expected" "$actual"
    failures=$((failures + 1))
  fi
}

printf '\n'

check 'every invoice came back' "$source_invoices" "$(read_one "$scratch" 'SELECT count(*) FROM invoices')"
check 'the legal numbers are identical' "$source_numbers" \
  "$(read_one "$scratch" "SELECT coalesce(string_agg(number, ',' ORDER BY number), '') FROM invoices WHERE number IS NOT NULL")"
check 'the schema is at the same migration' "$source_migrations" \
  "$(read_one "$scratch" 'SELECT count(*) FROM schema_migrations')"

# Constraints and triggers, not only rows. A restore that dropped the trigger
# freezing a closed VAT period would look perfect and have lost the invariant.
check 'the VAT period freeze trigger survived' \
  "$(read_one "$source_db" "SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal")" \
  "$(read_one "$scratch" "SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal")"

check 'every foreign key survived' \
  "$(read_one "$source_db" "SELECT count(*) FROM pg_constraint WHERE contype = 'f'")" \
  "$(read_one "$scratch" "SELECT count(*) FROM pg_constraint WHERE contype = 'f'")"

# A subscription whose offer version went missing is a restore that lost a join.
# The column is `offer_version_id`, not `offer_id`: a subscription holds the
# *version* it was sold, because a version freezes when it is published (ADR-033)
# and the offer above it keeps changing.
check 'subscriptions still reach their offer version' '0' \
  "$(read_one "$scratch" 'SELECT count(*) FROM subscriptions s LEFT JOIN offer_versions v ON v.id = s.offer_version_id WHERE v.id IS NULL')"

printf '\n'

if [ "$failures" -gt 0 ]; then
  printf 'The restored copy is not the database that was dumped. %s check(s) failed.\n' "$failures" >&2
  exit 1
fi

printf 'Restored and verified. The procedure works against this schema.\n'
