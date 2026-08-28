#!/usr/bin/env bash
#
# Backup health check for stock_app_backend.
#
# A backup job that silently stops is worse than no backup at all: it buys false
# confidence right up until the restore. This runs daily and is the only thing
# that turns a dead cron into a message a human sees.
#
# Any problem exits non-zero and writes to stderr, which cron mails via MAILTO.
#
# Installed at /opt/backups/bin/db-backup-check.sh, run from the admin crontab.
#
set -uo pipefail   # deliberately not -e: collect every problem, not just the first

# --- config ---------------------------------------------------------------
BACKUP_ROOT="${BACKUP_ROOT:-/opt/backups}"
MAX_DB_AGE_HOURS="${MAX_DB_AGE_HOURS:-3}"     # hourly job, 3h allows one miss
MAX_MEDIA_AGE_HOURS="${MAX_MEDIA_AGE_HOURS:-30}"  # daily job, 30h allows one miss
# Measured on prod 2026-08-28: a real dump is ~52 KB gzipped; a schema-only
# dump of an empty database is ~6 KB. 15 KB sits between them, so this catches
# "dumped an empty database" without false-alarming as the real dump fluctuates.
# Raise it as the dataset grows -- it is a floor, not a regression detector.
MIN_DUMP_BYTES="${MIN_DUMP_BYTES:-15000}"
MIN_FREE_MB="${MIN_FREE_MB:-2048}"
# --------------------------------------------------------------------------

problems=0

report() {
    echo "backup check: $*" >&2
    problems=$((problems + 1))
}

age_hours() {
    local path="$1"
    echo $(( ( $(date +%s) - $(stat -c %Y "$path") ) / 3600 ))
}

newest_in() {
    find "$1" -maxdepth 1 -type f -name "$2" -printf '%T@ %p\n' 2>/dev/null \
        | sort -rn | head -n 1 | cut -d' ' -f2-
}

# --- did the DB backup run, and recently? ---------------------------------
marker="$BACKUP_ROOT/.last-success"
if [[ ! -f "$marker" ]]; then
    report "no successful DB backup has ever been recorded ($marker missing)"
else
    age="$(age_hours "$marker")"
    if [[ "$age" -gt "$MAX_DB_AGE_HOURS" ]]; then
        report "last successful DB backup was ${age}h ago (limit ${MAX_DB_AGE_HOURS}h)"
    fi
fi

# --- is the newest dump real, and does it still decompress? ---------------
newest_db="$(newest_in "$BACKUP_ROOT/hourly" '*.sql.gz')"
if [[ -z "$newest_db" ]]; then
    report "no dumps found in $BACKUP_ROOT/hourly"
else
    size="$(stat -c %s "$newest_db")"
    # Catches the two failures that still leave a plausible-looking file:
    # a dump of an empty database, and a gzipped error message.
    if [[ "$size" -lt "$MIN_DUMP_BYTES" ]]; then
        report "newest dump $(basename "$newest_db") is only ${size} bytes (limit ${MIN_DUMP_BYTES})"
    fi
    if ! gzip -t "$newest_db" 2>/dev/null; then
        report "newest dump $(basename "$newest_db") fails gzip integrity check"
    fi
fi

# --- media ----------------------------------------------------------------
newest_media="$(newest_in "$BACKUP_ROOT/media" 'bet-slips-*.tar.gz')"
if [[ -z "$newest_media" ]]; then
    report "no media archives found in $BACKUP_ROOT/media"
else
    age="$(age_hours "$newest_media")"
    if [[ "$age" -gt "$MAX_MEDIA_AGE_HOURS" ]]; then
        report "newest media archive is ${age}h old (limit ${MAX_MEDIA_AGE_HOURS}h)"
    fi
    if ! gzip -t "$newest_media" 2>/dev/null; then
        report "newest media archive $(basename "$newest_media") fails gzip integrity check"
    fi
fi

# --- headroom -------------------------------------------------------------
# Reported every run so shrinking disk is visible before it starts failing
# backups, rather than the morning it takes production down.
avail_mb="$(df -Pm "$BACKUP_ROOT" | awk 'NR==2 {print $4}')"
if [[ "$avail_mb" -lt "$MIN_FREE_MB" ]]; then
    report "only ${avail_mb}MB free on $BACKUP_ROOT (limit ${MIN_FREE_MB}MB)"
fi

if [[ "$problems" -gt 0 ]]; then
    echo "backup check: ${problems} problem(s); ${avail_mb}MB free" >&2
    exit 1
fi

# Silent on success: cron only mails when there is something to say.
exit 0
