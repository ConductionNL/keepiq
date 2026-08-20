#!/usr/bin/env bash
#
# Doriath API-contract test runner (Newman / Postman).
#
# Runs tests/integration/doriath.postman_collection.json against a live
# Nextcloud instance serving the doriath (vault) app. The collection is
# self-contained and idempotent: it creates the vault objects it needs (a
# secret, a folder) and deletes them again in teardown.
#
# Usage:
#   ./run-newman.sh                                  # defaults to localhost:8080, admin:admin
#   BASE_URL=http://localhost:8080 ./run-newman.sh
#   ADMIN_USER=admin ADMIN_PASS=admin ./run-newman.sh
#
# Uses a globally-installed `newman` if present, otherwise falls back to
# `npx newman`. Runs are serialised via flock (when available) so concurrent
# CI agents do not trip the Nextcloud brute-force protection.
#
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

set -euo pipefail

# Re-exec under an exclusive flock so parallel agents serialise.
LOCK_FILE="/tmp/uiaudit-doriath.lock"
if [ "${DORIATH_NEWMAN_LOCKED:-}" != "1" ] && command -v flock >/dev/null 2>&1; then
  export DORIATH_NEWMAN_LOCKED=1
  exec flock "${LOCK_FILE}" "$0" "$@"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COLLECTION="${SCRIPT_DIR}/doriath.postman_collection.json"

BASE_URL="${BASE_URL:-http://localhost:8080}"
ADMIN_USER="${ADMIN_USER:-admin}"
ADMIN_PASS="${ADMIN_PASS:-admin}"

# Authenticated requests use baseUrl; the authorization (no-auth) tests use a
# DIFFERENT host alias so the session cookie that authenticated requests
# establish (host-scoped) is never sent to them — keeping them genuinely
# unauthenticated. Defaults to the 127.0.0.1 form of baseUrl.
if [ -n "${NO_AUTH_BASE:-}" ]; then
  NOAUTH_BASE="${NO_AUTH_BASE}"
elif [[ "${BASE_URL}" == *"localhost"* ]]; then
  NOAUTH_BASE="${BASE_URL/localhost/127.0.0.1}"
else
  NOAUTH_BASE="${BASE_URL/127.0.0.1/localhost}"
fi

if command -v newman >/dev/null 2>&1; then
  NEWMAN=(newman)
else
  NEWMAN=(npx --yes newman)
fi

# --ignore-redirects: assert NC's 401/303 on unauthenticated requests directly
# instead of following it to a 200 HTML login page (so authz tests are honest).
"${NEWMAN[@]}" run "${COLLECTION}" \
  --env-var "baseUrl=${BASE_URL}" \
  --env-var "noAuthBase=${NOAUTH_BASE}" \
  --env-var "adminUser=${ADMIN_USER}" \
  --env-var "adminPass=${ADMIN_PASS}" \
  --ignore-redirects \
  --reporters cli \
  --color on \
  "$@"

# Machine secret-store API contract (openconnector-secret-store-api).
# The unauthenticated subset (discovery + token negatives + bearer-required)
# always runs; the seeded machine flow runs only when SEEDED_APP_ID +
# SEEDED_PRIVATE_KEY_PEM are provided (the openconnector-side CI supplies them).
#
# ⚠️ ASSERTIONS ARE SIGNED HERE, NOT IN THE COLLECTION.
#
# The collection used to sign its own RS256 assertions in a `prerequest` script
# via `require('crypto')`. Newman's sandbox does not provide node's crypto
# module, so that call threw on every run:
#
#   "assertion signing unavailable: Cannot find module 'crypto'"
#
# The script caught it, left the assertion empty, and every seeded test then
# took its `pm.test.skip` branch. The seeded machine flow therefore NEVER
# executed — not locally, not in CI — while the run still reported green. That
# is why a live 400 on the machine secret-request happy path was not caught by
# these "integration tests": for the authenticated surface there effectively
# were none.
#
# Signing in the runner, where real node is available, is what makes the seeded
# folders actually run. Two DISTINCT assertions are minted because a spent `jti`
# is refused on reuse: one for the secret-store folder, one for the
# secret-requests folder. The second is also handed over as `replayAssertion`,
# so the replay test spends it a second time on purpose.
sign_assertion() {
  # $1 = application id, $2 = PEM file, $3 = jti tag
  node -e '
    const crypto = require("crypto");
    const fs = require("fs");
    const [appId, pemPath, tag] = process.argv.slice(1);
    const pem = fs.readFileSync(pemPath, "utf8");
    const now = Math.floor(Date.now() / 1000);
    const b64u = (b) => Buffer.from(b).toString("base64")
      .replace(/=+$/, "").replace(/\+/g, "-").replace(/\//g, "_");
    const signingInput = b64u(JSON.stringify({ alg: "RS256", typ: "JWT" })) + "." +
      b64u(JSON.stringify({
        iss: appId, aud: "doriath", iat: now, exp: now + 300,
        jti: "newman-" + tag + "-" + now + "-" + Math.random().toString(36).slice(2),
      }));
    const sig = crypto.sign("RSA-SHA256", Buffer.from(signingInput), pem)
      .toString("base64").replace(/=+$/, "").replace(/\+/g, "-").replace(/\//g, "_");
    process.stdout.write(signingInput + "." + sig);
  ' "$1" "$2" "$3"
}

SIGNED_ASSERTION=""
SIGNED_ASSERTION_2=""
if [ -n "${SEEDED_APP_ID:-}" ] && [ -n "${SEEDED_PRIVATE_KEY_PEM:-}" ]; then
  if command -v node >/dev/null 2>&1; then
    PEM_FILE="$(mktemp)"
    # 0600 before the key lands in it, not after.
    chmod 600 "${PEM_FILE}"
    printf '%s' "${SEEDED_PRIVATE_KEY_PEM}" > "${PEM_FILE}"
    trap 'rm -f "${PEM_FILE}"' EXIT
    SIGNED_ASSERTION="$(sign_assertion "${SEEDED_APP_ID}" "${PEM_FILE}" store)"
    SIGNED_ASSERTION_2="$(sign_assertion "${SEEDED_APP_ID}" "${PEM_FILE}" requests)"
  else
    # Loud, because silence here is what hid the gap for so long.
    echo "run-newman.sh: node not found — seeded machine folders will SKIP" >&2
  fi
fi

MACHINE_COLLECTION="${SCRIPT_DIR}/machine-secret-api.postman_collection.json"
"${NEWMAN[@]}" run "${MACHINE_COLLECTION}" \
  --env-var "baseUrl=${BASE_URL}" \
  --env-var "noAuthBase=${NOAUTH_BASE}" \
  --env-var "seededAppId=${SEEDED_APP_ID:-}" \
  --env-var "seededPrivateKeyPem=${SEEDED_PRIVATE_KEY_PEM:-}" \
  --env-var "seededSecretName=${SEEDED_SECRET_NAME:-zgw-api-token}" \
  --env-var "injectedAssertion=${SIGNED_ASSERTION}" \
  --env-var "injectedAssertion2=${SIGNED_ASSERTION_2}" \
  --env-var "replayAssertion=${SIGNED_ASSERTION_2}" \
  --ignore-redirects \
  --reporters cli \
  --color on \
  "$@"
