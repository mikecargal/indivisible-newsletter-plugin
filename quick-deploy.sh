#!/bin/bash

# Quick Deploy - Fast deployment to WordPress for testing
# This is a simplified version of deploy.sh for rapid iteration

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="$SCRIPT_DIR/src"
DEST_DIR="$SCRIPT_DIR/../dev_wordpress_claude/wordpress/wp-content/plugins/indivisible-newsletter"

source "$SCRIPT_DIR/../dev_wordpress_claude/copy-sources.sh"

echo "🚀 Quick deploying plugin..."

mkdir -p "$DEST_DIR"

# Copy files
cp -r "$SRC_DIR"/* "$DEST_DIR/"

# Ship CHANGELOG.md (closes GBD4 — admin release-notes screen reads from here).
ids_copy_changelog "$SCRIPT_DIR" "$DEST_DIR"

echo "✅ Deployed to WordPress!"
# Report the canonical URL WordPress actually uses — NOT localhost, and with no
# :port. The Docker port mapping answers on localhost:8000, but WP_HOME is the
# .local host, so localhost (or any :port) serves broken absolute URLs. Derived
# live from WP so it stays accurate and never carries a port; falls back if the
# container is down/renamed.
SITE_URL="$(docker exec wp_dev wp option get home --allow-root 2>/dev/null)"
echo "🌐 Test at: ${SITE_URL:-https://mikes-mac-studio.local}"
