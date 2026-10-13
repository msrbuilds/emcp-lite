# Changelog

## 1.0.2

* Security: remove arbitrary SQL reads from Lite to prevent credential-bearing database values being exposed. Structured content queries and database schema inspection remain available.

- Revalidate OAuth consent scope and PKCE, reject malformed request types, and limit OAuth authentication to the MCP route.
- Escape dashboard, consent and Themer output at rendering boundaries; protect inline data and stylesheet output.
- Return log exports through WordPress JSON responses while retaining CSV formula protection.
- Resolve configured content directories and REST paths, and read Spectra defaults from its verified local directory.
- Preserve WordPress error reporting and exclude dependency development directories from release packages.

## 1.0.1

Addresses the WordPress.org review: removes remote plugin, theme and user administration and arbitrary code insertion; limits settings writes; scopes notices to EMCP screens; uses WordPress asset APIs; validates authentication inputs; escapes dynamic callbacks; updates bundled dependencies and service disclosures. Preserves the dashboard, content and design tools, and optional Cloud/Gateway connections.

## 1.0.0

Initial WordPress.org edition based on EMCP Free 3.19.0. Preserves the dashboard, content tools, Elementor and Gutenberg editing, History, and ordinary Themer templates. Removes licensing, external updates, executable code tools, sandbox deployment, and remote marketplace installation. Cloud and Gateway are optional connections.
