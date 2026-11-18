#!/usr/bin/env bash
# Builds the three upload folders for Hostinger (run from the repo root, in Git Bash on Windows):
#
#   deploy/public_html/  → public_html/          https://cityfloristhubli.com        (frontend-user)
#   deploy/admin/        → public_html/admin/    https://admin.cityfloristhubli.com  (frontend-dashboard)
#   deploy/api/          → public_html/api/      https://api.cityfloristhubli.com    (backend, CodeIgniter)
#
# and a .zip of each (deploy/*.zip), to upload in hPanel → File Manager and "Extract" there.
# Never contains a .env: create public_html/api/.env on the server from backend/.env.production.example.
#
# Usage: bash scripts/package-hostinger.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="$ROOT/deploy"
cd "$ROOT"

echo "==> Cleaning $OUT"
rm -rf "$OUT"
mkdir -p "$OUT/public_html" "$OUT/admin" "$OUT/api"

echo "==> Building the website (frontend-user, uses frontend-user/.env.production)"
(cd frontend-user && npm ci --no-audit --no-fund && npm run build)
cp -r frontend-user/dist/. "$OUT/public_html/"

echo "==> Building the dashboard (frontend-dashboard, uses frontend-dashboard/.env.production)"
(cd frontend-dashboard && npm ci --no-audit --no-fund && npm run build)
cp -r frontend-dashboard/dist/. "$OUT/admin/"

echo "==> Preparing the backend (production dependencies only)"
# A separate copy, so your own backend/vendor (with the test tools) is left alone.
TMP="$OUT/.backend-build"
mkdir -p "$TMP"
cp -r backend/. "$TMP/"
rm -rf "$TMP/vendor"
# The server has the intl extension; a local PHP without it can still prepare the files.
(cd "$TMP" && composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --ignore-platform-req=ext-intl)

cp -r "$TMP/." "$OUT/api/"
rm -rf "$TMP" || true
cd "$OUT/api"
# sf_proc_*: temporary files Composer leaves behind on Windows
rm -rf tests build .env .env.local phpunit.xml phpunit.xml.dist .phpunit.result.cache public/uploads/* sf_proc_*
# backend/deploy/.htaccess is the folder's own .htaccess (sends every request into public/)
mv deploy/.htaccess .htaccess && rm -rf deploy
# CodeIgniter needs these folders to exist (it won't create writable/cache itself); start them empty.
for d in cache debugbar logs session uploads; do
  mkdir -p "writable/$d"
  find "writable/$d" -mindepth 1 -maxdepth 1 ! -name index.html -exec rm -rf {} +
  touch "writable/$d/.keep"
done
mkdir -p public/uploads && touch public/uploads/.keep
cd "$OUT"

echo "==> Zipping"
rmdir "$OUT/.backend-build" 2>/dev/null || true
for d in public_html admin api; do
  if command -v zip >/dev/null 2>&1; then
    (cd "$d" && zip -qr "../$d.zip" .)
  elif [ -x /c/Windows/System32/tar.exe ]; then
    # Git Bash on Windows has no zip. Windows' own tar writes proper zip files (forward slashes, so they
    # extract correctly on Hostinger's Linux server). Listing the entries keeps dotfiles such as .htaccess.
    (cd "$d" && /c/Windows/System32/tar.exe -a -c -f "../$d.zip" $(ls -A))
  else
    echo "No zip tool found: upload the deploy/$d folder itself instead." >&2
  fi
done

echo
echo "Done. Upload:"
echo "  deploy/public_html.zip → extract in public_html/"
echo "  deploy/admin.zip       → extract in public_html/admin/"
echo "  deploy/api.zip         → extract in public_html/api/   (then create public_html/api/.env)"
