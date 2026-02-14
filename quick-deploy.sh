#!/bin/bash

# Quick Deploy - Fast deployment to WordPress for testing
# This is a simplified version of deploy.sh for rapid iteration

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="$SCRIPT_DIR/src"
DEST_DIR="$SCRIPT_DIR/../dev_wordpress_claude/wordpress/wp-content/plugins/indivisible-newsletter"

echo "🚀 Quick deploying plugin..."

mkdir -p "$DEST_DIR"

# Copy files
cp -r "$SRC_DIR"/* "$DEST_DIR/"

echo "✅ Deployed to WordPress!"
echo "🌐 Test at: http://localhost:8000"
