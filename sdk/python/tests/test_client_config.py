"""Construction of the client from one ClientConfig."""

from __future__ import annotations

import dataclasses
import os
import sys
import time
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "src"))
sys.path.insert(0, HERE)

import test_client_golden as g  # noqa: E402

from keepiq_sdk import Client, ClientConfig, Credentials  # noqa: E402

PEM = g.FIXTURE["privateKeyPem"]


class ClientConfigTest(unittest.TestCase):
    def test_the_old_call_forms_name_the_new_one(self) -> None:
        for args, kwargs in [
            ((g.BASE, "billing", PEM), {}),
            ((g.BASE, "billing", PEM), {"certificate_pem": g.FIXTURE["certificatePem"]}),
            ((ClientConfig(g.BASE, Credentials("billing", PEM)),), {"timeout": 5.0}),
            ((g.BASE,), {}),
            ((), {"url": g.BASE, "application_id": "billing", "private_key_pem": PEM}),
            ((), {}),
            ((ClientConfig(g.BASE, Credentials("billing", PEM)), "extra"), {}),
        ]:
            with self.subTest(args=len(args), kwargs=sorted(kwargs)):
                with self.assertRaises(TypeError) as ctx:
                    Client(*args, **kwargs)
                self.assertIn("ClientConfig(url, Credentials(application_id, private_key_pem", str(ctx.exception))

    def test_defaults(self) -> None:
        config = ClientConfig(g.BASE, Credentials("billing", PEM))
        self.assertEqual(config.timeout, 30.0)
        self.assertIs(config.clock, time.time)
        self.assertIsNone(config.credentials.certificate_pem)

    def test_the_config_is_frozen(self) -> None:
        config = ClientConfig(g.BASE, Credentials("billing", PEM))
        with self.assertRaises(dataclasses.FrozenInstanceError):
            config.url = "https://other.example.org"  # type: ignore[misc]
        with self.assertRaises(dataclasses.FrozenInstanceError):
            config.credentials.private_key_pem = ""  # type: ignore[misc]

    def test_the_private_key_stays_out_of_repr(self) -> None:
        config = ClientConfig(g.BASE, Credentials("billing", PEM, g.FIXTURE["certificatePem"]))
        self.assertNotIn("PRIVATE KEY", repr(config))
        self.assertNotIn("CERTIFICATE", repr(config))
        self.assertIn("billing", repr(config))

    def test_a_config_by_keyword_is_accepted(self) -> None:
        Client(config=ClientConfig(g.BASE, Credentials("billing", PEM)))


if __name__ == "__main__":
    unittest.main()
