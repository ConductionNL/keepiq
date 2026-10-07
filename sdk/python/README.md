# keepiq-sdk for Python

Read and write your application's Keepiq secrets. Values are decrypted and
encrypted in your process; only ciphertext and a signed assertion cross the
network, and the private key never leaves your process.

```python
from keepiq_sdk import Client

key = open("/run/secrets/keepiq.pem").read()
client = Client("https://cloud.example.org", "billing", key)
secret = client.get_by_name("stripe-key")
print(secret.key)
```

Methods: `get_by_name(name, folder=None)`, `get_by_id(id)`,
`list(updated_since=None)`, `create(fields)` and `update(id, fields)`. Fields
are `name`, `url` and `typeId` (sent as they are) and `key`, `login` and
`additionalFields` (encrypted first). Errors: `NotFoundError`,
`UnauthorizedError`, `NotModifiedError`, `AmbiguousNameError` (with
`candidates`), `KeyMismatchError` and `ApiError`.

Pass `certificate_pem=` to refuse any envelope encrypted to another certificate
before decryption. The application can read its own certificate at
`GET /api/v1/app/certificate` (with its access token) and pass that PEM here.

Tests: `python -m pytest` (or `python -m unittest discover -s tests`) in this
directory. They run against `sdk/testdata/`, shared with the Go and TypeScript
libraries.
