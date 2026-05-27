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
echo "🌐 Test at: http://localhost:8000"
