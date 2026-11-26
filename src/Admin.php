<?php

namespace ArchivioMicologico;

use Throwable;

if (!defined('ABSPATH')) { exit; }

class Admin {

	/**
	 * Append an entry to the internal debug log (stored in option am_debug_log).
	 * Used in Settings → Debug.
	 */
	private static function am_debug_log_append(string $tag, array $data = []): void {
		$log = get_option('am_debug_log', []);
		if (!is_array($log)) { $log = []; }
		$user = wp_get_current_user();
		$entry = [
			'time' => time(),
			'tag'  => $tag,
			'user' => ($user && $user->exists()) ? ($user->user_login . ' (' . $user->ID . ')') : 'guest',
			'data' => $data,
		];
		array_unshift($log, $entry);
		$log = array_slice($log, 0, 50);
		update_option('am_debug_log', $log, false);
	}

	/**
	 * Register a redirect that runs on the load-$hook phase (before any output is sent).
	 * Prevents white pages caused by "headers already sent" when redirecting from page callbacks.
	 */
	private static function register_load_redirect(string $hook_suffix, string $ctx, string $target_url, callable $cap_check, string $cap_die_msg): void {
		add_action("load-$hook_suffix", function() use ($ctx, $target_url, $cap_check, $cap_die_msg) {
			$hs_file = null;
			$hs_line = null;
			$headers_sent = headers_sent($hs_file, $hs_line);
			self::am_debug_log_append('admin_redirect_load_try', [
				'ctx' => $ctx,
				'target' => $target_url,
				'headers_sent' => $headers_sent ? 1 : 0,
				'hs_file' => $hs_file,
				'hs_line' => $hs_line,
				'ob_level' => function_exists('ob_get_level') ? ob_get_level() : null,
				'ob_len'   => function_exists('ob_get_length') ? ob_get_length() : null,
				'uri'      => $_SERVER['REQUEST_URI'] ?? '',
				'referer'  => $_SERVER['HTTP_REFERER'] ?? '',
			]);

			if (!call_user_func($cap_check)) {
				wp_die(esc_html($cap_die_msg));
			}

			// Primary path: header redirect, runs early.
			if (!$headers_sent) {
				wp_safe_redirect($target_url);
				exit;
			}

			// Fallback path: never leave a blank screen.
			self::am_debug_log_append('admin_redirect_load_fallback', [
				'ctx' => $ctx,
				'target' => $target_url,
			]);

			echo '<div class="wrap">';
			echo '<h1>' . esc_html__('Reindirizzamento…', 'archivio-micologico') . '</h1>';
			echo '<p>' . esc_html__('La pagina richiesta è stata reindirizzata. Se non succede nulla automaticamente, usa il pulsante qui sotto.', 'archivio-micologico') . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url($target_url) . '">' . esc_html__('Apri la pagina di destinazione', 'archivio-micologico') . '</a></p>';
			echo '<script>setTimeout(function(){ window.location.href = ' . wp_json_encode($target_url) . '; }, 600);</script>';
			echo '</div>';
			exit;
		});
	}



	private static function get_front_submit_page_url(): string {
		$chosen = AdminPages::page_url('submit');
		if ($chosen !== '') {
			return $chosen;
		}
		$pages = [];
		add_filter('posts_where', [__CLASS__, 'filter_submit_form_shortcode_page_where']);
		$pages = get_posts([
			'post_type'         => 'page',
			'post_status'       => ['publish', 'private'],
			'numberposts'       => 1,
			'orderby'           => 'date',
			'order'             => 'DESC',
			'no_found_rows'     => true,
			'suppress_filters'  => false,
		]);
		remove_filter('posts_where', [__CLASS__, 'filter_submit_form_shortcode_page_where']);

		if (!empty($pages) && !empty($pages[0]->ID)) {
			$url = get_permalink((int) $pages[0]->ID);
			if (is_string($url) && $url !== '') {
				return $url;
			}
		}

		return admin_url('post-new.php?post_type=' . AM_CPT);
	}

	public static function filter_submit_form_shortcode_page_where(string $where): string {
		global $wpdb;
		return $where . $wpdb->prepare(" AND {$wpdb->posts}.post_content LIKE %s", '%[am_submit_form%');
	}

	private static function get_menu_icon(): string {
		// Simple monochrome mushroom icon (SVG) encoded as data URI for admin menu.
		$svg = "<svg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 20 20'>" .
			"<path fill='#a7aaad' d='M10 2c4.4 0 8 2.5 8 5.6 0 2.1-1.8 3.9-4.6 4.9-1 .4-2.2.6-3.4.7.2 1.3.5 2.6.4 3.8 0 1.1-.8 2-1.9 2H8.9c-1.1 0-1.9-.9-1.9-2 0-1.2.2-2.5.4-3.8-1.2-.1-2.4-.3-3.4-.7C1.8 11.5 0 9.7 0 7.6 0 4.5 3.6 2 8 2h2zm-3.6 8.4c.9.3 2 .5 3.6.5s2.7-.2 3.6-.5c2-.7 3.4-1.9 3.4-2.8C16.9 5.6 13.9 4 10 4S3.1 5.6 3.1 7.6c0 .9 1.4 2.1 3.3 2.8z'/>" .
			"</svg>";
		return 'data:image/svg+xml;base64,' . base64_encode($svg);
	}

	private static function get_submenu_label(string $icon, string $label): string {
		return '<span class="am-admin-menu-item"><span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span><span class="am-admin-menu-item__text">' . esc_html($label) . '</span></span>';
	}

