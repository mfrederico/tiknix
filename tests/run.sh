#!/usr/bin/env bash
#
# Run the unit suite (tests/unit/, phpunit.xml at the repo root).
#
#   tests/run.sh                          everything
#   tests/run.sh ConceptToolsTest         one class (name or path)
#   tests/run.sh --filter testName        one test, or any other phpunit option
#   tests/run.sh --testdox                readable listing instead of dots
#
# Touches nothing live: the tests build throwaway installs under /tmp and use an
# in-memory SQLite. Safe to run on the working tree that serves the site.
#
# error_reporting=0 is the PHP 8.5 / PHPUnit 9.6 combination: the vendored PHPUnit
# raises deprecation notices, and failOnWarning in phpunit.xml would count them as
# failures. The tests' own assertions are unaffected.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHPUNIT="$ROOT/vendor/bin/phpunit"

if [[ ! -x "$PHPUNIT" ]]; then
    echo "tests/run.sh: $PHPUNIT is missing. Run: php -d error_reporting=0 /usr/bin/composer install" >&2
    exit 2
fi

args=()
for a in "$@"; do
    # A bare class name resolves to its file; anything else passes straight through.
    if [[ "$a" != -* && -f "$ROOT/tests/unit/$a.php" ]]; then
        args+=("$ROOT/tests/unit/$a.php")
    else
        args+=("$a")
    fi
done

cd "$ROOT"
exec php -d error_reporting=0 "$PHPUNIT" "${args[@]}"
