# @conduction/keepiq-sdk

Read and write your application's Keepiq secrets from Node 20 or later, or a
browser. Values are decrypted and encrypted in your process with WebCrypto; only
ciphertext and a signed assertion cross the network. No runtime dependency.

```ts
import { Client } from '@conduction/keepiq-sdk'

const client = new Client('https://cloud.example.org', 'billing', process.env.KEEPIQ_APP_KEY!)
const secret = await client.getByName('stripe-key')
await client.update(secret.id, { key: 'the new value' })
```

Methods: `getByName(name, folder?)`, `getById(id)`, `list(updatedSince?)`,
`create(fields)` and `update(id, fields)`. Fields are `name`, `url` and
`typeId` (sent as they are) and `key`, `login` and `additionalFields`
(encrypted first). Errors: `NotFoundError`, `UnauthorizedError`,
`NotModifiedError`, `AmbiguousNameError` (with `candidates`),
`KeyMismatchError` and `ApiError`.

Pass `{ certificatePem }` as the fourth argument to refuse any envelope
encrypted to another certificate before decryption. The application can read
its own certificate at `GET /api/v1/app/certificate` (with its access token)
and pass that PEM here.

Tests: `npm install && npm test` in this directory. They run against
`sdk/testdata/`, shared with the Go and Python libraries.
