<?php
if (!defined('ABSPATH')) { exit; }

class AM_Activator {
	public static function activate(): void {
		// Register types before flushing rules.
		if (class_exists('AM_Post_Types')) {
			AM_Post_Types::register();
		}
		if (class_exists('AM_Taxonomies')) {
			AM_Taxonomies::register();
		}

		self::add_roles_and_caps();
		if (function_exists('am_cleanup_legacy_roles_and_permissions')) { am_cleanup_legacy_roles_and_permissions(); }
		delete_option('am_caps_runtime_checked_1_5_4');
		self::ensure_archive_page();
		flush_rewrite_rules();
	}

	/**
	 * Ensures roles/capabilities exist after updates or manual role changes.
	 * Safe to call on every load.
	 */
	public static function ensure_caps(): void {
		self::add_roles_and_caps();
		if (function_exists('am_cleanup_legacy_roles_and_permissions')) { am_cleanup_legacy_roles_and_permissions(); }
	}


private static function ensure_archive_page(): void {
	// Create (once) a front-end archive page that can be linked in menus.
	// The page contains shortcode [am_archive].
	$opt_key = 'am_archive_page_id';
	$page_id = (int) get_option($opt_key, 0);
	if ($page_id && get_post($page_id)) {
		return;
	}
	// Try to find an existing page that contains the [am_archive] shortcode.
	$found = get_posts([
		'post_type'      => 'page',
		'post_status'    => 'any',
		'numberposts'    => 1,
		'no_found_rows'  => true,
		// Use a direct content match instead of full-text search ('s'),
		// which is unreliable for shortcode strings across different DB/search engines.
		'meta_query'     => [], // placeholder to satisfy PHPCS; real filter below
	]);
	// Override with a direct SQL LIKE on post_content (reliable across all WP installs).
	$am_archive_where_cb = function( $where ) {
		global $wpdb;
		$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_content LIKE %s", '%[am_archive]%' );
		return $where;
	};
	add_filter( 'posts_where', $am_archive_where_cb );
	$found = get_posts([
		'post_type'      => 'page',
		'post_status'    => 'any',
		'numberposts'    => 1,
		'no_found_rows'  => true,
	]);
	remove_filter( 'posts_where', $am_archive_where_cb );
	if ($found) {
		update_option($opt_key, (int) $found[0]->ID);
		return;
	}
	$new_id = wp_insert_post([
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_title' => 'Archivio Funghi',
		'post_name' => 'archivio-funghi',
		'post_content' => "[am_archive]",
	]);
	if (!is_wp_error($new_id) && $new_id) {
		update_option($opt_key, (int) $new_id);
	}
}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	private static function add_roles_and_caps(): void {
		am_ensure_admin_editor_caps_runtime();
	}

}
