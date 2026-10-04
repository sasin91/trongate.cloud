#!/bin/bash
# Build and push the benchmark images (app with stock and lazy sessions, and wrk), amd64 only.
#   docker login registry.trongate.cloud -u push   # once
#   bench/push-images.sh
set -euo pipefail
REGISTRY="${REGISTRY:-registry.trongate.cloud/platform}"
cd "$(dirname "$0")/.."
for s in stock lazy; do
  docker buildx build --platform linux/amd64 -f bench/Dockerfile --build-arg SESSION_START=$s \
    -t "$REGISTRY/bench:$s" --push .
done
docker buildx build --platform linux/amd64 -f bench/wrk.Dockerfile -t "$REGISTRY/bench-wrk:latest" --push .
