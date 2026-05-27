#!/bin/bash

# Indivisible Newsletter Poster Plugin - Bundle Script
# Creates a zip file for distribution

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="$SCRIPT_DIR/src"
DIST_DIR="$SCRIPT_DIR/dist"
PLUGIN_NAME="indivisible-newsletter"

source "$SCRIPT_DIR/../dev_wordpress_claude/copy-sources.sh"
VERSION=$(ids_extract_version "$SRC_DIR/indivisible-newsletter.php")
ZIP_FILE="$DIST_DIR/${PLUGIN_NAME}-${VERSION}.zip"

echo "========================================="
echo "Bundling $PLUGIN_NAME v$VERSION"
echo "========================================="

# Create dist directory if it doesn't exist
mkdir -p "$DIST_DIR"

# Refuse to overwrite a sealed version (no -dev suffix in version → immutable)
if [[ "$VERSION" != *-dev ]] && [ -f "$ZIP_FILE" ]; then
    echo "error: sealed artifact already exists: $ZIP_FILE" >&2
    echo "       sealed versions are immutable; rebuild from tag if needed" >&2
    exit 4
fi

# Remove the old unversioned zip if present (deprecated output)
rm -f "$DIST_DIR/${PLUGIN_NAME}.zip"

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

# Include CHANGELOG.md from the plugin repo root (if present)
if [ -f "$SCRIPT_DIR/CHANGELOG.md" ]; then
    cp "$SCRIPT_DIR/CHANGELOG.md" "$PLUGIN_DIR/CHANGELOG.md"
fi

# Create versioned zip
echo "Creating versioned zip: $(basename "$ZIP_FILE")"
cd "$TEMP_DIR"
zip -r "$ZIP_FILE" "$PLUGIN_NAME" -q

# Clean up
rm -rf "$TEMP_DIR"

echo "========================================="
echo "Bundle created: $ZIP_FILE"
echo "========================================="
