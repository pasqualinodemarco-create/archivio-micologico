<?php
/**
 * Plugin Name: Archivio Micologico
 * Plugin URI: https://cervignanometeo.altervista.org
 * Description: Archivio Micologico: archivio micologico online con CPT dedicato, tassonomie gerarchiche e inserimento/gestione front-end.
 * Version: 1.5.54
 * Author: Pasqualino De Marco
 * Author URI: https://cervignanometeo.altervista.org
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Text Domain: archivio-micologico
 */

if (!defined('ABSPATH')) { exit; }



// AM_FATAL_LOGGER (stores last fatal error in am_debug_log, last 20 events)
if ( ! function_exists('am_fatal_logger_register') ) {
	function am_fatal_logger_register() {
		register_shutdown_function(function () {
			$e = error_get_last();
			if ( ! $e ) { return; }
			// Fatal types
			$fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];
			if ( ! in_array($e['type'], $fatal_types, true) ) { return; }

			if ( function_exists('get_option') && function_exists('update_option') ) {
				$log = get_option('am_debug_log', []);
				if ( ! is_array($log) ) { $log = []; }
				array_unshift($log, [
					'ts'   => gmdate('Y-m-d H:i:s'),
					'tag'  => 'FATAL',
					'data' => [
						'type' => $e['type'] ?? null,
						'msg'  => $e['message'] ?? '',
						'file' => $e['file'] ?? '',
						'line' => $e['line'] ?? null,
					],
				]);
				$log = array_slice($log, 0, 20);
				update_option('am_debug_log', $log, false);
			}
		});
	}
	am_fatal_logger_register();
}

define('AM_VERSION', '1.5.54');
define('AM_PATH', plugin_dir_path(__FILE__));
define('AM_URL', plugin_dir_url(__FILE__));

define('AM_CPT', 'am_fungo');
define('AM_ARTICLE_CPT', 'am_articolo');
define('AM_TAX_CLASSIFICAZIONE', 'am_classificazione');
define('AM_TAX_INFRASPEC', 'am_formavarieta');
define('AM_TAX_AUTHOR', 'am_autore');
define('AM_TAX_ARTICLE_CAT', 'am_articolo_cat');

// CPT capabilities (derived from capability_type [am_fungo, am_funghi]).
define('AM_CAP_CREATE_POSTS', 'create_am_funghi');
define('AM_CAP_EDIT_POST', 'edit_am_fungo');
define('AM_CAP_READ_POST', 'read_am_fungo');
define('AM_CAP_DELETE_POST', 'delete_am_fungo');
define('AM_CAP_EDIT_POSTS', 'edit_am_funghi');
define('AM_CAP_EDIT_OTHERS_POSTS', 'edit_others_am_funghi');
define('AM_CAP_PUBLISH_POSTS', 'publish_am_funghi');
define('AM_CAP_READ_PRIVATE_POSTS', 'read_private_am_funghi');
define('AM_CAP_DELETE_POSTS', 'delete_am_funghi');
define('AM_CAP_EDIT_PUBLISHED_POSTS', 'edit_published_am_funghi');
define('AM_CAP_DELETE_PUBLISHED_POSTS', 'delete_published_am_funghi');
define('AM_CAP_EDIT_PRIVATE_POSTS', 'edit_private_am_funghi');
define('AM_CAP_DELETE_PRIVATE_POSTS', 'delete_private_am_funghi');
define('AM_CAP_DELETE_OTHERS_POSTS', 'delete_others_am_funghi');
define('AM_CAP_MANAGE_SETTINGS', 'am_manage_settings');

// Legacy AM role slugs retained only for cleanup/backward compatibility.
define('AM_ROLE_CONTRIBUTOR', 'am_contributor');
define('AM_ROLE_REVIEWER', 'am_reviewer');
define('AM_ROLE_MANAGER', 'am_manager');



