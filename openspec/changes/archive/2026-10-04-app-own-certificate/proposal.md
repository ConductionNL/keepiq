---
kind: code
---

# An application reads its own certificate

## Why

The client libraries and the Kubernetes operator (keepiq#776, #777) check every envelope's `certificateFingerprint` against the application's certificate before they decrypt. A private key cannot produce its certificate, so today the operator has to configure the certificate next to the key, or the check does not run. Ruben's decision of 2 Oct: keep the certificate optional in the clients, and give an application a way to fetch its own certificate from the server.

## What Changes

- `GET /api/v1/app/certificate` on the Bearer machine surface returns the calling application's active certificate (public), its suite id and the `sha256:` fingerprint, computed exactly as the envelope's `certificateFingerprint`.
- The discovery document names the path under `certificate`.
- The Go, Python and TypeScript library docs and the Kubernetes docs say how to use it.

## Capabilities

### Modified Capabilities

- `secret-store-api`: an application can read its own certificate and fingerprint.

## Impact

- **Backend**: one controller (`ApplicationCertificateController`), one route, one discovery field.
- **Security**: the endpoint returns only the public certificate of the calling application's own active suite. JwtAuthMiddleware binds the application before the controller runs. No private material, no other application's certificate.
- **Database**: none.
