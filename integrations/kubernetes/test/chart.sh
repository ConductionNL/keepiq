#!/usr/bin/env bash
# helm lint and helm template snapshot tests for the chart.
#   integrations/kubernetes/test/chart.sh          compare with the snapshots
#   integrations/kubernetes/test/chart.sh --update rewrite them
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
chart="$here/../charts/keepiq-operator"
snap="$here/snapshots"
mkdir -p "$snap"
helm lint "$chart"
helm lint "$chart" --set clusterWide=true
status=0
for case in default cluster-wide; do
	args=(--namespace shop)
	[ "$case" = cluster-wide ] && args+=(--set clusterWide=true --set replicaCount=2)
	out="$(helm template keepiq-operator "$chart" "${args[@]}")"
	if [ "${1:-}" = "--update" ]; then
		printf '%s\n' "$out" > "$snap/$case.yaml"
	elif ! diff -u "$snap/$case.yaml" <(printf '%s\n' "$out"); then
		echo "snapshot $case differs; run with --update after checking the diff" >&2
		status=1
	fi
done
# Namespaced by default: a Role and a RoleBinding, and --watch-namespace.
grep -q '^kind: Role$' "$snap/default.yaml"
grep -q -- '--watch-namespace=shop' "$snap/default.yaml"
! grep -q 'kind: ClusterRole' "$snap/default.yaml"
# Cluster-wide on request: a ClusterRole, no namespace restriction, leader election.
grep -q '^kind: ClusterRole$' "$snap/cluster-wide.yaml"
! grep -q -- '--watch-namespace' "$snap/cluster-wide.yaml"
grep -q -- '--leader-elect' "$snap/cluster-wide.yaml"
exit $status
