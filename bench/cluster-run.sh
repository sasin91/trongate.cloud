#!/bin/bash
# Runs the Trongate benchmark in a throwaway "bench" namespace and deletes it afterwards.
# Needs kubectl for the cluster and the images from bench/push-images.sh.
#   bench/cluster-run.sh                   # in-cluster: wrk Job -> Service -> app pod
#   GATEWAY_URL=http://<gateway svc>.<ns>.svc GATEWAY_NAME=... GATEWAY_NAMESPACE=... \
#   GATEWAY_SECTION=... BENCH_HOST=bench.trongate.dev bench/cluster-run.sh   # also via the gateway
# Results land in bench/results/<UTC timestamp>/ (raw wrk output + summary.csv).
set -euo pipefail
cd "$(dirname "$0")"
REGISTRY="${REGISTRY:-registry.trongate.cloud/platform}"
export WRK_IMAGE="$REGISTRY/bench-wrk:latest"
OUT="results/$(date -u +%Y%m%dT%H%M%SZ)"; mkdir -p "$OUT"
KEEP="${KEEP:-0}"
cleanup() { [ "$KEEP" = 1 ] || kubectl delete namespace bench --wait=false >/dev/null 2>&1 || true; }
trap cleanup EXIT

kubectl apply -f k8s/base.yaml >/dev/null
kubectl -n bench rollout status deploy/valkey --timeout=180s >/dev/null

# app <session_start> <cpu> <log_level> <files|valkey>
app() {
  export IMAGE="$REGISTRY/bench:$1" CPU="$2" LOG_LEVEL="$3" SESSION_HANDLER=files SESSION_PATH=""
  if [ "$4" = valkey ]; then SESSION_HANDLER=redis; SESSION_PATH="tcp://valkey.bench.svc:6379?persistent=1"; fi
  envsubst < k8s/app.yaml | kubectl apply -f - >/dev/null
  kubectl -n bench rollout status deploy/app --timeout=300s >/dev/null
  kubectl -n bench exec deploy/valkey -- valkey-cli flushall >/dev/null
}

# load <case name> <path> <nocookie|users> [url base] [host]
load() {
  local name="$1" base="${4:-http://app.bench.svc}"
  export NAME="wrk-$(echo "$name" | tr '_.' '--')" URL="$base$2" HOST_HEADER="${5:-app.bench.svc}" LUA_FLAG=""
  [ "$3" = users ] && LUA_FLAG="-s /scripts/users.lua"
  envsubst < k8s/wrk.yaml | kubectl apply -f - >/dev/null
  kubectl -n bench wait --for=condition=complete "job/$NAME" --timeout=300s >/dev/null \
    || { echo "$name: wrk job failed"; kubectl -n bench logs "job/$NAME" | tail -5; return 0; }
  kubectl -n bench logs "job/$NAME" > "$OUT/$name.txt"
  local node; node=$(kubectl -n bench get pod -l app=bench-app -o jsonpath='{.items[0].spec.nodeName}')
  awk -v c="$name" -v n="$node" '/^== run/{r=$3} /^ +50%/{p50=$2} /^ +99%/{p99=$2} /Requests\/sec/{print c","r","$2","p50","p99","n}' "$OUT/$name.txt" | tee -a "$OUT/summary.csv"
  echo "$name sessions_in_valkey=$(kubectl -n bench exec deploy/valkey -- valkey-cli dbsize)" >> "$OUT/notes.txt"
  kubectl -n bench delete job "$NAME" --wait=false >/dev/null
}

echo "case,run,rps,p50,p99,app_node" | tee "$OUT/summary.csv"

# 1. As customer apps run today: 0.5 CPU, Caddy DEBUG logging, file sessions
app stock 500m DEBUG files
load today_0.5cpu_raw /raw.php nocookie
load today_0.5cpu_users /bench users
load today_0.5cpu_nocookie /bench nocookie

# 2. CPU sweep with Caddy at INFO
for cpu in 500m 1 2; do
  app stock $cpu INFO files
  load info_${cpu}cpu_raw /raw.php nocookie
  load info_${cpu}cpu_users /bench users
done

# 3. Session matrix at 1 CPU, INFO
for store in files valkey; do for s in stock lazy; do
  app $s 1 INFO $store
  load sess_${store}_${s}_nocookie /bench nocookie
  load sess_${store}_${s}_users /bench users
done; done

# 4. Optional: the same app through the gateway (ingress path)
if [ -n "${GATEWAY_URL:-}" ]; then
  envsubst < k8s/route.yaml | kubectl apply -f - >/dev/null
  app stock 1 INFO files; sleep 5
  load gateway_1cpu_raw /raw.php nocookie "$GATEWAY_URL" "$BENCH_HOST"
  load gateway_1cpu_users /bench users "$GATEWAY_URL" "$BENCH_HOST"
fi

echo "Results: bench/$OUT"
