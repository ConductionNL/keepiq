#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# Provision a freshly installed Nextcloud so Doriath's Newman (Postman) API
# contract suite can run, for the shared `Integration Tests (Newman)` CI job.
#
# Wired up as the workflow's `newman-seed-command`. That step runs AFTER the
# `php -S` server is up and with cwd set to the Nextcloud SERVER ROOT, so this
# is invoked as:
#
#     newman-seed-command: 'bash apps/doriath/tests/integration/ci-seed.sh'
#
# WHY THE NEWMAN JOB NEEDS A SEED AT ALL
# --------------------------------------
# Doriath is a zero-knowledge vault: every secret is sealed against the OWNER'S
# EncryptionSuite. `SecretService` opens the create/update/delete paths with
# `getActiveSuiteOrBlock()`, which throws `SuiteBlockedException` when the
# calling user owns no active suite, and `SecretController` maps that to 403.
#
# A suite can only be minted with a real RSA key pair whose private key is
# wrapped under a master password — client-side crypto that a Postman
# collection cannot perform. So the collection CANNOT provision itself; the
# instance has to arrive with a vault already unlocked-able, exactly as the
# Playwright job's `tests/e2e/ci-seed.sh` arranges for the browser specs.
#
# Without it the whole secret surface of the contract suite fails, and it fails
# in a way that actively misdirects: `SecretController` extends `OCSController`,
# and Nextcloud's `OCSMiddleware` rewrites a 401/403 from an OCSController into
# an OCS v1 envelope — which carries **HTTP 200** with the real status buried in
# `ocs.meta.statuscode`. `POST /api/v1/secrets` therefore answers
# "200 OK" with no `id`, the collection stores `undefined` as the secret id, and
# every downstream request goes to `/api/v1/secrets/null`. Measured on CI run
# 31074210361: 17 of 85 assertions failed, all of them from that one cause.
#
# WHAT ACTUALLY GATES THE SEEDERS
# -------------------------------
# `SeedDevelopmentData::run()` (and its sibling seeders) open with:
#
#     if ($this->config->getSystemValueBool('debug', false) === false) { return; }
#
# and the shared workflow installs Nextcloud with `occ maintenance:install`,
# which leaves `debug` at its default of FALSE. On a stock CI install the repair
# step runs, returns immediately, seeds nothing, and exits 0. Nothing in the
# install path is load-bearing evidence that provisioning happened — so this
# script does it explicitly and then VERIFIES it, turning a bad provision into
# ONE loud failure here instead of a wall of misattributed assertion failures.
#
# This is deliberately LEANER than the Playwright seed: the Newman job builds no
# frontend bundle and the contract suite touches only Doriath's own REST
# surface, so there is no bundle gate and no OpenRegister configuration import
# here. Everything it does is idempotent.

set -euo pipefail

# ── Target resolution ────────────────────────────────────────────────────────
# The workflow's "Seed test data" step exports BASE_URL / NEXTCLOUD_URL /
# NC_BASE_URL / ADMIN_USER / ADMIN_PASSWORD. Accept all of them, and fall back
# to the runner's own `php -S 0.0.0.0:8080` only when we are demonstrably in CI.
#
# Off CI an unset target is a HARD ERROR. On a developer box `localhost:8080` is
# the SHARED dev container, and this script performs ADMIN WRITES — it must
# never disable/re-enable an app or seed a vault in somebody else's environment.
BASE="${PLAYWRIGHT_BASE_URL:-${BASE_URL:-${NEXTCLOUD_URL:-${NC_BASE_URL:-}}}}"
IN_CI=false
if [ "${GITHUB_ACTIONS:-}" = "true" ] || [ "${CI:-}" = "true" ]; then
	IN_CI=true
fi
if [ -z "$BASE" ]; then
	if [ "$IN_CI" = "true" ]; then
		BASE="http://localhost:8080"
	else
		echo "ERROR: no base URL set. Export BASE_URL (or NEXTCLOUD_URL)." >&2
		echo "       Refusing to default to http://localhost:8080 outside CI —" >&2
		echo "       that is the SHARED dev container and this script writes to it." >&2
		exit 1
	fi
fi
BASE="${BASE%/}"

USER_NAME="${ADMIN_USER:-${NC_ADMIN_USER:-admin}}"
USER_PASS="${ADMIN_PASSWORD:-${NC_ADMIN_PASS:-admin}}"

