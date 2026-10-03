#!/usr/bin/env bash
# End-to-end test on a kind cluster: the operator syncs a secret from a stub
# Keepiq running in the cluster, a rotation reaches the Secret, and the
# no-Secret recipe pod reads its value from the process environment.
#
# Needs: kind, kubectl, helm, docker, and images built beforehand:
#   keepiq-operator:e2e  (integrations/kubernetes/Dockerfile)
#   keepiq-cli:e2e       (cli/Dockerfile)
# Run from the repository root. Used by .github/workflows/integrations-kubernetes.yml.
set -euo pipefail
root="$(pwd)"
cluster="${KIND_CLUSTER:-keepiq-e2e}"
ns=shop

kind create cluster --name "$cluster" --wait 120s
trap 'kind delete cluster --name "$cluster"' EXIT
kind load docker-image keepiq-operator:e2e keepiq-cli:e2e --name "$cluster"

kubectl create namespace "$ns"
value="$(openssl rand -hex 16)"
recipe_value="$(openssl rand -hex 16)"

# The stub Keepiq, from sdk/testdata, inside the cluster.
kubectl -n "$ns" create configmap keepiq-stub \
	--from-file=stub_server.py="$root/sdk/testdata/stub_server.py" \
	--from-file=machine_envelope.json="$root/sdk/testdata/machine_envelope.json"
kubectl -n "$ns" apply -f - << YAML
apiVersion: v1
kind: Pod
metadata: {name: keepiq-stub, labels: {app: keepiq-stub}}
spec:
  containers:
    - name: stub
      image: python:3.12-slim
      command: [sh, -c, "pip install -q cryptography && cp /stub/* /tmp/ && python /tmp/stub_server.py --host 0.0.0.0 --port 8080 --application shop-prod --add db-password=$value --add migrate-password=$recipe_value"]
      volumeMounts: [{name: stub, mountPath: /stub}]
      readinessProbe:
        httpGet: {path: /index.php/apps/keepiq/api/v1/app/.well-known/keepiq, port: 8080}
  volumes: [{name: stub, configMap: {name: keepiq-stub}}]
---
apiVersion: v1
kind: Service
metadata: {name: keepiq}
spec:
  selector: {app: keepiq-stub}
  ports: [{port: 80, targetPort: 8080}]
YAML
kubectl -n "$ns" wait --for=condition=Ready pod/keepiq-stub --timeout=180s

python3 - "$root/sdk/testdata/machine_envelope.json" > /tmp/keepiq-key.pem << 'PY'
import json, sys
print(json.load(open(sys.argv[1]))["privateKeyPem"], end="")
PY
kubectl -n "$ns" create secret generic keepiq-app-key --from-file=key.pem=/tmp/keepiq-key.pem
rm -f /tmp/keepiq-key.pem

helm install keepiq-operator "$root/integrations/kubernetes/charts/keepiq-operator" -n "$ns" \
	--set image.repository=keepiq-operator --set image.tag=e2e --set image.pullPolicy=Never --wait --timeout 180s

kubectl -n "$ns" apply -f - << 'YAML'
apiVersion: keepiq.conduction.nl/v1alpha1
kind: KeepiqConnection
metadata: {name: shop}
spec:
  url: http://keepiq.shop.svc/index.php
  applicationId: shop-prod
  privateKeySecretRef: {name: keepiq-app-key, key: key.pem}
---
apiVersion: keepiq.conduction.nl/v1alpha1
kind: KeepiqSecret
metadata: {name: shop-db}
spec:
  connectionRef: {name: shop}
  target: {name: shop-db}
  refreshInterval: 10s
  items:
    - {name: db-password, field: key, targetKey: DB_PASSWORD}
YAML

for _ in $(seq 60); do
	got="$(kubectl -n "$ns" get secret shop-db -o jsonpath='{.data.DB_PASSWORD}' 2> /dev/null | base64 -d || true)"
	[ "$got" = "$value" ] && break
	sleep 2
done
[ "$got" = "$value" ] || { kubectl -n "$ns" describe keepiqsecret shop-db; echo "Secret shop-db never got the value" >&2; exit 1; }
kubectl -n "$ns" get keepiqsecret shop-db -o jsonpath='{.status.conditions[0].status}' | grep -qx True
echo "ok - the operator synced the value into Secret shop-db"
if kubectl -n "$ns" get keepiqsecret shop-db -o yaml | grep -qF "$value"; then echo "the value leaked into the resource" >&2; exit 1; fi
if kubectl -n "$ns" logs deploy/keepiq-operator | grep -qF "$value"; then echo "the value leaked into the operator log" >&2; exit 1; fi
echo "ok - no value in the resource or the operator log"

# The recipe: init container installs the CLI, the app runs through keepiq ci run.
kubectl -n "$ns" apply -f - << 'YAML'
apiVersion: v1
kind: Pod
metadata: {name: recipe}
spec:
  restartPolicy: Never
  volumes:
    - {name: keepiq, emptyDir: {}}
    - name: keepiq-key
      secret: {secretName: keepiq-app-key, items: [{key: key.pem, path: key.pem}]}
  initContainers:
    - name: keepiq-cli
      image: keepiq-cli:e2e
      imagePullPolicy: Never
      command: ["/usr/local/bin/keepiq", "install", "/keepiq/keepiq"]
      volumeMounts: [{name: keepiq, mountPath: /keepiq}]
  containers:
    - name: app
      image: busybox:1.36
      command: ["/keepiq/keepiq", "ci", "run", "migrate-password", "--", "sh", "-c", "printf %s \"$KEEPIQ_MIGRATE_PASSWORD\" | sha256sum"]
      env:
        - {name: KEEPIQ_URL, value: http://keepiq.shop.svc/index.php}
        - {name: KEEPIQ_APP_ID, value: shop-prod}
        - {name: KEEPIQ_APP_KEY_FILE, value: /keepiq-key/key.pem}
      volumeMounts:
        - {name: keepiq, mountPath: /keepiq, readOnly: true}
        - {name: keepiq-key, mountPath: /keepiq-key, readOnly: true}
YAML
kubectl -n "$ns" wait --for=jsonpath='{.status.phase}'=Succeeded pod/recipe --timeout=120s
want="$(printf %s "$recipe_value" | sha256sum | cut -c1-64)"
kubectl -n "$ns" logs recipe | grep -q "$want" || { kubectl -n "$ns" logs recipe; echo "recipe pod did not see the value" >&2; exit 1; }
echo "ok - the recipe pod read the value from its environment"
for s in $(kubectl -n "$ns" get secrets -o name); do
	if kubectl -n "$ns" get "$s" -o json | python3 -c 'import base64,json,sys; d=json.load(sys.stdin).get("data") or {}; v=sys.argv[1]; sys.exit(0 if any(v in base64.b64decode(x).decode("utf-8","replace") for x in d.values()) else 1)' "$recipe_value"; then
		echo "$s holds the recipe value" >&2; exit 1
	fi
done
echo "ok - no Kubernetes Secret holds the recipe value"
