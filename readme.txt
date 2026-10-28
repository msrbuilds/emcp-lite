=== EMCP Lite ===
Contributors: mianshahzadraza
Tags: mcp, ai, claude, elementor, gutenberg
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Claude, ChatGPT, Cursor and other AI assistants to WordPress through MCP to manage content, media and page layouts.

== Description ==

EMCP Lite connects your WordPress site to AI assistants that support the Model Context Protocol (MCP), such as Claude, ChatGPT, Cursor and Claude Code. Ask your assistant to draft posts, organize media, update menus or build Elementor and Gutenberg layouts, and it works directly on your site through tools you control.

Every request acts as the connected WordPress user, within that user's permissions and only through the tools you enable. Changes are logged, and supported changes can be undone from History.

= What your AI assistant can do =

* Create and edit posts, pages, categories, tags and menus.
* Upload, organize and resize media, and add stock photos from Unsplash, Pexels or Pixabay using your own API key.
* Build and edit Elementor pages: containers, widgets, templates, global colors and fonts (when Elementor is active).
* Build and edit Gutenberg blocks and patterns, including supported block plugins such as Spectra and Kadence Blocks.
* Design headers, footers and content templates with the optional Themer module.
* Inspect your site: plugins, themes, users, database structure, performance and security checks.
* Read your site settings.
* Use bundled prompts and brand kits to keep results on brand.

= Stay in control =

* Choose exactly which tools AI clients may use on the Tools screen.
* Every request runs with the connected user's WordPress capabilities.
* History records the changes made through EMCP, with one-click undo where supported.
* The MCP Log shows each request from your connected clients.
* Management access lets you decide which administrators can change EMCP settings.

EMCP Lite does not install, update or remove plugins or themes, manage users, change site settings or run custom code.

= Connect your AI client =

The Connection screen guides you through setup for Claude Desktop, Claude.ai, Claude Code, Cursor, ChatGPT, Antigravity and other MCP clients, using OAuth sign-in or a WordPress Application Password.

Elementor is optional: the WordPress and Gutenberg tools work without it, and some integrations need their respective plugins. EMCP Lite is independently developed and is not affiliated with WordPress, Elementor, Anthropic or OpenAI.

= Privacy and external services =

EMCP Lite does not send data anywhere until you connect an AI client or enable an optional service.

**AI clients.** A direct MCP connection communicates only with the AI client you choose. The client receives the results of the tools it calls, which can include site content or personal data the connected WordPress user can access. Check your AI client's privacy policy before connecting it. Activity and History records are stored on your own site.

**EMCP Cloud (optional).** Local tools work without a Cloud account. Choosing Connect sends your site URL, site name, an installation identifier and OAuth registration and authorization details to emcptools.com. Once connected, the plugin exchanges and refreshes tokens and can request account and service status. Settings sync sends supported EMCP preferences, including tool toggles, modules and your free-text site context. Credentials are excluded, but site-context text is not redacted, so do not put passwords or API keys in it. Hosted services may require a paid plan. A developer or administrator can configure a different Cloud endpoint.

**Gateway access (optional).** Gateway is a separate opt-in that is off by default. Enabling it creates a revocable EMCP OAuth token for the authorizing WordPress user and sends it to the EMCP service. It does not send a WordPress password or Application Password. The token works only on the EMCP MCP endpoint, not wp-admin, the WordPress REST API or EMCP settings. Gateway requests act as that user, within the enabled tools and that user's permissions, and can include write operations. Gateway relays MCP requests and responses, including any content or personal data the selected tools return. You can turn off Gateway and disconnect Cloud on the Connection screen at any time.

* Service: https://emcptools.com/
* Terms of service: https://emcptools.com/terms-of-service/
* Privacy policy: https://emcptools.com/privacy/

**Stock photos (optional).** Unsplash, Pexels and Pixabay are contacted only after you add that provider's API key and request a search or download. Requests send your API key, search terms and selected image identifiers, and images are downloaded from the provider's servers. Unsplash download notifications are sent when its API requires them. Provider terms and image licenses apply:

