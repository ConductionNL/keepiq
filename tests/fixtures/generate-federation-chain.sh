#!/usr/bin/env bash
# SPDX-License-Identifier: EUPL-1.2
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
#
# Regenerates tests/fixtures/federation-chain.json for
# tests/vitest/federatedCertificate.spec.js: two Keepiq-like CAs with the
# SAME distinguished names (every Keepiq instance names its CA alike), a
# user certificate signed with PKCS#1 v1.5, one signed with RSASSA-PSS as
# phpseclib 3 renewals were, and one with another common name.
# Partner A's keys are then re-issued with other extensions or validity, so
# only those tell the certificates apart: mallory's key marked CA (as
# openssl_csr_sign marked user certificates before) or signed by the root, a
# leaf signed with mallory's key, and intermediates and roots that may not
# issue or are not valid now.
# Usage: bash tests/fixtures/generate-federation-chain.sh
set -euo pipefail
# openssl's errors go to /dev/null below, so an older one would fail silently.
read -r OPENSSL_NAME OPENSSL_VERSION _ < <(openssl version)
if [ "$OPENSSL_NAME" != OpenSSL ] || ! printf '%s\n' 3.4.0 "$OPENSSL_VERSION" | sort -V -C; then
	echo "$0: needs OpenSSL 3.4 or later for -not_before and -not_after, found: $(openssl version)" >&2
	exit 1
fi
cd "$(dirname "$0")"
W=$(mktemp -d)
trap 'rm -rf "$W"' EXIT
DAYS=36500
CA='basicConstraints=critical,CA:true'
# A private-enterprise arc no verifier knows.
UNKNOWN=1.3.6.1.4.1.99999.1
EXPIRED=(-not_before 20000101000000Z -not_after 20010101000000Z)
NOT_YET_VALID=(-not_before 22000101000000Z -not_after 22010101000000Z)
root() { # name key extension [openssl options]
	openssl req -x509 -key "$W/$2.key" -out "$W/$1.pem" -subj "/C=NL/O=Keepiq/CN=Keepiq Root CA" \
		-days $DAYS -sha256 -config /dev/null -addext "$3" "${@:4}" 2>/dev/null
}
issue() { # name csr issuer extensions [openssl options]
	local ext=()
	if [ -n "$4" ]; then
		printf '%s\n' "$4" > "$W/$1.ext"
		ext=(-extfile "$W/$1.ext")
	fi
	openssl x509 -req -in "$W/$2.csr" -CA "$W/$3.pem" -CAkey "$W/$3.key" -CAcreateserial \
		-out "$W/$1.pem" -days $DAYS -sha256 "${ext[@]}" "${@:5}" 2>/dev/null
}
ca() { # name
	openssl genrsa -out "$W/$1-root.key" 2048 2>/dev/null
	root "$1-root" "$1-root" "$CA"
	openssl req -newkey rsa:2048 -nodes -keyout "$W/$1-int.key" -out "$W/$1-int.csr" \
		-subj "/C=NL/O=Keepiq/CN=Keepiq Intermediate CA" 2>/dev/null
	issue "$1-int" "$1-int" "$1-root" "$CA"$'\nkeyUsage=critical,keyCertSign,cRLSign'
}
leaf() { # ca name cn [pss]
	openssl req -newkey rsa:2048 -nodes -keyout "$W/$2.key" -out "$W/$2.csr" -subj "/C=NL/O=Keepiq/CN=$3" 2>/dev/null
	local opts=()
	if [ "${4:-}" = pss ]; then opts=(-sigopt rsa_padding_mode:pss -sigopt rsa_pss_saltlen:32 -sigopt rsa_mgf1_md:sha256); fi
	issue "$2" "$2" "$1-int" '' "${opts[@]}"
}
# openssl writes neither a repeated extension nor a non-DER one, so this
# splices one in before the certificate's own and re-signs it.
prepend() { # name issuer extension-hex
	python3 - "$W/$1.pem" "$W/$2.key" "$3" <<'PY'
import base64, subprocess, sys, textwrap
path, key, extension = sys.argv[1:4]
der = base64.b64decode("".join(line for line in open(path).read().splitlines() if not line.startswith("-----")))
def tlv(b, i):
	n, s = b[i + 1], i + 2
	if n & 0x80:
		k = n & 0x7f
		n, s = int.from_bytes(b[s:s + k], "big"), s + k
	return b[i], s, s + n
def enc(tag, body):
	n = len(body)
	size = (n.bit_length() + 7) // 8
	return bytes([tag]) + (bytes([n]) if n < 128 else bytes([0x80 | size]) + n.to_bytes(size, "big")) + body
_, cert_start, _ = tlv(der, 0)
_, tbs_start, tbs_end = tlv(der, cert_start)
i = tbs_start
while der[i] != 0xa3:
	i = tlv(der, i)[2]
_, seq_start, _ = tlv(der, tlv(der, i)[1])
tbs = enc(0x30, der[tbs_start:i] + enc(0xa3, enc(0x30, bytes.fromhex(extension) + der[seq_start:tbs_end])))
algorithm = der[tbs_end:tlv(der, tbs_end)[2]]
signature = subprocess.run(["openssl", "dgst", "-sha256", "-sign", key], input=tbs, capture_output=True, check=True).stdout
body = base64.b64encode(enc(0x30, tbs + algorithm + enc(0x03, b"\0" + signature))).decode()
open(path, "w").write("-----BEGIN CERTIFICATE-----\n" + "\n".join(textwrap.wrap(body, 64)) + "\n-----END CERTIFICATE-----\n")
PY
}
ca a
ca b
leaf a bob bob@cloud.partner.example
leaf a bobPss bob@cloud.partner.example pss
leaf a mallory mallory@cloud.partner.example
leaf b bobOtherRoot bob@cloud.partner.example
issue leafMarkedCa mallory a-int "$CA"
issue leafSignedByRoot mallory a-root ''
openssl req -newkey rsa:2048 -nodes -keyout "$W/carol.key" -out "$W/carol.csr" \
	-subj "/C=NL/O=Keepiq/CN=carol@cloud.partner.example" 2>/dev/null
