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

# PHP tests (parallel via paratest). No JS tests in this plugin.
echo "Running PHP tests..."
$DC exec -T wordpress bash -c \
    "cd /var/www/plugins/$PLUGIN_DIR && vendor/bin/paratest \
        --processes ${PARATEST_PROCS:-6} \
        --parallel-suite"
