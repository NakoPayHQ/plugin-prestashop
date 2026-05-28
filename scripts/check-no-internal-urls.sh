#!/usr/bin/env bash
# Guard against shipping internal / dev-only URLs in public plugin packages.
#
# Allowed production hosts:
#   - https://api.nakopay.com/v1/                             (active, canonical,
#                                                              branded primary in all
#                                                              merchant plugins)
#   - https://daslrxpkbkqrbnjwouiq.supabase.co/functions/v1/  (origin fallback,
#                                                              declared in plugin
#                                                              clients as BASE_FALLBACK)
#   - nakopay.com / *.nakopay.com docs + marketing links
#
# What we DO want to catch:
#   - localhost / 127.0.0.1 hardcoded base URLs
#   - lovable.app / lovable.dev preview URLs (transient sandboxes)
#   - any *.supabase.co project ref OTHER than the canonical one above
#
# Usage: plugins/scripts/check-no-internal-urls.sh [path...]
#   - paths default to the directory containing this script's parent (plugins/)
#   - exits 0 on clean, non-zero on the first leak
set -euo pipefail

if [[ $# -gt 0 ]]; then
  ROOTS=("$@")
else
  ROOTS=("$(cd "$(dirname "$0")/.." && pwd)")
fi

PATTERNS=(
  'http://localhost'
  'http://127\.0\.0\.1'
  '\.lovable\.(app|dev)'
)

EXCLUDES=(
  '--glob=!**/node_modules/**'
  '--glob=!**/vendor/**'
  '--glob=!**/dist/**'
  '--glob=!**/build/**'
  '--glob=!**/.git/**'
  '--glob=!**/_reference/**'
  '--glob=!**/CHANGELOG.md'
  '--glob=!**/scripts/check-no-internal-urls.sh'
)

fail=0
# Some plugin server-runtimes legitimately bind their own webhook server to
# localhost in dev (e.g. plugins/whatsapp BASE_URL = the bot's HTTP server).
# That has nothing to do with the NakoPay API base. Only flag a localhost URL
# when it is being used as the NakoPay API base.
ALLOW_LOCALHOST_VARS='(BASE_URL|PORT|WEBHOOK_URL|CALLBACK_URL|SERVER_URL|PUBLIC_URL|APP_URL|LISTEN|HOST)'

for ROOT in "${ROOTS[@]}"; do
  for pat in "${PATTERNS[@]}"; do
    matches="$(rg -n --no-heading "${EXCLUDES[@]}" "$pat" "$ROOT" 2>/dev/null || true)"
    if [[ "$pat" == "http://localhost" || "$pat" == 'http://127\.0\.0\.1' ]]; then
      # Strip lines that are clearly the plugin's own server URL, not the NakoPay API.
      matches="$(echo "$matches" | rg -v ":[[:space:]]*${ALLOW_LOCALHOST_VARS}[[:space:]]*:" || true)"
      # Also strip lines that mention nakopay in the same line (those would be flagged elsewhere)
      matches="$(echo "$matches" | grep -vE '(NAKOPAY_API_BASE|NakoPay api|api\.nakopay)' || true)" || true
    fi
    if [[ -n "$matches" ]]; then
      echo "$matches"
      echo ""
      echo "ERROR: forbidden URL pattern '$pat' found in $ROOT." >&2
      fail=1
    fi
  done

  # Catch any non-canonical *.supabase.co project ref (allow only the canonical one).
  leaks="$(rg -n --no-heading "${EXCLUDES[@]}" 'https?://[a-z0-9-]+\.supabase\.co' "$ROOT" 2>/dev/null \
      | grep -v 'daslrxpkbkqrbnjwouiq\.supabase\.co' || true)"
  if [[ -n "$leaks" ]]; then
    echo "$leaks" >&2
    echo "" >&2
    echo "ERROR: non-canonical Supabase project URL found in $ROOT. Only daslrxpkbkqrbnjwouiq.supabase.co is allowed." >&2
    fail=1
  fi
done

if [[ $fail -ne 0 ]]; then
  exit 1
fi

echo "OK: no leaked URLs in: ${ROOTS[*]}"
