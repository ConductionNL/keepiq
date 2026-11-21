"""Credential and decryption invariants the golden record cannot see."""

from __future__ import annotations

import base64
import logging
import os
import sys
import unittest
from unittest import mock

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import test_client_golden as g  # noqa: E402
from keepiq_sdk import Client, ClientConfig, Credentials, KeyMismatchError, crypto  # noqa: E402


def tampered(blob: str) -> str:
    raw = bytearray(base64.b64decode(blob))
    raw[-1] ^= 0x01
    return base64.b64encode(bytes(raw)).decode()


def run_one(script: dict, step, certificate_pem=None):
    transport = g.Transport(script)
    with mock.patch.object(g.client_module.urllib.request, "urlopen", transport):
        credentials = Credentials("billing", g.FIXTURE["privateKeyPem"], certificate_pem)
        c = Client(ClientConfig(g.BASE, credentials, clock=g.Clock()))
        return step(c), transport


class SecrecyTest(unittest.TestCase):
    by_id = ("GET", g.SECRETS + "/sec-cli-fixture")

    def test_fingerprint_mismatch_is_refused_before_any_decryption(self) -> None:
        env = g.envelope(encryption__certificateFingerprint="sha256:00")
        script = g.standard({self.by_id: [g.answer(200, env)]})
        with mock.patch.object(crypto, "decrypt_field", side_effect=AssertionError("decrypted before the pin check")):
            with self.assertRaises(KeyMismatchError):
                run_one(script, lambda c: c.get_by_id("sec-cli-fixture"), certificate_pem=g.FIXTURE["certificatePem"])

    def test_a_missing_fingerprint_is_refused_when_a_certificate_is_pinned(self) -> None:
        for fingerprint in ("", None, "missing", 42):
            with self.subTest(fingerprint=fingerprint):
                env = g.envelope()
                if fingerprint == "missing":
                    del env["encryption"]["certificateFingerprint"]
                else:
                    env["encryption"]["certificateFingerprint"] = fingerprint
                script = g.standard({self.by_id: [g.answer(200, env)]})
                with mock.patch.object(crypto, "decrypt_field", side_effect=AssertionError("decrypted before the pin check")):
                    with self.assertRaises(KeyMismatchError):
                        run_one(script, lambda c: c.get_by_id("sec-cli-fixture"), certificate_pem=g.FIXTURE["certificatePem"])
                script = g.standard({self.by_id: [g.answer(200, env)]})
                secret, _ = run_one(script, lambda c: c.get_by_id("sec-cli-fixture"))
                self.assertEqual(secret.key, g.FIXTURE["plaintext"]["key"])

    def test_tampered_ciphertext_is_refused_without_partial_plaintext(self) -> None:
        env = g.envelope()
        env["ciphertext"]["login"] = tampered(env["ciphertext"]["login"])
        script = g.standard({self.by_id: [g.answer(200, env)]})
        with self.assertRaises(ValueError):
            run_one(script, lambda c: c.get_by_id("sec-cli-fixture"))

    def test_every_assertion_has_a_fresh_jti(self) -> None:
        script = g.standard({self.by_id: [g.answer(401), g.answer(200, g.envelope())]})
        forms = []
        real = g.describe_body

        def capture(url, data):
            if data is not None and url == g.TOKEN:
                forms.append(dict(g.urllib.parse.parse_qsl(data.decode())))
            return real(url, data)

        with mock.patch.object(g, "describe_body", capture):
            run_one(script, lambda c: c.get_by_id("sec-cli-fixture"))
        jtis = []
        for form in forms:
            claims = form["assertion"].split(".")[1]
            jtis.append(g.json.loads(base64.urlsafe_b64decode(claims + "=" * (-len(claims) % 4)))["jti"])
        self.assertEqual(len(jtis), 2)
        self.assertNotEqual(jtis[0], jtis[1])

    def test_no_token_or_plaintext_in_logs_or_errors(self) -> None:
        env = g.envelope()
        script = g.standard({self.by_id: [g.answer(200, env), g.answer(500, b"boom")]})
        plain = g.FIXTURE["plaintext"]["key"]
        with self.assertLogs(level=logging.DEBUG) as logs:
            logging.getLogger("probe").debug("probe")
            try:
                run_one(script, lambda c: (c.get_by_id("sec-cli-fixture"), c.get_by_id("sec-cli-fixture")))
            except Exception as err:  # noqa: BLE001
                self.assertNotIn("tok-1", str(err) + repr(err))
                self.assertNotIn(plain, str(err) + repr(err))
        for line in logs.output:
            self.assertNotIn("tok-1", line)
            self.assertNotIn(plain, line)

    def test_request_repr_leaves_out_the_headers(self) -> None:
        captured = []
        script = g.standard({self.by_id: [g.answer(200, g.envelope())]})

        def keep(c):
            real = c._raw

            def spy(call):
                captured.append(repr(call))
                return real(call)

            c._raw = spy
            return c.get_by_id("sec-cli-fixture")

        run_one(script, keep)
        self.assertTrue(captured)
        for text in captured:
            self.assertNotIn("tok-1", text)
            self.assertNotIn("Bearer", text)


if __name__ == "__main__":
    unittest.main()
