<?php
/**
 * EMCP Cloud sync: push/pull sandbox artifact bundles + config via the Cloud API.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EMCP_Tools_Cloud_Sync {
	private static function not_connected(): \WP_Error {
		return new \WP_Error( 'not_connected', __( 'This site is not connected to EMCP Cloud.', 'emcp-lite' ) );
	}

	/**
	 * Workspace plan + usage.
	 *
	 * @return array|\WP_Error
	 */
	public static function status() {
		return EMCP_Tools_Cloud_Client::get( '/api/cloud/v1/me' );
	}

	/**
	 * List the account's cloud artifacts (optionally by kind).
	 *
	 * @param string $kind Optional kind filter.
	 * @return array|\WP_Error
	 */


	/**
	 * Back up a local sandbox artifact to the cloud.
	 *
	 * @param string $kind Artifact kind (block/widget/snippet).
	 * @param int    $id   Local artifact id.
	 * @return array|\WP_Error
	 */


	/**
	 * The sandbox CPT post type for each artifact kind.
	 *
	 * @return array<string,string>
	 */


	/**
	 * Back up every local sandbox artifact (optionally only the given kinds) to
	 * the cloud in one call — the bulk counterpart to backup(). Reuses the
	 * per-artifact backup() so each push keeps its checksum/validation.
	 *
	 * Artifacts whose content has not changed since their last push are skipped
	 * (the cloud PUT is an upsert keyed by uuid, so re-pushing was harmless but
	 * re-uploaded the whole library on every click); pass $force to push them
	 * anyway.
	 *
	 * @param string[] $kinds Kinds to sync (block/widget/snippet); empty = all.
	 * @param bool     $force Push even artifacts that are already up to date.
	 * @return array|\WP_Error { pushed, skipped, failed, items:[{kind,id,ok,skipped?,error?}] }.
	 */


	/**
	 * Whether a local artifact's content is unchanged since its last cloud push:
	 * it was pushed before AND the checksum recorded at that push equals the
	 * current one. A missing baseline (pushed before checksum tracking existed)
	 * counts as changed, since the content cannot be proven unchanged; the next
	 * push records a fresh baseline.
	 *
	 * @param string $kind Artifact kind.
	 * @param int    $id   Local artifact id.
	 * @return bool
	 */


	/**
	 * Pull a cloud artifact into this site (imports as a new local draft).
	 *
	 * @param string $artifact_uuid Cloud artifact uuid.
	 * @param string $kind          Artifact kind (falls back to the bundle's kind).
	 * @return array|\WP_Error
	 */


	/**
	 * Push a config blob (settings/brand_kit/tool_toggles) to the cloud.
	 *
	 * Settings are account-scoped so another connected site can pull them. Other
	 * config types retain their existing site-scoped behavior.
	 *
	 * @param string $type Config type.
	 * @param array  $data Config data.
	 * @return array|\WP_Error
	 */
	public static function push_config( string $type, array $data ) {
		if ( ! EMCP_Tools_Cloud::is_connected() ) {
			return self::not_connected();
		}
		$scope     = ( 'settings' === $type ) ? 'account' : 'site';
		$site_uuid = ( 'account' === $scope ) ? '' : EMCP_Tools_Cloud::site_uuid();
		return EMCP_Tools_Cloud_Client::put(
			'/api/cloud/v1/config/' . rawurlencode( $type ),
			array( 'scope' => $scope, 'site_uuid' => $site_uuid, 'data' => (string) wp_json_encode( $data ) )
		);
	}

	/**
	 * Pull a config blob from the cloud.
	 *
	 * @param string $type Config type.
	 * @return array|\WP_Error
	 */
	public static function pull_config( string $type ) {
		$scope     = ( 'settings' === $type ) ? 'account' : 'site';
		$site_uuid = ( 'account' === $scope ) ? '' : EMCP_Tools_Cloud::site_uuid();
		return EMCP_Tools_Cloud_Client::get(
			'/api/cloud/v1/config/' . rawurlencode( $type )
			. '?scope=' . rawurlencode( $scope )
			. '&site_uuid=' . rawurlencode( $site_uuid )
		);
	}

	/**
	 * Browse published marketplace listings (public; no connection required, but
	 * we still route through the client for a consistent base URL).
	 *
	 * Accepts either a bare category string (legacy) or an options array:
	 * `q`, `kind`, `category`, `access`, `sort`, `page`, `per_page`. When `page`
	 * or `per_page` is present the endpoint returns one page plus
	 * `{ page, per_page, total, pages }` meta; the response always carries a
	 * `facets` block ({ kinds, categories }).
	 *
	 * @param array|string $args Options array, or a bare category string (legacy).
	 * @return array|\WP_Error
	 */


	/**
	 * Website submit-page URL for publishing a backed-up artifact to the
	 * marketplace. The artifact must already be pushed to the cloud (its
	 * stable uuid is what the submit page looks up). '' if unresolvable.
	 *
	 * @param string $kind block|widget|snippet.
	 * @param int    $id   Local artifact id.
	 * @return string
	 */


	/**
	 * Publish one of your cloud artifacts as a marketplace listing (pending
	 * moderation).
	 *
	 * @param string $artifact_uuid Cloud artifact uuid.
	 * @param string $title         Listing title.
	 * @param string $summary       Short description.
	 * @param string $category      Category.
	 * @return array|\WP_Error
	 */


	/**
	 * Install a marketplace listing into this site (imports as a new draft).
	 *
	 * @param string $slug Listing slug.
	 * @return array|\WP_Error
	 */


	/**
	 * Marketplace state for a local artifact (published? pending update? updatable?).
	 * Drives the Sandbox buttons.
	 *
	 * @param string $kind block|widget|snippet.
	 * @param int    $id   Local artifact id.
	 * @return array|\WP_Error
	 */


	/**
	 * Push an update to an already-published marketplace listing: re-push the
	 * bundle (which creates a new cloud version) and flag the listing as a
	 * pending update for re-review.
	 *
	 * @param string $kind      block|widget|snippet.
	 * @param int    $id        Local artifact id.
	 * @param string $changelog Optional author notes.
	 * @return array|\WP_Error
	 */


	/**
	 * Public marketplace URL for a published listing.
	 *
	 * @param string $slug Listing slug.
	 * @return string
	 */

}
