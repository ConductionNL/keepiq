#!/usr/bin/env bash
# Prints ca-constraints-chain.json: a partner CA with the fewest extensions a verifier must accept (CAs carry only basicConstraints CA:TRUE, leaves none), a leaf forged with another leaf's key, and re-issues of the same keys with bad constraints, extensions, validity or chain length, so only that tells them apart.
# The profile Keepiq issues today (lib/Service/certificate-extensions.cnf) is built in federated-certificate.test.ts.
# Needs OpenSSL 3.4 or later for -not_before and -not_after.
# Usage: bash src/testing/generate-ca-constraints-chain.sh > src/testing/ca-constraints-chain.json
set -euo pipefail
W=$(mktemp -d)
trap 'rm -rf "$W"' EXIT
DAYS=36500
ROOT_DN="/C=NL/O=Conduction/OU=Keepiq/CN=Keepiq Root CA"
INT_DN="/C=NL/O=Conduction/OU=Keepiq/CN=Keepiq Intermediate CA"
# A private-enterprise arc no verifier knows.
UNKNOWN=1.3.6.1.4.1.99999.1
EXPIRED=(-not_before 20000101000000Z -not_after 20010101000000Z)
NOT_YET_VALID=(-not_before 22000101000000Z -not_after 22010101000000Z)

openssl genrsa -out "$W/root.key" 2048 2>/dev/null
root() { # name extension [openssl options]
	openssl req -x509 -key "$W/root.key" -out "$W/$1.pem" -subj "$ROOT_DN" -days $DAYS -sha256 \
		-config /dev/null -addext "$2" "${@:3}" 2>/dev/null
}
root root basicConstraints=critical,CA:true
root rootPathLen0 basicConstraints=critical,CA:true,pathlen:0
root rootExpired basicConstraints=critical,CA:true "${EXPIRED[@]}"

csr() { # name subject bits
	openssl req -newkey "rsa:$3" -nodes -keyout "$W/$1.key" -out "$W/$1.csr" -subj "$2" 2>/dev/null
}
issue() { # name csr issuer extensions [openssl options]
	local ext=()
	if [ -n "$4" ]; then
		printf '%s\n' "$4" > "$W/$1.ext"
		ext=(-extfile "$W/$1.ext")
	fi
	openssl x509 -req -in "$W/$2.csr" -CA "$W/$3.pem" -CAkey "$W/$3.key" -CAcreateserial \
		-out "$W/$1.pem" -days $DAYS -sha256 "${ext[@]}" "${@:5}" 2>/dev/null
	[ "$1" = "$2" ] || cp "$W/$2.key" "$W/$1.key"
}
# openssl drops a repeated extension, so this splices one in before the certificate's own and re-signs it.
duplicate() { # name issuer extension-hex
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
CA='basicConstraints=critical,CA:true'
csr int "$INT_DN" 2048
issue intermediate int root "$CA"
issue intermediateNoBasicConstraints int root 'subjectKeyIdentifier=hash'
issue intermediateNotCa int root 'basicConstraints=critical,CA:false'
issue intermediateNoCertSign int root "$CA"$'\nkeyUsage=critical,digitalSignature'
issue intermediatePathLen0 int root 'basicConstraints=critical,CA:true,pathlen:0'
issue intermediateUnknownCritical int root "$CA"$'\n'"$UNKNOWN=critical,ASN1:NULL"
# A critical basicConstraints without cA in front of its CA:TRUE one, so a verifier that lets the last win accepts it.
issue intermediateDuplicateBasicConstraints int root "$CA"
duplicate intermediateDuplicateBasicConstraints root 300c0603551d130101ff04023000
issue intermediateExpired int root "$CA" "${EXPIRED[@]}"
issue intermediateNotYetValid int root "$CA" "${NOT_YET_VALID[@]}"
csr subIntermediate "/C=NL/O=Conduction/OU=Keepiq/CN=Keepiq Sub CA" 2048
issue subIntermediate subIntermediate intermediate "$CA"

# 4096 bits, as Keepiq user keys are: rsaEncrypt writes 512-byte blocks.
csr mallory /C=NL/O=Conduction/CN=mallory@cloud.partner.example 4096
issue mallory mallory intermediate ''
issue malloryUnknownCritical mallory intermediate "$UNKNOWN=critical,ASN1:NULL"
issue malloryUnknownNonCritical mallory intermediate "$UNKNOWN=ASN1:NULL"
# A second copy of the $UNKNOWN extension it already carries.
issue malloryDuplicateExtension mallory intermediate "$UNKNOWN=ASN1:NULL"
duplicate malloryDuplicateExtension intermediate 300f06092b06010401868d1f0104020500
issue malloryByRoot mallory root ''
issue malloryBySubIntermediate mallory subIntermediate ''
# A partner user holds their own leaf key, so they can sign a leaf naming a colleague.
csr forgedAlice /C=NL/O=Conduction/CN=alice@cloud.partner.example 4096
issue forgedAlice forgedAlice mallory ''

fp() { openssl x509 -in "$W/$1.pem" -outform DER | sha256sum | cut -d' ' -f1; }
python3 - "$W" "$(fp root)" "$(fp rootPathLen0)" "$(fp rootExpired)" <<'PY'
import json, sys
w, root, root_path_len_0, root_expired = sys.argv[1:5]
r = lambda n: open(f"{w}/{n}").read()
names = ["root", "rootPathLen0", "rootExpired", "intermediate", "intermediateNoBasicConstraints", "intermediateNotCa", "intermediateNoCertSign", "intermediatePathLen0",
	"intermediateUnknownCritical", "intermediateDuplicateBasicConstraints", "intermediateExpired", "intermediateNotYetValid", "subIntermediate",
	"mallory", "malloryUnknownCritical", "malloryUnknownNonCritical", "malloryDuplicateExtension", "malloryByRoot", "malloryBySubIntermediate", "forgedAlice"]
out = {"_note": "TEST ONLY: throwaway keys and certificates generated by generate-ca-constraints-chain.sh; they protect nothing."}
out.update({n: r(f"{n}.pem") for n in names})
out.update(rootFingerprint=root, rootPathLen0Fingerprint=root_path_len_0, rootExpiredFingerprint=root_expired, malloryKey=r("mallory.key"))
print(json.dumps(out, indent=1))
PY
