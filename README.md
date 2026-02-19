# Indivisible Newsletter Poster Plugin

WordPress plugin that automates newsletter management for Indivisible groups by polling an IMAP inbox, parsing incoming emails, and creating draft WordPress posts.

## Development Setup

This plugin is developed outside the WordPress installation directory to prevent it from being wiped out during backup restorations.

### VS Code Multi-Root Workspace

Open the WordPress development workspace that includes this plugin:

```bash
cd ../dev_wordpress_claude
code wordpress-development.code-workspace
```

This provides:
- WordPress Project and all three plugins in one workspace
- XDebug path mappings for debugging
- Built-in tasks for deployment and bundling (Cmd+Shift+B)

### Directory Structure

```
indivisible-newsletter-plugin/
├── src/                            # Plugin source code
│   ├── includes/                   # PHP class files
│   │   ├── class-in-admin.php      # Settings page, encryption, sanitization
│   │   ├── class-in-cron.php       # Cron schedule management
│   │   ├── class-in-email.php      # IMAP connection and MIME parsing
│   │   └── class-in-processor.php  # Email processing and post creation
│   ├── indivisible-newsletter.php  # Main plugin file
│   └── uninstall.php
├── tests/                          # PHPUnit test files
│   ├── bootstrap.php               # Defines SECURE_AUTH_SALT for encryption tests
│   ├── wp-tests-config.php
│   ├── test-settings.php
│   ├── test-encryption.php
│   ├── test-sanitization.php
│   ├── test-cron.php
│   ├── test-email-parsing.php
│   ├── test-processor.php
│   └── test-processor-extended.php
├── .vscode/
│   └── settings.json               # PHPUnit Docker path mappings
├── composer.json                   # PHP test dependencies
├── phpunit.xml.dist                # PHPUnit configuration (with coverage)
├── dist/                           # Built/bundled files (created by bundle.sh)
├── bundle.sh                       # Creates distribution zip file
├── deploy.sh                       # Deploys to local WordPress
├── quick-deploy.sh                 # Fast deployment for development
└── README.md                       # This file
```

## Scripts

### Quick Deploy (Recommended for Development)

Fast deployment for rapid iteration:

```bash
./quick-deploy.sh
```

### Deploy to Local WordPress

Full deployment with status messages:

```bash
./deploy.sh
```

After deploying, activate the plugin in WordPress Admin → Plugins.

**VS Code Task:** Press Cmd+Shift+B and select the newsletter deploy task.

### Bundle for Distribution

Create a zip file for distribution:

```bash
./bundle.sh
```

## Testing

All changes must follow a test-first workflow — write tests before implementation.

### VS Code Test Sidebar (Recommended)

Install this VS Code extension to see and run PHP tests from the sidebar:

- **[PHPUnit Test Explorer](https://marketplace.visualstudio.com/items?itemName=recca0120.vscode-phpunit)** (`recca0120.vscode-phpunit`) — PHP test discovery and running via Docker

Open the workspace from `dev_wordpress_claude/wordpress-development.code-workspace` — Docker settings and path mappings are pre-configured.

> **Note:** PHP tests require Docker to be running (`docker-compose up -d` in `dev_wordpress_claude/`).

> **Note:** This plugin has no JavaScript files. If JS is added in future, set up Jest following the pattern in `login-required-plugin` or `indivisible-agenda-plugin`, then also install the **[Jest](https://marketplace.visualstudio.com/items?itemName=Orta.vscode-jest)** (`orta.vscode-jest`) extension.

### Run from the Command Line

From the `dev_wordpress_claude/` directory:

```bash
./run-tests.sh newsletter   # PHP tests
```

### PHP Tests (PHPUnit)

- **Location:** `tests/test-*.php`
- **Runner:** PHPUnit 9.6 inside Docker container
- **Base class:** `WP_UnitTestCase`
- **First-time setup:** `cd /var/www/plugins/indivisible-newsletter-plugin && composer install` (inside Docker)
- **Coverage:** Configured in `phpunit.xml.dist` for all `src/*.php`
- **Important:** `SECURE_AUTH_SALT` is defined in `tests/bootstrap.php` — required for OpenSSL encryption tests

### First-Time Test Database Setup

PHP tests require a dedicated test database. If it doesn't exist yet:

```bash
cd ../dev_wordpress_claude && ./setup-test-db.sh
```

## Development Workflow

1. **Write tests first** for the new behavior
2. **Get tests approved** before writing implementation code
3. **Make changes** to files in the `src/` directory
4. **Run tests** to verify:

   ```bash
   cd ../dev_wordpress_claude && ./run-tests.sh newsletter
   ```

5. **Deploy** to local WordPress for integration testing:

   ```bash
   ./quick-deploy.sh
   ```

6. **Bundle** for distribution when ready:

   ```bash
   ./bundle.sh
   ```

## Plugin Features

- Polls a configured IMAP inbox on a cron schedule
- Parses MIME emails including quoted-printable and base64 encoding
- Decodes MIME-encoded headers (RFC 2047)
- Extracts HTML content from multipart emails
- Creates WordPress draft posts from processed emails
- Notifies a webmaster email on new post creation
- Encrypts stored IMAP credentials using OpenSSL (AES-256-CBC)
- Admin settings page for IMAP configuration, cron schedule, and category assignment

## WordPress Compatibility

- Requires WordPress 5.0+
- Tested with WordPress 6.x
- Requires PHP `imap` extension for IMAP connectivity
- Requires PHP `openssl` extension for credential encryption
