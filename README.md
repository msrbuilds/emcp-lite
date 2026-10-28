# EMCP Lite

Connect MCP-compatible AI clients to WordPress, with the EMCP dashboard, content and media tools, Elementor and Gutenberg support, per-tool controls, activity logs and change history.

This is the separate Lite edition prepared for the WordPress.org directory. WordPress.org approval is pending. The original Free/Pro product remains at [msrbuilds/elementor-mcp](https://github.com/msrbuilds/elementor-mcp).

## Features

- Manage posts, pages, taxonomies, menus and media.
- Build with supported Elementor widgets, Gutenberg blocks and structured design controls.
- Use Themer layouts, dynamic content and loop templates.
- Inspect plugins, themes, users, database structure, performance and security.
- Control tool access using WordPress permissions and the EMCP dashboard.
- Connect directly over MCP, or explicitly opt into EMCP Cloud and Gateway.

Elementor is optional. Some integrations require their respective plugins. Available tools depend on the installed plugins and enabled modules.

Lite excludes Freemius, third-party updates, arbitrary code insertion, executable artifact installation, shell commands, arbitrary filesystem access, raw database writes and remote plugin/theme/user administration. See [readme.txt](readme.txt) for the full scope and external-service disclosures.

## Requirements

WordPress 6.9 or newer and PHP 8.1 or newer. AI clients connect through the official [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin; EMCP Lite offers a one-click install and activation when it is missing. For development, use Node.js matching [.nvmrc](.nvmrc), npm and Composer.

## Build and install

GitHub source downloads require a build; they are not installable plugin packages.

```sh
git clone https://github.com/msrbuilds/emcp-lite.git
cd emcp-lite
composer install --no-dev
npm ci
npm run build
```

Copy the built folder, without `node_modules`, to `wp-content/plugins/emcp-lite`, or zip it and upload it through WordPress Plugins > Add New. Activate EMCP Lite, then open the EMCP dashboard and follow the Connection setup. Review enabled tools before allowing writes.

Do not activate Lite alongside the original Free/Pro edition. They share internal identifiers; Lite pauses when the original edition is active. This repository does not automatically migrate or update existing GitHub Free installations.

## Dependencies

Runtime dependency versions are pinned in `composer.lock`; JavaScript dependencies are pinned in `package-lock.json`. Keep the distinct `EmcpLite100` Composer autoloader suffix.

Report bugs through [GitHub Issues](https://github.com/msrbuilds/emcp-lite/issues). Include your WordPress/PHP versions and reproducible steps, with credentials and personal data removed.

## Privacy and license

Cloud and Gateway are optional. Read the data-sharing details and provider terms in [readme.txt](readme.txt) before enabling an external service.

GPL-2.0-or-later. See [LICENSE](LICENSE). Third-party licenses are retained with their assets and Composer dependencies. EMCP Lite is independently developed and is not affiliated with WordPress or Elementor.
