"""Tests for keepiq_sdk against the shared vectors and the stub server.

Runs under pytest or plain ``python -m unittest discover -s tests``.
KEEPIQ_WRITE_VECTORS=1 rewrites sdk/testdata/encrypted_by_python.json.
"""

from __future__ import annotations

import json
import os
import sys
import unittest
from datetime import datetime, timezone

HERE = os.path.dirname(os.path.abspath(__file__))
TESTDATA = os.path.normpath(os.path.join(HERE, "..", "..", "testdata"))
sys.path.insert(0, os.path.join(HERE, "..", "src"))
sys.path.insert(0, TESTDATA)

from cryptography.hazmat.primitives.asymmetric import rsa  # noqa: E402
from cryptography.hazmat.primitives import serialization  # noqa: E402

import stub_server  # noqa: E402
from keepiq_sdk import (  # noqa: E402
    AmbiguousNameError,
    Client,
    KeyMismatchError,
    NotFoundError,
    NotModifiedError,
    UnauthorizedError,
)
from keepiq_sdk import crypto  # noqa: E402


def load(name: str) -> dict:
    with open(os.path.join(TESTDATA, name), encoding="utf-8") as fh:
        return json.load(fh)


FIXTURE = load("machine_envelope.json")


class ClientTest(unittest.TestCase):
    def setUp(self) -> None:
        self.stub = stub_server.Stub("billing")
        self.server = self.stub.serve(0)
        self.url = f"http://127.0.0.1:{self.server.server_address[1]}{stub_server.WEBROOT}"

    def tearDown(self) -> None:
        self.server.shutdown()
        self.server.server_close()

    def client(self, **kw) -> Client:
        return Client(self.url, "billing", FIXTURE["privateKeyPem"], **kw)

    def test_get_by_name_decrypts_the_server_envelope(self) -> None:
        c = self.client()
        s = c.get_by_name("ci-fixture-db-password")
        self.assertEqual(s.key, FIXTURE["plaintext"]["key"])
        self.assertEqual(s.login, FIXTURE["plaintext"]["login"])
        self.assertEqual(s.additional_fields, FIXTURE["plaintext"]["additionalFields"])
        self.assertEqual(s.folder_path, "ci/database")
        self.assertIsNotNone(s.lease)
        self.assertEqual(s.lease.id, "lease-7")
        c.get_by_id("sec-cli-fixture")
        self.assertEqual(self.stub.exchanges, 1, "the token must be cached")

    def test_unchanged_read_is_not_modified(self) -> None:
        c = self.client()
        self.assertTrue(c.get_by_id("sec-cli-fixture").etag)
        with self.assertRaises(NotModifiedError):
            c.get_by_id("sec-cli-fixture")

    def test_ambiguous_name_lists_candidates(self) -> None:
        twin = self.stub.new_envelope("sec-twin", {"name": "ci-fixture-db-password"})
        twin["secret"]["folderPath"] = "other"
        self.stub.envelopes["sec-twin"] = twin
        with self.assertRaises(AmbiguousNameError) as ctx:
            self.client().get_by_name("ci-fixture-db-password")
        got = {c.id: c.folder_path for c in ctx.exception.candidates}
        self.assertEqual(got, {"sec-cli-fixture": "ci/database", "sec-twin": "other"})

    def test_not_found(self) -> None:
        with self.assertRaises(NotFoundError):
            self.client().get_by_name("nope")

    def test_wrong_key_is_unauthorized(self) -> None:
        other = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        pem = other.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()).decode()
        with self.assertRaises(UnauthorizedError):
            Client(self.url, "billing", pem).get_by_id("sec-cli-fixture")

    def test_revoked_token_is_renewed_once(self) -> None:
        c = self.client()
        c.get_by_id("sec-cli-fixture")
        self.stub.revoke_next = True
        c.list()
        self.assertEqual(self.stub.exchanges, 2)

    def test_writes_send_only_ciphertext(self) -> None:
        c = self.client()
        created = c.create({"name": "stripe-key", "key": "sk_live_first", "login": "billing-bot"})
        self.assertEqual(created.key, "sk_live_first")
        c.update(created.id, {"key": "YOUR_TOKEN_HERE"})
        for body in self.stub.bodies:
            for plain in ("sk_live_first", "billing-bot", "YOUR_TOKEN_HERE", "PRIVATE KEY"):
                self.assertNotIn(plain, body)
        got = c.get_by_id(created.id)
        self.assertEqual(got.key, "YOUR_TOKEN_HERE")
        self.assertEqual(got.login, "billing-bot")
        with self.assertRaises(ValueError):
            c.create({"name": "x", "password": "y"})

    def test_list_filters_on_updated_since(self) -> None:
        self.stub.envelopes["sec-new"] = self.stub.new_envelope("sec-new", {"name": "n"})
        c = self.client()
        self.assertEqual(len(c.list()), 2)
        recent = c.list(datetime(2026, 10, 1, tzinfo=timezone.utc))
        self.assertEqual([s.id for s in recent], ["sec-new"])

    def test_fingerprint_mismatch_is_refused(self) -> None:
        self.stub.envelopes["sec-cli-fixture"]["encryption"]["certificateFingerprint"] = "sha256:00"
        with self.assertRaises(KeyMismatchError):
            self.client(certificate_pem=FIXTURE["certificatePem"]).get_by_id("sec-cli-fixture")


