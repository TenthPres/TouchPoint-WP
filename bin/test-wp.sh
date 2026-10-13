#!/usr/bin/env bash
#
# Run the tests that run within WordPress (phpunit.wordpress.xml), using a temporary database.
#
# If TP_TESTS_DB_HOST is set (as on GitHub Actions), that database is used, and nothing is started.  Otherwise, a
# MariaDB server is started just for the run, on a socket (so it isn't reachable from the network), with its data in a
# temporary folder.  The server is stopped, and the folder is deleted, when the tests finish, however they finish.
#
# Anything after the script's name is passed to PHPUnit.  For example:
#   bin/test-wp.sh --filter Meeting_GroupingWriter
#   bin/test-wp.sh --exclude-group known-issue
#
# Needs, on Debian and Ubuntu (including WSL):
#   sudo apt-get install --no-install-recommends php-mysql php-mbstring php-xml php-curl php-zip mariadb-server-core mariadb-client-core
# (Use the PHP version's own packages, like php8.4-mysql, if there is more than one PHP.)
#
# WordPress's test library empties the tables of the database it's given.  The temporary database is only for that.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

if [ ! -f vendor/autoload.php ]; then
    echo "Run \`composer install\` first." >&2
    exit 1
fi

if [ -n "${TP_TESTS_DB_HOST:-}" ]; then
    exec vendor/bin/phpunit -c phpunit.wordpress.xml "$@"
fi

MARIADBD="$(command -v mariadbd || true)"
[ -z "$MARIADBD" ] && [ -x /usr/sbin/mariadbd ] && MARIADBD=/usr/sbin/mariadbd
INSTALL_DB="$(command -v mariadb-install-db || true)"

if [ -z "$MARIADBD" ] || [ -z "$INSTALL_DB" ] || ! command -v my_print_defaults >/dev/null 2>&1; then
    echo "MariaDB's server wasn't found, or isn't all there.  Install it (about 45 MB, and it doesn't start by itself) with:" >&2
    echo "  sudo apt-get install --no-install-recommends mariadb-server-core mariadb-client-core" >&2
    exit 1
fi

if ! php -r 'exit(extension_loaded("mysqli") ? 0 : 1);'; then
    echo "PHP's mysqli extension wasn't found.  Install it with:" >&2
    echo "  sudo apt-get install --no-install-recommends php-mysql" >&2
    exit 1
fi

DIR="$(mktemp -d "${TMPDIR:-/tmp}/tp-wp-tests-XXXXXX")"
SERVER_PID=""

cleanup() {
    if [ -n "$SERVER_PID" ] && kill -0 "$SERVER_PID" 2>/dev/null; then
        kill "$SERVER_PID" 2>/dev/null || true
        # Give it a few seconds to finish cleanly, and then insist.
        for _ in $(seq 1 50); do
            kill -0 "$SERVER_PID" 2>/dev/null || break
            sleep 0.1
        done
        kill -9 "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi
    rm -rf "$DIR"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

SOCKET="$DIR/db.sock"

"$INSTALL_DB" --no-defaults --datadir="$DIR/data" --auth-root-authentication-method=normal --skip-test-db \
    >"$DIR/install.log" 2>&1 || { cat "$DIR/install.log" >&2; exit 1; }

"$MARIADBD" --no-defaults \
    --datadir="$DIR/data" \
    --socket="$SOCKET" \
    --pid-file="$DIR/db.pid" \
    --skip-networking \
    --skip-log-bin \
    --character-set-server=utf8mb4 \
    --innodb-buffer-pool-size=64M \
    --innodb-flush-log-at-trx-commit=0 \
    --max-connections=20 \
    >"$DIR/server.log" 2>&1 &
SERVER_PID=$!

READY=0
for _ in $(seq 1 100); do
    if php -r 'exit(@(new mysqli("localhost", "root", "", "", 0, $argv[1]))->connect_errno ? 1 : 0);' "$SOCKET" 2>/dev/null; then
        READY=1
        break
    fi
    kill -0 "$SERVER_PID" 2>/dev/null || break
    sleep 0.2
done

if [ "$READY" -ne 1 ]; then
    echo "The temporary database didn't start.  Its log:" >&2
    cat "$DIR/server.log" >&2
    exit 1
fi

php -r '
    $m = new mysqli("localhost", "root", "", "", 0, $argv[1]);
    $m->query("CREATE DATABASE IF NOT EXISTS touchpoint_wp_tests CHARACTER SET utf8mb4");
    exit($m->errno ? 1 : 0);
' "$SOCKET"

export TP_TESTS_DB_HOST="localhost:$SOCKET"
export TP_TESTS_DB_USER=root
export TP_TESTS_DB_PASSWORD=""

# Not exec, so that the cleanup runs afterward.
set +e
vendor/bin/phpunit -c phpunit.wordpress.xml "$@"
STATUS=$?
exit "$STATUS"
