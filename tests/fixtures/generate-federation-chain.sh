#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
#
# Regenerates tests/fixtures/federation-chain.json for
# tests/crypto/federatedCertificate.spec.js: two Keepiq-like CAs with the
# SAME distinguished names (every Keepiq instance names its CA alike), a
# user certificate signed with PKCS#1 v1.5, one signed with RSASSA-PSS as
# phpseclib 3 renewals were, and one with another common name.
# Usage: bash tests/fixtures/generate-federation-chain.sh
set -euo pipefail
cd "$(dirname "$0")"
W=$(mktemp -d)
trap 'rm -rf "$W"' EXIT
DAYS=36500
ca() { # name
	openssl req -x509 -newkey rsa:2048 -nodes -keyout "$W/$1-root.key" -out "$W/$1-root.pem" \
		-subj "/C=NL/O=Keepiq/CN=Keepiq Root CA" -days $DAYS -sha256 2>/dev/null
	openssl req -newkey rsa:2048 -nodes -keyout "$W/$1-int.key" -out "$W/$1-int.csr" \
		-subj "/C=NL/O=Keepiq/CN=Keepiq Intermediate CA" 2>/dev/null
	printf 'basicConstraints=critical,CA:true\nkeyUsage=critical,keyCertSign,cRLSign\n' > "$W/ca.ext"
	openssl x509 -req -in "$W/$1-int.csr" -CA "$W/$1-root.pem" -CAkey "$W/$1-root.key" -CAcreateserial \
		-out "$W/$1-int.pem" -days $DAYS -sha256 -extfile "$W/ca.ext" 2>/dev/null
}
leaf() { # ca name cn [pss]
	openssl req -newkey rsa:2048 -nodes -keyout "$W/$2.key" -out "$W/$2.csr" -subj "/C=NL/O=Keepiq/CN=$3" 2>/dev/null
	local opts=()
	if [ "${4:-}" = pss ]; then opts=(-sigopt rsa_padding_mode:pss -sigopt rsa_pss_saltlen:32 -sigopt rsa_mgf1_md:sha256); fi
	openssl x509 -req -in "$W/$2.csr" -CA "$W/$1-int.pem" -CAkey "$W/$1-int.key" -CAcreateserial \
		-out "$W/$2.pem" -days $DAYS -sha256 "${opts[@]}" 2>/dev/null
}
ca a
ca b
leaf a bob bob@cloud.partner.example
leaf a bobPss bob@cloud.partner.example pss
leaf a mallory mallory@cloud.partner.example
leaf b bobOtherRoot bob@cloud.partner.example
fp() { openssl x509 -in "$1" -outform DER | sha256sum | cut -d' ' -f1; }
python3 - "$W" "$(fp "$W/a-root.pem")" "$(fp "$W/b-root.pem")" <<'PY' > federation-chain.json
import json, sys
w, fa, fb = sys.argv[1:4]
r = lambda n: open(f"{w}/{n}.pem").read()
print(json.dumps({
    "rootA": r("a-root"), "intermediateA": r("a-int"), "rootAFingerprint": fa,
    "rootB": r("b-root"), "intermediateB": r("b-int"), "rootBFingerprint": fb,
    "bob": r("bob"), "bobPss": r("bobPss"), "mallory": r("mallory"), "bobOtherRoot": r("bobOtherRoot"),
}, indent=1))
PY
