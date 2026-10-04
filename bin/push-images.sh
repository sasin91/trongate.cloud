#!/bin/bash
# Build the platform images and push them to the cluster's own registry
# (registry.trongate.cloud/platform/*), which deploy/k8s/platform/kustomization.yaml
# pulls from. Built locally on purpose rather than in CI.
#
#   docker login registry.trongate.cloud -u push   # once; the registry's push user
#   bin/push-images.sh                             # tags :main and :<short sha>
#
# Needs Docker with BuildKit (Docker Desktop, or Docker Engine in WSL). Always
# linux/amd64: the cluster is x86. After pushing, restart the Deployments so
# they pull the new :main:
#   kubectl -n trongate-cloud rollout restart deploy/trongate-cloud-app deploy/trongate-cloud-worker
set -euo pipefail

REGISTRY="${REGISTRY:-registry.trongate.cloud/platform}"
PLATFORM="linux/amd64"

cd "$(dirname "$0")/.."

if [ -n "$(git status --porcelain)" ]; then
    echo "Working tree has uncommitted changes; commit or stash them first so the" >&2
    echo "image matches a commit." >&2
    exit 1
fi

sha="$(git rev-parse --short HEAD)"

build() {
    local image="$1" dockerfile="$2"
    echo "==> ${REGISTRY}/${image} (${sha})"
    docker buildx build \
        --platform "$PLATFORM" \
        --file "$dockerfile" \
        --tag "${REGISTRY}/${image}:main" \
        --tag "${REGISTRY}/${image}:${sha}" \
        --label "org.opencontainers.image.revision=$(git rev-parse HEAD)" \
        --push \
        .
}

build trongate-cloud Dockerfile
build trongate-cloud-worker Dockerfile.worker

echo "Pushed :main and :${sha}."
