#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# Provision the capture instance brought up by compose.yaml in this directory:
#
#   bash browser-extension/capture/setup.sh <compose project> <app dir> [<openregister dir>]
#
# The same steps as tests/integration/federation/setup.sh, for one instance:
# copy Keepiq in (and a built OpenRegister, only when its directory is given:
# Keepiq needs no other app, ADR-006), enable it with debug on so admin gets
# the development vault (master password "Oj"), and VERIFY the vault exists. Then the development secrets,
# which carry real company names, are purged: the capture script fills the
# vault with demo items through the extension itself. Last, an app password
# for the extension is written to browser-extension/capture/out/app-password.
set -euo pipefail

PROJECT="${1:?compose project}"
APP_DIR="$(cd "${2:?app dir}" && pwd)"
OR_DIR=""
if [ -n "${3:-}" ]; then
	OR_DIR="$(cd "$3" && pwd)"
fi
PORT="${KQ_MEDIA_PORT:-8188}"
BASE="http://localhost:${PORT}"
CONTAINER="${PROJECT}-nc-1"
OUT="${APP_DIR}/browser-extension/capture/out"

occ() {
	docker exec -u www-data "$CONTAINER" php occ "$@"
}

api() {
	local method="$1" path="$2"
	curl -sS -f -u admin:admin -X "$method" \
		-H 'OCS-APIRequest: true' -H 'Accept: application/json' \
		"${BASE}/index.php/apps/keepiq${path}"
}

echo "[capture-setup] waiting for ${BASE}"
for _ in $(seq 1 120); do
	if curl -sf "${BASE}/status.php" | grep -q '"installed":true'; then
		break
	fi
	sleep 5
done
curl -sf "${BASE}/status.php" | grep -q '"installed":true' \
	|| { echo "::error::the instance did not finish installing"; exit 1; }

occ config:system:set appstoreenabled --value=false --type=boolean
occ config:system:set debug --value=true --type=boolean
occ config:system:set overwrite.cli.url --value="${BASE}"
# The capture reaches this instance as https://cloud.example.com too, through
# the forwarding demo server in fixtures.mjs.
occ config:system:set trusted_domains 5 --value=cloud.example.com
# The welcome wizard would cover the app on the first browser visit.
occ app:disable firstrunwizard >/dev/null 2>&1 || true

if [ -n "$OR_DIR" ]; then
	echo "[capture-setup] copying OpenRegister in"
	docker exec "$CONTAINER" mkdir -p /var/www/html/custom_apps/openregister
	tar -C "$OR_DIR" --exclude=./node_modules --exclude=./.git --exclude=./tests --exclude=./coverage \
		--exclude=./docs --exclude=./custom_apps --exclude=./openspec --exclude=./website -cf - . \
		| docker exec -i "$CONTAINER" tar -xf - -C /var/www/html/custom_apps/openregister
	docker exec "$CONTAINER" chown -R www-data:www-data /var/www/html/custom_apps/openregister
	occ app:enable openregister
fi

echo "[capture-setup] copying Keepiq in"
docker exec "$CONTAINER" mkdir -p /var/www/html/custom_apps/keepiq
tar -C "$APP_DIR" --exclude=.git --exclude=node_modules --exclude=.lane --exclude=tests \
	--exclude=./docs --exclude=./browser-extension --exclude=./openspec -cf - . \
	| docker exec -i "$CONTAINER" tar -xf - -C /var/www/html/custom_apps/keepiq
docker exec "$CONTAINER" chown -R www-data:www-data /var/www/html/custom_apps/keepiq
occ app:enable keepiq
occ app:list | sed -n '/Enabled:/,/Disabled:/p' | grep -q ' keepiq:' \
	|| { echo "::error::keepiq is not enabled"; exit 1; }
# The web server can still answer 404 for Keepiq's routes for a moment after
# app:enable (it failed one of two identical runs of 4934a642); wait for the
# API before the first real call, which still fails loudly if it never comes.
for _ in $(seq 1 30); do
	api GET /api/v1/suites >/dev/null 2>&1 && break
	sleep 2
done
# The development vault of admin, which the extension unlocks with "Oj".
suites="$(api GET /api/v1/suites)"
echo "$suites" | grep -q '"status":"active"' \
	|| { echo "::error::admin has no active suite: ${suites:0:300}"; exit 1; }

echo "[capture-setup] purging the development secrets"
ids="$(api GET '/api/v1/secrets?limit=200' | python3 -c '
import json, sys
body = json.load(sys.stdin)
if isinstance(body, dict):
    for key in ("results", "data", "items", "secrets"):
        if isinstance(body.get(key), list):
            body = body[key]
            break
print(" ".join(item["id"] for item in body))
')"
for id in $ids; do
	api DELETE "/api/v1/secrets/${id}" >/dev/null
	api DELETE "/api/v1/secrets/${id}/purge" >/dev/null
done
left="$(api GET '/api/v1/secrets?limit=200' | python3 -c '
import json, sys
body = json.load(sys.stdin)
if isinstance(body, dict):
    for key in ("results", "data", "items", "secrets"):
        if isinstance(body.get(key), list):
            body = body[key]
            break
print(len(body))
')"
[ "$left" = "0" ] || { echo "::error::${left} development secrets are left"; exit 1; }

echo "[capture-setup] creating an app password for the extension"
mkdir -p "$OUT"
docker exec -u www-data -e NC_PASS=admin "$CONTAINER" \
	php occ user:auth-tokens:add --password-from-env --name 'Keepiq extension' admin \
	| tail -n 1 | tr -d '[:space:]' > "${OUT}/app-password"
[ -s "${OUT}/app-password" ] || { echo "::error::no app password was created"; exit 1; }

echo "[capture-setup] ready: ${BASE} (admin, master password Oj)"
