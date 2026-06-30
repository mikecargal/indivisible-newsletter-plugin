#!/bin/bash
# Run PHP tests for Indivisible Newsletter.
# Executes inside the Docker container via docker-compose.
set -e
cd "$(dirname "$0")"

PLUGIN_DIR="$(basename "$PWD")"
WORKSPACE_DIR="${WORKSPACE_DIR:-$(cd ../dev_wordpress_claude 2>/dev/null && pwd)}"

if [ -z "$WORKSPACE_DIR" ] || [ ! -f "$WORKSPACE_DIR/docker-compose.yml" ]; then
    echo "ERROR: Cannot find dev_wordpress_claude workspace (needed for Docker)." >&2
    echo "  Expected at: ../dev_wordpress_claude" >&2
    exit 1
fi

DC="docker-compose -f $WORKSPACE_DIR/docker-compose.yml"
WRAPPER="$WORKSPACE_DIR/test-run-wrapper.sh"

# Use the wrapper for run-scoped database isolation if available;
# fall back to direct docker-compose exec for backward compatibility.
run_in_container() {
    local procs=$1; shift
    if [ -x "$WRAPPER" ]; then
        "$WRAPPER" "$procs" "$PLUGIN_DIR" "$@"
    else
        $DC exec -T wordpress bash -c "cd /var/www/plugins/$PLUGIN_DIR && $*"
    fi
}

# PHP tests (parallel via paratest).
echo "Running PHP tests..."
run_in_container "${PARATEST_PROCS:-10}" \
    "vendor/bin/paratest --processes ${PARATEST_PROCS:-10} --parallel-suite --coverage-clover=.phpunit.cache/test-coverage.xml"

# JS tests (CON10 B1 introduced the plugin's first JS — the Reprocess confirm modal).
echo "Running JS tests..."
run_in_container -1 "npx jest --no-cache --coverage"
