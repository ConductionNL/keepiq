<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Client libraries

Read and write your application's secrets from Go, Python or TypeScript in a few lines.
Each library decrypts in your own process. Only ciphertext and a signed assertion cross the network.

You need an approved Keepiq application and its private key.
See [OpenConnector integration](./integration-openconnector.md) for how an application is registered and approved.

## Python

Install `keepiq-sdk` (it needs only `cryptography`):

```python
from keepiq_sdk import Client

key = open("/run/secrets/keepiq.pem").read()
client = Client("https://cloud.example.org", "billing", key)
secret = client.get_by_name("stripe-key")
print(secret.key)
```

## TypeScript and JavaScript

Install `@conduction/keepiq-sdk`. It runs on Node 20 and later and in browsers, with no runtime dependency.

```ts
import { Client } from '@conduction/keepiq-sdk'

const client = new Client('https://cloud.example.org', 'billing', process.env.KEEPIQ_APP_KEY!)
const secret = await client.getByName('stripe-key')
await client.update(secret.id, { key: 'the new value' })
```

## Go

```go
import keepiq "github.com/ConductionNL/keepiq/sdk/go"

c, err := keepiq.New("https://cloud.example.org", "billing", pemString)
s, err := c.GetByName("stripe-key", "")
fmt.Println(s.Key)
```

The Go library uses only the standard library. The `keepiq` command-line client is built on it.

## What every library does

The three libraries offer the same calls:

| Call | What it does |
|---|---|
| get by name | Reads the secret with exactly this name. Pass a folder path to narrow it. |
| get by id | Reads one secret by its id. |
| list | Reads every secret, or only the ones changed after a given moment. |
| create | Files a new secret. `name` and `key` are required. |
| update | Replaces the fields you name and leaves the others alone. |

Values go in `key`, `login` and `additionalFields`. The library encrypts them with your own key before sending.
`name`, `url` and `typeId` travel as they are, so keep secrets out of them.

Each library finds the instance through the discovery document and signs the token request with your key.
It keeps the token until it expires and asks for a new one when the server refuses the old one.

A second read of an unchanged secret answers "not modified", so a polling job does no work.
When your instance hands out leases, every read carries the lease id and its expiry.

Two secrets with the same name raise an "ambiguous name" error.
It lists both ids and folder paths, so you can pick one with a folder path or rename one.

Pass your application's certificate as well, and the library checks it against your key at start.
After that it refuses any secret encrypted to another certificate, before it decrypts anything.

## Base URL

Use the address you open Nextcloud on. When your instance has no pretty URLs, add `/index.php`.

## Proof that they agree

All three libraries, the command-line client and the server share one set of test files in `sdk/testdata/`.
Every library decrypts what the server and the browser wrote.
The server decrypts what every library wrote.
When one implementation drifts, its tests fail.

## Next step

Bring secrets into a pipeline with the [CI integrations](./ci-integrations.md).
