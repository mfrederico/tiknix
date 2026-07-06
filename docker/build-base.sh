#!/usr/bin/env bash
# Build & push the tiknix-base image (PHP 8.3 + extensions precompiled).
# Run once, or whenever the PHP version / extension set changes. App deploys
# `FROM` this image so they never compile extensions.
#
# Requires: docker, and `docker login ghcr.io -u <user>` (a GitHub PAT with
# write:packages) beforehand. Prefer the "base-image" GitHub Action if you have
# no local docker.
#
#   ./docker/build-base.sh [tag]        # tag defaults to 8.3
#   TIKNIX_BASE_IMAGE=ghcr.io/you/tiknix-base ./docker/build-base.sh
set -euo pipefail

IMAGE="${TIKNIX_BASE_IMAGE:-ghcr.io/mfrederico/tiknix-base}"
TAG="${1:-8.3}"

cd "$(dirname "$0")/.."
docker build -f docker/base/Dockerfile -t "$IMAGE:$TAG" .
docker push "$IMAGE:$TAG"

echo
echo "pushed $IMAGE:$TAG"
echo "-> make the GHCR package PUBLIC so Hyperlift can pull it without credentials."
