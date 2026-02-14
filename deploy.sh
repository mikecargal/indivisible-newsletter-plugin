#!/bin/bash

# Indivisible Newsletter Poster Plugin - Deploy Script
# Deploys plugin to local WordPress development environment

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="$SCRIPT_DIR/src"
WP_PLUGINS_DIR="$SCRIPT_DIR/../dev_wordpress_claude/wordpress/wp-content/plugins"
PLUGIN_NAME="indivisible-newsletter"
PLUGIN_DEST="$WP_PLUGINS_DIR/$PLUGIN_NAME"

echo "========================================="
echo "Deploying $PLUGIN_NAME to local WordPress"
echo "========================================="

# Check if WordPress plugins directory exists
if [ ! -d "$WP_PLUGINS_DIR" ]; then
    echo "Error: WordPress plugins directory not found: $WP_PLUGINS_DIR"
    exit 1
fi

# Remove existing plugin directory if it exists
if [ -d "$PLUGIN_DEST" ]; then
    echo "Removing existing plugin directory..."
    rm -rf "$PLUGIN_DEST"
fi

# Create plugin directory
echo "Creating plugin directory..."
mkdir -p "$PLUGIN_DEST"

# Copy plugin files
echo "Copying plugin files..."
cp -r "$SRC_DIR"/* "$PLUGIN_DEST/"

# Set proper permissions
echo "Setting permissions..."
chmod -R 755 "$PLUGIN_DEST"

echo "========================================="
echo "Deployment complete!"
echo "Plugin location: $PLUGIN_DEST"
echo ""
echo "Next steps:"
echo "1. Go to WordPress Admin → Plugins"
echo "2. Activate 'Indivisible Newsletter Poster'"
echo "========================================="
