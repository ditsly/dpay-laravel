#!/usr/bin/env bash
# Sourced by the package matrix scripts. Extracts the `composer` binary (a
# PHAR runnable by any PHP) from the official `composer:2` image — content
# pinned by the image digest Docker verified on pull — so no cell ever runs an
# installer script fetched over the network. Cached under the parent of this
# script's directory: integrations/.cache in the monorepo, .cache/ in a
# published plugin repository (bin/composer-bin.sh).
#
#   composer_bin_from_image   → prints the host path of the binary; exit 1 on failure
composer_bin_from_image() {
  local image="${COMPOSER_IMAGE:-composer:2}"
  local cache_dir="${COMPOSER_BIN_CACHE:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.cache}"
  local digest bin cid
  docker image inspect "$image" >/dev/null 2>&1 || docker pull -q "$image" >/dev/null || return 1
  digest="$(docker image inspect --format '{{index .RepoDigests 0}}' "$image" 2>/dev/null | sed 's/.*@sha256://' | cut -c1-16)"
  [ -n "$digest" ] || digest="$(docker image inspect --format '{{.Id}}' "$image" | sed 's/sha256://' | cut -c1-16)"
  bin="$cache_dir/composer-$digest"
  if [ ! -s "$bin" ]; then
    mkdir -p "$cache_dir"
    cid="$(docker create "$image")" || return 1
    docker cp "$cid:/usr/bin/composer" "$bin.tmp" >/dev/null && chmod 0555 "$bin.tmp" && mv "$bin.tmp" "$bin"
    docker rm "$cid" >/dev/null
    [ -s "$bin" ] || return 1
  fi
  echo "$bin"
}
