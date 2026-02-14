#!/bin/bash

# Indivisible Newsletter Poster Plugin - Bundle Script
# Creates a zip file for distribution

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="$SCRIPT_DIR/src"
DIST_DIR="$SCRIPT_DIR/dist"
PLUGIN_NAME="indivisible-newsletter"
VERSION=$(grep "Version:" "$SRC_DIR/indivisible-newsletter.php" | awk '{print $3}')

echo "========================================="
echo "Bundling $PLUGIN_NAME v$VERSION"
echo "========================================="

# Create dist directory if it doesn't exist
mkdir -p "$DIST_DIR"

# Remove old zip if it exists
if [ -f "$DIST_DIR/${PLUGIN_NAME}.zip" ]; then
    echo "Removing old bundle..."
    rm "$DIST_DIR/${PLUGIN_NAME}.zip"
fi

# Create temporary directory for bundling
TEMP_DIR=$(mktemp -d)
PLUGIN_DIR="$TEMP_DIR/$PLUGIN_NAME"

echo "Creating plugin directory structure..."
mkdir -p "$PLUGIN_DIR"

# Copy plugin files
echo "Copying plugin files..."
cp -r "$SRC_DIR"/* "$PLUGIN_DIR/"

# Remove any development files that shouldn't be in distribution
echo "Cleaning up development files..."
find "$PLUGIN_DIR" -name ".DS_Store" -delete
find "$PLUGIN_DIR" -name "*.bak" -delete
find "$PLUGIN_DIR" -name "*.tmp" -delete
find "$PLUGIN_DIR" -name ".gitkeep" -delete

# Create zip file
echo "Creating zip archive..."
cd "$TEMP_DIR"
zip -r "$DIST_DIR/${PLUGIN_NAME}.zip" "$PLUGIN_NAME" -q

# Clean up
rm -rf "$TEMP_DIR"

echo "========================================="
echo "Bundle created: $DIST_DIR/${PLUGIN_NAME}.zip"
echo "========================================="