echo "[newman-seed] target: ${BASE}"

# cwd is the Nextcloud server root, so `occ` is right here. Guard anyway: if it
# is not, the occ-driven provisioning below would silently do nothing.
if [ ! -f occ ]; then
	echo "::error::tests/integration/ci-seed.sh must run from the Nextcloud server root (no ./occ here; cwd=$(pwd))."
	exit 1
fi

# ── 1. Turn on debug mode and re-run the repair steps ────────────────────────
# Setting `debug` after the app was already enabled is not enough on its own —
# repair steps only run during install/upgrade — so the app is disabled and
# re-enabled, which takes `Installer::installApp()` → `installAppLastSteps()`
# and executes both the `post-migration` and the `install` repair-step lists
# again. Every seeder is idempotent (SeedDevelopmentData returns early when
# admin already owns a key-pair-consistent suite; SeedSecretTypes upserts by
# name), so running them twice is harmless.
echo "[newman-seed] enabling Nextcloud debug mode (gates the Doriath dev seeders)"
php occ config:system:set debug --value=true --type=boolean

echo "[newman-seed] re-running Doriath repair steps so the dev seeders execute"
php occ app:disable doriath
php occ app:enable doriath

# ── 2. Verify — every claim below is checked, none is assumed ────────────────
# A repair step that exits 0 is not evidence that it seeded anything: repair
# steps run with NO user session and `InitializeSettings::run()` downgrades a
# `\Throwable` to `$output->warning(...)`, while `occ app:enable` still exits 0.
# So query the real endpoints and fail loudly on anything missing.
#
# ⚠️ Before believing any zero, the verifier proves the query CAN match: the
# secret-types check runs FIRST and must return a non-empty list through the
# exact same auth + JSON-unwrapping path. A zero there means the probe is
# broken; a zero afterwards is a real empty result.
api_get() {
	local path="$1" out="$2" code
	code="$(
		curl -sS -u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
			-H 'Accept: application/json' -o "$out" \
			-w '%{http_code}' "${BASE}${path}" || echo 000
	)"
	echo "[newman-seed] GET ${path} -> HTTP ${code}"
	# An OCS 200 is not success, and a 403 whose body parses as empty looks
	# exactly like "returned nothing" — so the status code is always printed
	# and the OCS envelope is unwrapped by the verifier below.
	if [ "$code" != "200" ]; then
		echo "::error::${path} answered HTTP ${code}, not 200. First 500 bytes:"
		head -c 500 "$out"; echo
		return 1
	fi
	return 0
}

