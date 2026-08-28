#!/usr/bin/env bash
#
# Production database backup for stock_app_backend.
#
# Deliberately OS-level rather than an artisan command: backups have to keep
# running when the app is broken, mid-deploy, or the queue worker is dead --
# which is exactly when you need them.
#
# Usage:
#   db-backup.sh                  # hourly dump, promoted to daily once a day
#   db-backup.sh --tag predeploy  # snapshot before a migration, own retention
#
# Installed at /opt/backups/bin/db-backup.sh, run from the admin crontab.
#
set -euo pipefail

# --- config ---------------------------------------------------------------
# Sized against measured prod (2026-08-28): the DB is 1.8 MB, a gzipped dump is
# 52 KB, and the disk has ~29 GB free. Retention below costs ~2 MB, so these are
# set for usefulness, not scarcity. MIN_FREE_MB -- not these counts -- is what
# actually stops backups from filling the disk as the app grows.
BACKUP_ROOT="${BACKUP_ROOT:-/opt/backups}"
ENV_FILE="${ENV_FILE:-/opt/apps/backend/.env}"
MYSQL_DEFAULTS="${MYSQL_DEFAULTS:-$HOME/.my.cnf}"

KEEP_HOURLY="${KEEP_HOURLY:-24}"      # 1 day -- the RPO window
KEEP_DAILY="${KEEP_DAILY:-14}"        # 2 weeks -- catches corruption noticed late
KEEP_PREDEPLOY="${KEEP_PREDEPLOY:-5}" # last 5 releases
MIN_FREE_MB="${MIN_FREE_MB:-2048}"

LOG="$BACKUP_ROOT/backup.log"
LOG_MAX_BYTES=$((1024 * 1024))
# --------------------------------------------------------------------------

TAG="hourly"
while [[ $# -gt 0 ]]; do
    case "$1" in
        --tag) TAG="${2:?--tag needs a value}"; shift 2 ;;
        *) echo "unknown argument: $1" >&2; exit 2 ;;
    esac
done

case "$TAG" in
    hourly)    KEEP="$KEEP_HOURLY" ;;
    predeploy) KEEP="$KEEP_PREDEPLOY" ;;
    *) echo "unknown tag: $TAG (expected hourly or predeploy)" >&2; exit 2 ;;
esac

log() {
    printf '%s [%s] %s\n' "$(date -Is)" "$TAG" "$*" >> "$LOG"
}

# Loud on stderr as well as the log: cron mails stderr, which is how a failure
# actually reaches a human.
fail() {
    log "FAILED: $*"
    echo "db-backup.sh [$TAG] FAILED: $*" >&2
    exit 1
}

[[ -d "$BACKUP_ROOT" ]] || { echo "BACKUP_ROOT missing: $BACKUP_ROOT" >&2; exit 1; }

# Keep the log from becoming the thing that fills the disk.
if [[ -f "$LOG" ]] && [[ "$(stat -c %s "$LOG")" -gt "$LOG_MAX_BYTES" ]]; then
    mv -f "$LOG" "$LOG.1"
fi
touch "$LOG"; chmod 0600 "$LOG"

# One run at a time. A dump that outlives its hour must not overlap the next.
exec 9>"$BACKUP_ROOT/.db-backup.lock"
if ! flock -n 9; then
    log "skipped: another run still in progress"
    exit 0
fi

# --- read the live DB config ----------------------------------------------
# Parsed from .env rather than hardcoded: prod is lotto_db, local is stock_db,
# and hardcoding the wrong one produces a backup of nothing that still looks
# like a success.
[[ -r "$ENV_FILE" ]] || fail "cannot read $ENV_FILE"

env_get() {
    grep -E "^${1}=" "$ENV_FILE" | tail -n 1 | cut -d= -f2- \
        | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'\$/\1/" -e 's/\r$//'
}

DB_DATABASE="$(env_get DB_DATABASE)"
DB_HOST="$(env_get DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_get DB_PORT)"; DB_PORT="${DB_PORT:-3306}"

[[ -n "$DB_DATABASE" ]] || fail "DB_DATABASE not found in $ENV_FILE"
[[ -r "$MYSQL_DEFAULTS" ]] || fail "cannot read credentials file $MYSQL_DEFAULTS"

