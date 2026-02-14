# Indivisible Newsletter Poster Plugin

This file provides guidance to Claude Code when working on this plugin.

## Project Context

This plugin is part of a WordPress development environment in the sibling `dev_wordpress_claude/` directory. The plugin is developed here (outside the WordPress directory) to prevent it from being wiped during WordPress backup restorations.

## Plugin Purpose

Newsletter management for Indivisible groups.

## File Structure

```
indivisible-newsletter-plugin/
├── src/                              # Plugin source code (EDIT HERE)
│   ├── js/                           # JavaScript files (as needed)
│   ├── css/                          # CSS files (as needed)
│   ├── includes/                     # PHP class files (as needed)
│   └── indivisible-newsletter.php    # Main plugin file
├── dist/                             # Built distribution files (auto-generated)
├── bundle.sh                         # Creates distribution zip
├── deploy.sh                         # Deploys to WordPress for testing
├── quick-deploy.sh                   # Fast deployment
└── README.md                         # User documentation
```

## Making Changes

### IMPORTANT: Where to Edit
- **DO EDIT:** Files in `src/` directory
- **DON'T EDIT:** Files in `../dev_wordpress_claude/wordpress/wp-content/plugins/indivisible-newsletter/`
- The deployed version is overwritten each time deployment scripts run

### After Making Changes
Always deploy to WordPress for integration testing:
```bash
./quick-deploy.sh
```

This copies your changes to the WordPress plugins directory where they can be tested.

## Testing Context

### WordPress Environment
- Local URL: http://localhost:8000
- Admin: http://localhost:8000/wp-admin
- Database: MySQL 8.0 via Docker
- Table prefix: `wp_fx2b2f_`

## Common Development Tasks

### Add a Feature
1. Plan the feature considering WordPress integration points
2. Edit files in `src/`
3. Run `./quick-deploy.sh` frequently to test during development
4. Test thoroughly in WordPress environment
5. Update README.md with new feature documentation
6. Run `./bundle.sh` to create distribution package

### Fix a Bug
1. Identify the issue in the WordPress environment
2. Edit the relevant file in `src/`
3. Run `./quick-deploy.sh` to test the fix
4. Verify fix works in WordPress
5. Run `./bundle.sh` when ready to distribute

### Add JavaScript
1. Create new JS file in `src/js/`
2. Enqueue properly using `wp_enqueue_script()` in PHP
3. Use `wp_localize_script()` to pass data from PHP to JS
4. Run `./quick-deploy.sh`
5. Hard refresh browser (Cmd+Shift+R) to clear cached JS
6. Test functionality in WordPress

### Add Styles
1. Create CSS file in `src/css/`
2. Enqueue properly using `wp_enqueue_style()` in PHP
3. Run `./quick-deploy.sh`
4. Hard refresh browser to see changes

## Debugging

### PHP Debugging
- XDebug is configured in the WordPress Docker container
- Set breakpoints in VS Code
- Use multi-root workspace for proper path mappings

### JavaScript Debugging
- Use browser DevTools Console
- Check for script loading in Network tab
- Verify localized data objects exist

### Common Issues
- **Plugin not working**: Check if activated in WordPress Admin → Plugins
- **JS not loading**: Check browser console for errors, verify file paths
- **Changes not appearing**: Remember to run `./quick-deploy.sh` after editing
- **Database errors**: Check WordPress error logs

## Integration with Main WordPress Project

When Claude Code works on this plugin:
1. Context should include both plugin source and WordPress environment
2. Changes should be made to `src/` files, not deployed files
3. Always consider WordPress best practices and standards
4. Test changes in the WordPress environment at http://localhost:8000
5. Deploy frequently during development for integration testing

## WordPress Best Practices

- Use WordPress coding standards (WordPress PHP Coding Standards)
- Sanitize all user input with appropriate functions (sanitize_text_field, etc.)
- Escape all output (esc_html, esc_attr, esc_url, wp_kses, etc.)
- Use WordPress nonces for form submissions
- Use prepared statements for database queries
- Follow WordPress Security Best Practices
- Use WordPress functions instead of PHP equivalents when available
- Prefix all functions, classes, and global variables with plugin-specific prefix (e.g., `indivisible_newsletter_`)

## Development Guidelines

### Naming Conventions
- Functions: `indivisible_newsletter_function_name()`
- Classes: `Indivisible_Newsletter_Class_Name`
- Hooks: `indivisible_newsletter_hook_name`
- Database tables: `{$wpdb->prefix}indivisible_newsletter_table_name`
- Options: `indivisible_newsletter_option_name`

### Code Organization
- Keep related functionality together
- Use classes for complex features
- Comment complex logic
- Document hooks and filters
- Use meaningful variable and function names

### Security
- Always validate and sanitize user input
- Escape output based on context
- Use nonces for forms and AJAX
- Check user capabilities before allowing actions
- Prevent direct file access with ABSPATH check

### User Interface
- All content should use theme colors
- Forms should be styled like the registration form (http://localhost:8000/registration/)
- Check all content for good contrast (more important than matching registration form)
