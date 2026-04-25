# Extension Cursor

Extension Cursor is a WordPress plugin for managing APPTOOK stock licences from a dark admin dashboard.

## Overview

The plugin provides:
- a WordPress admin page
- stock licence import and deletion
- bulk import support
- a separate monitor section for future expansion

## Architecture

The codebase is split into small classes to keep responsibilities clear:

- `extension-cursor.php` - plugin bootstrap and constants
- `includes/class-extension-cursor-plugin.php` - main bootstrap and activation entry point
- `includes/class-extension-cursor-database.php` - database schema installation
- `includes/class-extension-cursor-repository.php` - data access layer
- `includes/class-extension-cursor-service.php` - application service layer
- `includes/class-extension-cursor-admin.php` - admin UI and AJAX handlers
- `assets/admin.js` - admin interactions and bulk import UI
- `assets/admin.css` - admin theme and layout styles

## Data Model

The plugin stores stock licences in the custom table `wp_extension_cursor_stock_keys`.
Each row contains:
- `id`
- `token`
- `token_capacity`
- `created_at`
- `updated_at`

## Bulk Import Format

You can import multiple licences by pasting rows in this format:

```text
TOKEN-001 | 10
TOKEN-002 | 50
TOKEN-003 | 100
```

If capacity is omitted, the plugin falls back to `1`.

## Development Notes

- The plugin uses WordPress capability checks and nonces for admin requests.
- Database access is isolated in the repository class to simplify future maintenance.
- Before changing `README.md`, always review the current file first.
- Keep documentation aligned with the actual code structure and feature set.
- Design standard: use a consistent `30px` border radius across cards, inputs, buttons, tabs, and other UI surfaces.

## Requirements

- WordPress 6.4+
- PHP 8.1+