issue leafSignedByLeaf carol mallory ''
root rootAPathLen0 a-root "$CA,pathlen:0"
root rootAExpired a-root "$CA" "${EXPIRED[@]}"
issue intermediateABasicConstraintsOnly a-int a-root "$CA"
issue intermediateANoBasicConstraints a-int a-root 'subjectKeyIdentifier=hash'
issue intermediateANotCa a-int a-root 'basicConstraints=critical,CA:false'
issue intermediateANoCertSign a-int a-root "$CA"$'\nkeyUsage=critical,digitalSignature,cRLSign'
issue intermediateAPathLen0 a-int a-root "$CA,pathlen:0"
issue intermediateAUnknownCritical a-int a-root "$CA"$'\n'"$UNKNOWN=critical,ASN1:NULL"
issue intermediateAUnknownNonCritical a-int a-root "$CA"$'\n'"$UNKNOWN=ASN1:NULL"
issue intermediateAExpired a-int a-root "$CA" "${EXPIRED[@]}"
issue intermediateANotYetValid a-int a-root "$CA" "${NOT_YET_VALID[@]}"
# A critical basicConstraints without cA in front of its CA:TRUE one.
issue intermediateADuplicateBasicConstraints a-int a-root "$CA"
prepend intermediateADuplicateBasicConstraints a-root 300c0603551d130101ff04023000
# keyUsage 03 02 03 04: keyCertSign set only in the three padding bits.
issue intermediateAKeyCertSignInPadding a-int a-root "$CA"
prepend intermediateAKeyCertSignInPadding a-root 300e0603551d0f0101ff040403020304
# keyUsage 03 01 03: no bits at all, yet three of them unused.
issue intermediateAEmptyKeyUsageWithUnusedBits a-int a-root "$CA"
prepend intermediateAEmptyKeyUsageWithUnusedBits a-root 300d0603551d0f0101ff0403030103
fp() { openssl x509 -in "$1" -outform DER | sha256sum | cut -d' ' -f1; }
python3 - "$W" "$(fp "$W/a-root.pem")" "$(fp "$W/b-root.pem")" "$(fp "$W/rootAPathLen0.pem")" "$(fp "$W/rootAExpired.pem")" <<'PY' > federation-chain.json
import json, sys
w, fa, fb, fa0, fax = sys.argv[1:6]
r = lambda n: open(f"{w}/{n}.pem").read()
variants = ["leafMarkedCa", "leafSignedByRoot", "leafSignedByLeaf", "rootAPathLen0", "rootAExpired",
    "intermediateABasicConstraintsOnly", "intermediateANoBasicConstraints", "intermediateANotCa",
    "intermediateANoCertSign", "intermediateAPathLen0", "intermediateAUnknownCritical",
    "intermediateAUnknownNonCritical", "intermediateAExpired", "intermediateANotYetValid",
    "intermediateADuplicateBasicConstraints", "intermediateAKeyCertSignInPadding",
    "intermediateAEmptyKeyUsageWithUnusedBits"]
print(json.dumps({
    "rootA": r("a-root"), "intermediateA": r("a-int"), "rootAFingerprint": fa,
    "rootB": r("b-root"), "intermediateB": r("b-int"), "rootBFingerprint": fb,
    "bob": r("bob"), "bobPss": r("bobPss"), "mallory": r("mallory"), "bobOtherRoot": r("bobOtherRoot"),
    **{n: r(n) for n in variants}, "rootAPathLen0Fingerprint": fa0, "rootAExpiredFingerprint": fax,
}, indent=1))
PY
