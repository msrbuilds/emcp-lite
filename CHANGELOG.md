# Changelog

## 1.0.8

- Fixed: **Claude could not connect on sites where another plugin answers the shared OAuth discovery address** (reported in a support ticket; the same cause as earlier Tropk and SiteGround reports). Claude signed in with the other plugin, so EMCP rejected its tokens and the MCP Log showed every request failing with "Sorry, you are not allowed to do that." EMCP's OAuth issuer is now its own REST address (`/wp-json/emcp-tools/oauth/v1`), so clients look for its settings at addresses only EMCP answers. Sites with plain permalinks keep the old address. Existing connections keep working; a client that failed this way needs to be removed and added again.
- Fixed: **Atomic paragraphs, headings and buttons removed inline HTML** (#180). They now keep the inline tags Elementor accepts for each element and remove everything else, including scripts and event attributes.
- Fixed: **The `query` tool refused a SELECT that used `REPLACE()` or `INSERT()`** (#181). The string functions are now allowed when followed by an opening parenthesis; `REPLACE INTO` and `INSERT INTO` are still refused.

## 1.0.7

- Fix quiet sites needing a reconnect in wp-admin: Gateway credentials keep their full life when they rotate, live credentials already cut short get it back once, and a daily background check renews a Cloud connection that has not renewed for a week. A site that already lapsed needs one last reconnect. The check runs on WP-Cron, so a site with no visits at all can still lapse.
- Fix Disconnect, Gateway off and Re-issue on the Connection screen failing with an expired-link error.
- Fix OAuth sign-in on every subsite of a subdirectory multisite.
- Make Compact tool mode report which tools only read.
- List each EMCP Pro builder's tested version on the Page Builders screen as a minimum, because later versions are supported too.

## 1.0.6

- Fix every wp-admin page failing for users who are not administrators when another plugin adds a menu item with no capability (EMCP Tools issue #174).
- Fix pulling settings from EMCP Cloud: explain why a pull failed (for example, nothing pushed yet), report how many settings it applied, keep tools that newer versions switch off switched off.
- Make the cloud-config-sync MCP tool apply pulled settings and push this site's own settings.
- Correct the ChatGPT App connection steps to match the app's Plugins flow.

## 1.0.5

- Add native integrations for LiteSpeed Cache, Redirection, WP Mail SMTP, Custom Post Type UI, Wordfence and Pods. Wordfence is read-only; LiteSpeed invalidation requires a supported server.
- Keep new write tools disabled until explicitly enabled, with capability checks, confirmation, native storage verification and conflict-aware History recovery.
- Preserve quoted CPT UI labels and descriptions, verify Pods writes after native hooks, and prevent conflicting SMTP plugins or uncertain receipts from causing accidental mail-test replay.

## 1.0.4

- Use the official MCP Adapter plugin from WordPress.org instead of a bundled copy, with a one-click install and activation notice.
- Use the EMCP Lite name throughout the dashboard, notices, messages and Claude Desktop bundle.
- First release on WordPress.org.

## 1.0.3

- Remove site-settings writes: Lite reads site settings but never changes them, including through History undo.
- Verify the OAuth consent nonce before reading any submitted field, and use standard nonce checks for downloads.
- Update enshrined/svg-sanitize to 1.0.0 and the WordPress MCP adapter to 0.7.1.
- Avoid WordPress debug notices on sites without Elementor.

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
