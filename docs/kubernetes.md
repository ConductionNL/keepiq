<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
-->

# Kubernetes

Get a Keepiq application secret into a pod with one resource and no scripting.
The Keepiq operator decrypts inside your cluster, with an application key your cluster holds.
The Keepiq server only ever sends ciphertext.

## Install the operator

```sh
helm install keepiq-operator oci://ghcr.io/conductionnl/charts/keepiq-operator --version 0.1.0 --namespace shop
```

The operator watches the namespace it runs in. Give each team or namespace its own Keepiq application, so it reads only its own vault.

## Sync a secret into a Kubernetes Secret

Put the application's private key in a Kubernetes Secret, then create two resources:

```yaml
apiVersion: keepiq.conduction.nl/v1alpha1
kind: KeepiqConnection
metadata:
  name: shop
spec:
  url: https://cloud.example.org
  applicationId: shop-prod
  privateKeySecretRef: {name: keepiq-app-key, key: key.pem}
---
apiVersion: keepiq.conduction.nl/v1alpha1
kind: KeepiqSecret
metadata:
  name: shop-db
spec:
  connectionRef: {name: shop}
  target: {name: shop-db}
  restartTargets:
    - {kind: Deployment, name: shop-api}
  items:
    - {name: db-password, field: key, targetKey: DB_PASSWORD}
```

Secret `shop-db` now holds `DB_PASSWORD`.
Rotate `db-password` in Keepiq and `shop-db` follows within a minute. Deployment `shop-api` restarts with the new value.

A `field` is `key`, `login` or `additionalFields.<name>`. Set `refreshInterval` to poll more or less often; the minimum is 10 seconds.

## When something is wrong

`kubectl get keepiqsecrets` shows `Ready` and a reason. The target Secret stays as it was.

| Reason | What to do |
|---|---|
| `SecretNotFound` | Check the name, or file the secret in the application's vault. |
| `AmbiguousName` | Two secrets share the name. The event lists both; add `folder:` to the item or rename one. |
| `TokenRefused` | Check the application id, its approval, and the key. |
| `FingerprintMismatch` | The key does not belong to the certificate. Put the right key in the Secret. |

`FingerprintMismatch` needs the certificate: set `certificateSecretRef` on the `KeepiqConnection`.
To get the certificate, call `GET /api/v1/app/certificate` with the application's access token. The answer holds the PEM and its fingerprint.

No value ever lands in the resource, an event or the operator log.

## Keep the value out of Kubernetes Secrets

A Kubernetes Secret is readable by anyone with Secret read rights in the namespace.
For a workload that must not keep its value in etcd, let the pod fetch it when it starts:
an init container copies the `keepiq` CLI into the pod, and the container starts through `keepiq ci run`.
The chart README holds the full pod template.

## Next step

Register a Keepiq application for your namespace and install the operator with the command above.