class VectorTest(unittest.TestCase):
    def setUp(self) -> None:
        self.key = crypto.load_private_key(FIXTURE["privateKeyPem"])

    def test_decrypts_the_php_serializer_envelope(self) -> None:
        for name, plain in FIXTURE["plaintext"].items():
            self.assertEqual(crypto.decrypt_field(FIXTURE["envelope"]["ciphertext"][name], self.key), plain)

    def test_decrypts_the_browser_vector(self) -> None:
        with open(os.path.join(TESTDATA, "webcrypto_envelope.json"), encoding="utf-8") as fh:
            raw = fh.read()
        env = json.loads(raw)
        if isinstance(env, str):
            env = json.loads(env)
        key = crypto.load_private_key(crypto.unwrap_private_key(env["blobB64"], env["masterPw"]))
        self.assertEqual(crypto.decrypt_field(env["fieldB64"], key), env["plaintext"])

    def test_decrypts_every_library_vector(self) -> None:
        for lang in ("go", "python", "js"):
            path = os.path.join(TESTDATA, f"encrypted_by_{lang}.json")
            if not os.path.exists(path):
                continue  # the producing library's own test fails on a missing vector
            vec = load(f"encrypted_by_{lang}.json")
            for name, plain in vec["plaintext"].items():
                self.assertEqual(crypto.decrypt_field(vec["ciphertext"][name], self.key), plain, f"{lang} {name}")

    def test_python_vector(self) -> None:
        path = os.path.join(TESTDATA, "encrypted_by_python.json")
        if os.environ.get("KEEPIQ_WRITE_VECTORS") == "1":
            plaintext = load("vector_plaintexts.json")["plaintext"]
            vec = {
                "_comment": "TEST ONLY. Values encrypted by Python (sdk/python) to the machine_envelope.json test key. "
                "Decrypted by every library and by DecryptService in tests/Unit/Service/SdkEncryptedVectorsTest.php.",
                "plaintext": plaintext,
                "ciphertext": {k: crypto.encrypt_field(v, self.key.public_key()) for k, v in plaintext.items()},
            }
            with open(path, "w", encoding="utf-8") as fh:
                json.dump(vec, fh, indent=4, ensure_ascii=False)
                fh.write("\n")
        self.assertTrue(os.path.exists(path), "write it with KEEPIQ_WRITE_VECTORS=1")
        vec = load("encrypted_by_python.json")
        self.assertEqual(vec["plaintext"], load("vector_plaintexts.json")["plaintext"])
        for name, plain in vec["plaintext"].items():
            self.assertEqual(crypto.decrypt_field(vec["ciphertext"][name], self.key), plain)

    def test_encrypt_round_trip_and_chunking(self) -> None:
        import base64
        import struct

        for value in ("s3cr3t", "x" * 1000, "", "é 🔑"):
            ct = crypto.encrypt_field(value, self.key.public_key())
            raw = base64.b64decode(ct)
            chunks = max(1, -(-len(value.encode()) // 446))
            self.assertEqual(struct.unpack(">I", raw[:4])[0], chunks)
            self.assertEqual(len(raw), 4 + chunks * 512)
            self.assertEqual(crypto.decrypt_field(ct, self.key), value)


if __name__ == "__main__":
    unittest.main()