# --- disk guard -----------------------------------------------------------
# Before dumping, not after. Hourly dumps share a disk with the live database:
# filling it takes production down, which is worse than missing one backup.
avail_mb="$(df -Pm "$BACKUP_ROOT" | awk 'NR==2 {print $4}')"
if [[ "$avail_mb" -lt "$MIN_FREE_MB" ]]; then
    fail "only ${avail_mb}MB free on $BACKUP_ROOT, need ${MIN_FREE_MB}MB -- refusing to dump"
fi

# --- dump -----------------------------------------------------------------
DEST_DIR="$BACKUP_ROOT/$TAG"
mkdir -p "$DEST_DIR"; chmod 0700 "$DEST_DIR"

stamp="$(date +%Y%m%d-%H%M%S)"
final="$DEST_DIR/${DB_DATABASE}-${stamp}.sql.gz"
tmp="$DEST_DIR/.${DB_DATABASE}-${stamp}.sql.gz.partial"

# Clean up a partial file if we die mid-dump -- it must never be left behind
# looking like a real backup.
trap 'rm -f "$tmp"' EXIT

started="$(date +%s)"
log "starting dump of $DB_DATABASE ($DB_HOST:$DB_PORT), ${avail_mb}MB free"

# --single-transaction: consistent InnoDB snapshot WITHOUT locking writers, so
# bets and deposits keep flowing during the dump. Verified safe -- all 38 tables
# are InnoDB; a MyISAM table would silently break that guarantee.
#
# No --set-gtid-purged here: that is a MySQL flag and MariaDB's mysqldump
# rejects it.
if ! mysqldump \
        --defaults-file="$MYSQL_DEFAULTS" \
        --host="$DB_HOST" --port="$DB_PORT" \
        --single-transaction --quick \
        --routines --triggers --events \
        --databases "$DB_DATABASE" 2>>"$LOG" | gzip -6 > "$tmp"; then
    fail "mysqldump failed (see $LOG)"
fi

# Verify before it earns the real name. A truncated dump that looks like a good
# backup is worse than no backup at all.
gzip -t "$tmp" 2>>"$LOG" || fail "dump failed gzip integrity check"

# Floor catches a gzipped error message or a truncated pipe. Measured on prod
# 2026-08-28: a real dump is ~52 KB gzipped, a schema-only dump of an empty
# database is ~6 KB. Deliberately well below both -- the tighter "did we dump
# actual data" check lives in db-backup-check.sh.
size="$(stat -c %s "$tmp")"
[[ "$size" -gt 4096 ]] || fail "dump is only ${size} bytes -- refusing to keep it"

chmod 0600 "$tmp"
mv -f "$tmp" "$final"
trap - EXIT

elapsed=$(( $(date +%s) - started ))
log "wrote $(basename "$final") (${size} bytes) in ${elapsed}s"

# --- promote one dump per day --------------------------------------------
# First successful dump of the day, not a fixed hour: if the 03:00 run fails,
# the day still gets a daily.
if [[ "$TAG" == "hourly" ]]; then
    DAILY_DIR="$BACKUP_ROOT/daily"
    mkdir -p "$DAILY_DIR"; chmod 0700 "$DAILY_DIR"
    today="$(date +%Y%m%d)"
    if ! compgen -G "$DAILY_DIR/*-${today}-*.sql.gz" > /dev/null; then
        # Hard link: costs no extra disk until the hourly copy is pruned.
        ln "$final" "$DAILY_DIR/$(basename "$final")"
        log "promoted $(basename "$final") to daily/"
    fi
fi

# --- retention ------------------------------------------------------------
# Filenames are timestamped, so a reverse lexical sort is chronological.
prune() {
    local dir="$1" keep="$2" f
    [[ -d "$dir" ]] || return 0
    find "$dir" -maxdepth 1 -type f -name '*.sql.gz' -printf '%f\n' \
        | sort -r | tail -n "+$((keep + 1))" \
        | while IFS= read -r f; do
              rm -f -- "$dir/$f"
              log "pruned $(basename "$dir")/$f"
          done
}

prune "$DEST_DIR" "$KEEP"
if [[ "$TAG" == "hourly" ]]; then
    prune "$BACKUP_ROOT/daily" "$KEEP_DAILY"
fi

# Monitoring reads this file's mtime. Only a fully verified run touches it.
date -Is > "$BACKUP_ROOT/.last-success"
chmod 0600 "$BACKUP_ROOT/.last-success"

log "ok"
