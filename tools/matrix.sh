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
# A cell is PHP:LARAVEL[:GUZZLE]. Laravel 11 and 12 require Guzzle 7; Laravel 13
# allows 7 or 8 and resolves 8 unless the cell pins 7. Laravel 10's framework does
# not constrain Guzzle, so Composer admits Guzzle 8 there too: the package works on
# it (the 8.2:10:8 cell), but Laravel 10's own HTTP client calls RequestException
# methods Guzzle 8 removed, so a Laravel 10 application keeps `guzzlehttp/guzzle: ^7`
# in its own composer.json (its skeleton pins ^7.2) and the other Laravel 10 cells
# pin Guzzle 7 the same way. Every cell checks that the Guzzle major it expects is
# the one installed.
#
#   tools/matrix.sh                        # every supported cell
#   tools/matrix.sh 8.1:10 8.5:13 8.3:13:7 # a subset (php:laravel[:guzzle])
set -uo pipefail
cd "$(dirname "$0")/.."
cells=("$@")
if [ ${#cells[@]} -eq 0 ]; then
  cells=(8.1:10 8.2:10 8.2:10:8 8.2:11 8.3:10 8.3:11 8.3:12 8.4:11 8.4:12 8.5:12 8.3:13 8.4:13 8.5:13 8.3:13:7)
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
  IFS=: read -r php laravel guzzle <<< "$cell"
  case "$laravel" in
    10) testbench='^8.22' ;;
    11) testbench='^9.0' ;;
    12) testbench='^10.0' ;;
    13) testbench='^11.0' ;;
    *) echo "unknown Laravel major: $laravel"; exit 2 ;;
  esac
  case "$guzzle" in
    '') if [ "$laravel" = 10 ]; then guzzle_constraint='^7.5'; else guzzle_constraint=''; fi
        expect_guzzle=$([ "$laravel" = 13 ] && echo 8 || echo 7) ;;
    7) guzzle_constraint='^7.5'; expect_guzzle=7 ;;
    8) case "$laravel" in 11|12) echo "Laravel $laravel requires Guzzle 7 (guzzlehttp/guzzle ^7.8.2); no Guzzle 8 cell resolves"; exit 2 ;; esac
       guzzle_constraint='^8.0'; expect_guzzle=8 ;;
    *) echo "unknown Guzzle major: $guzzle"; exit 2 ;;
  esac
  label="PHP $php × Laravel $laravel × Guzzle $expect_guzzle"
  echo "=== $label (orchestra/testbench $testbench) ==="
  mounts=(-v "$PWD:/src/laravel:ro")
  [ -d "$PWD/../php-sdk" ] && mounts+=(-v "$PWD/../php-sdk:/src/php-sdk:ro")
  docker run --rm "${mounts[@]}" -v "$composer_bin:/usr/local/bin/composer:ro" -w /work/laravel -e TESTBENCH="$testbench" -e LARAVEL="$laravel" -e GUZZLE="$guzzle_constraint" -e EXPECT_GUZZLE="$expect_guzzle" -e DPAY_SDK_VCS="${DPAY_SDK_VCS:-}" "php:$php-cli" bash -ec '
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
    if [ -n "$GUZZLE" ]; then composer require --no-update --no-interaction "guzzlehttp/guzzle:$GUZZLE" >/dev/null; fi
    if [ "$LARAVEL" = "10" ] || [ "$LARAVEL" = "11" ]; then
      # Laravel 10/11 are past security support and carry published advisories; Composer 2.10
      # refuses to install them unless told so. The matrix installs them ON PURPOSE to prove the
      # package on the frameworks merchants still run — the advisories are theirs, not the package'"'"'s.
      composer config --json policy.advisories.ignore '"'"'["laravel/framework"]'"'"'
    fi
    composer update --no-interaction --no-progress --prefer-dist 2>&1 | grep -E "^(Installing|  - Installing (laravel/framework|orchestra/testbench|phpunit/phpunit|dpay/dpay-php|larastan/larastan|phpstan/phpstan) )" || true
    for pkg in laravel/framework orchestra/testbench phpunit/phpunit guzzlehttp/guzzle dpay/dpay-php larastan/larastan phpstan/phpstan; do printf "%s " "$pkg"; composer show "$pkg" 2>/dev/null | grep -E "^versions" | sed "s/versions *: *//"; done
    installed_guzzle="$(php -r "require \"vendor/autoload.php\"; echo GuzzleHttp\\ClientInterface::MAJOR_VERSION;")"
    if [ "$installed_guzzle" != "$EXPECT_GUZZLE" ]; then echo "expected Guzzle $EXPECT_GUZZLE, resolved Guzzle $installed_guzzle"; exit 1; fi
    composer validate --strict
    vendor/bin/phpunit | tail -2
    if [ "$LARAVEL" = "12" ] || [ "$LARAVEL" = "13" ]; then
      vendor/bin/phpstan analyse --memory-limit=1G --no-progress | tail -2
      vendor/bin/phpstan analyse --memory-limit=1G --no-progress -c phpstan.tests.neon | tail -2
      vendor/bin/pint --test 2>&1 | grep -v '"'"'^[[:space:]]*$'"'"' | tail -1
    fi
  '
  rc=$?
  echo "=== $label exit=$rc"
  [ $rc -ne 0 ] && status=1
done
echo "=== matrix exit=$status"
exit $status
