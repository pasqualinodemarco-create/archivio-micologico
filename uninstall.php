<?php
// If uninstall not called from WordPress, exit.
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

// --- Remove all plugin options ---
$options = [
	'am_require_login',
	'am_default_status',
	'am_max_upload_mb',
	'am_edit_page_id',
	'am_archive_page_id',
	'am_show_submit_result_admin',
	'am_submit_result_ttl',
	'am_enable_debug_panel',
	'am_seo_mode',
	'am_articles_order',
	'am_allow_edit_others',
	'am_wp_perms',
	'am_roles_create',
	'am_roles_edit',
	'am_random_widget_title',
	'am_random_widget_count',
	'am_random_widget_autoplay',
	'am_random_widget_interval',
	'am_random_widget_visible',
	'am_debug_log',
	'am_caps_runtime_checked_1_5_4',
];
foreach ($options as $option) {
	delete_option($option);
}

// --- Remove AM capabilities from all roles ---
$am_caps = [
	'create_am_funghi',
	'edit_am_fungo',
	'read_am_fungo',
	'delete_am_fungo',
	'edit_am_funghi',
	'edit_others_am_funghi',
	'publish_am_funghi',
	'read_private_am_funghi',
	'delete_am_funghi',
	'delete_others_am_funghi',
	'edit_published_am_funghi',
	'delete_published_am_funghi',
	'edit_private_am_funghi',
	'delete_private_am_funghi',
	'am_manage_settings',
];
$roles_obj = wp_roles();
if ($roles_obj && isset($roles_obj->roles) && is_array($roles_obj->roles)) {
	foreach (array_keys($roles_obj->roles) as $role_key) {
		$role = get_role((string) $role_key);
		if (!$role) { continue; }
		foreach ($am_caps as $cap) {
			if ($role->has_cap($cap)) {
				$role->remove_cap($cap);
			}
		}
	}
}

// --- Remove legacy custom roles ---
$legacy_roles = [
	'am_contributor',
	'am_reviewer',
	'am_manager',
	'am_author',
];
foreach ($legacy_roles as $role) {
	remove_role($role);
}

// --- Remove transients ---
delete_transient('am_last_saved_perms');