function am_get_required_caps_for_admin_editor(): array {
	return [
		AM_CAP_CREATE_POSTS,
		AM_CAP_EDIT_POST,
		AM_CAP_READ_POST,
		AM_CAP_DELETE_POST,
		AM_CAP_EDIT_POSTS,
		AM_CAP_EDIT_OTHERS_POSTS,
		AM_CAP_PUBLISH_POSTS,
		AM_CAP_READ_PRIVATE_POSTS,
		AM_CAP_DELETE_POSTS,
		AM_CAP_DELETE_OTHERS_POSTS,
		AM_CAP_EDIT_PUBLISHED_POSTS,
		AM_CAP_DELETE_PUBLISHED_POSTS,
		AM_CAP_EDIT_PRIVATE_POSTS,
		AM_CAP_DELETE_PRIVATE_POSTS,
		AM_CAP_MANAGE_SETTINGS,
		'manage_terms',
		'edit_terms',
		'delete_terms',
		'assign_terms',
		'upload_files',
	];
}

function am_ensure_admin_editor_caps_runtime(): void {
	if (!function_exists('wp_roles')) { return; }

	$caps = am_get_required_caps_for_admin_editor();
	$roles_obj = wp_roles();

	if (!$roles_obj || !isset($roles_obj->roles) || !is_array($roles_obj->roles)) {
		return;
	}

	foreach (array_keys($roles_obj->roles) as $role_key) {
		$role = get_role((string) $role_key);
		if (!$role) { continue; }

		if (in_array((string) $role_key, ['administrator', 'editor'], true)) {
			foreach ($caps as $cap) {
				$role->add_cap($cap);
			}
			continue;
		}

		foreach ($caps as $cap) {
			if ($role->has_cap($cap)) {
				$role->remove_cap($cap);
			}
		}
	}

	if (function_exists('am_cleanup_legacy_roles_and_permissions')) {
		am_cleanup_legacy_roles_and_permissions();
	}
}

function am_ensure_admin_editor_caps_runtime_once(): void {
	$last = (int) get_option('am_caps_runtime_checked_1_5_4', 0);
	if ($last > 0 && (time() - $last) < HOUR_IN_SECONDS) {
		return;
	}

	am_ensure_admin_editor_caps_runtime();
	update_option('am_caps_runtime_checked_1_5_4', time(), false);
}

add_action('admin_init', 'am_ensure_admin_editor_caps_runtime_once', 3);

function am_user_is_admin_or_editor(): bool {
	if (!is_user_logged_in()) { return false; }
	$user = wp_get_current_user();
	$roles = is_array($user->roles) ? $user->roles : [];
	return in_array('administrator', $roles, true) || in_array('editor', $roles, true) || current_user_can('manage_options');
}

function am_cleanup_legacy_roles_and_permissions(): void {
	$legacy_roles = [
		'am_contributor',
		'am_reviewer',
		'am_manager',
		'am_author',
	];

	foreach ($legacy_roles as $legacy_role) {
		remove_role($legacy_role);
	}

	delete_transient('am_last_saved_perms');
}


require_once AM_PATH . 'includes/class-am-activator.php';
require_once AM_PATH . 'includes/class-am-post-types.php';
require_once AM_PATH . 'includes/class-am-taxonomies.php';
require_once AM_PATH . 'includes/class-am-admin.php';
require_once AM_PATH . 'includes/class-am-frontend.php';
require_once AM_PATH . 'includes/class-am-widget-random-funghi.php';
require_once AM_PATH . 'includes/elementor/class-am-elementor-conditions.php';

register_activation_hook(__FILE__, ['AM_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['AM_Activator', 'deactivate']);

add_action('plugins_loaded', function () {
	// Keep roles/capabilities in sync even after updates.
	AM_Activator::ensure_caps();
	AM_Post_Types::init();
	AM_Taxonomies::init();
	AM_Admin::init();
	AM_Frontend::init();
});