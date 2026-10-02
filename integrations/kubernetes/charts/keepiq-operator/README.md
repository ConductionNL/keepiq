# keepiq-operator

Get a Keepiq application secret into a pod with one resource and no scripting.
The operator decrypts inside your cluster, with an application key your cluster holds.

## Install

```sh
helm install keepiq-operator oci://ghcr.io/conductionnl/charts/keepiq-operator --version 0.1.0 --namespace shop
```

The operator watches only the namespace it runs in. Set `clusterWide=true` to watch every namespace.
Keep one Keepiq application per namespace or team, so a namespace reads only its own vault.

## Sync a secret

Store the application private key in a Kubernetes Secret, then point a connection at it:

```yaml
apiVersion: keepiq.conduction.nl/v1alpha1
kind: KeepiqConnection
metadata:
  name: shop
spec:
  url: https://cloud.example.org
  applicationId: shop-prod
  privateKeySecretRef: {name: keepiq-app-key, key: key.pem}
  # Optional: refuse anything encrypted to another certificate.
  certificateSecretRef: {name: keepiq-app-key, key: cert.pem}
---
apiVersion: keepiq.conduction.nl/v1alpha1
kind: KeepiqSecret
metadata:
  name: shop-db
spec:
  connectionRef: {name: shop}
  target: {name: shop-db}
  refreshInterval: 60s
  restartTargets:
    - {kind: Deployment, name: shop-api}
  items:
    - {name: db-password, field: key, targetKey: DB_PASSWORD}
    - {name: db-password, field: login, targetKey: DB_USER}
    - {name: db-password, field: additionalFields.host, targetKey: DB_HOST}
```

Secret `shop-db` then holds the three values. When someone rotates `db-password` in Keepiq, `shop-db` follows within one refresh interval.
Deployment `shop-api` rolls out new pods at that moment.

`kubectl get keepiqsecrets` shows `Ready` and a reason.
An unknown name, two secrets with one name, a refused key or a wrong certificate each leave the target alone and raise an event.
The event for two secrets with one name lists both ids and folders. Add `folder:` to the item to pick one.

No value ever lands in the resource status, an event or the operator log.

A Kubernetes Secret is readable by anyone with Secret read rights in the namespace.
Turn on etcd encryption at rest, or use the recipe below.

## Keep the value out of Kubernetes Secrets

For a workload that must not have its value in etcd, let the pod fetch it at start.
An init container copies the static `keepiq` CLI into the pod, and the container starts its command through `keepiq ci run`:

```yaml
apiVersion: v1
kind: Pod
metadata:
  name: shop-migrate
spec:
  volumes:
    - name: keepiq
      emptyDir: {}
    - name: keepiq-key
      secret:
        secretName: keepiq-app-key
        items: [{key: key.pem, path: key.pem}]
  initContainers:
    - name: keepiq-cli
      image: ghcr.io/conductionnl/keepiq-cli:0.3.0
      command: ["/usr/local/bin/keepiq", "install", "/keepiq/keepiq"]
      volumeMounts: [{name: keepiq, mountPath: /keepiq}]
  containers:
    - name: migrate
      image: registry.example.org/shop/migrate:1.4
      command: ["/keepiq/keepiq", "ci", "run", "db-password", "--", "./migrate.sh"]
      env:
        - {name: KEEPIQ_URL, value: https://cloud.example.org}
        - {name: KEEPIQ_APP_ID, value: shop-prod}
        - {name: KEEPIQ_APP_KEY_FILE, value: /keepiq-key/key.pem}
      volumeMounts:
        - {name: keepiq, mountPath: /keepiq, readOnly: true}
        - {name: keepiq-key, mountPath: /keepiq-key, readOnly: true}
```

`./migrate.sh` finds the value in `KEEPIQ_DB_PASSWORD`. It lives only in that process's environment; no Kubernetes Secret holds it.
The application key is still a Kubernetes Secret, mounted as a file.

## Next step

Register the Keepiq application for this namespace, put its key in `keepiq-app-key`, and apply the two resources above.
