<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Changelog of keepiq-sdk for Python

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the library uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Releases are tagged `sdk-py-vX.Y.Z`.

## [1.0.0]

### Changed

- The client is built from one configuration object. `Client` takes a `ClientConfig`, which holds the Nextcloud address, a `Credentials`, the request timeout and the clock. `Credentials` holds the application id, the private key and, optionally, the certificate. Both are frozen dataclasses, and the `repr()` of `Credentials` leaves out the private key and the certificate.
- The old form `Client(url, application_id, private_key_pem, certificate_pem=..., timeout=..., clock=...)`, with positional or keyword arguments, raises a `TypeError` that names the new one, as do `Client()` and extra arguments after a `ClientConfig`.

Nothing else changed: the five methods, their errors, the requests sent and the checks on the certificate are the same as in 0.1.0.

### Upgrading from 0.1.0

```python
from keepiq_sdk import Client, ClientConfig, Credentials

# 0.1.0
client = Client(url, "billing", key, certificate_pem=cert, timeout=10)

# 1.0.0
client = Client(ClientConfig(url, Credentials("billing", key, cert), timeout=10))
```

## [0.1.0]

- First release: read, list, create and update an application's secrets, decrypted and encrypted in your process.
