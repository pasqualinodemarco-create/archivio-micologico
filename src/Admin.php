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
		$log = array_slice($log, 0, 80);
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
		if (!(current_user_can('manage_terms') || current_user_can('manage_options')) && !current_user_can('manage_options')) {
			wp_die(esc_html__('Non autorizzato.', 'archivio-micologico'));
		}

		$tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'infra';
		$cfg = self::vocabolari_get_tax_and_labels($tab);
		$tax = (string) ($cfg['tax'] ?? '');

		echo '<div class="wrap">';
		echo '<h1>' . esc_html($cfg['title']) . '</h1>';

		// Tabs
		$base = admin_url('admin.php?page=am_vocabolari');
		$tab_infra_url = add_query_arg(['tab' => 'infra'], $base);
		$tab_author_url = add_query_arg(['tab' => 'author'], $base);

		echo '<h2 class="nav-tab-wrapper" style="margin-bottom:12px">';
		echo '<a class="nav-tab ' . ($cfg['tab'] === 'infra' ? 'nav-tab-active' : '') . '" href="' . esc_url($tab_infra_url) . '">' . esc_html__('Forma / Varietà', 'archivio-micologico') . '</a>';
		echo '<a class="nav-tab ' . ($cfg['tab'] === 'author' ? 'nav-tab-active' : '') . '" href="' . esc_url($tab_author_url) . '">' . esc_html__('Autore', 'archivio-micologico') . '</a>';
		echo '</h2>';

		// Messages
		if (isset($_GET['am_msg'])) {
			$msg = sanitize_text_field((string) $_GET['am_msg']);
			if ($msg === 'added') {
				echo '<div class="notice notice-success"><p>' . esc_html__('Voce aggiunta.', 'archivio-micologico') . '</p></div>';
			} elseif ($msg === 'updated') {
				echo '<div class="notice notice-success"><p>' . esc_html__('Voce aggiornata.', 'archivio-micologico') . '</p></div>';
			} elseif ($msg === 'deleted') {
				echo '<div class="notice notice-success"><p>' . esc_html__('Voce eliminata.', 'archivio-micologico') . '</p></div>';
			} elseif ($msg === 'merged') {
				echo '<div class="notice notice-success"><p>' . esc_html__('Voci unite correttamente.', 'archivio-micologico') . '</p></div>';
			}
		}
		if (isset($_GET['am_err'])) {
			$err = sanitize_text_field((string) $_GET['am_err']);
			echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
		}

		if (!$tax || !taxonomy_exists($tax)) {
			echo '<div class="notice notice-error"><p>' . esc_html__('Tassonomia non disponibile. Verifica configurazione plugin.', 'archivio-micologico') . '</p></div>';
			echo '</div>';
			return;
		}

		$edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
		$edit_term = null;
		if ($edit_id > 0) {
			$edit_term = get_term($edit_id, $tax);
			if ($edit_term && is_wp_error($edit_term)) { $edit_term = null; }
		}

		$merge_id = isset($_GET['merge']) ? intval($_GET['merge']) : 0;
		$merge_term = null;
		if ($merge_id > 0) {
			$merge_term = get_term($merge_id, $tax);
			if ($merge_term && is_wp_error($merge_term)) { $merge_term = null; }
			if ($merge_term && $edit_id === $merge_id) { $merge_term = null; }
		}

		echo '<div style="display:flex; gap:24px; align-items:flex-start; max-width: 1100px;">';

		// Left: add/edit form
		echo '<div style="flex: 0 0 380px;">';
		echo '<div class="card" style="max-width:380px; padding:16px;">';
		echo '<h2 style="margin-top:0">' . esc_html($cfg['section']) . '</h2>';

		if ($edit_term) {
			echo '<h3 style="margin:12px 0 8px 0">' . esc_html__('Modifica voce', 'archivio-micologico') . '</h3>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			echo '<input type="hidden" name="action" value="am_vocab_update">';
			echo '<input type="hidden" name="tab" value="' . esc_attr($cfg['tab']) . '">';
			echo '<input type="hidden" name="term_id" value="' . esc_attr((string)$edit_id) . '">';
			wp_nonce_field('am_vocab_update');
			echo '<p><input type="text" class="regular-text" name="name" value="' . esc_attr($edit_term->name) . '" required></p>';
			submit_button(__('Salva modifiche', 'archivio-micologico'));
			echo '<p><a href="' . esc_url(add_query_arg(['tab' => $cfg['tab']], $base)) . '">' . esc_html__('Annulla', 'archivio-micologico') . '</a></p>';
			echo '</form>';
		} else {
			echo '<h3 style="margin:12px 0 8px 0">' . esc_html($cfg['add_label']) . '</h3>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
			echo '<input type="hidden" name="action" value="am_vocab_add">';
			echo '<input type="hidden" name="tab" value="' . esc_attr($cfg['tab']) . '">';
			wp_nonce_field('am_vocab_add');
			echo '<p><input type="text" class="regular-text" name="name" placeholder="' . esc_attr((string)$cfg['placeholder']) . '" required></p>';
			submit_button(__('Aggiungi', 'archivio-micologico'), 'primary');
			echo '</form>';
		}

		echo '<p class="description" style="margin-top:10px;">' . esc_html__('Da qui puoi correggere errori di battitura o eliminare inserimenti errati. Le voci vengono usate nei menu a cascata del frontend.', 'archivio-micologico') . '</p>';
		if ($merge_term) {
			echo '<hr style="margin:14px 0;">';
			echo '<h3 style="margin:0 0 8px 0">' . esc_html__('Unisci voci', 'archivio-micologico') . '</h3>';
			echo '<p class="description" style="margin-top:0;">' . esc_html__('Usa questa funzione per unire un duplicato in una voce corretta, senza perdere le assegnazioni alle schede.', 'archivio-micologico') . '</p>';
			echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('Confermi l\'unione? Il duplicato verrà eliminato.', 'archivio-micologico')) . '\');">';
			echo '<input type="hidden" name="action" value="am_vocab_merge">';
			echo '<input type="hidden" name="tab" value="' . esc_attr($cfg['tab']) . '">';
			echo '<input type="hidden" name="from_term_id" value="' . esc_attr((string) ((int) $merge_term->term_id)) . '">';
			wp_nonce_field('am_vocab_merge');
			echo '<p><strong>' . esc_html__('Da:', 'archivio-micologico') . '</strong> ' . esc_html($merge_term->name) . '</p>';
			echo '<p><label for="am-merge-to"><strong>' . esc_html__('In:', 'archivio-micologico') . '</strong></label><br>';
			echo '<select id="am-merge-to" name="to_term_id" required style="width:100%;">';
			echo '<option value="">' . esc_html__('Seleziona voce di destinazione…', 'archivio-micologico') . '</option>';
			$all_terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC', 'number' => 0]);
			if (!is_wp_error($all_terms) && !empty($all_terms)) {
				foreach ($all_terms as $tt) {
					if ((int)$tt->term_id === (int)$merge_term->term_id) { continue; }
					echo '<option value="' . esc_attr((string)((int)$tt->term_id)) . '">' . esc_html($tt->name) . '</option>';
				}
			}
			echo '</select></p>';
			submit_button(__('Unisci e elimina duplicato', 'archivio-micologico'), 'primary');
			echo '<p><a href="' . esc_url(add_query_arg(['tab' => $cfg['tab']], $base)) . '">' . esc_html__('Annulla', 'archivio-micologico') . '</a></p>';
			echo '</form>';
		}

		echo '</div></div>';

		// Right: list
		echo '<div style="flex: 1 1 auto;">';
		echo '<div class="card" style="padding:0;">';
		echo '<h2 style="padding:16px 16px 0 16px; margin:0;">' . esc_html__('Elenco voci', 'archivio-micologico') . '</h2>';

		$search_q = isset($_GET['q']) ? sanitize_text_field((string) $_GET['q']) : '';
		echo '<div style="padding:12px 16px 0 16px;">';
		echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="display:flex; gap:8px; align-items:center; max-width: 520px;">';
		echo '<input type="hidden" name="page" value="am_vocabolari">';
		echo '<input type="hidden" name="tab" value="' . esc_attr($cfg['tab']) . '">';
		echo '<input type="search" name="q" value="' . esc_attr($search_q) . '" class="regular-text" placeholder="' . esc_attr__('Cerca voce…', 'archivio-micologico') . '">';
		submit_button(__('Cerca', 'archivio-micologico'), 'secondary', '', false);
		echo '<a class="button" href="' . esc_url(add_query_arg(['tab' => $cfg['tab']], $base)) . '">' . esc_html__('Reset', 'archivio-micologico') . '</a>';
		echo '</form>';
		echo '</div>';


		$terms = get_terms([
			'taxonomy' => $tax,
			'hide_empty' => false,
			'orderby' => 'name',
			'order' => 'ASC',
			'number' => 0,
			'search' => $search_q ? $search_q : '' ,
		]);
		if (is_wp_error($terms)) { $terms = []; }

		echo '<table class="widefat striped am-vocab-table" style="margin-top:12px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__('Voce', 'archivio-micologico') . '</th>';
		echo '<th style="width:90px;text-align:right;">' . esc_html__('Usi', 'archivio-micologico') . '</th>';
		echo '<th style="width:240px;white-space:nowrap;">' . esc_html__('Azioni', 'archivio-micologico') . '</th>';
		echo '</tr></thead><tbody>';

		if (empty($terms)) {
			echo '<tr><td colspan="3">' . esc_html__('Nessuna voce presente.', 'archivio-micologico') . '</td></tr>';
		} else {
			foreach ($terms as $t) {
				$edit_url = add_query_arg(['tab' => $cfg['tab'], 'edit' => (int)$t->term_id], $base);
				echo '<tr>';
				echo '<td><strong>' . esc_html($t->name) . '</strong></td>';
				echo '<td style="text-align:right;">' . intval($t->count) . '</td>';
				echo '<td style="white-space:nowrap;">';
echo '<div class="am-vocab-actions">';

				echo '<a class="button button-small" href="' . esc_url($edit_url) . '">' . esc_html__('Modifica', 'archivio-micologico') . '</a> ';
				echo '<a class="button button-small"" href="' . esc_url(add_query_arg(['tab' => $cfg['tab'], 'merge' => (int)$t->term_id], $base)) . '">' . esc_html__('Unisci', 'archivio-micologico') . '</a> ';
				echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;" onsubmit="return confirm(\'' . esc_js(__('Eliminare questa voce? Verrà rimossa anche dalle schede collegate.', 'archivio-micologico')) . '\');">';
				echo '<input type="hidden" name="action" value="am_vocab_delete">';
				echo '<input type="hidden" name="tab" value="' . esc_attr($cfg['tab']) . '">';
				echo '<input type="hidden" name="term_id" value="' . esc_attr((string)((int)$t->term_id)) . '">';
				wp_nonce_field('am_vocab_delete');
				echo '<button type="submit" class="button button-small button-link-delete">' . esc_html__('Elimina', 'archivio-micologico') . '</button>';
				echo '</form>';
				
echo '</div>';
echo '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
		echo '</div></div>';

		echo '</div>'; // flex
		echo '</div>'; // wrap
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

		$hook_all = add_submenu_page(
			$parent_slug,
			__('Schede', 'archivio-micologico'),
			self::get_submenu_label('dashicons-category', __('Schede', 'archivio-micologico')),
			AM_CAP_EDIT_POSTS,
			'am_all_schede',
			'__return_null'
		);
		if ($hook_all) {
			self::register_load_redirect(
				(string) $hook_all,
				'all_schede',
				admin_url('edit.php?post_type=' . AM_CPT),
				function() { return current_user_can(AM_CAP_EDIT_POSTS); },
				__('Non hai il permesso di accedere a questa pagina.', 'archivio-micologico')
			);
		}

		$hook_add = add_submenu_page(
			$parent_slug,
			__('Nuova Scheda', 'archivio-micologico'),
			self::get_submenu_label('dashicons-plus-alt2', __('Nuova Scheda', 'archivio-micologico')),
			AM_CAP_CREATE_POSTS,
			'am_add_new',
			'__return_null'
		);
		if ($hook_add) {
			self::register_load_redirect(
				(string) $hook_add,
				'add_new_fungo',
				self::get_front_submit_page_url(),
				function() { return current_user_can(AM_CAP_CREATE_POSTS); },
				__('Non hai il permesso di accedere a questa pagina.', 'archivio-micologico')
			);
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

		$hook_tax = add_submenu_page(
			$parent_slug,
			__('Tassonomia', 'archivio-micologico'),
			self::get_submenu_label('dashicons-networking', __('Tassonomia', 'archivio-micologico')),
			'manage_terms',
			'am_classificazione',
			'__return_null'
		);
		if ($hook_tax) {
			self::register_load_redirect(
				(string) $hook_tax,
				'classificazione',
				admin_url('edit-tags.php?taxonomy=' . AM_TAX_CLASSIFICAZIONE . '&post_type=' . AM_CPT),
				function() { return (current_user_can('manage_terms') || current_user_can('manage_options')) || current_user_can('manage_options'); },
				__('Non hai il permesso di accedere a questa pagina.', 'archivio-micologico')
			);
		}

		add_submenu_page(
			$parent_slug,
			__('Vocabolari', 'archivio-micologico'),
			self::get_submenu_label('dashicons-book-alt', __('Vocabolari', 'archivio-micologico')),
			'manage_terms',
			'am_vocabolari',
			[__CLASS__, 'render_vocabolari_page']
		);

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
add_settings_section('am_main', __('Generali', 'archivio-micologico'), function () {
			echo '<p>' . esc_html__('Configurazione di base per l\'inserimento front-end.', 'archivio-micologico') . '</p>';
		}, 'am_settings');

		add_settings_section('am_recent_entries', __('Ultime schede pubblicate', 'archivio-micologico'), '__return_empty_string', 'am_settings');
		add_settings_field('am_recent_entries_count', __('Numero di schede da mostrare', 'archivio-micologico'), static function () {
			$count = max(0, min(20, (int) get_option('am_recent_entries_count', 5)));
			echo '<input type="number" min="0" max="20" id="am_recent_entries_count" name="am_recent_entries_count" value="' . esc_attr((string) $count) . '" aria-describedby="am_recent_entries_count_help" />';
			echo '<p class="description" id="am_recent_entries_count_help">' . esc_html__('Scegli quante schede mostrare, fino a 20. Imposta 0 per nascondere il pannello nell’archivio.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_recent_entries', ['label_for' => 'am_recent_entries_count']);

		add_settings_section('am_widget_random', __('Widget schede casuali', 'archivio-micologico'), function () {
			echo '<p>' . esc_html__('Impostazioni predefinite del widget/carosello e dello shortcode [am_random_funghi].', 'archivio-micologico') . '</p>';
		}, 'am_settings');

		add_settings_field('am_random_widget_title', __('Titolo predefinito widget', 'archivio-micologico'), function () {
			$val = (string) get_option('am_random_widget_title', '');
			echo '<input type="text" class="regular-text" name="am_random_widget_title" value="' . esc_attr($val) . '" />';
			echo '<p class="description">' . esc_html__('Titolo usato dal widget se non ne imposti uno manualmente nella singola area widget.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_widget_random');

		add_settings_field('am_random_widget_count', __('Numero schede da mostrare', 'archivio-micologico'), function () {
			$val = (int) get_option('am_random_widget_count', 5);
			echo '<input type="number" min="1" max="12" name="am_random_widget_count" value="' . esc_attr((string) $val) . '" />';
			echo '<p class="description">' . esc_html__('Quante schede fungo casuali mostrare nel carosello. Consigliato: 5.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_widget_random');

		add_settings_field('am_random_widget_visible', __('Immagini visibili contemporaneamente', 'archivio-micologico'), function () {
			$val = (int) get_option('am_random_widget_visible', 3);
			echo '<input type="number" min="1" max="3" name="am_random_widget_visible" value="' . esc_attr((string) $val) . '" />';
			echo '<p class="description">' . esc_html__('Numero di schede per riga su desktop. Su schermi stretti il carosello si adatta a una o due colonne.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_widget_random');

		add_settings_field('am_random_widget_rows', __('Numero di righe', 'archivio-micologico'), function () {
			$rows = max(1, min(4, (int) get_option('am_random_widget_rows', 1)));
			echo '<input type="number" min="1" max="4" id="am_random_widget_rows" name="am_random_widget_rows" value="' . esc_attr($rows) . '" />';
			echo '<p class="description">' . esc_html__('Scegli da una a quattro righe. Con 5 schede e 3 righe, la seconda colonna avrà due schede. Per riempire 3 righe da 3, imposta almeno 9 schede.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_widget_random', ['label_for' => 'am_random_widget_rows']);

		add_settings_field('am_random_widget_autoplay', __('Movimento automatico', 'archivio-micologico'), function () {
			$val = (int) get_option('am_random_widget_autoplay', 1);
			echo '<label><input type="checkbox" name="am_random_widget_autoplay" value="1" ' . checked(1, $val, false) . ' /> ' . esc_html__('Attiva lo scorrimento automatico del carosello', 'archivio-micologico') . '</label>';
		}, 'am_settings', 'am_widget_random');

		add_settings_field('am_random_widget_interval', __('Velocità autoplay (secondi)', 'archivio-micologico'), function () {
			$val = (int) get_option('am_random_widget_interval', 4);
			echo '<input type="number" min="2" max="20" name="am_random_widget_interval" value="' . esc_attr((string) $val) . '" />';
			echo '<p class="description">' . esc_html__('Intervallo tra uno scorrimento e il successivo. Valori bassi = carosello più veloce.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_widget_random');

		// Debug toggle (sempre visibile in Generali)
		add_settings_field('am_enable_debug_panel', __('Mostra pannello debug', 'archivio-micologico'), function () {
	$val = (int) get_option('am_enable_debug_panel', 0);
	echo '<label><input type="checkbox" name="am_enable_debug_panel" value="1" ' . checked(1, $val, false) . ' /> ' . esc_html__('Mostra i pannelli di debug nella pagina Impostazioni', 'archivio-micologico') . '</label>';
	echo '<p class="description">' . esc_html__('Il log viene sempre salvato nel database. Attiva questa opzione per visualizzarlo in pagina (consigliato solo in caso di problemi).', 'archivio-micologico') . '</p>';
}, 'am_settings', 'am_main');


		add_settings_field('am_require_login', __('Richiedi login', 'archivio-micologico'), function () {
			$val = (int) get_option('am_require_login', 1);
			echo '<label><input type="checkbox" name="am_require_login" value="1" ' . checked(1, $val, false) . ' /> ' . esc_html__('Richiedi il login prima di mostrare il modulo; l’invio resta riservato ad Amministratore ed Editore', 'archivio-micologico') . '</label>';
		}, 'am_settings', 'am_main');

		add_settings_field('am_default_status', __('Stato predefinito nuove schede', 'archivio-micologico'), function () {
			$val = (string) get_option('am_default_status', 'pending');
			$options = [
				'pending' => __('In revisione (pending)', 'archivio-micologico'),
				'draft' => __('Bozza (draft)', 'archivio-micologico'),
				'publish' => __('Pubblicato (publish)', 'archivio-micologico'),
				'private' => __('Privato (private)', 'archivio-micologico'),
			];
			echo '<select name="am_default_status">';
			foreach ($options as $k => $label) {
				echo '<option value="' . esc_attr($k) . '" ' . selected($k, $val, false) . '>' . esc_html($label) . '</option>';
			}
			echo '</select>';
		}, 'am_settings', 'am_main');

		add_settings_field('am_articles_order', __('Ordinamento Articoli (Archivio)', 'archivio-micologico'), function () {
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
		}, 'am_settings', 'am_main');


		add_settings_field('am_max_upload_mb', __('Limite upload immagini (MB)', 'archivio-micologico'), function () {
			$val = (int) get_option('am_max_upload_mb', 5);
			echo '<input type="number" min="1" max="50" name="am_max_upload_mb" value="' . esc_attr($val) . '" />';
			echo '<p class="description">' . esc_html__('Limite per le nuove immagini caricate dai moduli AM. Restano validi i limiti del server; i file già presenti non vengono modificati.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_main');

		add_settings_field('am_single_show_title', __('Titolo interno della scheda fungo', 'archivio-micologico'), function () {
			$show = (int) get_option('am_single_show_title', 1);
			echo '<input type="hidden" name="am_single_show_title" value="0">';
			echo '<label><input type="checkbox" name="am_single_show_title" value="1" ' . checked(1, $show, false) . '> ';
			echo esc_html__('Mostra il titolo all’interno della scheda', 'archivio-micologico') . '</label>';
			echo '<p class="description">' . esc_html__('Togli la spunta se il tema mostra già il titolo sopra il contenuto.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_main');

		add_settings_field('am_edit_page_id', __('Pagina modifica schede (front-end)', 'archivio-micologico'), function () {
			$val = (int) get_option('am_edit_page_id', 0);
			$pages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'ASC']);
			echo '<select name="am_edit_page_id">';
			echo '<option value="0">' . esc_html__('(non impostata)', 'archivio-micologico') . '</option>';
			foreach ($pages as $pg) {
				echo '<option value="' . esc_attr($pg->ID) . '" ' . selected($pg->ID, $val, false) . '>' . esc_html($pg->post_title) . '</option>';
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__('Seleziona la pagina che contiene lo shortcode [am_edit_form]. Serve per generare il pulsante “Modifica” nella scheda fungo.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_main');
	

		add_settings_field('am_show_submit_result_admin', __('Mostra esito invio (gestori AM)', 'archivio-micologico'), function () {
			$val = (int) get_option('am_show_submit_result_admin', 1);
			echo '<label><input type="checkbox" name="am_show_submit_result_admin" value="1" ' . checked(1, $val, false) . ' /> ' . esc_html__('Mostra il box “Esito invio” ad Amministratore ed Editore nel form front-end', 'archivio-micologico') . '</label>';
		}, 'am_settings', 'am_main');

		add_settings_field('am_submit_result_ttl', __('Durata esito invio (secondi)', 'archivio-micologico'), function () {
			$val = (int) get_option('am_submit_result_ttl', 20);
			
		
echo '<input type="number" min="0" max="600" name="am_submit_result_ttl" value="' . esc_attr($val) . '" />';
			echo '<p class="description">' . esc_html__('0 = non nascondere automaticamente. Valore consigliato: 20.', 'archivio-micologico') . '</p>';
		}, 'am_settings', 'am_main');


}

	public static function render_dashboard_page(): void {
		if (!current_user_can(AM_CAP_EDIT_POSTS)) {
			wp_die(esc_html__('Non hai il permesso di accedere a questa pagina.', 'archivio-micologico'));
		}

		$fungi_counts = wp_count_posts(AM_CPT);
		$article_counts = wp_count_posts(AM_ARTICLE_CPT);
		$total_fungi = $fungi_counts ? (int) ($fungi_counts->publish ?? 0) : 0;
		$total_articles = $article_counts ? (int) ($article_counts->publish ?? 0) : 0;
		$basidio = term_exists('Basidiomycota', AM_TAX_CLASSIFICAZIONE);
		$ascomy = term_exists('Ascomycota', AM_TAX_CLASSIFICAZIONE);
		$basidio_count = 0;
		$ascomy_count = 0;
		if (is_array($basidio) && !empty($basidio['term_id'])) {
			$basidio_count = self::count_posts_in_taxonomy_branch((int) $basidio['term_id']);
		}
		if (is_array($ascomy) && !empty($ascomy['term_id'])) {
			$ascomy_count = self::count_posts_in_taxonomy_branch((int) $ascomy['term_id']);
		}
		$recent_fungi = get_posts([
			'post_type' => AM_CPT,
			'post_status' => ['publish','draft','pending','private'],
			'numberposts' => 5,
			'orderby' => 'date',
			'order' => 'DESC',
		]);
		$fungi_author_counts = self::get_fungi_author_counts();

		echo '<div class="wrap">';
		echo '<style>
		.am-dashboard{max-width:1240px;margin-top:18px;}
		.am-dashboard-hero{display:grid;grid-template-columns:minmax(180px,240px) 1fr;align-items:center;gap:28px;background:linear-gradient(135deg,#ffffff 0%,#f5f7f4 100%);border:1px solid #d7ddd4;border-radius:18px;padding:24px 28px;box-shadow:0 2px 10px rgba(0,0,0,.03);}
		.am-dashboard-hero__logo{display:flex;flex-direction:column;align-items:center;justify-content:center;background:#fff;border:1px solid #e3e7e1;border-radius:16px;padding:16px;min-height:170px;}
		.am-dashboard-hero__logo img{display:block;max-width:100%;max-height:170px;width:auto;height:auto;}
		.am-dashboard-hero__body{min-width:280px;}
		.am-dashboard-hero__meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
		.am-dashboard-hero__eyebrow{display:inline-block;padding:6px 11px;border-radius:999px;background:#edf7ed;color:#166534;font-size:12px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;margin:0;}
		.am-dashboard-hero__version{display:inline-block;font-size:16px;line-height:1.2;color:#50575e;font-weight:600;}.am-dashboard-hero__version--under-logo{display:block;margin-top:10px;font-size:13px;color:#64748b;text-align:center;}
		.am-dashboard-hero h1{margin:0 0 10px;font-size:34px;line-height:1.12;color:#1d2327;}
		.am-dashboard-hero p{margin:0;color:#50575e;font-size:15px;max-width:760px;line-height:1.55;}
		.am-dashboard-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:18px 0 22px;}
		.am-dashboard-card{display:block;text-decoration:none;color:inherit;background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:18px 18px 16px;box-shadow:0 1px 2px rgba(0,0,0,.02);transition:transform .12s ease,box-shadow .12s ease,border-color .12s ease;}
		.am-dashboard-card:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(0,0,0,.06);border-color:#c3c4c7;}
		.am-dashboard-card__label{font-size:13px;color:#50575e;margin-bottom:10px;font-weight:600;}
		.am-dashboard-card__value{font-size:40px;line-height:1;font-weight:700;color:#1d2327;}
		.am-dashboard-panels{display:grid;grid-template-columns:minmax(340px,1.45fr) minmax(280px,1fr);gap:18px;align-items:start;}
		.am-dashboard-main{display:grid;gap:18px;align-self:start;}
		.am-dashboard-side{display:grid;gap:18px;align-self:start;}
		.am-dashboard-panel{background:#fff;border:1px solid #dcdcde;border-radius:16px;padding:22px;box-shadow:0 1px 2px rgba(0,0,0,.02);}
		.am-dashboard-panel h2{margin:0 0 14px;font-size:20px;line-height:1.2;color:#1d2327;}
		.am-dashboard-recent{margin:0;padding:0;list-style:none;}
		.am-dashboard-recent li{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:11px 0;border-top:1px solid #f0f0f1;}
		.am-dashboard-recent li:first-child{border-top:0;padding-top:0;}
		.am-dashboard-recent a{text-decoration:none;font-weight:600;line-height:1.4;}
		.am-dashboard-recent a:hover{text-decoration:underline;}
		.am-dashboard-recent__status{flex:0 0 auto;color:#646970;white-space:nowrap;font-size:12px;background:#f6f7f7;border:1px solid #e2e4e7;border-radius:999px;padding:4px 8px;}

		.am-dashboard-authors{margin:0;padding:0;list-style:none;display:grid;gap:10px;}
		.am-dashboard-authors li{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 12px;border:1px solid #eef0f1;border-radius:12px;background:#fafbfb;}
		.am-dashboard-authors__name{font-weight:600;color:#1d2327;line-height:1.35;}
		.am-dashboard-authors__count{flex:0 0 auto;min-width:34px;text-align:center;font-size:13px;font-weight:700;color:#166534;background:#edf7ed;border:1px solid #cfe7d1;border-radius:999px;padding:4px 10px;}
		.am-dashboard-authors-note{margin:10px 0 0;color:#646970;font-size:12px;line-height:1.45;}
		.am-dashboard-actions{display:grid;grid-template-columns:1fr;gap:10px;}
		.am-dashboard-actions .button{justify-content:center;min-height:42px;padding:0 14px;display:inline-flex;align-items:center;border-radius:10px;}
		.am-dashboard-note{margin-top:10px;color:#646970;font-size:12px;line-height:1.45;}
		.am-dashboard-panel--credits{padding-top:20px;}
		.am-dashboard-credits{margin-top:0;padding-top:0;border-top:0;}
		.am-dashboard-credits__item{margin:0 0 12px;}
		.am-dashboard-credits__label{display:flex;align-items:center;gap:8px;margin:0 0 4px;font-size:12px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;color:#646970;}
		.am-dashboard-credits__label .dashicons{font-size:16px;width:16px;height:16px;color:#50575e;}
		.am-dashboard-credits__value{margin:0 0 0 24px;font-size:14px;line-height:1.45;color:#1d2327;font-weight:600;}
		.am-dashboard-credits__value a{text-decoration:none;}
		.am-dashboard-credits__value a:hover{text-decoration:underline;}
		@media (max-width: 1100px){.am-dashboard-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
		@media (max-width: 960px){.am-dashboard-hero{grid-template-columns:1fr;padding:22px;}.am-dashboard-hero__logo{max-width:240px;justify-self:center;}.am-dashboard-panels{grid-template-columns:1fr;}.am-dashboard-side{grid-template-columns:1fr;}}
		@media (max-width: 640px){.am-dashboard-grid{grid-template-columns:1fr;}.am-dashboard-panel{padding:18px;}.am-dashboard-card__value{font-size:34px;}}
		.am-vocab-actions{display:flex;align-items:center;gap:8px;flex-wrap:nowrap;white-space:nowrap;}.am-vocab-actions form{display:inline-block;margin:0;}.am-vocab-actions .button,.am-vocab-actions .button-link-delete{margin:0 !important;}.am-vocab-table th:last-child,.am-vocab-table td:last-child{white-space:nowrap;width:240px;}.am-vocab-table .button-small{min-width:auto;}</style>';
		$dashboard_logo = AM_URL . 'assets/img/mycolibrary-logo.png';
		echo '<div class="am-dashboard">';
		echo '<section class="am-dashboard-hero">';
		echo '<div class="am-dashboard-hero__logo">';
		echo '<img src="' . esc_url($dashboard_logo) . '" alt="' . esc_attr__('MycoLibrary', 'archivio-micologico') . '">';
		echo '<div class="am-dashboard-hero__version am-dashboard-hero__version--under-logo">v' . esc_html(AM_VERSION) . '</div>';
		echo '</div>';
		echo '<div class="am-dashboard-hero__body">';
		echo '<div class="am-dashboard-hero__meta">';
		echo '<span class="am-dashboard-hero__eyebrow">' . esc_html__('Pannello di controllo', 'archivio-micologico') . '</span>';
		echo '</div>';
		echo '<h1>' . esc_html__('Archivio Micologico · Dashboard', 'archivio-micologico') . '</h1>';
		echo '<p>' . esc_html__('Vista rapida dello stato dell’archivio, con conteggi delle schede pubblicate e collegamenti diretti alle funzioni più usate.', 'archivio-micologico') . '</p>';
		echo '</div>';
		echo '</section>';
		echo '<div class="am-dashboard-grid">';
		self::render_dashboard_card(__('Schede totali', 'archivio-micologico'), (string) $total_fungi, admin_url('edit.php?post_type=' . AM_CPT));
		self::render_dashboard_card(__('Basidiomycota', 'archivio-micologico'), (string) $basidio_count, admin_url('edit-tags.php?taxonomy=' . AM_TAX_CLASSIFICAZIONE . '&post_type=' . AM_CPT));
		self::render_dashboard_card(__('Ascomycota', 'archivio-micologico'), (string) $ascomy_count, admin_url('edit-tags.php?taxonomy=' . AM_TAX_CLASSIFICAZIONE . '&post_type=' . AM_CPT));
		self::render_dashboard_card(__('Articoli totali', 'archivio-micologico'), (string) $total_articles, admin_url('edit.php?post_type=' . AM_ARTICLE_CPT));
		echo '</div>';
		echo '<div class="am-dashboard-panels">';
		echo '<div class="am-dashboard-main">';
		echo '<div class="am-dashboard-panel">';
		echo '<h2>' . esc_html__('Ultime schede', 'archivio-micologico') . '</h2>';
		if ($recent_fungi) {
			echo '<ul class="am-dashboard-recent">';
			foreach ($recent_fungi as $post) {
				$status_obj = get_post_status_object($post->post_status);
				echo '<li>';
				echo '<a href="' . esc_url(get_permalink($post->ID)) . '" target="_blank" rel="noopener noreferrer">' . esc_html(get_the_title($post)) . '</a>';
				echo '<span class="am-dashboard-recent__status">' . esc_html($status_obj ? $status_obj->label : $post->post_status) . '</span>';
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__('Nessuna scheda trovata.', 'archivio-micologico') . '</p>';
		}
		echo '</div>';
		echo '<div class="am-dashboard-panel">';
		echo '<h2>' . esc_html__('Autori schede funghi', 'archivio-micologico') . '</h2>';
		if ($fungi_author_counts) {
			echo '<ul class="am-dashboard-authors">';
			foreach ($fungi_author_counts as $author_item) {
				echo '<li>';
				echo '<span class="am-dashboard-authors__name">' . esc_html($author_item['name']) . '</span>';
				echo '<span class="am-dashboard-authors__count">' . esc_html((string) $author_item['count']) . '</span>';
				echo '</li>';
			}
			echo '</ul>';
			echo '<p class="am-dashboard-authors-note">' . esc_html__('Conteggio calcolato sulle schede fungo pubblicate.', 'archivio-micologico') . '</p>';
		} else {
			echo '<p>' . esc_html__('Nessun autore trovato.', 'archivio-micologico') . '</p>';
		}
		echo '</div>';
		echo '</div>';
		echo '<div class="am-dashboard-side">';
		echo '<div class="am-dashboard-panel">';
		echo '<h2>' . esc_html__('Accesso rapido', 'archivio-micologico') . '</h2>';
		echo '<div class="am-dashboard-actions">';
		echo '<a class="button button-primary" href="' . esc_url(self::get_front_submit_page_url()) . '">' . esc_html__('Nuova Scheda', 'archivio-micologico') . '</a>';
		echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=' . AM_CPT)) . '">' . esc_html__('Apri Schede', 'archivio-micologico') . '</a>';
		echo '<a class="button" href="' . esc_url(admin_url('edit-tags.php?taxonomy=' . AM_TAX_CLASSIFICAZIONE . '&post_type=' . AM_CPT)) . '">' . esc_html__('Apri Tassonomia', 'archivio-micologico') . '</a>';
		echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=am_settings')) . '">' . esc_html__('Apri Impostazioni', 'archivio-micologico') . '</a>';
		echo '</div>';
		echo '<p class="am-dashboard-note">' . esc_html__('I conteggi mostrano le schede pubblicate, in coerenza con l’archivio visibile nel frontend.', 'archivio-micologico') . '</p>';
		echo '</div>';
		echo '<div class="am-dashboard-panel am-dashboard-panel--credits">';
		echo '<h2>' . esc_html__('Crediti', 'archivio-micologico') . '</h2>';
		echo '<div class="am-dashboard-credits">';
		echo '<div class="am-dashboard-credits__item">';
		echo '<div class="am-dashboard-credits__label"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span>' . esc_html__('Autore', 'archivio-micologico') . '</div>';
		echo '<div class="am-dashboard-credits__value">Pasqualino De Marco</div>';
		echo '</div>';
		echo '<div class="am-dashboard-credits__item">';
		echo '<div class="am-dashboard-credits__label"><span class="dashicons dashicons-groups" aria-hidden="true"></span>' . esc_html__('Associazione', 'archivio-micologico') . '</div>';
		echo '<div class="am-dashboard-credits__value">Associazione Micologica Bassa Friulana</div>';
		echo '</div>';
		echo '<div class="am-dashboard-credits__item">';
		echo '<div class="am-dashboard-credits__label"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>' . esc_html__('Sito web', 'archivio-micologico') . '</div>';
		echo '<div class="am-dashboard-credits__value"><a href="https://www.micologiabf.org" target="_blank" rel="noopener noreferrer">micologiabf.org</a></div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}


	private static function get_fungi_author_counts(): array {
		global $wpdb;

		$results = $wpdb->get_results($wpdb->prepare(
			"SELECT p.post_author AS author_id, COUNT(*) AS total
			 FROM {$wpdb->posts} p
			 WHERE p.post_type = %s
			   AND p.post_status = 'publish'
			 GROUP BY p.post_author
			 ORDER BY total DESC, author_id ASC",
			AM_CPT
		), ARRAY_A);

		if (empty($results)) {
			return [];
		}

		$items = [];
		foreach ($results as $row) {
			$author_id = isset($row['author_id']) ? (int) $row['author_id'] : 0;
			$count = isset($row['total']) ? (int) $row['total'] : 0;
			$name = $author_id > 0 ? get_the_author_meta('display_name', $author_id) : '';
			if (!$name) {
				$name = __('Autore sconosciuto', 'archivio-micologico');
			}
			$items[] = [
				'author_id' => $author_id,
				'name' => $name,
				'count' => $count,
			];
		}

		return $items;
	}


	private static function count_posts_in_taxonomy_branch(int $term_id): int {
		if ($term_id <= 0) {
			return 0;
		}

		$q = new \WP_Query([
			'post_type'              => AM_CPT,
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => [[
				'taxonomy'         => AM_TAX_CLASSIFICAZIONE,
				'field'            => 'term_id',
				'terms'            => [$term_id],
				'include_children' => true,
			]],
		]);

		return (int) $q->found_posts;
	}

	private static function render_dashboard_card(string $label, string $value, string $url): void {
		echo '<a href="' . esc_url($url) . '" class="am-dashboard-card">';
		echo '<div class="am-dashboard-card__label">' . esc_html($label) . '</div>';
		echo '<div class="am-dashboard-card__value">' . esc_html($value) . '</div>';
		echo '</a>';
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
		];

		echo '<h2 style="margin-top:24px">' . esc_html__('Audit shortcode e widget', 'archivio-micologico') . '</h2>';
		echo '<table class="widefat striped" style="max-width:980px">';
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
		echo '<div class="wrap">';
		self::am_debug_add('render_settings_page', ['am_saved' => isset($_GET['am_saved']) ? 1 : 0, 'am_err' => isset($_GET['am_err']) ? sanitize_key((string)$_GET['am_err']) : '']);
		if (function_exists('am_cleanup_legacy_roles_and_permissions')) { am_cleanup_legacy_roles_and_permissions(); }
		self::am_debug_add('AM_PERMS_SIMPLIFIED_1_5_3', ['allowed' => ['administrator', 'editor']]);
		self::am_debug_add('AM_CAPS_RUNTIME_CHECK_1_5_4', ['enabled' => 1]);


$show_debug = (current_user_can('manage_options') || current_user_can(AM_CAP_MANAGE_SETTINGS)) && ((defined('WP_DEBUG') && WP_DEBUG) || ((int) get_option('am_enable_debug_panel', 0) === 1));

// Debug panels (optional). Log is always stored in DB.
$am_log = get_option('am_debug_log', []);
if ($show_debug && is_array($am_log) && !empty($am_log)) {
	echo '<details style="margin:10px 0;padding:10px;border:1px solid #ccd0d4;background:#fff;">';
	echo '<summary><strong>AM DEBUG (ultimi eventi)</strong></summary>';
	echo '<div style="max-height:220px; overflow:auto; margin-top:8px;">';
	foreach ($am_log as $row) {
		$time = isset($row['time']) ? date_i18n('Y-m-d H:i:s', intval($row['time'])) : '';
		$tag  = isset($row['tag']) ? (string) $row['tag'] : '';
		$user = isset($row['user']) ? (string) $row['user'] : '';
		$data = isset($row['data']) ? $row['data'] : [];
		echo '<div style="margin:0 0 8px 0;padding:6px;border-bottom:1px dashed #ddd;">';
		echo '<code>' . esc_html($time) . '</code> <strong>' . esc_html($tag) . '</strong> — ' . esc_html($user) . '<br>';
		echo '<code>' . esc_html(wp_json_encode($data)) . '</code>';
		echo '</div>';
	}
	echo '</div></details>';
}

if (isset($_GET['am_err'])) {
			$err = sanitize_key((string) $_GET['am_err']);
			echo '<div class="notice notice-error"><p><strong>Salvataggio non completato</strong> (errore: ' . esc_html($err) . ').</p></div>';
		}
		if (isset($_GET['am_saved'])) {
			echo '<div class="notice notice-success"><p>' . esc_html__('Impostazioni salvate.', 'archivio-micologico') . '</p></div>';


		}
		echo '<h1>' . esc_html__('Archivio Micologico - Impostazioni', 'archivio-micologico') . '</h1>';

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
		echo '<input type="hidden" name="action" value="am_save_settings">';
		wp_nonce_field('am_save_settings');

		// Render existing sections/fields (main settings)
		do_settings_sections('am_settings');
		echo '<p class="description" style="margin-top:8px;max-width:900px">' . esc_html__('Le impostazioni del widget valgono sia per il widget WordPress sia, come default, per lo shortcode [am_random_funghi]. Lo shortcode può comunque sovrascrivere numero, titolo, immagini per riga, righe, autoplay e intervallo.', 'archivio-micologico') . '</p>';


		// 1.5.0: fixed permissions summary. No AM custom profiles and no role matrix.
		echo '<h2 style="margin-top:24px">' . esc_html__('Permessi Archivio Micologico', 'archivio-micologico') . '</h2>';
		echo '<div class="notice notice-info inline" style="max-width:980px;padding:12px 14px;">';
		echo '<p><strong>' . esc_html__('Permessi semplificati attivi', 'archivio-micologico') . '</strong></p>';
		echo '<p>' . esc_html__('Il plugin non usa più profili AM personalizzati né una matrice ruoli configurabile.', 'archivio-micologico') . '</p>';
		echo '<ul style="list-style:disc;margin-left:20px">';
		echo '<li>' . esc_html__('Amministratore: accesso completo a schede, articoli, tassonomie, media, impostazioni e strumenti.', 'archivio-micologico') . '</li>';
		echo '<li>' . esc_html__('Editore: normali permessi WordPress + gestione completa Archivio Micologico.', 'archivio-micologico') . '</li>';
		echo '<li>' . esc_html__('Altri ruoli: nessuna gestione AM dal front-end o dal back-end.', 'archivio-micologico') . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__('Le vecchie opzioni ruoli vengono normalizzate automaticamente e gli eventuali ruoli AM legacy vengono rimossi. Il controllo runtime delle capability Admin/Editore è attivo.', 'archivio-micologico') . '</p>';
		echo '</div>';


		self::render_shortcode_widget_audit_1_5_5();


		echo '<h2 style="margin-top:24px">' . esc_html__('SEO schede fungo', 'archivio-micologico') . '</h2>';
		$am_seo_mode = (string) get_option('am_seo_mode', 'auto');
		echo '<table class="form-table" role="presentation">';
		echo '<tr>';
		echo '<th scope="row">' . esc_html__('Meta SEO automatici AM', 'archivio-micologico') . '</th>';
		echo '<td>';
		echo '<select name="am_seo_mode">';
		echo '<option value="auto" ' . selected($am_seo_mode, 'auto', false) . '>' . esc_html__('Automatico: disattiva AM se è attivo un plugin SEO', 'archivio-micologico') . '</option>';
		echo '<option value="force" ' . selected($am_seo_mode, 'force', false) . '>' . esc_html__('Forza meta SEO AM', 'archivio-micologico') . '</option>';
		echo '<option value="off" ' . selected($am_seo_mode, 'off', false) . '>' . esc_html__('Disattiva meta SEO AM', 'archivio-micologico') . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__('La modalità consigliata è Automatico. Se Yoast, Rank Math, SEOPress o All in One SEO sono attivi, Archivio Micologico evita duplicati di meta description, canonical e JSON-LD.', 'archivio-micologico') . '</p>';
		echo '</td>';
		echo '</tr>';
		echo '</table>';


		submit_button();
		echo '</form>';

		// Tools (separate forms - avoid nested forms issues)
		echo '<hr style="margin:24px 0">';
		echo '<h2>' . esc_html__('Strumenti', 'archivio-micologico') . '</h2>';
		echo '<h3>' . esc_html__('Debug', 'archivio-micologico') . '</h3>';
		self::render_clear_debug_log_tool();

		echo '</div>';
	}

	
	private static function render_admin_permissions_table_1_5_8(): void {
		$rows = [
			[
				'role' => __('Amministratore', 'archivio-micologico'),
				'admin_access' => __('Sì', 'archivio-micologico'),
				'fungi' => __('Gestione completa', 'archivio-micologico'),
				'articles' => __('Gestione completa', 'archivio-micologico'),
				'taxonomies' => __('Può creare, modificare e assegnare classificazioni', 'archivio-micologico'),
				'media' => __('Accesso completo alla Libreria Media, immagini, gallerie e PDF', 'archivio-micologico'),
				'settings' => __('Sì', 'archivio-micologico'),
			],
			[
				'role' => __('Editore', 'archivio-micologico'),
				'admin_access' => __('Sì', 'archivio-micologico'),
				'fungi' => __('Gestione completa AM', 'archivio-micologico'),
				'articles' => __('Gestione completa AM', 'archivio-micologico'),
				'taxonomies' => __('Può creare, modificare e assegnare classificazioni', 'archivio-micologico'),
				'media' => __('Accesso completo alla Libreria Media, immagini, gallerie e PDF', 'archivio-micologico'),
				'settings' => __('Sì, per le impostazioni AM', 'archivio-micologico'),
			],
			[
				'role' => __('Altri ruoli WordPress', 'archivio-micologico'),
				'admin_access' => __('No', 'archivio-micologico'),
				'fungi' => __('Non autorizzati', 'archivio-micologico'),
				'articles' => __('Non autorizzati', 'archivio-micologico'),
				'taxonomies' => __('Non autorizzati', 'archivio-micologico'),
				'media' => __('Solo eventuali permessi WordPress propri, non gestione AM', 'archivio-micologico'),
				'settings' => __('No', 'archivio-micologico'),
			],
		];

		echo '<h2>' . esc_html__('Permessi lato admin Archivio Micologico', 'archivio-micologico') . '</h2>';
		echo '<p>' . esc_html__('Dalla linea 1.5.x il plugin usa solo i ruoli WordPress standard Amministratore ed Editore per la gestione AM. I vecchi profili AM personalizzati non sono più utilizzati.', 'archivio-micologico') . '</p>';
		echo '<table class="widefat striped" style="max-width:1200px;margin:12px 0 24px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__('Ruolo WordPress', 'archivio-micologico') . '</th>';
		echo '<th>' . esc_html__('Accesso admin AM', 'archivio-micologico') . '</th>';
		echo '<th>' . esc_html__('Schede fungo', 'archivio-micologico') . '</th>';
		echo '<th>' . esc_html__('Articoli AM', 'archivio-micologico') . '</th>';
		echo '<th>' . esc_html__('Classificazioni', 'archivio-micologico') . '</th>';
		echo '<th>' . esc_html__('Media / PDF / Gallerie', 'archivio-micologico') . '</th>';
		echo '<th>' . esc_html__('Impostazioni AM', 'archivio-micologico') . '</th>';
		echo '</tr></thead><tbody>';

		foreach ($rows as $row) {
			echo '<tr>';
			echo '<td><strong>' . esc_html($row['role']) . '</strong></td>';
			echo '<td>' . esc_html($row['admin_access']) . '</td>';
			echo '<td>' . esc_html($row['fungi']) . '</td>';
			echo '<td>' . esc_html($row['articles']) . '</td>';
			echo '<td>' . esc_html($row['taxonomies']) . '</td>';
			echo '<td>' . esc_html($row['media']) . '</td>';
			echo '<td>' . esc_html($row['settings']) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__('Nota: il controllo runtime delle capability mantiene allineati Amministratore ed Editore e rimuove eventuali vecchie capability AM dagli altri ruoli.', 'archivio-micologico') . '</p>';
	}


public static function render_instructions_page(): void {

		// Access control
		if (!current_user_can(AM_CAP_EDIT_POSTS)) {
			wp_die(esc_html__('Non hai i permessi per visualizzare questa pagina.', 'archivio-micologico'));
		}

		echo '<div class="wrap">';
		self::am_debug_add('render_instructions_page', []);
		echo '<h1>' . esc_html__('Archivio Micologico – Istruzioni', 'archivio-micologico') . '</h1>';
		self::render_admin_permissions_table_1_5_8();


		echo '<p><strong>' . esc_html__('Versione plugin:', 'archivio-micologico') . '</strong> <code>' . esc_html(AM_VERSION) . '</code></p>';

		echo '<h2>' . esc_html__('Configurazione delle pagine', 'archivio-micologico') . '</h2>';
		echo '<p>' . esc_html__('Con Anna Weather, modifica la pagina nell’editor a blocchi di WordPress, aggiungi un blocco Shortcode e incolla lo shortcode della funzione desiderata.', 'archivio-micologico') . '</p>';
		echo '<ul>';
		echo '<li><code>[am_archive]</code> – ' . esc_html__('Albero delle classificazioni, ricerca live e ultime schede pubblicate.', 'archivio-micologico') . '</li>';
		echo '<li><code>[am_archive_filters]</code> – ' . esc_html__('Filtri per divisione, famiglia, genere e specie.', 'archivio-micologico') . '</li>';
		echo '<li><code>[am_search_page]</code> – ' . esc_html__('Pagina di ricerca con risultati paginati.', 'archivio-micologico') . '</li>';
		echo '<li><code>[am_submit_form]</code> – ' . esc_html__('Modulo guidato per inserire una scheda fungo.', 'archivio-micologico') . '</li>';
		echo '<li><code>[am_edit_form]</code> – ' . esc_html__('Modulo guidato per modificare una scheda fungo.', 'archivio-micologico') . '</li>';
		echo '<li><code>[am_article_form]</code> – ' . esc_html__('Modulo per inserire articoli con immagini, galleria e PDF.', 'archivio-micologico') . '</li>';
		echo '<li><code>[am_random_funghi]</code> – ' . esc_html__('Carosello di schede fungo casuali.', 'archivio-micologico') . '</li>';
		echo '</ul>';

		echo '<h2>' . esc_html__('Archivio e ricerca', 'archivio-micologico') . '</h2>';
		echo '<p>' . esc_html__('L’archivio organizza le schede in un albero con quattro livelli: Divisione, Famiglia, Genere e Specie. Per mostrare anche i filtri, inserisci [am_archive_filters] sopra [am_archive] nella stessa pagina.', 'archivio-micologico') . '</p>';
		echo '<p>' . esc_html__('La ricerca live, la pagina dedicata e il caricamento dei risultati successivi cercano in titoli, contenuti, autore, forma o varietà e tassonomie. I risultati includono contenuti pubblicati senza password.', 'archivio-micologico') . '</p>';
		echo '<p>' . esc_html__('In Impostazioni → Ultime schede pubblicate scegli quante schede mostrare (5 per impostazione predefinita, fino a 20). Imposta 0 per nascondere il pannello. Per aprirlo dal menu di Anna Weather, aggiungi #ultime-schede all’indirizzo della pagina Archivio.', 'archivio-micologico') . '</p>';

		echo '<h2>' . esc_html__('Inserimento, modifica e revisione', 'archivio-micologico') . '</h2>';
		echo '<p>' . esc_html__('Usa [am_submit_form] nella pagina Nuovo inserimento e [am_edit_form] in una pagina dedicata alla modifica. In Archivio Micologico → Impostazioni seleziona quest’ultima in Pagina modifica schede (front-end). Il pulsante Modifica scheda compare per chi può modificare la scheda.', 'archivio-micologico') . '</p>';
		echo '<p>' . esc_html__('Segui i passaggi Classificazione, Descrizioni, Media e allegati e Controllo finale. Per creare una scheda scegli Invia scheda. Per aggiornare una scheda esistente scegli Salva modifiche. Se l’invio restituisce un avviso, leggi il messaggio nel modulo.', 'archivio-micologico') . '</p>';
		echo '<p>' . esc_html__('Amministratore ed Editore possono usare Salva revisione sulle schede che hanno il permesso di modificare. Il pulsante registra il revisore e la data e l’ora della revisione.', 'archivio-micologico') . '</p>';
		echo '<p>' . esc_html__('Se Anna Weather mostra già il titolo nel template della scheda, disattiva Mostra il titolo all’interno della scheda nelle impostazioni del plugin.', 'archivio-micologico') . '</p>';

		echo '<h2>' . esc_html__('Carosello delle schede casuali', 'archivio-micologico') . '</h2>';
		echo '<p>' . esc_html__('Con Anna Weather inserisci [am_random_funghi] in un blocco Shortcode. Nei temi che dispongono di aree widget puoi anche usare Archivio Micologico – Schede casuali.', 'archivio-micologico') . '</p>';
		echo '<p>' . esc_html__('Lo shortcode accetta numero (1–12), titolo, visibili (1–3 colonne su desktop), righe (1–4), autoplay (0 o 1) e intervallo (2–20 secondi). I parametri omessi ereditano le impostazioni del plugin.', 'archivio-micologico') . '</p>';
		echo '<pre><code>[am_random_funghi numero="6" visibili="3" righe="2" autoplay="1" intervallo="4"]</code></pre>';

		echo '<h2>' . esc_html__('Se il modulo non funziona', 'archivio-micologico') . '</h2>';
		echo '<p>' . esc_html__('Verifica che la pagina usi il blocco Shortcode e che l’utente abbia il ruolo e i permessi necessari. Se il problema è comparso dopo un aggiornamento, svuota la cache e riprova.', 'archivio-micologico') . '</p>';
		echo '<p>' . esc_html__('Se il problema persiste, attiva Mostra pannello debug nelle Impostazioni del plugin e consulta la sezione Debug. Per problemi con i controlli multimediali, verifica anche gli errori nella console del browser.', 'archivio-micologico') . '</p>';

		echo '</div>';
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
		echo '<p class="description" style="margin-top:6px"><em>' . esc_html__('Nota: il pannello “AM DEBUG” è nascosto. Per visualizzarlo, abilita “Mostra pannello debug” nella sezione Generali.', 'archivio-micologico') . '</em></p>';
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
