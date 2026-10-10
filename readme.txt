=== EMCP Lite ===
Contributors: mianshahzadraza
Tags: mcp, ai, automation, elementor, gutenberg
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI clients to WordPress through MCP, with content, media, Elementor and Gutenberg tools, access controls and change history.

== Description ==

EMCP Lite connects MCP-compatible AI clients to your WordPress site. It includes the EMCP dashboard, connection setup, per-tool controls, activity logs and change history.

Use it to manage WordPress content and media, work with Elementor and Gutenberg layouts, inspect the site, and control which tools your connected clients can use. Available tools depend on the plugins and modules enabled on your site.

Features include:

* WordPress posts, pages, taxonomies, menus, media and curated settings tools.
* Elementor page structures, widgets, templates, styles and supported global design controls, when Elementor is active.
* Gutenberg blocks and patterns, with integrations for supported installed plugins.
* Bundled prompts and brand kits.
* Optional Themer layouts, dynamic content and loop templates, without a template quota.
* Read-only plugin, theme and user information.
* Read-only database inspection, performance checks and security inspection.
* Per-tool controls, WordPress capability checks, local management access controls and supported change-history undo operations.
* Direct MCP connections, plus optional EMCP Cloud settings sync and hosted Gateway access.

This edition does not include Freemius, remote plugin/theme installation or lifecycle changes, user administration, critical site-settings changes, managed Cloud configuration deployment, arbitrary CSS/JavaScript/PHP insertion, generated executable widgets/blocks, PHP templates, shell commands, arbitrary filesystem tools, raw database writes, or executable Cloud/Marketplace artifact installation. Ordinary Elementor widgets, Gutenberg blocks and Themer layouts remain available. Use structured design controls for styling.

Some optional integrations require their respective plugins. Elementor is not required for the ordinary WordPress tools. EMCP Lite is independently developed and is not affiliated with WordPress or Elementor.

= Privacy and external services =

Direct MCP connections communicate with the AI client you choose. The client receives the tool results it requests, which may contain site content or personal data according to the connected WordPress user's permissions. Check your AI client's privacy policy before connecting it. Local activity and change-history records are stored on your WordPress site.

EMCP Cloud is optional. Local tools work without a Cloud account. Choosing Connect sends your site URL, site name, installation identifier and OAuth registration/authorization information to emcptools.com. Once connected, the plugin exchanges and refreshes tokens and can request account/service status. Settings sync sends supported preferences, including tool toggles, modules and free-text site context. Credential options are excluded, but site-context text is not automatically redacted: do not put passwords or API keys in it. Extensions can narrow, but cannot expand, Lite's settings-sync allowlist. Hosted services may require a paid service plan. The default Cloud endpoint is emcptools.com; a developer or site administrator can configure a different endpoint. Review that service before connecting.

Gateway access is a separate opt-in, initially unchecked. Enabling it provisions a revocable credential for the authorizing WordPress user and sends it to the EMCP service. Gateway requests act as that user, subject to enabled tools and that user's WordPress permissions; the credential is not limited to read-only operations. Gateway connections relay MCP requests and responses, including any content or personal data returned by the selected tools. You can disable Gateway access and disconnect Cloud from the Connection screen.

Service: https://emcptools.com/
Terms: https://emcptools.com/terms-of-service/
Privacy: https://emcptools.com/privacy/

Optional stock-image tools contact a provider only after you configure its API key and request a search or download. Requests send your API credential, search terms and selected image identifiers; downloads use the provider's image hosts. Unsplash download attribution requests are made when required by its API. Provider terms and image licenses apply:

* Unsplash: https://unsplash.com/terms and https://unsplash.com/privacy
* Pexels: https://www.pexels.com/terms-of-service/ and https://www.pexels.com/privacy-policy/
* Pixabay: https://pixabay.com/service/terms/ and https://pixabay.com/service/privacy/

WordPress.org plugin and theme discovery uses the WordPress.org APIs when you request a search, sending search terms and pagination parameters. EMCP Lite cannot install or update that software. Front-end inspection and media-import tools request the specific URLs you provide or select. Tutorial links open YouTube only when clicked; dashboard tutorial thumbnails are bundled locally.

= Source and build instructions =

Human-readable frontend source is included in admin-src/. Build configuration, package.json, package-lock.json and admin-build/ are included. With a supported Node.js LTS version, run `npm ci` followed by `npm run build`. WordPress supplies React and WordPress JavaScript libraries at runtime. Composer dependency source and licenses are included in vendor/, with composer.json and composer.lock for reproducibility. Do not ship node_modules or development tests in a distribution ZIP.

Bundled library sources:

* Swiper 14.3.0 (MIT): https://github.com/nolimits4web/swiper/tree/v14.3.0
* Fuse.js (Apache-2.0): https://github.com/krisk/Fuse
* jsdiff (BSD-3-Clause): https://github.com/kpdecker/jsdiff
* Lucide (ISC): https://github.com/lucide-icons/lucide

Exact JavaScript versions are pinned in package-lock.json. Readable Swiper source ships beside its minified distribution. Font and library license notices are included in assets/licenses/, assets/fonts/ and assets/lib/swiper/.
== Installation ==

1. Upload the emcp-lite folder to wp-content/plugins, or install its ZIP through Plugins > Add New.
2. Activate EMCP Lite.
3. Open the EMCP dashboard, choose Connection, and follow the setup for your MCP client.
4. Review the Tools and Modules screens before enabling write operations.

== Frequently Asked Questions ==

= Is a subscription required? =

No. Included local features do not require a subscription or a license key. Optional hosted EMCP services have separate service plans.

= Does this replace my existing GitHub EMCP installation? =

No automatic replacement is performed. Do not activate two EMCP editions on the same site. Lite pauses when another EMCP edition is active. They retain compatible internal settings and data names, so use the dedicated Lite test site when evaluating a change of edition and back up before deliberately switching.

= Does uninstall delete my pages or designs? =

No. Content, Themer layouts, brand-kit backups and change-history records are preserved. Uninstall removes local connection credentials and scheduled EMCP maintenance when no other EMCP edition is installed. If another edition is installed, shared data is preserved for it. Disconnect hosted services before removing the plugin if you also want to remove the service-side connection.

= Does every operation support undo? =

No. History shows which operations support undo. Undo may be refused if the resource changed afterward or an operation is not reversible.

== Changelog ==

= 1.0.1 =
* Removes remote administration and arbitrary code insertion from Lite while retaining content tools and the dashboard.
* Updates bundled dependencies, request validation, output escaping, asset loading and service disclosures.

= 1.0.0 =
* Initial WordPress.org edition based on EMCP Free 3.19.0.
* Retains the dashboard and ordinary free tools, with targeted executable-feature removals.
* Removes Freemius and third-party updates, adds explicit service disclosures, and removes Themer template quotas.

== Upgrade Notice ==

= 1.0.0 =
Initial WordPress.org edition. Review enabled tools and connection permissions before use.