* Unsplash: https://unsplash.com/terms and https://unsplash.com/privacy
* Pexels: https://www.pexels.com/terms-of-service/ and https://www.pexels.com/privacy-policy/
* Pixabay: https://pixabay.com/service/terms/ and https://pixabay.com/service/privacy/

**Other requests.** Plugin and theme search uses the WordPress.org API, sending your search terms and page number. When a security check runs, it looks up your installed plugins' slugs on the WordPress.org API to compare versions. Page inspection and media import tools request only the URLs you provide or select. Tutorial videos open on YouTube only when you click them; their thumbnails are bundled with the plugin.

= Source code =

The full source code, including the uncompressed admin interface source in the admin-src folder, ships with the plugin and is available on GitHub: https://github.com/msrbuilds/emcp-lite

To rebuild the admin scripts, run `npm ci` and then `npm run build` with a current Node.js LTS release. Composer dependencies are included in the vendor folder, with composer.json and composer.lock.

Bundled libraries:

* Swiper 14.3.0 (MIT): https://github.com/nolimits4web/swiper
* Fuse.js (Apache-2.0): https://github.com/krisk/Fuse
* jsdiff (BSD-3-Clause): https://github.com/kpdecker/jsdiff
* Lucide (ISC): https://github.com/lucide-icons/lucide
* svg-sanitize (GPL-2.0-or-later): https://github.com/darylldoyle/svg-sanitizer
* MCP Adapter (GPL-2.0-or-later): https://github.com/WordPress/mcp-adapter

License notices are included in the assets/licenses, assets/fonts and assets/lib/swiper folders.

== Installation ==

1. In your WordPress dashboard, go to Plugins > Add New, search for "EMCP Lite", then install and activate it.
2. Open EMCP in the admin menu and choose Connection.
3. Pick your AI client and follow the steps to connect it.
4. On the Tools screen, review which tools your AI client may use before allowing write operations.

== Frequently Asked Questions ==

= What is MCP? =

The Model Context Protocol is an open standard that lets AI assistants use external tools. EMCP Lite adds an MCP server to your WordPress site, so a compatible assistant can read and update your content through the tools you allow.

= Which AI clients work with EMCP Lite? =

Any client that supports remote MCP servers. The Connection screen has guided setup for Claude Desktop, Claude.ai, Claude Code, Cursor, ChatGPT, Antigravity, OpenClaw and Hermes, plus a generic option for other clients.

= Do I need Elementor? =

No. The WordPress, media and Gutenberg tools work on any site. Elementor tools appear when Elementor is active.

= Do I need an EMCP Cloud account? =

No. Connecting directly works without an account or license key. EMCP Cloud and Gateway are optional hosted services with their own plans.

= What can an AI assistant change on my site? =

Only what you allow. It uses the tools enabled on the Tools screen and the permissions of the WordPress user it connects as. EMCP Lite cannot install or remove plugins or themes, manage users, change site settings or run custom code. Review changes in History and undo them where supported.

= Can I use EMCP Lite alongside the full EMCP Tools plugin? =

Please activate only one EMCP edition at a time. EMCP Lite pauses automatically when another edition is active.

= Does uninstalling delete my pages or designs? =

No. Your content, Themer layouts, brand kits and History are kept. Uninstalling removes local connection credentials and scheduled maintenance tasks. If you use EMCP Cloud, disconnect it before uninstalling to remove the connection on the service side too.

= Can every change be undone? =

Not every change. History shows which entries support undo. An undo can be refused if the item changed again afterwards.

== Screenshots ==

1. Dashboard: recent AI activity, connected clients and tool status at a glance.
2. Connection: guided setup for Claude, ChatGPT, Cursor and other MCP clients.
3. Tools: choose exactly which tools your AI clients may use.
4. History: review changes made through EMCP and undo them.
5. MCP Log: every request from your connected clients.

== Changelog ==

= 1.0.4 =

* First release on WordPress.org.

== Upgrade Notice ==

= 1.0.4 =

First release on WordPress.org. Review the enabled tools before connecting an AI client.
