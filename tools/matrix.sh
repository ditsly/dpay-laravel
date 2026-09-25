#!/usr/bin/env bash
# The Testbench matrix in Docker: php:X.Y-cli images, one cell per (PHP,
# Laravel) pair the framework supports. Composer is the binary of the official
# `composer:2` image, copied out once and mounted read-only into every cell —
# never an installer script fetched over the network and run unverified. The
# integrations/ tree (php-sdk + laravel) is mounted READ-ONLY and copied into
# the container without vendor/ so every cell resolves its own dependency set
# and the host tree is never touched. The SDK path repository resolves as
# ../php-sdk exactly as it does on the host. In the published repository there
# is no ../php-sdk: dpay/dpay-php resolves from Packagist, or from the git URL in
# DPAY_SDK_VCS (e.g. https://github.com/ditsly/dpay-php) when set.
#
#   tools/matrix.sh                 # every supported cell
#   tools/matrix.sh 8.1:10 8.5:12   # a subset (php:laravel)
set -uo pipefail
cd "$(dirname "$0")/.."
cells=("$@")
if [ ${#cells[@]} -eq 0 ]; then
  cells=(8.1:10 8.2:10 8.2:11 8.3:10 8.3:11 8.3:12 8.4:11 8.4:12 8.5:12)
fi
status=0
# The published repository ships its own copy in tools/; inside the monorepo it is
# integrations/tools/composer-bin.sh.
if [ -f "$PWD/tools/composer-bin.sh" ]; then
  # shellcheck source=composer-bin.sh
  . "$PWD/tools/composer-bin.sh"
else
  # shellcheck source=../../tools/composer-bin.sh
  . "$PWD/../tools/composer-bin.sh"
fi
composer_bin="$(composer_bin_from_image)" || { echo "could not obtain the composer binary from the composer:2 image"; exit 2; }
for cell in "${cells[@]}"; do
  php="${cell%%:*}"
  laravel="${cell##*:}"
  case "$laravel" in
    10) testbench='^8.22' ;;
    11) testbench='^9.0' ;;
    12) testbench='^10.0' ;;
    *) echo "unknown Laravel major: $laravel"; exit 2 ;;
  esac
  echo "=== PHP $php × Laravel $laravel (orchestra/testbench $testbench) ==="
  mounts=(-v "$PWD:/src/laravel:ro")
  [ -d "$PWD/../php-sdk" ] && mounts+=(-v "$PWD/../php-sdk:/src/php-sdk:ro")
  docker run --rm "${mounts[@]}" -v "$composer_bin:/usr/local/bin/composer:ro" -w /work/laravel -e TESTBENCH="$testbench" -e LARAVEL="$laravel" -e DPAY_SDK_VCS="${DPAY_SDK_VCS:-}" "php:$php-cli" bash -ec '
    set -o pipefail
    mkdir -p /work
    for pkg in php-sdk laravel; do
      [ -d /src/$pkg ] || continue
      mkdir -p /work/$pkg
      (cd /src/$pkg && tar --exclude=./vendor --exclude=./composer.lock --exclude=./.phpunit.cache --exclude=./.phpstan.cache --exclude=./.phpstan.tests.cache -cf - .) | tar -xf - -C /work/$pkg
    done
    cd /work/laravel
    php -v | head -1
    (apt-get update -qq && apt-get install -y -qq git unzip libsqlite3-dev libzip-dev) >/dev/null 2>&1
    docker-php-ext-install pdo_sqlite >/dev/null 2>&1 || true
    export COMPOSER_ROOT_VERSION=1.0.0
    composer --version 2>/dev/null | head -1
    if [ -n "$DPAY_SDK_VCS" ]; then composer config repositories.dpay-php vcs "$DPAY_SDK_VCS"; fi
    # Pin the Testbench major for this cell; illuminate/* follow it.
    composer require --dev --no-update --no-interaction "orchestra/testbench:$TESTBENCH" >/dev/null
    if [ "$LARAVEL" != "12" ]; then
      # Laravel 10/11 are past security support and carry published advisories; Composer 2.10
      # refuses to install them unless told so. The matrix installs them ON PURPOSE to prove the
      # package on the frameworks merchants still run — the advisories are theirs, not the package'"'"'s.
      composer config --json policy.advisories.ignore '"'"'["laravel/framework"]'"'"'
    fi
    composer update --no-interaction --no-progress --prefer-dist 2>&1 | grep -E "^(Installing|  - Installing (laravel/framework|orchestra/testbench|phpunit/phpunit|dpay/dpay-php|larastan/larastan|phpstan/phpstan) )" || true
    for pkg in laravel/framework orchestra/testbench phpunit/phpunit dpay/dpay-php larastan/larastan phpstan/phpstan; do printf "%s " "$pkg"; composer show "$pkg" 2>/dev/null | grep -E "^versions" | sed "s/versions *: *//"; done
    composer validate --strict
    vendor/bin/phpunit | tail -2
    if [ "$LARAVEL" = "12" ]; then
      vendor/bin/phpstan analyse --memory-limit=1G --no-progress | tail -2
      vendor/bin/phpstan analyse --memory-limit=1G --no-progress -c phpstan.tests.neon | tail -2
      vendor/bin/pint --test 2>&1 | tail -1
    fi
  '
  rc=$?
  echo "=== PHP $php × Laravel $laravel exit=$rc"
  [ $rc -ne 0 ] && status=1
done
echo "=== matrix exit=$status"
exit $status
