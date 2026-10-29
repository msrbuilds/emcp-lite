<?php
/** Read-only, credential-free native Wordfence inspection. */
defined( 'ABSPATH' ) || exit;
final class EMCP_Tools_Wordfence_Api {
	public static function active(): bool { return defined( 'WORDFENCE_VERSION' ) && class_exists( 'wfConfig' ); }
	public static function allowed(): bool { return current_user_can( 'manage_options' ) && ( ! self::active() || wfUtils::isAdmin() ); }
	public static function error( string $code, string $message ): WP_Error { return new WP_Error( $code, $message ); }
	public static function ready() {
		if ( ! self::active() ) { return self::error( 'plugin_inactive', 'Activate Wordfence first.' ); }
		if ( '9.0.2' !== WORDFENCE_VERSION || ! class_exists( 'wfIssues' ) || ! class_exists( 'wfBlock' ) ) { return self::error( 'unsupported_version', 'This Wordfence version has not been qualified.' ); }
		if ( is_multisite() ) { return self::error( 'unsupported_environment', 'This adapter currently supports single sites.' ); }
		return true;
	}
	public static function finding( array $row ): array {
		$out = array(); foreach ( array( 'id', 'severity', 'time', 'lastUpdated' ) as $key ) { $out[ $key ] = (int) ( $row[ $key ] ?? 0 ); }
		$out['type'] = in_array( $row['type'] ?? '', wfIssues::validIssueTypes(), true ) ? $row['type'] : 'unknown';
		$out['status'] = in_array( $row['status'] ?? '', array( 'new', 'ignoreC', 'ignoreP' ), true ) ? $row['status'] : 'unknown';
		return $out;
	}
	public static function block( $block ): array {
		$out = array(); foreach ( array( 'id', 'type', 'blockedTime', 'lastAttempt', 'blockedHits', 'expiration' ) as $key ) { $out[ $key ] = (int) $block->$key; }
		return $out;
	}
	public static function read( string $operation, array $args ) {
		global $wpdb;
		try {
			if ( 'status' === $operation ) {
				$counts = ( new wfIssues() )->getIssueCounts(); if ( $wpdb->last_error ) { return self::error( 'read_failed', 'Native finding counts could not be read.' ); }
				return array( 'version' => WORDFENCE_VERSION, 'license_configured' => (bool) wfConfig::get( 'apiKey', false, false ), 'premium' => (bool) wfConfig::get( 'isPaid', false, false ), 'application_passwords_disabled' => (bool) wfConfig::get( 'loginSec_disableApplicationPasswords', false, false ), 'finding_counts' => $counts, 'read_only' => true, 'note' => 'Existing findings are not a clean-scan verdict. Check scan-status separately. When application passwords are disabled, use EMCP OAuth sign-in.' );
			}
			if ( 'firewall' === $operation ) {
				$firewall = new wfFirewall(); $stored = wfConfig::get( 'waf_status', '', false );
				return array( 'loaded_mode' => $firewall->firewallMode(), 'stored_mode' => in_array( $stored, array( 'enabled', 'disabled', 'learning-mode' ), true ) ? $stored : 'unknown', 'protection_mode' => $firewall->protectionMode(), 'operational' => defined( 'WFWAF_OPERATIONAL' ) && WFWAF_OPERATIONAL, 'runtime_scope' => defined( 'WP_CLI' ) && WP_CLI ? 'current_cli_process' : 'current_http_request', 'note' => 'Runtime WAF state belongs to this request. Reconnect long-lived CLI clients after native firewall changes.' );
			}
			if ( 'scan-status' === $operation ) {
				foreach ( array( 'wf_scanRunning', 'wf_scanLastStatusTime', 'scanStartAttempt', 'lastScanFailureType', 'scanTime', 'scheduledScansEnabled' ) as $key ) { wfConfig::get( $key, false, false ); }
				$scanner = new wfScanner();
				return array( 'running' => $scanner->isRunning(), 'scheduled_enabled' => $scanner->isEnabled(), 'last_completed_at' => (int) $scanner->lastScanTime() ?: null, 'last_status_at' => (int) wfIssues::lastScanStatusUpdate() ?: null, 'failure' => wfIssues::hasScanFailed() ?: null, 'scan_start_supported' => false );
			}
			$limit = $args['limit'] ?? 20; $offset = $args['offset'] ?? 0;
			if ( 'list-blocks' === $operation ) {
				$table = wfBlock::blocksTable();
				$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE expiration=0 OR expiration>UNIX_TIMESTAMP()', $table ) );
				if ( $wpdb->last_error ) { return self::error( 'read_failed', 'Native block counts could not be read.' ); }
				$rows = wfBlock::allBlocks( true, array(), $offset, $limit, 'ruleAdded', 'descending' );
				if ( $wpdb->last_error ) { return self::error( 'read_failed', 'Native blocks could not be read.' ); }
				return array( 'items' => array_map( array( self::class, 'block' ), $rows ), 'total' => (int) $total, 'limit' => $limit, 'offset' => $offset, 'private_fields_redacted' => true );
			}
			$table = ( new wfIssues() )->getIssuesTable();
			if ( 'get-finding' === $operation ) {
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id,type,severity,status,time,lastUpdated FROM %i WHERE id=%d', $table, $args['id'] ), ARRAY_A );
				if ( $wpdb->last_error ) { return self::error( 'read_failed', 'Native finding could not be read.' ); }
				return $row ? self::finding( $row ) : self::error( 'not_found', 'Finding not found.' );
			}
			$status = $args['status'] ?? 'new';
			$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status=%s', $table, $status ) );
			if ( $wpdb->last_error ) { return self::error( 'read_failed', 'Native finding counts could not be read.' ); }
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,type,severity,status,time,lastUpdated FROM %i WHERE status=%s ORDER BY severity DESC,id DESC LIMIT %d OFFSET %d', $table, $status, $limit, $offset ), ARRAY_A );
			if ( $wpdb->last_error ) { return self::error( 'read_failed', 'Native findings could not be read.' ); }
			return array( 'items' => array_map( array( self::class, 'finding' ), $rows ), 'total' => (int) $total, 'limit' => $limit, 'offset' => $offset, 'review_url' => admin_url( 'admin.php?page=WordfenceScan' ), 'private_fields_redacted' => true );
		} catch ( Throwable $error ) { return self::error( 'read_failed', 'Wordfence could not provide this status. Inspect its native dashboard.' ); }
	}
}
