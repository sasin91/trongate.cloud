#!/bin/bash
# Build the platform images on this machine and push them to the cluster's own
# registry (registry.trongate.cloud/platform/*). The fallback: normally a push to
# main is built in the cluster (worker task build_platform) and rolled out by
# Flux image automation; see deploy/k8s/README.md, "Deploying the platform".
#
#   docker login registry.trongate.cloud -u push   # once; the registry's push user
#   bin/push-images.sh      # tags :main, :<short sha> and :main-<commit time>-<short sha>
#
# Needs Docker with BuildKit (Docker Desktop, or Docker Engine in WSL). Always
# linux/amd64: the cluster is x86. Flux picks up the main-<commit time>-<sha>
# tag like a cluster build, as long as this commit is newer than the one running.
set -euo pipefail

REGISTRY="${REGISTRY:-registry.trongate.cloud/platform}"
PLATFORM="linux/amd64"

cd "$(dirname "$0")/.."

if [ -n "$(git status --porcelain)" ]; then
    echo "Working tree has uncommitted changes; commit or stash them first so the" >&2
    echo "image matches a commit." >&2
    exit 1
fi

sha="$(git rev-parse --short=7 HEAD)"
# Same scheme as modules/platform/tasks/build_platform.php: commit time in UTC
committed="$(TZ=UTC git show -s --format=%cd --date=format-local:%Y%m%d%H%M%S HEAD)"

build() {
    local image="$1" dockerfile="$2"
    echo "==> ${REGISTRY}/${image} (${sha})"
    docker buildx build \
        --platform "$PLATFORM" \
        --file "$dockerfile" \
        --tag "${REGISTRY}/${image}:main" \
        --tag "${REGISTRY}/${image}:${sha}" \
        --tag "${REGISTRY}/${image}:main-${committed}-${sha}" \
        --label "org.opencontainers.image.revision=$(git rev-parse HEAD)" \
        --push \
        .
}

build trongate-cloud Dockerfile
build trongate-cloud-worker Dockerfile.worker

echo "Pushed :main, :${sha} and :main-${committed}-${sha}."
