#!/usr/bin/env bash
#
# Production media backup for stock_app_backend.
#
# MUST run as root. storage/app/bet-slips is mode 0700 owned by apache, and the
# admin deploy user is not in the apache group -- run as admin this produces an
# archive missing every deposit proof and payout proof, which is exactly the
# evidence you need when a payment is disputed.
#
# Daily rather than hourly: much larger than the DB and far less volatile.
#
# Installed at /opt/backups/bin/media-backup.sh, run from /etc/cron.d/media-backup.
#
set -euo pipefail

# --- config ---------------------------------------------------------------
BACKUP_ROOT="${BACKUP_ROOT:-/opt/backups}"
APP_STORAGE="${APP_STORAGE:-/opt/apps/backend/storage/app}"
KEEP_MEDIA="${KEEP_MEDIA:-7}"
MIN_FREE_MB="${MIN_FREE_MB:-2048}"

LOG="$BACKUP_ROOT/backup.log"
# --------------------------------------------------------------------------

log() {
    printf '%s [media] %s\n' "$(date -Is)" "$*" >> "$LOG"
}

fail() {
    log "FAILED: $*"
    echo "media-backup.sh FAILED: $*" >&2
    exit 1
}

[[ -d "$BACKUP_ROOT" ]] || { echo "BACKUP_ROOT missing: $BACKUP_ROOT" >&2; exit 1; }
[[ -d "$APP_STORAGE" ]] || fail "app storage missing: $APP_STORAGE"

touch "$LOG"

exec 9>"$BACKUP_ROOT/.media-backup.lock"
if ! flock -n 9; then
    log "skipped: another run still in progress"
    exit 0
fi

# Fail loudly rather than quietly archiving nothing. If this script is ever run
# as the wrong user, the missing evidence must not be discovered during a
# dispute months later.
if [[ ! -r "$APP_STORAGE/bet-slips" ]]; then
    fail "cannot read $APP_STORAGE/bet-slips -- this script must run as root"
fi

avail_mb="$(df -Pm "$BACKUP_ROOT" | awk 'NR==2 {print $4}')"
if [[ "$avail_mb" -lt "$MIN_FREE_MB" ]]; then
    fail "only ${avail_mb}MB free on $BACKUP_ROOT, need ${MIN_FREE_MB}MB -- refusing to archive"
fi

DEST_DIR="$BACKUP_ROOT/media"
mkdir -p "$DEST_DIR"

stamp="$(date +%Y%m%d-%H%M%S)"
final="$DEST_DIR/bet-slips-${stamp}.tar.gz"
tmp="$DEST_DIR/.bet-slips-${stamp}.tar.gz.partial"

trap 'rm -f "$tmp"' EXIT

started="$(date +%s)"
log "starting archive of $APP_STORAGE, ${avail_mb}MB free"

# -p preserves the 0700/apache ownership bits, so a restore can put the
# permissions back the way the app expects them.
if ! tar -czpf "$tmp" -C "$APP_STORAGE" bet-slips public private 2>>"$LOG"; then
    fail "tar failed (see $LOG)"
fi

gzip -t "$tmp" 2>>"$LOG" || fail "archive failed gzip integrity check"

size="$(stat -c %s "$tmp")"
[[ "$size" -gt 1024 ]] || fail "archive is only ${size} bytes -- refusing to keep it"

# root:admin 0640 so the check script, which runs as admin, can stat and test it.
chmod 0640 "$tmp"
chown root:admin "$tmp" 2>/dev/null || log "warning: could not chown to root:admin"
mv -f "$tmp" "$final"
trap - EXIT

elapsed=$(( $(date +%s) - started ))
log "wrote $(basename "$final") (${size} bytes) in ${elapsed}s"

# Timestamped names, so a reverse lexical sort is chronological.
find "$DEST_DIR" -maxdepth 1 -type f -name 'bet-slips-*.tar.gz' -printf '%f\n' \
    | sort -r | tail -n "+$((KEEP_MEDIA + 1))" \
    | while IFS= read -r f; do
          rm -f -- "$DEST_DIR/$f"
          log "pruned media/$f"
      done

date -Is > "$BACKUP_ROOT/.last-media-success"
chmod 0640 "$BACKUP_ROOT/.last-media-success"
chown root:admin "$BACKUP_ROOT/.last-media-success" 2>/dev/null || true

log "ok"