	public static function init(): void {
		add_action('admin_menu', [__CLASS__, 'admin_menu']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
		add_action('admin_init', [__CLASS__, 'register_settings']);
		
		// Custom admin-post handlers for managed hosting (no options.php dependency)
		add_action('admin_post_am_save_settings', [__CLASS__, 'handle_save_settings']);
		add_action('admin_post_am_clear_debug_log', [__CLASS__, 'handle_clear_debug_log']);
		add_action('admin_post_am_export_debug_log', [__CLASS__, 'handle_export_debug_log']);
		add_action('admin_post_am_purge_legacy_terms', [__CLASS__, 'handle_purge_legacy_terms']);
		add_action('admin_post_am_vocab_add', [__CLASS__, 'handle_vocab_add']);
		add_action('admin_post_am_vocab_update', [__CLASS__, 'handle_vocab_update']);
		add_action('admin_post_am_vocab_delete', [__CLASS__, 'handle_vocab_delete']);
		add_action('admin_post_am_vocab_merge', [__CLASS__, 'handle_vocab_merge']);
		add_action('restrict_manage_posts', [__CLASS__, 'render_admin_post_filters']);
		add_filter('parse_query', [__CLASS__, 'apply_admin_post_filters']);
		// Rename the automatic first submenu item to match our desired label.
		add_action('admin_menu', [__CLASS__, 'tweak_submenu_labels'], 999);
		// Schede lists and editor have no menu entry of their own: keep Archivio Micologico highlighted.
		add_filter('parent_file', [__CLASS__, 'highlight_menu']);
	}

	/**
	 * Tool: purge legacy vocabularies for Forma/Varietà and Autore.
	 * This permanently deletes ALL terms from AM_TAX_INFRASPEC and AM_TAX_AUTHOR.
	 */

	public static function handle_purge_legacy_terms(): void {
		if (!current_user_can(AM_CAP_MANAGE_SETTINGS) && !current_user_can('manage_options')) {
			wp_die(esc_html__('Non autorizzato.', 'archivio-micologico'));
		}
		check_admin_referer('am_purge_legacy_terms');
		$confirm = isset($_POST['am_confirm']) ? (int) $_POST['am_confirm'] : 0;
		if (!$confirm) {
			wp_safe_redirect(admin_url('admin.php?page=am_settings&am_err=confirm'));
			exit;
		}

		$taxes = [];
		if (defined('AM_TAX_INFRASPEC')) { $taxes[] = AM_TAX_INFRASPEC; }
		if (defined('AM_TAX_AUTHOR')) { $taxes[] = AM_TAX_AUTHOR; }

		$deleted = 0;
		foreach ($taxes as $tax) {
			if (!taxonomy_exists($tax)) { continue; }
			$terms = get_terms([
				'taxonomy' => $tax,
				'hide_empty' => false,
				'fields' => 'ids',
				'number' => 0,
			]);
			if (is_wp_error($terms) || empty($terms)) { continue; }
			foreach ($terms as $tid) {
				$tid = (int) $tid;
				$res = wp_delete_term($tid, $tax);
				if (!is_wp_error($res)) { $deleted++; }
			}
		}

		// Also clear legacy meta IDs that pointed to those terms.
		global $wpdb;
		$wpdb->delete( $wpdb->postmeta, ['meta_key' => 'am_infra_id'], ['%s'] );
		$wpdb->delete( $wpdb->postmeta, ['meta_key' => 'am_author_id'], ['%s'] );

		wp_safe_redirect(admin_url('admin.php?page=am_settings&am_purged=1&am_purged_n=' . intval($deleted)));
		exit;
	}

	// =========================
	// Vocabolari: Forma/Varietà e Autore
	// =========================
	private static function vocabolari_get_tax_and_labels(string $tab): array {
		$tab = $tab === 'author' ? 'author' : 'infra';

		$infra_tax = defined('AM_TAX_INFRASPEC') ? AM_TAX_INFRASPEC : '';
		$author_tax = defined('AM_TAX_AUTHOR') ? AM_TAX_AUTHOR : '';

		if ($tab === 'author') {
			return [
				'tab' => 'author',
				'tax' => $author_tax,
				'title' => __('Vocabolari', 'archivio-micologico'),
				'section' => __('Autore', 'archivio-micologico'),
				'add_label' => __('Aggiungi autore', 'archivio-micologico'),
				'placeholder' => __('Es. (Pers.) Fr.', 'archivio-micologico'),
			];
		}

		return [
			'tab' => 'infra',
			'tax' => $infra_tax,
			'title' => __('Vocabolari', 'archivio-micologico'),
			'section' => __('Forma / Varietà', 'archivio-micologico'),
			'add_label' => __('Aggiungi forma/varietà', 'archivio-micologico'),
			'placeholder' => __('Es. f. albina / var. rubra', 'archivio-micologico'),
		];
	}

	public static function render_vocabolari_page(): void {
		AdminPages::vocabularies();
	}

	public static function handle_vocab_add(): void {
		if (!(current_user_can('manage_terms') || current_user_can('manage_options')) && !current_user_can('manage_options')) {
			wp_die(esc_html__('Non autorizzato.', 'archivio-micologico'));
		}
		check_admin_referer('am_vocab_add');

		$tab = isset($_POST['tab']) ? sanitize_key((string)$_POST['tab']) : 'infra';
		$cfg = self::vocabolari_get_tax_and_labels($tab);
		$tax = (string)($cfg['tax'] ?? '');
		$name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
		$redirect = admin_url('admin.php?page=am_vocabolari&tab=' . $cfg['tab']);

		if (!$tax || !taxonomy_exists($tax)) {
			wp_safe_redirect(add_query_arg(['am_err' => 'Tassonomia non disponibile'], $redirect));
			exit;
		}
		if (mb_strlen(trim($name)) < 1) {
			wp_safe_redirect(add_query_arg(['am_err' => 'Nome non valido'], $redirect));
			exit;
		}

		$res = wp_insert_term($name, $tax);
		if (is_wp_error($res)) {
			wp_safe_redirect(add_query_arg(['am_err' => $res->get_error_message()], $redirect));
			exit;
		}

		wp_safe_redirect(add_query_arg(['am_msg' => 'added'], $redirect));
		exit;
	}

	public static function handle_vocab_update(): void {
		if (!current_user_can('manage_terms') && !current_user_can('manage_options')) {
			wp_die(esc_html__('Non autorizzato.', 'archivio-micologico'));
		}
		check_admin_referer('am_vocab_update');

		$tab = isset($_POST['tab']) ? sanitize_key((string)$_POST['tab']) : 'infra';
		$cfg = self::vocabolari_get_tax_and_labels($tab);
		$tax = (string)($cfg['tax'] ?? '');
		$term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
		$name = isset($_POST['name']) ? sanitize_text_field(wp_unslash((string) $_POST['name'])) : '';
		$redirect = admin_url('admin.php?page=am_vocabolari&tab=' . $cfg['tab']);

		if (!$tax || !taxonomy_exists($tax) || $term_id <= 0) {
			wp_safe_redirect(add_query_arg(['am_err' => 'Dati non validi'], $redirect));
			exit;
		}

		$res = wp_update_term($term_id, $tax, ['name' => $name]);
		if (is_wp_error($res)) {
			wp_safe_redirect(add_query_arg(['am_err' => $res->get_error_message()], $redirect));
			exit;
		}

		wp_safe_redirect(add_query_arg(['am_msg' => 'updated'], $redirect));
		exit;
	}

	public static function handle_vocab_delete(): void {
		if (!(current_user_can('manage_terms') || current_user_can('manage_options')) || current_user_can('manage_options') && !current_user_can('manage_options')) {
			wp_die(esc_html__('Non autorizzato.', 'archivio-micologico'));
		}
		check_admin_referer('am_vocab_delete');

		$tab = isset($_POST['tab']) ? sanitize_key((string)$_POST['tab']) : 'infra';
		$cfg = self::vocabolari_get_tax_and_labels($tab);
		$tax = (string)($cfg['tax'] ?? '');
		$term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
		$redirect = admin_url('admin.php?page=am_vocabolari&tab=' . $cfg['tab']);

		if (!$tax || !taxonomy_exists($tax) || $term_id <= 0) {
			wp_safe_redirect(add_query_arg(['am_err' => 'Dati non validi'], $redirect));
			exit;
		}

		$res = wp_delete_term($term_id, $tax);
		if (is_wp_error($res)) {
			wp_safe_redirect(add_query_arg(['am_err' => $res->get_error_message()], $redirect));
			exit;
		}

		wp_safe_redirect(add_query_arg(['am_msg' => 'deleted'], $redirect));
		exit;
	}

	public static function handle_vocab_merge(): void {
		if (!current_user_can('manage_terms') && !current_user_can('manage_options')) {
			wp_die(esc_html__('Non autorizzato.', 'archivio-micologico'));
		}
		check_admin_referer('am_vocab_merge');

		$tab = isset($_POST['tab']) ? sanitize_key((string)$_POST['tab']) : 'infra';
		$cfg = self::vocabolari_get_tax_and_labels($tab);
		$tax = (string)($cfg['tax'] ?? '');
		$from_id = isset($_POST['from_term_id']) ? intval($_POST['from_term_id']) : 0;
		$to_id   = isset($_POST['to_term_id']) ? intval($_POST['to_term_id']) : 0;

		$redirect = admin_url('admin.php?page=am_vocabolari&tab=' . $cfg['tab']);

		if (!$tax || !taxonomy_exists($tax)) {
			wp_safe_redirect(add_query_arg(['am_err' => 'Tassonomia non disponibile'], $redirect));
			exit;
		}
		if ($from_id <= 0 || $to_id <= 0 || $from_id === $to_id) {
			wp_safe_redirect(add_query_arg(['am_err' => 'Selezione non valida'], $redirect));
			exit;
		}

		$from = get_term($from_id, $tax);
		$to   = get_term($to_id, $tax);
		if (!$from || is_wp_error($from) || !$to || is_wp_error($to)) {
			wp_safe_redirect(add_query_arg(['am_err' => 'Voce non trovata'], $redirect));
			exit;
		}

		// Reassign all objects (posts) from source to target
		$object_ids = get_objects_in_term($from_id, $tax);
		if (!is_wp_error($object_ids) && !empty($object_ids)) {
			foreach ($object_ids as $pid) {
				$pid = intval($pid);
				if ($pid <= 0) { continue; }
				wp_remove_object_terms($pid, [$from_id], $tax);
				wp_set_object_terms($pid, array_values(array_unique(array_merge(wp_get_object_terms($pid, $tax, ['fields' => 'ids']), [$to_id]))), $tax, false);
			}
		}

		// Delete source term
		$res = wp_delete_term($from_id, $tax);
		if (is_wp_error($res)) {
			wp_safe_redirect(add_query_arg(['am_err' => $res->get_error_message()], $redirect));
			exit;
		}

		wp_safe_redirect(add_query_arg(['am_msg' => 'merged'], $redirect));
		exit;
	}


	/**
	 * Hard debug log stored in DB (last 50 entries) to diagnose save/redirect issues on hosts like Altervista.
	 */
	public static function am_debug_add(string $tag, array $data = []): void {
		$log = get_option('am_debug_log', []);
		if (!is_array($log)) { $log = []; }
		$user = wp_get_current_user();
		$entry = [
			'time' => time(),
			'tag'  => $tag,
			'user' => ($user && $user->exists()) ? ($user->user_login . ' (' . $user->ID . ')') : 'guest',
			'data' => $data,
		];
		array_unshift($log, $entry);
		$log = array_slice($log, 0, 50);
		update_option('am_debug_log', $log, false);
	}

	public static function enqueue_admin_assets(): void {
		wp_enqueue_style('am-admin', AM_URL . 'assets/css/am-admin.css', [], AM_VERSION);
	}

	public static function admin_menu(): void {
		$parent_slug = 'am_dashboard';

		add_menu_page(
			__('Archivio Micologico', 'archivio-micologico'),
			__('Archivio Micologico', 'archivio-micologico'),
			AM_CAP_EDIT_POSTS,
			$parent_slug,
			[__CLASS__, 'render_dashboard_page'],
			self::get_menu_icon(),
			21
		);

		add_submenu_page(
			$parent_slug,
			__('Dashboard', 'archivio-micologico'),
			self::get_submenu_label('dashicons-dashboard', __('Dashboard', 'archivio-micologico')),
			AM_CAP_EDIT_POSTS,
			$parent_slug,
			[__CLASS__, 'render_dashboard_page']
		);

		$hook_tax = add_submenu_page(
			$parent_slug,
			__('Tassonomia', 'archivio-micologico'),
			self::get_submenu_label('dashicons-networking', __('Tassonomia', 'archivio-micologico')),
			'manage_terms',
			'am_classificazione',
			[AdminPages::class, 'taxonomy']
		);
		$hook_vocab = add_submenu_page(
			$parent_slug,
			__('Vocabolari', 'archivio-micologico'),
			self::get_submenu_label('dashicons-book-alt', __('Vocabolari', 'archivio-micologico')),
			'manage_terms',
			'am_vocabolari',
			[__CLASS__, 'render_vocabolari_page']
		);
		// The admin pages reuse the interface of [am_tassonomia] and [am_vocabolari].
		foreach ([$hook_tax, $hook_vocab] as $hook) {
			if ($hook) {
				add_action('load-' . $hook, static function (): void {
					add_action('admin_enqueue_scripts', [TermManager::class, 'enqueue_files']);
				});
			}
		}

		$hook_articles = add_submenu_page(
			$parent_slug,
			__('Articoli', 'archivio-micologico'),
			self::get_submenu_label('dashicons-media-document', __('Articoli', 'archivio-micologico')),
			AM_CAP_EDIT_POSTS,
			'am_articles',
			'__return_null'
		);
		if ($hook_articles) {
			self::register_load_redirect(
				(string) $hook_articles,
				'articles',
				admin_url('edit.php?post_type=' . AM_ARTICLE_CPT),
				function() { return current_user_can(AM_CAP_EDIT_POSTS); },
				__('Non hai il permesso di accedere a questa pagina.', 'archivio-micologico')
			);
		}

		$hook_add_article = add_submenu_page(
			$parent_slug,
			__('Nuovo Articolo', 'archivio-micologico'),
			self::get_submenu_label('dashicons-edit-page', __('Nuovo Articolo', 'archivio-micologico')),
			AM_CAP_CREATE_POSTS,
			'am_add_new_article',
			'__return_null'
		);
		if ($hook_add_article) {
			self::register_load_redirect(
				(string) $hook_add_article,
				'add_new_article',
				admin_url('post-new.php?post_type=' . AM_ARTICLE_CPT),
				function() { return current_user_can(AM_CAP_CREATE_POSTS); },
				__('Non hai il permesso di accedere a questa pagina.', 'archivio-micologico')
			);
		}

		$hook_article_cats = add_submenu_page(
			$parent_slug,
			__('Categorie Articoli', 'archivio-micologico'),
			self::get_submenu_label('dashicons-tag', __('Categorie Articoli', 'archivio-micologico')),
			'manage_terms',
			'am_article_categories',
			'__return_null'
		);
		if ($hook_article_cats) {
			self::register_load_redirect(
				(string) $hook_article_cats,
				'article_categories',
				admin_url('edit-tags.php?taxonomy=' . AM_TAX_ARTICLE_CAT . '&post_type=' . AM_ARTICLE_CPT),
				function() { return (current_user_can('manage_terms') || current_user_can('manage_options')) || current_user_can('manage_options'); },
				__('Non hai il permesso di accedere a questa pagina.', 'archivio-micologico')
			);
		}

		add_submenu_page(
			$parent_slug,
			__('Impostazioni', 'archivio-micologico'),
			self::get_submenu_label('dashicons-admin-generic', __('Impostazioni', 'archivio-micologico')),
			AM_CAP_MANAGE_SETTINGS,
			'am_settings',
			[__CLASS__, 'render_settings_page']
		);

		add_submenu_page(
			$parent_slug,
			__('Istruzioni', 'archivio-micologico'),
			self::get_submenu_label('dashicons-welcome-learn-more', __('Istruzioni', 'archivio-micologico')),
			AM_CAP_EDIT_POSTS,
			'am_instructions',
			[__CLASS__, 'render_instructions_page']
		);

		add_submenu_page(
			$parent_slug,
			__('Info / Versione', 'archivio-micologico'),
			self::get_submenu_label('dashicons-info-outline', __('Info / Versione', 'archivio-micologico')),
			AM_CAP_EDIT_POSTS,
			'am_info_versione',
			[__CLASS__, 'render_info_version_page']
		);
	}

	public static function highlight_menu($parent_file) {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if ($screen && $screen->post_type === AM_CPT && in_array($screen->base, ['edit', 'post'], true)) {
			global $submenu_file;
			$submenu_file = 'am_dashboard';
			return 'am_dashboard';
		}
		if ($screen && $screen->base === 'edit-tags' && $screen->taxonomy === AM_TAX_CLASSIFICAZIONE) {
			global $submenu_file;
			$submenu_file = 'am_classificazione';
			return 'am_dashboard';
		}
		return $parent_file;
	}

	public static function tweak_submenu_labels(): void {
		global $submenu;
		if (!isset($submenu['am_dashboard'][0][0])) {
			return;
		}
		// WordPress adds an automatic submenu item with the same label as the parent.
		// We rename it to match the desired first entry.
		$submenu['am_dashboard'][0][0] = self::get_submenu_label('dashicons-dashboard', __('Dashboard', 'archivio-micologico'));
	}









public static function render_admin_post_filters(): void {
	global $typenow;
	if ($typenow !== AM_CPT) {
		return;
	}
	if (!current_user_can(AM_CAP_EDIT_POSTS)) {
		return;
	}


	$selected_division = isset($_GET['am_division']) ? absint($_GET['am_division']) : 0;
	$selected_family = isset($_GET['am_family']) ? absint($_GET['am_family']) : 0;
	$selected_genus = isset($_GET['am_genus']) ? absint($_GET['am_genus']) : 0;
	$selected_species = isset($_GET['am_species']) ? absint($_GET['am_species']) : 0;

	$all_terms = get_terms(['taxonomy' => AM_TAX_CLASSIFICAZIONE, 'hide_empty' => false]);
	$famiglie = [];
	$generi = [];
	$specie_terms = [];
	if (!is_wp_error($all_terms) && $all_terms) {
		foreach ($all_terms as $t) {
			$depth = count(get_ancestors($t->term_id, AM_TAX_CLASSIFICAZIONE));
				if ($depth === 0) { continue; }
			elseif ($depth === 1) { $famiglie[] = $t; }
			elseif ($depth === 2) { $generi[] = $t; }
			else { $specie_terms[] = $t; }
		}
	}
	// For admin filters we show all levels (not cascading) to keep it simple.
	$all_terms = get_terms(['taxonomy' => AM_TAX_CLASSIFICAZIONE, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);

	echo '<span class="am-filters-row">';

	echo '<select name="am_division">';
	echo '<option value="0">' . esc_html__('Tutte le divisioni', 'archivio-micologico') . '</option>';
	$divisions = get_terms(['taxonomy' => AM_TAX_CLASSIFICAZIONE, 'hide_empty' => false, 'parent' => 0, 'orderby' => 'name', 'order' => 'ASC']);
	if (!is_wp_error($divisions)) { foreach ($divisions as $t) { if (in_array((string) $t->slug, ['basidiomycota','ascomycota'], true)) { echo '<option value= . esc_attr($t->term_id) .  ' . selected($selected_division, $t->term_id, false) . '>' . esc_html($t->name) . '</option>'; } } }
	echo '</select>';

		echo '<select name="am_family">';
	echo '<option value="0">' . esc_html__('Tutte le famiglie', 'archivio-micologico') . '</option>';
	if (!is_wp_error($famiglie)) {
		foreach ($famiglie as $t) {
			echo '<option value="' . esc_attr($t->term_id) . '" ' . selected($selected_family, $t->term_id, false) . '>' . esc_html($t->name) . '</option>';
		}
	}
	echo '</select>';

	// Genere: terms with parent != 0 and whose parent is a family.
	echo '<select name="am_genus">';
	echo '<option value="0">' . esc_html__('Tutti i generi', 'archivio-micologico') . '</option>';
	if (!is_wp_error($all_terms)) {
		foreach ($all_terms as $t) {
			if ((int) $t->parent === 0) { continue; }
			$parent = get_term((int) $t->parent, AM_TAX_CLASSIFICAZIONE);
			if (!$parent || is_wp_error($parent) || (int) $parent->parent !== 0) { continue; }
			echo '<option value="' . esc_attr($t->term_id) . '" ' . selected($selected_genus, $t->term_id, false) . '>' . esc_html($t->name) . '</option>';
		}
	}
	echo '</select>';

	// Specie: depth >= 2
	echo '<select name="am_species">';
	echo '<option value="0">' . esc_html__('Tutte le specie', 'archivio-micologico') . '</option>';
	if (!is_wp_error($all_terms)) {
		foreach ($all_terms as $t) {
			$anc = get_ancestors($t->term_id, AM_TAX_CLASSIFICAZIONE);
			if (!is_array($anc) || count($anc) < 2) { continue; }
			echo '<option value="' . esc_attr($t->term_id) . '" ' . selected($selected_species, $t->term_id, false) . '>' . esc_html($t->name) . '</option>';
		}
	}
	echo '</select>';
	echo '</span>';
}

public static function apply_admin_post_filters($query) {
	global $pagenow;
	if (!is_admin() || $pagenow !== 'edit.php') {
		return $query;
	}
	$pt = isset($_GET['post_type']) ? sanitize_key((string) $_GET['post_type']) : 'post';
	if ($pt !== AM_CPT) {
		return $query;
	}
	if (!$query->is_main_query()) {
		return $query;
	}

	$division = isset($_GET['am_division']) ? absint($_GET['am_division']) : 0;
	$family  = isset($_GET['am_family']) ? absint($_GET['am_family']) : 0;
	$genus   = isset($_GET['am_genus']) ? absint($_GET['am_genus']) : 0;
	$species = isset($_GET['am_species']) ? absint($_GET['am_species']) : 0;
	$term_id = $species ?: ($genus ?: ($family ?: $order));
	if ($term_id) {
		$query->set('tax_query', [[
			'taxonomy' => AM_TAX_CLASSIFICAZIONE,
			'field' => 'term_id',
			'terms' => [$term_id],
			'include_children' => true,
		]]);
	}
	return $query;
}

	public static function register_settings(): void {
		register_setting('am_settings', 'am_require_login');
		register_setting('am_settings', 'am_default_status');
		register_setting('am_settings', 'am_max_upload_mb');
		register_setting('am_settings', 'am_edit_page_id');
		register_setting('am_settings', 'am_show_submit_result_admin');
		register_setting('am_settings', 'am_submit_result_ttl');
		register_setting('am_settings', 'am_enable_debug_panel');
		register_setting('am_settings', 'am_seo_mode');
		register_setting('am_settings', 'am_articles_order');
		register_setting('am_settings', 'am_recent_entries_count', [
			'type' => 'integer',
			'default' => 5,
			'sanitize_callback' => static function ($value): int {
				return is_numeric($value) ? max(0, min(20, (int) $value)) : 5;
			},
		]);

		register_setting('am_settings', 'am_random_widget_title');
		register_setting('am_settings', 'am_random_widget_count', ['sanitize_callback' => function($v){ $v = (int) $v; return max(1, min(12, $v)); }]);
		register_setting('am_settings', 'am_random_widget_autoplay', ['sanitize_callback' => function($v){ return ((int) $v) === 1 ? 1 : 0; }]);
		register_setting('am_settings', 'am_random_widget_interval', ['sanitize_callback' => function($v){ $v = (int) $v; return max(2, min(20, $v)); }]);
		register_setting('am_settings', 'am_random_widget_visible', ['sanitize_callback' => function($v){ $v = (int) $v; return max(1, min(3, $v)); }]);
// 1.6.18: grouped by what they control. AdminPages::settings() prints the cards.
		foreach (AdminPages::setting_sections() as $section_id => [$section_title]) {
			add_settings_section($section_id, $section_title, '__return_false', 'am_settings');
		}

		add_settings_field('am_recent_entries_count', __('Ultime schede in archivio', 'archivio-micologico'), static function () {
			$count = max(0, min(20, (int) get_option('am_recent_entries_count', 5)));
			echo '<input type="number" min="0" max="20" id="am_recent_entries_count" name="am_recent_entries_count" value="' . esc_attr((string) $count) . '" aria-describedby="am_recent_entries_count_help" />';
			echo '<p class="description" id="am_recent_entries_count_help">' . esc_html__('Scegli quante schede mostrare, fino a 20. Imposta 0 per nascondere il pannello nell’archivio.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_display', ['label_for' => 'am_recent_entries_count']);


		add_settings_field('am_random_widget_title', __('Titolo', 'archivio-micologico'), function () {
			$val = (string) get_option('am_random_widget_title', '');
			echo '<input type="text" class="regular-text" name="am_random_widget_title" value="' . esc_attr($val) . '" />';
			echo '<p class="description">' . esc_html__('Titolo usato dal widget se non ne imposti uno manualmente nella singola area widget.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_widget_random');

		add_settings_field('am_random_widget_count', __('Schede caricate', 'archivio-micologico'), function () {
			$val = (int) get_option('am_random_widget_count', 5);
			echo '<input type="number" min="1" max="12" name="am_random_widget_count" value="' . esc_attr((string) $val) . '" />';
			echo '<p class="description">' . esc_html__('Quante schede fungo casuali caricare nel carosello, da 1 a 12. Per avere sempre la griglia piena, usa un multiplo del numero di righe.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_widget_random');

		add_settings_field('am_random_widget_visible', __('Schede per riga', 'archivio-micologico'), function () {
			$val = (int) get_option('am_random_widget_visible', 3);
			echo '<input type="number" min="1" max="3" name="am_random_widget_visible" value="' . esc_attr((string) $val) . '" />';
			echo '<p class="description">' . esc_html__('Numero di schede per riga su desktop. Su schermi stretti il carosello si adatta a una o due colonne.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_widget_random');

		add_settings_field('am_random_widget_rows', __('Numero di righe', 'archivio-micologico'), function () {
			$rows = max(1, min(4, (int) get_option('am_random_widget_rows', 1)));
			echo '<input type="number" min="1" max="4" id="am_random_widget_rows" name="am_random_widget_rows" value="' . esc_attr($rows) . '" />';
			echo '<p class="description">' . esc_html__('Da una a quattro righe. Le schede riempiono le colonne dall’alto in basso: con 9 schede, 3 per riga e 2 righe vedi 6 schede alla volta e le altre scorrono.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_widget_random', ['label_for' => 'am_random_widget_rows']);

		add_settings_field('am_random_widget_autoplay', __('Movimento automatico', 'archivio-micologico'), function () {
			$val = (int) get_option('am_random_widget_autoplay', 1);
			echo '<label><input type="checkbox" name="am_random_widget_autoplay" value="1" ' . checked(1, $val, false) . ' /> ' . esc_html__('Attiva lo scorrimento automatico del carosello', 'archivio-micologico') . '</label>';
			echo '<p class="description">' . esc_html__('Si ferma quando il visitatore passa sopra con il mouse e per chi ha scelto di ridurre le animazioni. Nello shortcode: autoplay="0" o "1".', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_widget_random');

		add_settings_field('am_random_widget_interval', __('Intervallo (secondi)', 'archivio-micologico'), function () {
			$val = (int) get_option('am_random_widget_interval', 4);
			echo '<input type="number" min="2" max="20" name="am_random_widget_interval" value="' . esc_attr((string) $val) . '" />';
			echo '<p class="description">' . esc_html__('Intervallo tra uno scorrimento e il successivo. Valori bassi = carosello più veloce.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_widget_random');

		// Debug toggle (sempre visibile in Generali)
		add_settings_field('am_enable_debug_panel', __('Pannello debug', 'archivio-micologico'), function () {
	$val = (int) get_option('am_enable_debug_panel', 0);
	echo '<label><input type="checkbox" name="am_enable_debug_panel" value="1" ' . checked(1, $val, false) . ' /> ' . esc_html__('Mostra il log degli ultimi eventi in questa pagina', 'archivio-micologico') . '</label>';
	echo '<p class="description">' . esc_html__('Il log viene sempre salvato nel database. Attiva questa opzione per visualizzarlo in pagina (consigliato solo in caso di problemi).', 'archivio-micologico') . '</p>';
}, 'am_settings', 'am_tools');


		add_settings_field('am_require_login', __('Accesso al modulo', 'archivio-micologico'), function () {
			$val = (int) get_option('am_require_login', 1);
			echo '<label><input type="checkbox" name="am_require_login" value="1" ' . checked(1, $val, false) . ' /> ' . esc_html__('Ai visitatori non collegati i moduli mostrano il link per accedere', 'archivio-micologico') . '</label>';
			echo '<p class="description">' . esc_html__('Vale per Nuovo inserimento, Modifica scheda e Nuovo articolo. In ogni caso solo Amministratori ed Editori possono inviare.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_entry');

		add_settings_field('am_default_status', __('Stato delle nuove schede', 'archivio-micologico'), function () {
			$val = (string) get_option('am_default_status', 'pending');
			$options = [
				'publish' => __('Pubblicata subito', 'archivio-micologico'),
				'pending' => __('In attesa di revisione', 'archivio-micologico'),
				'draft' => __('Bozza', 'archivio-micologico'),
				'private' => __('Privata', 'archivio-micologico'),
			];
			echo '<select id="am_default_status" name="am_default_status">';
			foreach ($options as $k => $label) {
				echo '<option value="' . esc_attr($k) . '" ' . selected($k, $val, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__('Pubblicata: visibile subito nell’archivio. In attesa di revisione: compare nella Dashboard e va pubblicata da un Editore. Bozza e Privata: visibili solo a chi gestisce l’archivio.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_entry');

		add_settings_field('am_articles_order', __('Ordine nell’archivio', 'archivio-micologico'), function () {
			$val = (string) get_option('am_articles_order', 'date');
			$opts = [
				'date' => __('Data (più recenti prima)', 'archivio-micologico'),
				'alpha' => __('Alfabetico (A→Z)', 'archivio-micologico'),
			];
			echo '<select name="am_articles_order">';
			foreach ($opts as $k => $label) {
				echo '<option value="' . esc_attr($k) . '" ' . selected($val, $k, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__('Controlla l’ordine di visualizzazione della sezione Articoli in [am_archive].', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_articles');


		add_settings_field('am_max_upload_mb', __('Peso massimo immagini (MB)', 'archivio-micologico'), function () {
			$val = (int) get_option('am_max_upload_mb', 5);
			echo '<input type="number" min="1" max="50" name="am_max_upload_mb" value="' . esc_attr($val) . '" />';
			echo '<p class="description">' . esc_html__('Limite per le nuove immagini caricate dai moduli AM. Restano validi i limiti del server; i file già presenti non vengono modificati.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_entry');

		add_settings_field('am_single_show_title', __('Titolo dentro la scheda', 'archivio-micologico'), function () {
			$show = (int) get_option('am_single_show_title', 1);
			echo '<input type="hidden" name="am_single_show_title" value="0">';
			echo '<label><input type="checkbox" name="am_single_show_title" value="1" ' . checked(1, $show, false) . '> ';
			echo esc_html__('Mostra il titolo all’interno della scheda', 'archivio-micologico') . '</label>';
			echo '<p class="description">' . esc_html__('Togli la spunta se il tema mostra già il titolo sopra il contenuto.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_display');

		add_settings_field('am_article_status', __('Stato dei nuovi articoli', 'archivio-micologico'), function () {
			$val = EntryForms::article_status();
			echo '<select id="am_article_status" name="am_article_status">';
			foreach ([
				'publish' => __('Pubblicato subito', 'archivio-micologico'),
				'pending' => __('In attesa di revisione', 'archivio-micologico'),
				'draft' => __('Bozza', 'archivio-micologico'),
				'private' => __('Privato', 'archivio-micologico'),
			] as $value => $label) {
				echo '<option value="' . esc_attr($value) . '" ' . selected($val, $value, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select><p class="description">' . esc_html__('Vale per gli articoli inviati dal modulo pubblico, indipendentemente dallo stato delle schede.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_articles', ['label_for' => 'am_article_status']);

		add_settings_field('am_archive_articles', __('Riquadro nell’archivio', 'archivio-micologico'), function () {
			echo '<input type="hidden" name="am_archive_articles" value="0">';
			echo '<label><input type="checkbox" name="am_archive_articles" value="1" ' . checked(1, (int) get_option('am_archive_articles', 1), false) . '> ';
			echo esc_html__('Mostra gli articoli pubblicati nell’Archivio micologico, accanto alle Ultime schede', 'archivio-micologico') . '</label>';
			echo '<p class="description">' . esc_html__('Il riquadro parte chiuso; il link “Torna agli articoli” lo apre. Le categorie si gestiscono in Archivio Micologico → Categorie Articoli.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_articles');

		add_settings_field('am_sitemap_images', __('Immagini nella sitemap', 'archivio-micologico'), function () {
			echo '<input type="hidden" name="am_sitemap_images" value="0">';
			echo '<label><input type="checkbox" name="am_sitemap_images" value="1" ' . checked(1, (int) get_option('am_sitemap_images', 1), false) . '> ';
			echo esc_html__('Comunica a Google anche tutte le foto della galleria di schede e articoli', 'archivio-micologico') . '</label>';
			echo '<p class="description">' . esc_html__('Richiede Yoast SEO; senza, la sitemap la genera WordPress senza immagini.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_seo');

		add_settings_field('am_book_nav', __('Sfoglia le schede', 'archivio-micologico'), function () {
			echo '<input type="hidden" name="am_book_nav" value="0">';
			echo '<label><input type="checkbox" name="am_book_nav" value="1" ' . checked(1, (int) get_option('am_book_nav', 1), false) . '> ';
			echo esc_html__('Frecce grandi ai lati della scheda per passare alla precedente o alla successiva', 'archivio-micologico') . '</label>';
			echo '<p class="description">' . esc_html__('Funzionano anche con i tasti ← e → della tastiera. Su telefono le frecce sono più piccole e senza etichetta.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_book');

		add_settings_field('am_book_order', __('Ordine delle pagine', 'archivio-micologico'), function () {
			$order = \ArchivioMicologico\BookNav::order_mode();
			echo '<select id="am_book_order" name="am_book_order">';
			foreach ([
				'archivio' => __('Come l’archivio: divisione, famiglia, nome scientifico', 'archivio-micologico'),
				'alfabetico' => __('Alfabetico per nome scientifico', 'archivio-micologico'),
				'recenti' => __('Dalla più recente alla più vecchia', 'archivio-micologico'),
			] as $value => $label) {
				echo '<option value="' . esc_attr($value) . '" ' . selected($order, $value, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select><p class="description">' . esc_html__('Decide quale scheda è la precedente e quale la successiva. Sono incluse solo le schede pubblicate.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_book', ['label_for' => 'am_book_order']);

		add_settings_field('am_book_effect', __('Effetto di transizione', 'archivio-micologico'), function () {
			$effect = (string) get_option('am_book_effect', 'libro');
			echo '<select id="am_book_effect" name="am_book_effect">';
			foreach ([
				'libro' => __('Libro: la pagina gira (consigliato)', 'archivio-micologico'),
				'scorrimento' => __('Scorrimento laterale', 'archivio-micologico'),
				'dissolvenza' => __('Dissolvenza', 'archivio-micologico'),
				'nessuno' => __('Nessuno', 'archivio-micologico'),
			] as $value => $label) {
				echo '<option value="' . esc_attr($value) . '" ' . selected($effect, $value, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select>';
			echo ' <label for="am_book_speed" style="margin-left:12px">' . esc_html__('Durata', 'archivio-micologico') . '</label> ';
			echo '<input type="number" id="am_book_speed" name="am_book_speed" min="200" max="1500" step="50" value="' . esc_attr((string) max(200, min(1500, (int) get_option('am_book_speed', 650)))) . '" class="small-text"> ms';
			echo '<p class="description">' . esc_html__('Attivo su Chrome, Edge e Safari recenti; gli altri browser cambiano pagina senza animazione. Disattivato per chi ha scelto di ridurre le animazioni nel sistema.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_book', ['label_for' => 'am_book_effect']);

		// 1.6.24: one selector per plugin page, all in "Pagine del plugin".
		foreach (AdminPages::plugin_pages() as $key => [$option, $tag, $label, $help]) {
			add_settings_field($option, $label, static function () use ($key, $option, $tag, $help) {
				$val = (int) get_option($option, 0);
				$pages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC', 'post_status' => 'publish,private']);
				echo '<select id="' . esc_attr($option) . '" name="' . esc_attr($option) . '">';
				echo '<option value="0">' . esc_html($key === 'edit' ? __('— Nessuna —', 'archivio-micologico') : __('Automatica (pagina con lo shortcode)', 'archivio-micologico')) . '</option>';
				foreach ($pages as $pg) {
					$has = has_shortcode((string) $pg->post_content, $tag);
					echo '<option value="' . esc_attr((string) $pg->ID) . '" ' . selected($pg->ID, $val, false) . '>' . esc_html($pg->post_title . ($has ? '  ✓ [' . $tag . ']' : '')) . '</option>';
				}
				echo '</select>';
				echo AdminPages::copy_code('[' . $tag . ']'); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built.
				echo '<p class="description">' . esc_html($help) . '</p>';
				$chosen = $val ? get_post($val) : null;
				$url = AdminPages::page_url($key);
				if ($chosen && !has_shortcode((string) $chosen->post_content, $tag)) {
					echo '<p class="am-page-status am-page-status--warn">' . esc_html(sprintf(__('La pagina scelta non contiene [%s]: aggiungilo con un blocco Shortcode.', 'archivio-micologico'), $tag)) . '</p>';
				} elseif ($url !== '') {
					echo '<p class="am-page-status">' . esc_html($val ? __('In uso:', 'archivio-micologico') : __('Rilevata:', 'archivio-micologico')) . ' <a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html((string) wp_parse_url($url, PHP_URL_PATH)) . '</a></p>';
				} elseif ($key !== 'edit') {
					echo '<p class="am-page-status am-page-status--warn">' . esc_html(sprintf(__('Nessuna pagina contiene [%s].', 'archivio-micologico'), $tag)) . '</p>';
				}
			}, 'am_settings', 'am_pages', ['label_for' => $option]);
		}

		add_settings_field('am_show_submit_result_admin', __('Riepilogo dopo l’invio', 'archivio-micologico'), function () {
			$val = (int) get_option('am_show_submit_result_admin', 1);
			echo '<label><input type="checkbox" name="am_show_submit_result_admin" value="1" ' . checked(1, $val, false) . ' /> ' . esc_html__('Dopo l’invio mostra il riquadro di conferma con i pulsanti Apri e Modifica', 'archivio-micologico') . '</label>';
			echo '<p class="description">' . esc_html__('Vale per Nuovo inserimento e Nuovo articolo. Gli errori vengono mostrati sempre.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_entry');

		add_settings_field('am_submit_result_ttl', __('Durata del riepilogo (secondi)', 'archivio-micologico'), function () {
			$val = (int) get_option('am_submit_result_ttl', 20);
			
		
echo '<input type="number" min="0" max="600" name="am_submit_result_ttl" value="' . esc_attr($val) . '" />';
			echo '<p class="description">' . esc_html__('0 = non nascondere automaticamente. Valore consigliato: 20.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_entry');

		add_settings_field('am_seo_mode', __('Meta SEO delle schede', 'archivio-micologico'), function () {
			$mode = (string) get_option('am_seo_mode', 'auto');
			echo '<select id="am_seo_mode" name="am_seo_mode">';
			foreach ([
				'auto' => __('Automatico (consigliato): li genera solo se non c’è un plugin SEO', 'archivio-micologico'),
				'force' => __('Sempre: li genera anche con un plugin SEO attivo', 'archivio-micologico'),
				'off' => __('Mai: li gestisce un altro plugin', 'archivio-micologico'),
			] as $value => $label) {
				echo '<option value="' . esc_attr($value) . '" ' . selected($mode, $value, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__('Con Yoast, Rank Math, SEOPress o All in One SEO attivi, Automatico evita meta description, canonical e JSON-LD doppi.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_seo', ['label_for' => 'am_seo_mode']);


}

	public static function render_dashboard_page(): void {
		AdminPages::dashboard();
	}

	/** Read the bundled changelog; a missing file must never break an admin page. */
	private static function get_current_changelog_items(): array {
		$path = AM_PATH . 'CHANGELOG.txt';
		if (!is_readable($path)) { return []; }
		$text = @file_get_contents($path);
		if (!is_string($text)) { return []; }
		return self::parse_changelog_items($text, AM_VERSION);
	}

	/** Supports both "= 1.5.56 =" and legacy "v1.5.54 - title" headings. */
	private static function parse_changelog_items(string $text, string $version): array {
		$items = [];
		$in_version = false;
		foreach (preg_split('/\R/u', $text) ?: [] as $line) {
			if (preg_match('/^\s*(?:=\s*)?v?(\d+\.\d+\.\d+(?:[.-][a-z0-9]+)*)\b/i', $line, $heading)) {
				if ($in_version && $heading[1] !== $version) { break; }
				$in_version = $heading[1] === $version;
				continue;
			}
			if ($in_version && preg_match('/^\s*[-*]\s+(.+)$/u', $line, $entry)) {
				$items[] = trim($entry[1]);
			}
		}
		return $items;
	}

	public static function render_info_version_page(): void {
		if (!current_user_can(AM_CAP_EDIT_POSTS)) {
			wp_die(esc_html__('Non hai il permesso di accedere a questa pagina.', 'archivio-micologico'));
		}
		$items = self::get_current_changelog_items();
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__('Archivio Micologico · Info / Versione', 'archivio-micologico') . '</h1>';
		echo '<table class="widefat striped" style="max-width:900px;background:#fff;">';
		echo '<tbody>';
		echo '<tr><td style="width:220px"><strong>' . esc_html__('Plugin', 'archivio-micologico') . '</strong></td><td>Archivio Micologico</td></tr>';
		echo '<tr><td><strong>' . esc_html__('Versione', 'archivio-micologico') . '</strong></td><td>' . esc_html(AM_VERSION) . '</td></tr>';
		echo '<tr><td><strong>' . esc_html__('Custom Post Type', 'archivio-micologico') . '</strong></td><td>' . esc_html(AM_CPT) . '</td></tr>';
		echo '<tr><td><strong>' . esc_html__('Tassonomia principale', 'archivio-micologico') . '</strong></td><td>' . esc_html(AM_TAX_CLASSIFICAZIONE) . '</td></tr>';
		echo '</tbody></table>';
		echo '<div style="max-width:900px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:18px;margin-top:18px;">';
		echo '<h2 style="margin-top:0">' . esc_html__('Changelog corrente', 'archivio-micologico') . '</h2>';
		if ($items) {
			echo '<ul style="margin:0;padding-left:18px;">';
			foreach ($items as $item) {
				echo '<li style="margin-bottom:8px;">' . esc_html((string) $item) . '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__('Nessuna voce changelog registrata per questa versione.', 'archivio-micologico') . '</p>';
		}
		echo '</div>';
		self::render_shortcode_widget_audit_1_5_5();
		echo '</div>';
	}

	
	private static function render_shortcode_widget_audit_1_5_5(): void {
		$shortcodes = [
			'am_archive_filters' => __('Filtri tassonomici archivio', 'archivio-micologico'),
			'am_archive' => __('Archivio schede fungo', 'archivio-micologico'),
			'am_search_page' => __('Pagina ricerca dedicata', 'archivio-micologico'),
			'am_submit_form' => __('Nuovo inserimento scheda fungo', 'archivio-micologico'),
			'am_edit_form' => __('Modifica scheda fungo', 'archivio-micologico'),
			'am_article_form' => __('Inserimento Articolo Archivio Micologico', 'archivio-micologico'),
			'am_random_funghi' => __('Carosello schede fungo casuali', 'archivio-micologico'),
			'am_tassonomia' => __('Gestione pubblica della tassonomia', 'archivio-micologico'),
			'am_vocabolari' => __('Gestione pubblica dei vocabolari', 'archivio-micologico'),
		];

		echo '<h2 style="margin-top:24px">' . esc_html__('Diagnostica: shortcode e widget', 'archivio-micologico') . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__('Elemento', 'archivio-micologico') . '</th>';
		echo '<th>' . esc_html__('Descrizione', 'archivio-micologico') . '</th>';
		echo '<th>' . esc_html__('Stato', 'archivio-micologico') . '</th>';
		echo '</tr></thead><tbody>';

		foreach ($shortcodes as $tag => $description) {
			$exists = shortcode_exists($tag);
			echo '<tr>';
			echo '<td><code>[' . esc_html($tag) . ']</code></td>';
			echo '<td>' . esc_html($description) . '</td>';
			echo '<td>' . ($exists ? '<span style="color:#008a20;font-weight:600">' . esc_html__('Presente', 'archivio-micologico') . '</span>' : '<span style="color:#b32d2e;font-weight:600">' . esc_html__('Mancante', 'archivio-micologico') . '</span>') . '</td>';
			echo '</tr>';
		}

		$widget_exists = class_exists(RandomFungiWidget::class);
		echo '<tr>';
		echo '<td><code>AM_Widget_Random_Funghi</code></td>';
		echo '<td>' . esc_html__('Widget WordPress: Archivio Micologico - Schede casuali', 'archivio-micologico') . '</td>';
		echo '<td>' . ($widget_exists ? '<span style="color:#008a20;font-weight:600">' . esc_html__('Presente', 'archivio-micologico') . '</span>' : '<span style="color:#b32d2e;font-weight:600">' . esc_html__('Mancante', 'archivio-micologico') . '</span>') . '</td>';
		echo '</tr>';

		echo '</tbody></table>';

		self::am_debug_add('AM_SHORTCODE_WIDGET_AUDIT_1_5_5', [
			'shortcodes' => array_map('shortcode_exists', array_keys($shortcodes)),
			'widget_random_funghi' => $widget_exists ? 1 : 0,
		]);
	}


public static function render_settings_page(): void {
		if (function_exists('am_cleanup_legacy_roles_and_permissions')) { am_cleanup_legacy_roles_and_permissions(); }
		AdminPages::settings();
	}

	public static function render_instructions_page(): void {
		AdminPages::instructions();
	}

	private static function apply_role_caps(array $roles_create, array $roles_edit, int $allow_edit_others = 0): void {
		am_ensure_admin_editor_caps_runtime();
	}
public static function handle_save_settings(): void {
		// Allow site admins OR AM managers (custom capability).
		self::am_debug_add('save_settings_enter', [
			'has_manage_options' => current_user_can('manage_options') ? 1 : 0,
			'has_am_manage_settings' => current_user_can(AM_CAP_MANAGE_SETTINGS) ? 1 : 0,
			'post_keys' => array_keys($_POST ?? []),
		]);
		if (!current_user_can('manage_options') && !current_user_can(AM_CAP_MANAGE_SETTINGS)) {
			self::am_debug_add('save_settings_blocked_caps');
			
wp_safe_redirect(add_query_arg(['page' => 'am_settings', 'am_err' => 'caps'], admin_url('admin.php')));
			exit;
		}
		$nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
		if (!$nonce || !wp_verify_nonce($nonce, 'am_save_settings')) {
			self::am_debug_add('save_settings_bad_nonce', ['nonce_present' => $nonce ? 1 : 0]);
			wp_safe_redirect(add_query_arg(['page' => 'am_settings', 'am_err' => 'nonce'], admin_url('admin.php')));
			exit;
		}

		$am_seo_mode = isset($_POST['am_seo_mode']) ? sanitize_key((string) wp_unslash($_POST['am_seo_mode'])) : 'auto';
		if (!in_array($am_seo_mode, ['auto', 'force', 'off'], true)) {
			$am_seo_mode = 'auto';
		}

		// Checkbox
		$require_login = isset($_POST['am_require_login']) ? 1 : 0;
		$show_result_admin = isset($_POST['am_show_submit_result_admin']) ? 1 : 0;
		$enable_debug_panel = isset($_POST['am_enable_debug_panel']) ? 1 : 0;

		// Selects / numbers
		$default_status = isset($_POST['am_default_status']) ? sanitize_key((string) $_POST['am_default_status']) : 'pending';
		$allowed_status = ['draft','pending','publish','private'];
		if (!in_array($default_status, $allowed_status, true)) { $default_status = 'pending'; }

		$max_upload = isset($_POST['am_max_upload_mb']) ? intval($_POST['am_max_upload_mb']) : 5;
		if ($max_upload < 1) { $max_upload = 1; }
		if ($max_upload > 50) { $max_upload = 50; }

		$edit_page_id = isset($_POST['am_edit_page_id']) ? intval($_POST['am_edit_page_id']) : 0;
		if ($edit_page_id < 0 || ($edit_page_id && get_post_type($edit_page_id) !== 'page')) { $edit_page_id = 0; }

		$ttl = isset($_POST['am_submit_result_ttl']) ? intval($_POST['am_submit_result_ttl']) : 20;
		if ($ttl < 0) { $ttl = 0; }
		if ($ttl > 600) { $ttl = 600; }


		$articles_order = isset($_POST['am_articles_order']) ? sanitize_key((string) $_POST['am_articles_order']) : 'date';
		if (!in_array($articles_order, ['date','alpha'], true)) { $articles_order = 'date'; }

		$random_widget_title = isset($_POST['am_random_widget_title']) ? sanitize_text_field((string) $_POST['am_random_widget_title']) : '';
		$random_widget_count = isset($_POST['am_random_widget_count']) ? intval($_POST['am_random_widget_count']) : 5;
		if ($random_widget_count < 1) { $random_widget_count = 1; }
		if ($random_widget_count > 12) { $random_widget_count = 12; }
		$random_widget_autoplay = isset($_POST['am_random_widget_autoplay']) ? 1 : 0;
		$random_widget_visible = isset($_POST['am_random_widget_visible']) ? intval($_POST['am_random_widget_visible']) : 3;
		if ($random_widget_visible < 1) { $random_widget_visible = 1; }
		if ($random_widget_visible > 3) { $random_widget_visible = 3; }
		$random_widget_interval = isset($_POST['am_random_widget_interval']) ? intval($_POST['am_random_widget_interval']) : 4;
		if ($random_widget_interval < 2) { $random_widget_interval = 2; }
		if ($random_widget_interval > 20) { $random_widget_interval = 20; }

		// 1.5.0: fixed permissions. Only WordPress Administrator and Editor can use AM management.
		$roles_create = ['editor'];
		$roles_edit = ['editor'];
		$allow_edit_others = 1;

		
		self::am_debug_add('save_settings_before_update', ['create' => $roles_create, 'edit' => $roles_edit]);

		try {
			update_option('am_seo_mode', $am_seo_mode);
			update_option('am_articles_order', $articles_order);
			$recent_count = $_POST['am_recent_entries_count'] ?? null;
			if (is_numeric($recent_count)) {
				update_option('am_recent_entries_count', $recent_count);
			}
			update_option('am_require_login', $require_login);
			update_option('am_default_status', $default_status);
			update_option('am_max_upload_mb', $max_upload);
			update_option('am_edit_page_id', $edit_page_id);
			$show_title = $_POST['am_single_show_title'] ?? null;
			if ($show_title === '0' || $show_title === '1') {
				update_option('am_single_show_title', (int) $show_title);
			}
			// 1.6.24: plugin pages (am_edit_page_id is saved above).
			foreach (AdminPages::plugin_pages() as $key => [$option]) {
				if ($key === 'edit' || !isset($_POST[$option])) { continue; }
				$page = absint($_POST[$option]);
				update_option($option, ($page && get_post_type($page) === 'page') ? $page : 0);
			}
			// 1.6.23: articles and sitemap; saved only when their fields are part of the form.
			$article_status = isset($_POST['am_article_status']) ? sanitize_key((string) $_POST['am_article_status']) : '';
			if (in_array($article_status, $allowed_status, true)) {
				update_option('am_article_status', $article_status);
			}
			foreach (['am_archive_articles', 'am_sitemap_images'] as $flag) {
				$value = $_POST[$flag] ?? null;
				if ($value === '0' || $value === '1') {
					update_option($flag, (int) $value);
				}
			}
			// 1.6.21: page-turn navigation; saved only when its fields are part of the form.
			$book_nav = $_POST['am_book_nav'] ?? null;
			if ($book_nav === '0' || $book_nav === '1') {
				update_option('am_book_nav', (int) $book_nav);
			}
			$book_order = isset($_POST['am_book_order']) ? sanitize_key((string) $_POST['am_book_order']) : '';
			if (in_array($book_order, \ArchivioMicologico\BookNav::ORDERS, true)) {
				update_option('am_book_order', $book_order);
				\ArchivioMicologico\BookNav::flush();
			}
						$book_effect = isset($_POST['am_book_effect']) ? sanitize_key((string) $_POST['am_book_effect']) : '';
			if (in_array($book_effect, \ArchivioMicologico\BookNav::EFFECTS, true)) {
				update_option('am_book_effect', $book_effect);
			}
			if (isset($_POST['am_book_speed']) && is_numeric($_POST['am_book_speed'])) {
				update_option('am_book_speed', max(200, min(1500, (int) $_POST['am_book_speed'])));
			}
			update_option('am_show_submit_result_admin', $show_result_admin);
			update_option('am_enable_debug_panel', $enable_debug_panel);
			update_option('am_submit_result_ttl', $ttl);
			update_option('am_allow_edit_others', $allow_edit_others);
			update_option('am_random_widget_title', $random_widget_title);
			update_option('am_random_widget_count', $random_widget_count);
			update_option('am_random_widget_autoplay', $random_widget_autoplay);
			update_option('am_random_widget_visible', $random_widget_visible);
			$rows = $_POST['am_random_widget_rows'] ?? null;
			if (is_scalar($rows)) {
				update_option('am_random_widget_rows', max(1, min(4, (int) $rows)));
			}
			update_option('am_random_widget_interval', $random_widget_interval);


			self::am_debug_add('save_settings_after_update', []);
			self::apply_role_caps($roles_create, $roles_edit, $allow_edit_others);
			if (function_exists('am_cleanup_legacy_roles_and_permissions')) { am_cleanup_legacy_roles_and_permissions(); }
			self::am_debug_add('save_settings_after_caps', ['mode' => 'fixed_wp_roles_1_5_3']);
			self::am_debug_add('save_settings_ok', ['create' => $roles_create, 'edit' => $roles_edit]);
		} catch (Throwable $e) {
			self::am_debug_add('save_settings_exception', [
				'msg' => $e->getMessage(),
				'file' => basename($e->getFile()),
				'line' => (int) $e->getLine(),
			]);
			wp_safe_redirect(add_query_arg(['page' => 'am_settings', 'am_err' => 'exception'], admin_url('admin.php')));
			exit;
		}

		wp_safe_redirect(add_query_arg(['page' => 'am_settings', 'am_saved' => 1], admin_url('admin.php')));
		exit;
	}



public static function render_clear_debug_log_tool(): void {
	if (!current_user_can('manage_options') && !current_user_can(AM_CAP_MANAGE_SETTINGS)) {
		echo '<p class="description">' . esc_html__('Non hai i permessi per usare questo strumento.', 'archivio-micologico') . '</p>';
		return;
	}

	$enabled = (int) get_option('am_enable_debug_panel', 0);
	echo '<p class="description">' . esc_html__('Il log debug viene sempre salvato nel database (ultimi 50 eventi). Puoi svuotarlo qui.', 'archivio-micologico') . '</p>';

	echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:6px">';
	echo '<input type="hidden" name="action" value="am_clear_debug_log">';
	wp_nonce_field('am_clear_debug_log');
	submit_button(__('Svuota log debug', 'archivio-micologico'), 'secondary', 'submit', false);
	echo '</form>';

	echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:6px">';
	echo '<input type="hidden" name="action" value="am_export_debug_log">';
	wp_nonce_field('am_export_debug_log');
	submit_button(__('Esporta log debug (TXT)', 'archivio-micologico'), 'secondary', 'submit', false);
	echo '</form>';

	if (!$enabled) {
		echo '<p class="description" style="margin-top:6px"><em>' . esc_html__('Nota: il pannello “AM DEBUG” è nascosto. Per visualizzarlo, abilita “Pannello debug” nella sezione Manutenzione.', 'archivio-micologico') . '</em></p>';
	}
}




public static function handle_export_debug_log(): void {
	self::am_debug_add('export_debug_enter', [
		'has_manage_options' => current_user_can('manage_options') ? 1 : 0,
		'has_am_manage_settings' => current_user_can(AM_CAP_MANAGE_SETTINGS) ? 1 : 0,
	]);

	if (!current_user_can('manage_options') && !current_user_can(AM_CAP_MANAGE_SETTINGS)) {
		self::am_debug_add('export_debug_blocked_caps');
		wp_safe_redirect(add_query_arg(['page' => 'am_settings', 'am_err' => 'caps'], admin_url('admin.php')));
		exit;
	}

	$nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
	if (!$nonce || !wp_verify_nonce($nonce, 'am_export_debug_log')) {
		self::am_debug_add('export_debug_bad_nonce', ['nonce_present' => $nonce ? 1 : 0]);
		wp_safe_redirect(add_query_arg(['page' => 'am_settings', 'am_err' => 'nonce'], admin_url('admin.php')));
		exit;
	}

	$log = get_option('am_debug_log', []);
	if (!is_array($log)) { $log = []; }

	$lines = [];
	foreach ($log as $row) {
		$time = isset($row['time']) ? date_i18n('Y-m-d H:i:s', intval($row['time'])) : '';
		$tag  = isset($row['tag']) ? (string) $row['tag'] : '';
		$user = isset($row['user']) ? (string) $row['user'] : '';
		$data = isset($row['data']) ? $row['data'] : [];
		$lines[] = $time . ' ' . $tag . ' — ' . $user . "
" . wp_json_encode($data) . "
";
	}

	$body = implode("
", $lines);
	$filename = 'am-debug-log-' . gmdate('Ymd-His') . '.txt';

	nocache_headers();
	header('Content-Type: text/plain; charset=' . get_option('blog_charset'));
	header('Content-Disposition: attachment; filename=' . $filename);
	echo $body;
	exit;
}

public static function handle_clear_debug_log(): void {
	self::am_debug_add('clear_debug_enter', [
		'has_manage_options' => current_user_can('manage_options') ? 1 : 0,
		'has_am_manage_settings' => current_user_can(AM_CAP_MANAGE_SETTINGS) ? 1 : 0,
	]);

	if (!current_user_can('manage_options') && !current_user_can(AM_CAP_MANAGE_SETTINGS)) {
		self::am_debug_add('clear_debug_blocked_caps');
		wp_safe_redirect(add_query_arg(['page' => 'am_settings', 'am_err' => 'caps'], admin_url('admin.php')));
		exit;
	}

	$nonce = isset($_POST['_wpnonce']) ? (string) $_POST['_wpnonce'] : '';
	if (!$nonce || !wp_verify_nonce($nonce, 'am_clear_debug_log')) {
		self::am_debug_add('clear_debug_bad_nonce', ['nonce_present' => $nonce ? 1 : 0]);
		wp_safe_redirect(add_query_arg(['page' => 'am_settings', 'am_err' => 'nonce'], admin_url('admin.php')));
		exit;
	}

	update_option('am_debug_log', [], false);
	self::am_debug_add('clear_debug_ok');
	wp_safe_redirect(add_query_arg(['page' => 'am_settings', 'am_saved' => 1], admin_url('admin.php')));
	exit;
}





}
