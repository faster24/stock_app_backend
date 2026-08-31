# Production backup & restore

Backups are OS-level cron jobs on the production VPS, deliberately independent of
Laravel — they keep running when the app is broken, mid-deploy, or the queue worker
is dead, which is exactly when they matter.

**Scope, stated plainly:** these are **on-box** backups. They protect against bad
migrations, bad queries, and fat-finger deletes. They do **not** survive host loss,
disk failure, or provider termination. Offsite replication is phase 2 — see
[Going offsite](#going-offsite).

## What runs

| Job | Schedule | Runs as | Writes to |
|-----|----------|---------|-----------|
| `db-backup.sh` | hourly, `:00` | `admin` | `/opt/backups/hourly/`, promoted daily to `/opt/backups/daily/` |
| `db-backup.sh --tag predeploy` | every deploy | CI over SSH | `/opt/backups/predeploy/` |
| `media-backup.sh` | daily, `03:20` | **root** | `/opt/backups/media/` |
| `db-backup-check.sh` | daily, `03:40` | `admin` | stderr → cron `MAILTO` |

Retention: 24 hourly, 14 daily, 5 predeploy, 7 media. All configurable at the top of
each script.

Measured 2026-08-28: the database is 1.8 MB and gzips to a **52 KB** dump; media is
4.1 MB across 20 files. Total footprint is therefore ~2 MB of dumps plus ~29 MB of
media archives — about **30 MB** against 29 GB free. Retention here is set for
usefulness, not scarcity; `MIN_FREE_MB` is what actually protects the disk as the data
grows.

`media-backup.sh` must run as **root**. `storage/app/bet-slips` is mode `0700` owned by
`apache`, and the `admin` deploy user is not in the `apache` group — run as `admin` it
would archive everything *except* the deposit proofs and payout proofs. The script
checks readability up front and fails loudly rather than producing a plausible-looking
archive with the evidence missing.

## First-time setup

None of this is automatic, and the pieces are independent — the deploy will fail on
each missing one in turn, which is exactly how it went the first time. Do all four.

**1. The backup root** — as root, once. The scripts refuse to run when it is missing
(`db-backup.sh:61`) rather than invent a path, and `/opt` is root-owned so the deploy
user cannot create it:

```bash
sudo mkdir -p /opt/backups
sudo chown admin:admin /opt/backups
sudo chmod 0700 /opt/backups          # dumps carry customer phone numbers
```

`hourly/`, `daily/`, `predeploy/`, `media/` and `bin/` are created underneath by the
scripts and the deploy — do not pre-create them.

**2. The MySQL backup user** — from a DBA/root MySQL account. `--single-transaction`
needs no lock, but `--routines`, `--triggers` and `--events` each need a privilege
beyond `SELECT`, so this user is *not* SELECT-only despite what the restore section
below used to imply:

```sql
CREATE USER 'backup_admin'@'127.0.0.1' IDENTIFIED BY '<choose a strong password>';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON lotto_db.* TO 'backup_admin'@'127.0.0.1';
GRANT SELECT ON mysql.proc TO 'backup_admin'@'127.0.0.1';   -- MariaDB: --routines reads this
FLUSH PRIVILEGES;
```

It has no write privilege anywhere, which is deliberate: this account can dump the
database and cannot alter it. That also means it **cannot perform a restore** — use a
DBA account for that.

If `mysqldump` complains about tablespace access on a future MariaDB, add
`GRANT PROCESS ON *.* TO 'backup_admin'@'127.0.0.1';` — not granted by default here because
`PROCESS` exposes every session's query text server-wide.

**3. `~/.my.cnf` for the deploy user** — this is what `db-backup.sh` reads
(`MYSQL_DEFAULTS`, overridable). Without it the deploy dies with
`cannot read credentials file /home/admin/.my.cnf`:

```bash
cat > ~/.my.cnf <<'CNF'
[client]
user=backup_admin
password=<the password from step 2>
CNF
chmod 0600 ~/.my.cnf
```

`0600` is not optional — `mysqldump` warns on a world-readable defaults file, and this
one holds a live database password.

**4. The cron entries.** `db-backup.sh` and `db-backup-check.sh` are installed to
`/opt/backups/bin/` by the deploy on every push, so they cannot drift from the repo.
`media-backup.sh` is **not** — it runs as root, and syncing it from a deploy that runs
as `admin` would leave a root-executed file writable by anyone who can push. Install
that one by hand, root-owned:

```bash
sudo install -m 0755 -o root -g root \
  /opt/apps/backend/scripts/media-backup.sh /opt/backups/bin/media-backup.sh
```

Then, `crontab -e` as `admin`:

```cron
MAILTO=<an address you actually read>
0  * * * * /opt/backups/bin/db-backup.sh
40 3 * * * /opt/backups/bin/db-backup-check.sh
```

and `sudo crontab -e` as root:

```cron
20 3 * * * /opt/backups/bin/media-backup.sh
```

`db-backup-check.sh` is the only thing that will tell you the backups have stopped.
Without a `MAILTO` that reaches a human, a silent failure stays silent.

**Verify the whole chain** before trusting it:

```bash
/opt/backups/bin/db-backup.sh --tag predeploy
ls -l /opt/backups/predeploy/          # expect a ~52 KB .sql.gz
/opt/backups/bin/db-backup-check.sh    # expect silence
```

## Restoring the database

**Never restore over the live database.** Restore to a scratch DB, verify it, then
decide.

```bash
ssh -p 2222 admin@162.0.239.182
ls -lt /opt/backups/hourly/            # pick the dump you want
```

The dumps are written with `--databases`, so they carry their own `CREATE DATABASE` and
`USE lotto_db` statements. That is convenient for full disaster recovery and dangerous
for a test restore: piping one straight into `mysql` **overwrites the live database**.
For a scratch restore, strip those lines:

```bash
mysql --defaults-file=~/.my.cnf -e "CREATE DATABASE lotto_db_restore_test"

gunzip -c /opt/backups/hourly/lotto_db-20260828-1400.sql.gz \
  | grep -v -E '^(CREATE DATABASE|USE )' \
  | mysql --defaults-file=~/.my.cnf lotto_db_restore_test
```

Note the `backup_admin` MySQL user holds no write privilege anywhere by design (see
[First-time setup](#first-time-setup)) and **cannot** perform the restore — use a
DBA/root MySQL account for this step.

### Verify before trusting it

A file that exists is not a backup. A file that restores is.

```sql
USE lotto_db_restore_test;

SELECT 'users' t, COUNT(*) n FROM users
UNION ALL SELECT 'bets',              COUNT(*) FROM bets
UNION ALL SELECT 'bet_numbers',       COUNT(*) FROM bet_numbers
UNION ALL SELECT 'deposits',          COUNT(*) FROM deposits
UNION ALL SELECT 'withdrawals',       COUNT(*) FROM withdrawals
UNION ALL SELECT 'wallets',           COUNT(*) FROM wallets
UNION ALL SELECT 'wallet_transactions', COUNT(*) FROM wallet_transactions;

-- Should sit within the RPO window of when the dump was taken.
SELECT MAX(created_at) FROM bets;
```

Drop the scratch DB when done: `DROP DATABASE lotto_db_restore_test;`

## Restoring media

```bash
sudo tar -xzpf /opt/backups/media/bet-slips-20260828-032000.tar.gz \
  -C /opt/apps/backend/storage/app

# Restore the permissions the app expects. The archive preserves them, but
# check anyway -- a readable bet-slips directory is a data leak.
sudo chown -R apache:apache /opt/apps/backend/storage/app/bet-slips
sudo chmod 0700 /opt/apps/backend/storage/app/bet-slips
```

A database restored without these files leaves payment disputes pointing at proof
images that no longer exist.

## Full disaster recovery

1. Provision the box, install PHP 8.2+, MariaDB, nginx; deploy the app to
   `/opt/apps/backend`. Redo [First-time setup](#first-time-setup) — the backup
   root, the MySQL `backup_admin` user, `~/.my.cnf` and the cron entries are all on the
   old host, and none of them are in any backup.
2. Restore `.env` — **it is not in any backup and not in git.** Also not backed up:
   `firebase-key.json`, hand-placed on the server; without it admin push
   notifications fail silently.
3. Restore the database (above), this time letting the dump's own `CREATE DATABASE`
   run.
4. Restore media (above), then fix ownership.
5. `php artisan config:clear && php artisan config:cache`
6. **Reconcile settlement state before starting the queue worker** — see below.
7. `php artisan queue:restart`, confirm `laravel-queue-backend.service` is up.

## The settlement trap — read this before any restore

`bet_settlement_runs` is the idempotency log for 2D settlement: a completed run
(non-null `settled_at`) is what stops a slot from settling twice.

Restoring to a point **before** a settlement run, while the upstream result still
exists, deletes that guard. The next scheduled run sees an unsettled slot, settles it
again, and **pays every winner a second time**.

After any restore, before bringing the queue worker back up:

```sql
-- Every result that has no completed settlement run will be re-settled.
SELECT r.id, r.history_id, r.open_time, r.created_at
FROM two_d_results r
LEFT JOIN bet_settlement_runs s
  ON s.history_id = r.history_id AND s.settled_at IS NOT NULL
WHERE s.id IS NULL;
```

Cross-check each row against `bets.settled_result_history_id`. If bets were already
settled against that `history_id` but the run row is missing, insert the completed run
row manually rather than letting the scheduler re-run it.

## Going offsite

Not built. On-box backups do not survive losing the host.

`rclone` is not installed. Once it is, each script needs one line after the atomic
`mv`, pushing `$final` to a bucket (Cloudflare R2 or Backblaze B2 — pennies at this
data size).

**Encrypt before that happens** (`age`, or `rclone crypt`). These dumps contain phone
numbers, balances, password hashes, and live Sanctum tokens. `0600` on-box is
acceptable only because the file never leaves a host you already control; the same
file in someone else's bucket is a breach waiting on a misconfigured ACL.

## When a backup fails

`db-backup-check.sh` mails via the crontab's `MAILTO` on any problem and is silent
otherwise. **Verify mail actually leaves the box** — if it does not, the check is
decorative. Alternative with no inbound requirement: have the check `curl` a
healthchecks.io ping on success only, so the *absence* of a ping raises the alarm.

Both backup scripts refuse to run when free disk is under `MIN_FREE_MB` (default
2048). This is intentional: hourly dumps share a disk with the live database, and
filling it takes production down — worse than missing one backup. If the guard trips,
free space; do not just lower the floor.

Logs: `/opt/backups/backup.log` (rotated at 1 MB to `backup.log.1`).