# PHP rather than python3 for the JSON work: `php` is definitionally present
# here (we just ran `occ` with it), so the verifier cannot fail for a reason
# unrelated to what it is verifying.
verify() {
	php -r '
	$path = $argv[1];
	$kind = $argv[2];
	$raw  = file_get_contents($path);
	$body = json_decode($raw, true);
	if ($body === null) {
		fwrite(STDERR, "::error::$kind: endpoint did not return JSON. First 500 bytes:\n" . substr($raw, 0, 500) . "\n");
		exit(1);
	}
	// Unwrap an OCS envelope if one is present, then the common list envelopes.
	if (is_array($body) === true && isset($body["ocs"]) === true && is_array($body["ocs"]) === true) {
		$body = ($body["ocs"]["data"] ?? $body["ocs"]);
	}
	$items = $body;
	if (is_array($items) === true && array_is_list($items) === false) {
		foreach (["results", "data", "items", "secrets", "types", "suites"] as $key) {
			if (isset($items[$key]) === true && is_array($items[$key]) === true) {
				$items = $items[$key];
				break;
			}
		}
	}
	if (is_array($items) === false || array_is_list($items) === false) {
		fwrite(STDERR, "::error::$kind: expected a list. First 500 bytes:\n" . substr($raw, 0, 500) . "\n");
		exit(1);
	}

	if ($kind === "secret-types") {
		// POSITIVE CONTROL. SeedSecretTypes runs unconditionally (no debug
		// gate), so this list is non-empty on ANY working install. If it is
		// empty the probe itself is wrong and every zero below is meaningless.
		$names    = array_filter(array_column($items, "name"));
		$required = ["login", "api_key", "ssh_key", "certificate", "note", "database", "totp"];
		echo "[newman-seed] secret types present: " . implode(", ", $names) . "\n";
		$missing = array_values(array_diff($required, $names));
		if (count($missing) > 0) {
			fwrite(STDERR, "::error::secret-types: missing system types " . implode(", ", $missing)
				. ". SeedSecretTypes did not run — the probe/auth path is broken, so no other "
				. "emptiness below can be trusted.\n");
			exit(1);
		}
		echo "[newman-seed] secret-types OK (" . count($items) . " types) — probe proven able to match.\n";
		exit(0);
	}

	if ($kind === "suites") {
		$active = array_values(array_filter($items, static fn ($i) => (($i["status"] ?? "") === "active")));
		echo "[newman-seed] suites for admin: " . count($items) . " total, " . count($active) . " active\n";
		if (count($active) === 0) {
			fwrite(STDERR, "::error::admin owns NO active EncryptionSuite. SeedDevelopmentData did not seed "
				. "the dev vault — check that `occ config:system:set debug` took effect BEFORE the app was "
				. "re-enabled. Without it SecretService::getActiveSuiteOrBlock() throws on every write and "
				. "POST /api/v1/secrets answers an OCS-wrapped 403 as HTTP 200 with no id.\n");
			exit(1);
		}
		foreach (["certificate", "privateKey"] as $prop) {
			if (empty($active[0][$prop]) === true) {
				fwrite(STDERR, "::error::the active suite has no `$prop` — it cannot seal anything.\n");
				exit(1);
			}
		}
		echo "[newman-seed] suites OK (active suite carries a certificate + wrapped private key).\n";
		exit(0);
	}

	fwrite(STDERR, "::error::unknown verification kind: $kind\n");
	exit(1);
	' "$1" "$2"
}

# Positive control FIRST — proves auth + unwrapping can return a non-empty list.
TYPES_BODY="$(mktemp)"
api_get '/index.php/apps/doriath/api/v1/secret-types' "$TYPES_BODY"
verify "$TYPES_BODY" secret-types

SUITES_BODY="$(mktemp)"
api_get '/index.php/apps/doriath/api/v1/suites' "$SUITES_BODY"
verify "$SUITES_BODY" suites

# ── 3. Prove a write actually succeeds ───────────────────────────────────────
# The suite check above says the vault CAN be written to; this proves it, over
# the exact endpoint the contract suite opens with. It asserts on the HTTP
# status AND on the body, because an OCS-wrapped 403 arrives as HTTP 200 — the
# precise failure this whole script exists to prevent.
PROBE_BODY="$(mktemp)"
PROBE_CODE="$(
	curl -sS -u "${USER_NAME}:${USER_PASS}" \
		-H 'OCS-APIRequest: true' -H 'Content-Type: application/json' -H 'Accept: application/json' \
		-X POST -d '{"name":"newman-seed write probe","key":"newman-seed-probe-blob"}' \
		-o "$PROBE_BODY" -w '%{http_code}' \
		"${BASE}/index.php/apps/doriath/api/v1/secrets" || echo 000
)"
echo "[newman-seed] POST /api/v1/secrets -> HTTP ${PROBE_CODE}"
PROBE_ID="$(php -r '
	$b = json_decode(file_get_contents($argv[1]), true);
	echo (is_array($b) === true ? (string) ($b["id"] ?? "") : "");
' "$PROBE_BODY")"
if [ "$PROBE_CODE" != "201" ] || [ -z "$PROBE_ID" ]; then
	echo "::error::the write probe did not create a secret (HTTP ${PROBE_CODE}, id='${PROBE_ID}'). Body:"
	head -c 500 "$PROBE_BODY"; echo
	echo "::error::An HTTP 200 here is an OCS-wrapped 403 from SecretController — read ocs.meta.statuscode."
	exit 1
fi
echo "[newman-seed] write probe OK (created ${PROBE_ID}) — cleaning it up."
curl -sS -o /dev/null -w '[newman-seed] DELETE probe -> HTTP %{http_code}\n' \
	-u "${USER_NAME}:${USER_PASS}" -H 'OCS-APIRequest: true' \
	-X DELETE "${BASE}/index.php/apps/doriath/api/v1/secrets/${PROBE_ID}" || true

echo "[newman-seed] Doriath vault provisioned for the Newman contract suite."
