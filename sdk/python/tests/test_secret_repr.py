"""The text form of a Secret shows its metadata, not its decrypted values."""

from __future__ import annotations

import dataclasses
import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "src"))

from keepiq_sdk import Lease, Secret  # noqa: E402


def sample(**overrides: str) -> Secret:
    values = {"key": "value-k3y", "login": "login-n4me", "additional_fields": '{"pin":"pin-9137"}'}
    values.update(overrides)
    return Secret(id="sec-1", name="db", folder_path="ci", etag='"e1"', lease=Lease(id="l-1", expires="soon"), **values)


class SecretReprTest(unittest.TestCase):
    def test_repr_and_str_leave_out_the_values(self) -> None:
        secret = sample()
        for text in (repr(secret), str(secret), f"{secret}", repr([secret])):
            for value in ("value-k3y", "login-n4me", "pin-9137"):
                self.assertNotIn(value, text)

    def test_repr_keeps_the_metadata(self) -> None:
        text = repr(sample())
        for part in ("id='sec-1'", "name='db'", "folder_path='ci'", "etag='\"e1\"'", "Lease(id='l-1'"):
            self.assertIn(part, text)

    def test_values_stay_readable_and_comparable(self) -> None:
        secret = sample()
        self.assertEqual((secret.key, secret.login, secret.additional_fields), ("value-k3y", "login-n4me", '{"pin":"pin-9137"}'))
        self.assertEqual(dataclasses.asdict(secret)["key"], "value-k3y")
        self.assertEqual(secret, sample())
        self.assertNotEqual(secret, sample(key="other"))
        self.assertNotEqual(secret, sample(login="other"))
        self.assertNotEqual(secret, sample(additional_fields="other"))

    def test_defaults_and_keywords_unchanged(self) -> None:
        bare = Secret(id="s", name="n")
        self.assertEqual((bare.key, bare.login, bare.additional_fields), ("", "", ""))
        names = [f.name for f in dataclasses.fields(Secret)]
        self.assertEqual(names[:11], ["id", "name", "url", "folder_path", "type", "created_at", "updated_at", "key_updated_at", "key", "login", "additional_fields"])


if __name__ == "__main__":
    unittest.main()
