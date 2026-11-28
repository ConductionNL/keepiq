"""The text form of a request, an answer and the client leaves out assertions, tokens and keys."""

from __future__ import annotations

import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import test_client_golden as g  # noqa: E402
from test_client_secrecy import run_one  # noqa: E402


class WireReprTest(unittest.TestCase):
    by_id = ("GET", g.SECRETS + "/sec-cli-fixture")

    def read_with_spy(self):
        calls, answers = [], []
        script = g.standard({self.by_id: [g.answer(200, g.envelope())]})

        def step(c):
            real = c._raw

            def spy(call):
                calls.append((call, repr(call)))
                resp = real(call)
                answers.append((resp, repr(resp)))
                return resp

            c._raw = spy
            c.get_by_id("sec-cli-fixture")
            return c

        client, _ = run_one(script, step)
        return client, calls, answers

    def test_token_call_repr_leaves_out_the_assertion(self) -> None:
        _, calls, _ = self.read_with_spy()
        token_calls = [(call, text) for call, text in calls if call.addr == g.TOKEN]
        self.assertEqual(len(token_calls), 1)
        call, text = token_calls[0]
        self.assertIn(b"assertion=", call.data)
        self.assertNotIn("assertion", text)
        self.assertNotIn("eyJ", text)
        self.assertIn("method='POST'", text)
        self.assertIn(repr(g.TOKEN), text)

    def test_answer_repr_leaves_out_the_body(self) -> None:
        _, _, answers = self.read_with_spy()
        self.assertEqual([resp.status for resp, _ in answers], [200, 200, 200])
        token_answers = [(resp, text) for resp, text in answers if b"access_token" in resp.body]
        self.assertEqual(len(token_answers), 1)
        resp, text = token_answers[0]
        self.assertIn(b"tok-1", resp.body)
        self.assertNotIn("tok-1", text)
        self.assertNotIn("access_token", text)
        self.assertEqual(text, f"_Response(status=200, body=<{len(resp.body)} bytes>)")
        status, _, body = resp
        self.assertEqual((status, body), (200, resp.body))

    def test_client_repr_shows_only_address_and_application(self) -> None:
        client, _, _ = self.read_with_spy()
        self.assertEqual(client._token, "tok-1")
        text = repr(client)
        self.assertEqual(text, f"Client(url={g.BASE.rstrip('/')!r}, application_id='billing')")
        self.assertNotIn("tok-1", text)
        self.assertNotIn("PRIVATE KEY", text)


if __name__ == "__main__":
    unittest.main()
