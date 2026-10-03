#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# Provision the two-instance federation pair (sharing-federated-recipients
# task 5.1) brought up by compose.yaml in this directory:
#
#   bash tests/integration/federation/setup.sh <compose project> <app dir>
#
# For each instance: wait for the install, allow the http pair to talk
# (allow_local_remote_servers), keep the app store away from the copied app,
# copy Keepiq in, enable it with debug on so the admin gets the development
# vault (master password "Oj"), and VERIFY that vault exists. Then admin on B
# opts in to receiving, and each administrator adds the other instance as a
# partner after reading its root fingerprint, in both directions. Every step
# is checked; a provisioning failure is one loud error here, not a timed-out
# spec later.
set -euo pipefail

PROJECT="${1:?compose project}"
APP_DIR="$(cd "${2:?app dir}" && pwd)"
declare -A PORT=([a]=8101 [b]=8102)

occ() {
	docker exec -u www-data "${PROJECT}-nc-$1-1" php occ "${@:2}"
}

api() {
	local side="$1" method="$2" path="$3" body="${4:-}"
	curl -sS -f -u admin:admin -X "$method" \
		-H 'OCS-APIRequest: true' -H 'Content-Type: application/json' -H 'Accept: application/json' \
		${body:+--data "$body"} "http://localhost:${PORT[$side]}/index.php/apps/keepiq${path}"
}

for side in a b; do
	echo "[fed-setup] waiting for instance ${side} (localhost:${PORT[$side]})"
	for _ in $(seq 1 120); do
		if curl -sf "http://localhost:${PORT[$side]}/status.php" | grep -q '"installed":true'; then
			break
		fi
		sleep 5
	done
	curl -sf "http://localhost:${PORT[$side]}/status.php" | grep -q '"installed":true' \
		|| { echo "::error::instance ${side} did not finish installing"; exit 1; }

	occ "$side" config:system:set appstoreenabled --value=false --type=boolean
	occ "$side" config:system:set allow_local_remote_servers --value=true --type=boolean
	occ "$side" config:system:set debug --value=true --type=boolean
	occ "$side" config:system:set overwrite.cli.url --value="http://localhost:${PORT[$side]}"

	echo "[fed-setup] copying Keepiq into ${side}"
	docker exec "${PROJECT}-nc-${side}-1" mkdir -p /var/www/html/custom_apps/keepiq
	tar -C "$APP_DIR" --exclude=.git --exclude=node_modules --exclude=.lane --exclude=tests -cf - . \
		| docker exec -i "${PROJECT}-nc-${side}-1" tar -xf - -C /var/www/html/custom_apps/keepiq
	docker exec "${PROJECT}-nc-${side}-1" chown -R www-data:www-data /var/www/html/custom_apps/keepiq
	occ "$side" app:enable keepiq
	occ "$side" app:list | sed -n '/Enabled:/,/Disabled:/p' | grep -q ' keepiq:' \
		|| { echo "::error::keepiq is not enabled on ${side}"; exit 1; }

	# The development vault of admin, which the specs unlock with "Oj".
	suites="$(api "$side" GET /api/v1/suites)"
	echo "$suites" | grep -q '"status":"active"' \
		|| { echo "::error::admin on ${side} has no active suite: ${suites:0:300}"; exit 1; }
done

echo "[fed-setup] admin on b opts in to receiving from other organisations"
occ b user:setting admin keepiq federation_receive 1
[ "$(occ b user:setting admin keepiq federation_receive)" = "1" ] \
	|| { echo "::error::the opt-in did not stick"; exit 1; }

for pair in "a b" "b a"; do
	set -- $pair
	here="$1" there="$2"
	url="http://localhost:${PORT[$there]}"
	echo "[fed-setup] ${here} adds ${there} as a partner"
	preview="$(api "$here" POST /api/v1/federation/partners/preview "{\"url\":\"${url}\"}")"
	fingerprint="$(echo "$preview" | python3 -c 'import json,sys; print(json.load(sys.stdin)["rootFingerprint"])')"
	published="$(curl -sf "${url}/index.php/apps/keepiq/api/v1/app/.well-known/keepiq" | python3 -c 'import json,sys; print(json.load(sys.stdin)["federation"]["rootFingerprint"])')"
	[ "$fingerprint" = "$published" ] \
		|| { echo "::error::preview fingerprint ${fingerprint} is not the one ${there} publishes (${published})"; exit 1; }
	existing="$(api "$here" GET /api/v1/federation/partners)"
	if ! echo "$existing" | grep -q "\"host\":\"localhost:${PORT[$there]}\""; then
		api "$here" POST /api/v1/federation/partners \
			"{\"url\":\"${url}\",\"rootFingerprint\":\"${fingerprint}\",\"allowOutbound\":true,\"allowInbound\":true}" >/dev/null
	fi
	api "$here" GET /api/v1/federation/partners | grep -q "\"rootFingerprint\":\"${fingerprint}\"" \
		|| { echo "::error::${here} did not pin ${there}"; exit 1; }
done

echo "[fed-setup] OCM discovery advertises the keepiq capability on both"
for side in a b; do
	curl -sf "http://localhost:${PORT[$side]}/ocm-provider/" | grep -q '"keepiq"' \
		|| { echo "::error::instance ${side} does not advertise the keepiq OCM capability"; exit 1; }
done

echo "[fed-setup] ready: A=http://localhost:8101 (admin@localhost:8101), B=http://localhost:8102 (admin@localhost:8102)"
