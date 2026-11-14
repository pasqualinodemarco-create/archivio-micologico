<?php

namespace ArchivioMicologico;

if (!defined('ABSPATH')) { exit; }

class EntryForms {
	const NONCE_ACTION = 'am_submit_fungo';
	const NONCE_ACTION_UPDATE = 'am_update_fungo';
	const NONCE_NAME = 'am_nonce';

	private const STICKY_TTL = 900; // 15 minutes
	private const STICKY_PREFIX = 'am_sticky_';

	public static function am_get_sticky(string $token): array {
		$token = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
		if ($token === '') return [];
		$val = get_transient(self::STICKY_PREFIX . $token);
		return is_array($val) ? $val : [];
	}

	private static function am_save_sticky(string $token, array $data): void {
		$token = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
		if ($token === '') return;
		set_transient(self::STICKY_PREFIX . $token, $data, self::STICKY_TTL);
	}

	private static function am_clear_sticky(string $token): void {
		$token = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
		if ($token === '') return;
		delete_transient(self::STICKY_PREFIX . $token);
	}

	private static function am_collect_sticky_post(): array {
		$post = $_POST ?? [];

		$gallery_raw = isset($post['am_gallery_ids']) ? (string) $post['am_gallery_ids'] : '';
		$gallery_ids = array_values(array_filter(array_map('absint', array_filter(array_map('trim', explode(',', $gallery_raw))))));

		return [
			'am_title'       => isset($post['am_title']) ? sanitize_text_field((string) $post['am_title']) : '',
			'am_division'    => isset($post['am_division']) ? absint($post['am_division']) : 0,
			'am_family'      => isset($post['am_family']) ? absint($post['am_family']) : 0,
			'am_genus'       => isset($post['am_genus']) ? absint($post['am_genus']) : 0,
			'am_species'     => isset($post['am_species']) ? absint($post['am_species']) : 0,

			'am_infra'       => isset($post['am_infra']) ? absint($post['am_infra']) : 0,
			'am_author'      => isset($post['am_author']) ? absint($post['am_author']) : 0,

			'am_content'     => isset($post['am_content']) ? (string) $post['am_content'] : '',
			'am_macro_micro' => isset($post['am_macro_micro']) ? (string) $post['am_macro_micro'] : '',
			'am_notes'       => isset($post['am_notes']) ? (string) $post['am_notes'] : '',

			'am_featured_id' => isset($post['am_featured_id']) ? absint($post['am_featured_id']) : 0,
			'am_gallery_ids' => $gallery_ids,
			'am_pdf_id'      => isset($post['am_pdf_id']) ? absint($post['am_pdf_id']) : 0,

			'am_article_cats' => isset($post['am_article_cats']) && is_array($post['am_article_cats']) ? array_values(array_filter(array_map('absint', $post['am_article_cats']))) : [],

			'am_sequenced'   => (!empty($post['am_sequenced']) && (string)$post['am_sequenced'] === '1') ? '1' : '0',
		];
	}

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

	public static function am_current_user_is_admin_or_editor(): bool {
		if (function_exists('am_user_is_admin_or_editor')) {
			return am_user_is_admin_or_editor();
		}
		if (!is_user_logged_in()) { return false; }
		$user = wp_get_current_user();
		$roles = is_array($user->roles) ? $user->roles : [];
		return in_array('administrator', $roles, true) || in_array('editor', $roles, true) || current_user_can('manage_options');
	}

	private static function am_roles_allowed(string $mode): bool {
		$allowed = self::am_current_user_is_admin_or_editor();
		if (!$allowed && (int) get_option('am_enable_debug_panel', 0) === 1 && function_exists('am_debug_funghi_force_output_event')) {
			am_debug_funghi_force_output_event('AM_PERMISSION_DENIED_ADMIN_EDITOR_ONLY_1_5_3', [
				'mode' => $mode,
				'user_id' => get_current_user_id(),
			]);
		}
		return $allowed;
	}

	private static function am_user_can_edit_post(int $post_id): bool {
		return self::am_current_user_is_admin_or_editor();
	}

	private static function am_strlen(string $s): int {
		if (function_exists('mb_strlen')) {
			return (int) mb_strlen($s);
		}
		return (int) strlen($s);
	}

	public static function ajax_save_review(): void {
		if (!am_user_can_review()) {
			wp_send_json_error(['message' => __('Non autorizzato.', 'archivio-micologico')]);
		}
		check_ajax_referer('am_ajax', 'nonce');

		$post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
		if ($post_id <= 0) {
			wp_send_json_error(['message' => __('ID scheda non valido.', 'archivio-micologico')]);
		}
		$post = get_post($post_id);
		if (!$post || $post->post_type !== AM_CPT) {
			wp_send_json_error(['message' => __('Scheda non valida.', 'archivio-micologico')]);
		}
		if (!current_user_can('edit_post', $post_id)) {
			wp_send_json_error(['message' => __('Permessi insufficienti.', 'archivio-micologico')]);
		}

		$user = wp_get_current_user();
		$reviewer_name = (string) ($user->display_name ?? '');
		$reviewer_id = (int) ($user->ID ?? 0);
		$dt = current_time('mysql');

		update_post_meta($post_id, 'am_last_reviewer_name', $reviewer_name);
		update_post_meta($post_id, 'am_last_review_datetime', $dt);
		update_post_meta($post_id, 'am_last_review_user_id', $reviewer_id);

		$display = sprintf(
			__('Ultima revisione: %1$s (%2$s)', 'archivio-micologico'),
			$reviewer_name !== '' ? $reviewer_name : 'Admin',
			$dt
		);

		wp_send_json_success([
			'reviewer' => $reviewer_name,
			'datetime' => $dt,
			'display'  => $display,
		]);
	}

	public static function ajax_add_term(): void {
		if (!is_user_logged_in()) {
			wp_send_json_error(['message' => __('Non autorizzato.', 'archivio-micologico')]);
		}

		check_ajax_referer('am_ajax', 'nonce');

		// Solo front-end: accetta richieste solo dal contesto front-end (JS del plugin).
		$context = isset($_POST['context']) ? sanitize_key((string) $_POST['context']) : '';
		if ($context !== 'frontend') {
			wp_send_json_error(['message' => __('Operazione non consentita.', 'archivio-micologico') . (current_user_can('manage_options') ? ' [DBG:context]' : '')]);
		}

		$user = wp_get_current_user();
		$roles = is_array($user->roles) ? $user->roles : [];
		$allowed = self::am_current_user_is_admin_or_editor();
		if (!$allowed) {
			wp_send_json_error(['message' => __('Permessi insufficienti.', 'archivio-micologico') . (current_user_can('manage_options') ? ' [DBG:perm]' : '')]);
		}

		$name = isset($_POST['name']) ? sanitize_text_field((string) $_POST['name']) : '';
		$parent = isset($_POST['parent']) ? absint($_POST['parent']) : 0;
		$level = isset($_POST['level']) ? sanitize_key((string) $_POST['level']) : '';

		$tax = AM_TAX_CLASSIFICAZIONE;
		if ($level === 'infra') { $tax = AM_TAX_INFRASPEC; $parent = 0; }
		if ($level === 'author') { $tax = AM_TAX_AUTHOR; $parent = 0; }

		if (self::am_strlen($name) < 1) {
			wp_safe_redirect(add_query_arg(['am_term_ok' => '0', 'am_term_msg' => rawurlencode('Inserisci un nome per il termine tassonomico.')], wp_get_referer()));
			exit;
		}

		// Enforce hierarchy levels for classificazione: Divisione > Famiglia > Genere > Specie
		if ($tax === AM_TAX_CLASSIFICAZIONE) {
			if ($level === 'family' && $parent <= 0) { wp_send_json_error(['message' => __('Seleziona prima una Divisione.', 'archivio-micologico')]); }
			if ($level === 'genus' && $parent <= 0) { wp_send_json_error(['message' => __('Seleziona prima una Famiglia.', 'archivio-micologico')]); }
			if ($level === 'species' && $parent <= 0) { wp_send_json_error(['message' => __('Seleziona prima un Genere.', 'archivio-micologico')]); }
		}


		// Prevent duplicates under the same parent.
		$exists = term_exists($name, $tax, $parent);
		if ($exists && !is_wp_error($exists)) {
			$term_id = is_array($exists) ? (int) $exists['term_id'] : (int) $exists;
			$term = get_term($term_id, $tax);
			wp_send_json_success([
				'id' => $term_id,
				'name' => $term && !is_wp_error($term) ? $term->name : $name,
				'message' => __('Termine già esistente: selezionato.', 'archivio-micologico'),
			]);
		}


		// Species epithets can recur in different genera. The parent-scoped check above handles true duplicates.
		$global_exists = $level === 'species' ? null : term_exists($name, $tax);
		if ($global_exists && !is_wp_error($global_exists)) {
			$gid = is_array($global_exists) ? (int) $global_exists['term_id'] : (int) $global_exists;
			$gterm = get_term($gid, $tax);
			if ($gterm && !is_wp_error($gterm)) {
				$existing_parent = (int) ($gterm->parent ?? 0);
				if ($tax === AM_TAX_CLASSIFICAZIONE && $parent > 0 && $existing_parent !== 0 && $existing_parent !== $parent) {
					$pp = get_term($existing_parent, $tax);
					$ppn = ($pp && !is_wp_error($pp)) ? $pp->name : '';
					wp_send_json_error(['message' => sprintf(__('Termine già esistente in una posizione diversa (genitore: %s). Usa quello esistente e non duplicare.', 'archivio-micologico'), $ppn)]);
				}
				// If it exists and parent is compatible (or parent not required), reuse it.
				wp_send_json_success(['id' => $gid, 'name' => $gterm->name, 'message' => __('Termine già esistente: selezionato.', 'archivio-micologico')]);
			}
		}

global $am_allow_frontend_term_insert;
		$am_allow_frontend_term_insert = true;
		$created = wp_insert_term($name, $tax, ['parent' => $parent]);
		$am_allow_frontend_term_insert = false;

		if (is_wp_error($created)) {
			wp_send_json_error(['message' => $created->get_error_message()]);
		}

		wp_send_json_success([
			'id' => (int) $created['term_id'],
			'name' => $name,
			'message' => __('Termine creato.', 'archivio-micologico'),
		]);
	}

	public static function render_submit_form($atts = []): string {
		self::maybe_enable_frontend_debug();
		$require_login = (int) get_option('am_require_login', 1);
		if ($require_login && !is_user_logged_in()) {
			$login_url = wp_login_url(get_permalink());
			return '<div class="am-notice am-notice--info">' .
				esc_html__('Devi effettuare il login per inserire una scheda.', 'archivio-micologico') .
				' <a href="' . esc_url($login_url) . '">' . esc_html__('Accedi', 'archivio-micologico') . '</a>' .
			'</div>';
		}

		if (is_user_logged_in() && (!self::am_roles_allowed('create') || !current_user_can(AM_CAP_CREATE_POSTS))) {
			return '<div class="am-notice am-notice--error">' . esc_html__('Non hai i permessi per creare una scheda.', 'archivio-micologico') . '</div>';
		}

		ob_start();

		// Success message is rendered in templates/submit-form.php in a single,
		// consolidated notice (to avoid duplicated confirmations).
		// Error message (rendered inside templates below the action buttons).
$am_pre_notice_html = '';
if (isset($_GET['am_error'])) {
	$code = sanitize_text_field((string) $_GET['am_error']);
	$messages = [
		'missing_title' => __('Inserisci il nome scientifico.', 'archivio-micologico'),
		'missing_content' => __('Inserisci la descrizione macroscopica.', 'archivio-micologico'),
		'macro_too_short' => __('La descrizione macroscopica deve essere lunga almeno 20 caratteri.', 'archivio-micologico'),
		'missing_macro_micro' => __('Inserisci la descrizione microscopica.', 'archivio-micologico'),
		'missing_infra' => __('Seleziona Forma o Varietà.', 'archivio-micologico'),
		'missing_author' => __('Seleziona Autore.', 'archivio-micologico'),
		'missing_featured' => __('Seleziona l’immagine anteprima.', 'archivio-micologico'),
		'missing_gallery' => __('Seleziona almeno un’immagine per la galleria.', 'archivio-micologico'),
		'missing_classification' => __('Seleziona Divisione, Famiglia, Genere e Specie.', 'archivio-micologico'),
		'permission' => __('Non hai i permessi per inviare una scheda.', 'archivio-micologico'),
		'nonce' => __('Sessione scaduta. Ricarica la pagina e riprova.', 'archivio-micologico'),
		'insert_failed' => __('Impossibile salvare la scheda. Riprova.', 'archivio-micologico'),
	];
	$msg = $messages[$code] ?? __('Si è verificato un errore durante l\'invio. Riprova.', 'archivio-micologico');
	$am_pre_notice_html .= '<div class="am-notice am-notice--error">' . esc_html($msg) . '</div>';
}

echo '<div id="am-submit-form" class="am-scope">';
		include AM_PATH . 'templates/submit-form.php';
		echo '</div>';

		return (string) ob_get_clean();
	}

	public static function render_article_form($atts = []): string {
		self::maybe_enable_frontend_debug();
		$require_login = (int) get_option('am_require_login', 1);
		if ($require_login && !is_user_logged_in()) {
			$login_url = wp_login_url(get_permalink());
			return '<div class="am-notice am-notice--warning">' .
				esc_html__('Devi effettuare il login per inserire un articolo.', 'archivio-micologico') .
				' <a href="' . esc_url($login_url) . '">' . esc_html__('Vai al login', 'archivio-micologico') . '</a></div>';
		}

		if (!is_user_logged_in() || !self::am_roles_allowed('create') || !current_user_can(AM_CAP_CREATE_POSTS)) {
			return '<div class="am-notice am-notice--error">' . esc_html__('Non sei autorizzato a inserire articoli.', 'archivio-micologico') . '</div>';
		}

		ob_start();
		$template = AM_PATH . 'templates/article-form.php';
		if (file_exists($template)) {
			include $template;
		} else {
			echo '<div class="am-notice am-notice--error">' . esc_html__('Template articolo mancante.', 'archivio-micologico') . '</div>';
		}
		return (string) ob_get_clean();
	}

	public static function render_edit_form($atts = []): string {
		self::maybe_enable_frontend_debug();
		$require_login = (int) get_option('am_require_login', 1);
		if ($require_login && !is_user_logged_in()) {
			$login_url = wp_login_url(get_permalink());
			return '<div class="am-notice am-notice--info">' .
				esc_html__('Devi effettuare il login per modificare una scheda.', 'archivio-micologico') .
				' <a href="' . esc_url($login_url) . '">' . esc_html__('Accedi', 'archivio-micologico') . '</a>' .
			'</div>';
		}

		if (!is_user_logged_in()) {
			return '<div class="am-notice am-notice--error">' . esc_html__('Non autorizzato.', 'archivio-micologico') . '</div>';
		}

		$post_id = isset($_GET['am_edit']) ? absint($_GET['am_edit']) : 0;
		if (!$post_id) {
			return '<div class="am-notice am-notice--error">' . esc_html__('Nessuna scheda selezionata per la modifica.', 'archivio-micologico') . '</div>';
		}

		$am_post = get_post($post_id);
		if (!$am_post || $am_post->post_type !== AM_CPT) {
			return '<div class="am-notice am-notice--error">' . esc_html__('Scheda non trovata.', 'archivio-micologico') . '</div>';
		}
if (!self::am_roles_allowed('edit') || !current_user_can('edit_post', $post_id)) {
			return '<div class="am-notice am-notice--error">' . esc_html__('Non hai i permessi per modificare questa scheda.', 'archivio-micologico') . '</div>';
		}

		// Prepare taxonomy preselection (Famiglia > Genere > Specie).
$am_selected_division = 0;
		$am_selected_family = 0;
		$am_selected_genus = 0;
		$am_selected_species = 0;
		$terms = get_the_terms($post_id, AM_TAX_CLASSIFICAZIONE);
		if ($terms && !is_wp_error($terms)) {
			$term = null;
			$max_depth = -1;
			foreach ($terms as $t) {
				$anc = get_ancestors($t->term_id, AM_TAX_CLASSIFICAZIONE);
				$depth = is_array($anc) ? count($anc) : 0;
				if ($depth > $max_depth) {
					$max_depth = $depth;
					$term = $t;
				}
			}
			if ($term) {
				$chain = array_reverse(get_ancestors($term->term_id, AM_TAX_CLASSIFICAZIONE));
				$chain[] = (int) $term->term_id;
				if (count($chain) >= 4) {
					$am_selected_division = (int) ($chain[0] ?? 0);
					$am_selected_family   = (int) ($chain[1] ?? 0);
					$am_selected_genus    = (int) ($chain[2] ?? 0);
					$am_selected_species  = (int) ($chain[3] ?? 0);
				} elseif (count($chain) === 3) {
					$am_selected_family   = (int) ($chain[0] ?? 0);
					$am_selected_genus    = (int) ($chain[1] ?? 0);
					$am_selected_species  = (int) ($chain[2] ?? 0);
				}
			}
		}
		if (!$am_selected_division) { $am_selected_division = (int) get_post_meta($post_id, 'am_tax_division_id', true); }
		if (!$am_selected_family) { $am_selected_family = (int) get_post_meta($post_id, 'am_tax_family_id', true); }
		if (!$am_selected_genus) { $am_selected_genus = (int) get_post_meta($post_id, 'am_tax_genus_id', true); }
		if (!$am_selected_species) { $am_selected_species = (int) get_post_meta($post_id, 'am_tax_species_id', true); }

		$am_macro_micro = (string) get_post_meta($post_id, 'am_macro_micro', true);
		$am_notes = (string) get_post_meta($post_id, 'am_notes', true);
		$am_selected_infra = (int) get_post_meta($post_id, 'am_infra_id', true);
		$am_selected_author = (int) get_post_meta($post_id, 'am_author_id', true);
		$am_last_reviewer_name = (string) get_post_meta($post_id, 'am_last_reviewer_name', true);
		$am_last_review_datetime = (string) get_post_meta($post_id, 'am_last_review_datetime', true);
		$am_featured_id = (int) get_post_thumbnail_id($post_id);
		$am_gallery_ids = get_post_meta($post_id, 'am_gallery_ids', true);
		if (!is_array($am_gallery_ids)) { $am_gallery_ids = []; }

		ob_start();

// Notices (rendered inside templates below the action buttons).
$am_pre_notice_html = '';
if (isset($_GET['am_updated']) && $_GET['am_updated'] === '1') {
	$am_pre_notice_html .= '<div class="am-notice am-notice--success">' . esc_html__('Scheda aggiornata correttamente.', 'archivio-micologico') . '</div>';
}

if (isset($_GET['am_error'])) {
	$code = sanitize_text_field((string) $_GET['am_error']);
	$messages = [
		'missing_title' => __('Inserisci il nome scientifico.', 'archivio-micologico'),
		'missing_content' => __('Inserisci la descrizione macroscopica.', 'archivio-micologico'),
		'macro_too_short' => __('La descrizione macroscopica deve essere lunga almeno 20 caratteri.', 'archivio-micologico'),
		'missing_macro_micro' => __('Inserisci la descrizione microscopica.', 'archivio-micologico'),
		'missing_infra' => __('Seleziona Forma o Varietà.', 'archivio-micologico'),
		'missing_author' => __('Seleziona Autore.', 'archivio-micologico'),
		'missing_featured' => __('Seleziona l’immagine anteprima.', 'archivio-micologico'),
		'missing_gallery' => __('Seleziona almeno un’immagine per la galleria.', 'archivio-micologico'),
		'missing_classification' => __('Seleziona Divisione, Famiglia, Genere e Specie.', 'archivio-micologico'),
		'permission' => __('Non hai i permessi per modificare questa scheda.', 'archivio-micologico'),
		'nonce' => __('Sessione scaduta. Ricarica la pagina e riprova.', 'archivio-micologico'),
		'update_failed' => __('Impossibile aggiornare la scheda. Riprova.', 'archivio-micologico'),
	];
	$msg = $messages[$code] ?? __('Si è verificato un errore durante l\'aggiornamento. Riprova.', 'archivio-micologico');
	$am_pre_notice_html .= '<div class="am-notice am-notice--error">' . esc_html($msg) . '</div>';
}

echo '<div id="am-edit-form" class="am-scope">';
include AM_PATH . 'templates/edit-form.php';
echo '</div>';
		return (string) ob_get_clean();
	}

	public static function ajax_get_terms(): void {
		check_ajax_referer('am_ajax', 'nonce');

		$taxonomy = sanitize_text_field($_GET['taxonomy'] ?? '');
		$allowed_tax = [AM_TAX_CLASSIFICAZIONE, AM_TAX_INFRASPEC, AM_TAX_AUTHOR];
		if (!in_array($taxonomy, $allowed_tax, true)) {
			wp_send_json_error(['message' => 'Invalid taxonomy']);
		}
		$parent = isset($_GET['parent']) ? absint($_GET['parent']) : 0;
		$args = [
			'taxonomy' => $taxonomy,
			'hide_empty' => false,
			'orderby' => 'name',
			'order' => 'ASC',
		];
		if ($taxonomy === AM_TAX_CLASSIFICAZIONE) {
			$args['parent'] = $parent;
		}
		$terms = get_terms($args);

		if (is_wp_error($terms)) {
			wp_send_json_error(['message' => $terms->get_error_message()]);
		}

		$charset = (string) (get_bloginfo('charset') ?: 'UTF-8');
		$data = array_map(function ($t) use ($charset) {
			return [
				'id'     => (int) $t->term_id,
				'name'   => html_entity_decode((string) $t->name, ENT_QUOTES | ENT_HTML5, $charset),
				'parent' => (int) $t->parent,
			];
		}, $terms);

		wp_send_json_success(['terms' => $data]);
	}

	public static function handle_submit(): void {

		$redirect = remove_query_arg(['am_error', 'am_token', 'am_submitted', 'am_post_id'], wp_get_referer() ?: home_url('/'));

		// Sticky token (used to restore form fields on validation errors)
		$am_token = isset($_POST['am_token']) ? sanitize_text_field((string) $_POST['am_token']) : '';
		if ($am_token === '') { $am_token = wp_generate_password(12, false, false); }

		if (!is_user_logged_in() || !self::am_roles_allowed('create') || !current_user_can(AM_CAP_CREATE_POSTS)) {
			self::set_submit_result(false, 'not_allowed', __('Non autorizzato a creare schede.', 'archivio-micologico'));
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error'=>'not_allowed','am_token'=>$am_token], $redirect));
			exit;
		}

		self::am_set_last_submit(['ok'=>0,'msg'=>'Submit iniziato','ts'=>time()]);


		$require_login = (int) get_option('am_require_login', 1);
		if ($require_login && !is_user_logged_in()) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: permission','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'permission','am_token'=>$am_token], $redirect));
			exit;
		}

		if (!is_user_logged_in() || !current_user_can(AM_CAP_CREATE_POSTS)) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: permission','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'permission','am_token'=>$am_token], $redirect));
			exit;
		}

		if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce($_POST[self::NONCE_NAME], self::NONCE_ACTION)) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: nonce','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'nonce','am_token'=>$am_token], $redirect));
			exit;
		}

		// Titolo canonico: Genere + Specie (+ Forma/Varietà) (+ Autore)
		// Non dipende da input manuale: viene sempre ricostruito per coerenza scientifica.
		$title = sanitize_text_field($_POST['am_title'] ?? '');

		$species_id = isset($_POST['am_species']) ? absint($_POST['am_species']) : 0;
		$infra_id   = isset($_POST['am_infra']) ? absint($_POST['am_infra']) : 0;
		$author_id  = isset($_POST['am_author']) ? absint($_POST['am_author']) : 0;
		$am_classification = self::resolve_classification_from_post_1_5_34($species_id);
		$term_division = (int) $am_classification['division'];
		$term_family   = (int) $am_classification['family'];
		$term_genus    = (int) $am_classification['genus'];
		$term_species  = (int) $am_classification['species'];
		$species_id    = $term_species;

		$species = '';
		if ($species_id) {
			$ts = get_term($species_id, AM_TAX_CLASSIFICAZIONE);
			if ($ts && !is_wp_error($ts)) { $species = (string) $ts->name; }
		}

		$infra = '';
		if ($infra_id) {
			$ti = get_term($infra_id, AM_TAX_INFRASPEC);
			if ($ti && !is_wp_error($ti)) { $infra = (string) $ti->name; }
		}

		$author = '';
		if ($author_id) {
			$ta = get_term($author_id, AM_TAX_AUTHOR);
			if ($ta && !is_wp_error($ta)) { $author = (string) $ta->name; }
		}

		// Costruisci sempre il titolo da Genere + Specie (+ Forma/Varietà) (+ Autore)
		$genus_name = '';
		$tg_tmp = $term_genus ? get_term($term_genus, AM_TAX_CLASSIFICAZIONE) : null;
		if ($tg_tmp && !is_wp_error($tg_tmp)) { $genus_name = (string) $tg_tmp->name; }
		$parts = array_filter([trim($genus_name), trim($species)]);
		$title = trim(implode(' ', $parts));
		if ($infra)  { $title = trim($title . ' ' . trim($infra)); }
		if ($author) { $title = trim($title . ' ' . trim($author)); }
		$title = trim(preg_replace('/\s+/', ' ', $title));
		$content = EditorContent::sanitize(wp_unslash($_POST['am_content'] ?? ''));
		$macro_plain = trim(wp_strip_all_tags($content));
		if (mb_strlen($macro_plain) < 20) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: macro_too_short','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'macro_too_short','am_token'=>$am_token], $redirect));
			exit;
		}

		$macro_micro = EditorContent::sanitize(wp_unslash($_POST['am_macro_micro'] ?? ''));

		$notes = EditorContent::sanitize(wp_unslash($_POST['am_notes'] ?? ''));
		// v1.5.34: classification already resolved from posted species/ancestors above.

		if (!$term_division || !$term_family || !$term_genus || !$term_species) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: missing_classification','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'missing_classification','am_token'=>$am_token], $redirect));
			exit;
		}

		$td = get_term($term_division, AM_TAX_CLASSIFICAZIONE);
		$tf = get_term($term_family, AM_TAX_CLASSIFICAZIONE);
		$tg = get_term($term_genus, AM_TAX_CLASSIFICAZIONE);
		$ts = get_term($term_species, AM_TAX_CLASSIFICAZIONE);
		if (!$td || is_wp_error($td) || !$tf || is_wp_error($tf) || !$tg || is_wp_error($tg) || !$ts || is_wp_error($ts)) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: missing_classification','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'missing_classification','am_token'=>$am_token], $redirect));
			exit;
		}
		if ((int) $tf->parent !== (int) $term_division || (int) $tg->parent !== (int) $term_family || (int) $ts->parent !== (int) $term_genus) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: missing_classification','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'missing_classification','am_token'=>$am_token], $redirect));
			exit;
		}

		$assign = $term_species ?: ($term_genus ?: $term_family);

		// Images: featured + gallery (required).

		// Featured image (optional on edit). If field is present and empty => clear; if absent => keep current.
		$featured_id = null;
		if ( array_key_exists('am_featured_id', $_POST) ) {
			$featured_id = absint($_POST['am_featured_id']);
		} else {
			$featured_id = 0;
		}

		// Gallery (optional on edit). If field present and empty => clear; if absent => keep current.
		$gallery_ids = null;
		if ( array_key_exists('am_gallery_ids', $_POST) ) {
			$raw = (string) $_POST['am_gallery_ids'];
			$gallery_ids = array_values(array_filter(array_map('absint', array_filter(array_map('trim', explode(',', $raw))))));
		} else {
			$gallery_ids = [];
		}

		// Optional PDF attachment (stored as attachment ID).
		$pdf_id = absint($_POST['am_pdf_id'] ?? 0);
		if ($pdf_id) {
			// Validate the attachment with the WordPress core MIME API.
			$mime = '';
			if (get_post_type($pdf_id) === 'attachment') {
				$mime = (string) get_post_mime_type($pdf_id);

				if ($mime === '') {
					$file = get_attached_file($pdf_id);
					if (is_string($file) && $file !== '' && function_exists('wp_check_filetype')) {
						$ft = wp_check_filetype($file);
						$mime = (string) ($ft['type'] ?? '');
					}
				}
			}

			if ($mime !== 'application/pdf') {
				$pdf_id = 0;
			}
		}


		$status = (string) get_option('am_default_status', 'pending');
		if (!in_array($status, ['draft', 'pending', 'publish', 'private'], true)) { $status = 'pending'; }
		if (in_array($status, ['publish', 'private'], true) && !current_user_can(AM_CAP_PUBLISH_POSTS)) { $status = 'pending'; }

		$post_id = wp_insert_post([
			'post_type' => AM_CPT,
			'post_title' => $title,
			'post_content' => $content,
			'post_status' => $status,
			'post_author' => get_current_user_id(),
		], true);

		if (is_wp_error($post_id)) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'wp_insert_post WP_Error: ' . $post_id->get_error_message(),'ts'=>time()]);
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: insert_failed','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'insert_failed','am_token'=>$am_token], $redirect));
			exit;
		}

		// Meta fields.
		update_post_meta($post_id, 'am_macro_micro', $macro_micro);
		update_post_meta($post_id, 'am_notes', $notes);
		update_post_meta($post_id, 'am_species', $species);
		update_post_meta($post_id, 'am_infra', $infra);
		update_post_meta($post_id, 'am_author', $author);
		update_post_meta($post_id, 'am_infra_id', $infra_id);
		update_post_meta($post_id, 'am_author_id', $author_id);

		// Ultima revisione: stessi permessi di modifica della scheda.
		$save_review = (isset($_POST['am_save_review']) && (string) $_POST['am_save_review'] === '1');
		if ($save_review && am_user_can_review((int) $post_id)) {
			$user = wp_get_current_user();
			update_post_meta($post_id, 'am_last_reviewer_name', (string) $user->display_name);
			update_post_meta($post_id, 'am_last_review_datetime', current_time('mysql'));
			update_post_meta($post_id, 'am_last_review_user_id', (int) $user->ID);
		}

		// Checkbox: Specie sequenziata (facoltativo)
		$sequenced = (isset($_POST['am_sequenced']) && (string) $_POST['am_sequenced'] === '1') ? '1' : '0';
		if ($sequenced === '1') { update_post_meta($post_id, 'am_sequenced', '1'); } else { delete_post_meta($post_id, 'am_sequenced'); }

		// Taxonomy assignment (prefer the most specific selected term).
		$assign = $term_species ?: ($term_genus ?: $term_family);
		if ($assign) {
			wp_set_object_terms($post_id, [$assign], AM_TAX_CLASSIFICAZIONE, false);
			update_post_meta($post_id, 'am_tax_division_id', $term_division);
			update_post_meta($post_id, 'am_tax_family_id', $term_family);
			update_post_meta($post_id, 'am_tax_genus_id', $term_genus);
			update_post_meta($post_id, 'am_tax_species_id', $term_species);
		}

		// Secondary taxonomies (Forma/Varietà, Autore)
		if ($infra_id) { wp_set_object_terms($post_id, [$infra_id], AM_TAX_INFRASPEC, false); }
		else { wp_set_object_terms($post_id, [], AM_TAX_INFRASPEC, false); }
		if ($author_id) { wp_set_object_terms($post_id, [$author_id], AM_TAX_AUTHOR, false); }
		else { wp_set_object_terms($post_id, [], AM_TAX_AUTHOR, false); }

		// Images.
		if ($featured_id) { set_post_thumbnail($post_id, $featured_id); } else { delete_post_thumbnail($post_id); }
		update_post_meta($post_id, 'am_gallery_ids', $gallery_ids);
		if ($pdf_id) { update_post_meta($post_id, 'am_pdf_id', $pdf_id); } else { delete_post_meta($post_id, 'am_pdf_id'); }

		self::am_set_last_submit(['ok'=>1,'msg'=>'Scheda creata correttamente','post_id'=>intval($post_id),'status'=>get_post_status($post_id),'ts'=>time()]);
		wp_safe_redirect(add_query_arg(['am_submitted'=>'1','am_post_id'=>(int)$post_id], $redirect));
		exit;
	}

	public static function handle_submit_article(): void {

		$redirect = wp_get_referer() ?: home_url('/');

		$am_token = isset($_POST['am_token']) ? sanitize_text_field((string) $_POST['am_token']) : '';
		if ($am_token === '') { $am_token = wp_generate_password(12, false, false); }

		if (!is_user_logged_in() || !self::am_roles_allowed('create') || !current_user_can(AM_CAP_CREATE_POSTS)) {
			self::set_submit_result(false, 'not_allowed', __('Non autorizzato a creare articoli.', 'archivio-micologico'));
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error'=>'not_allowed','am_token'=>$am_token], $redirect));
			exit;
		}

		$require_login = (int) get_option('am_require_login', 1);
		if ($require_login && !is_user_logged_in()) {
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'permission','am_token'=>$am_token], $redirect));
			exit;
		}

		if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce($_POST[self::NONCE_NAME], self::NONCE_ACTION)) {
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'nonce','am_token'=>$am_token], $redirect));
			exit;
		}

		$title = isset($_POST['am_title']) ? sanitize_text_field((string) $_POST['am_title']) : '';
		$content = isset($_POST['am_content']) ? (string) wp_unslash($_POST['am_content']) : '';

		$featured_id = isset($_POST['am_featured_id']) ? absint($_POST['am_featured_id']) : 0;
		$gallery_raw = isset($_POST['am_gallery_ids']) ? (string) $_POST['am_gallery_ids'] : '';
		$gallery_ids = array_values(array_filter(array_map('absint', array_filter(array_map('trim', explode(',', $gallery_raw))))));
		$pdf_id = isset($_POST['am_pdf_id']) ? absint($_POST['am_pdf_id']) : 0;

		$cats = [];
		if (isset($_POST['am_article_cats']) && is_array($_POST['am_article_cats'])) {
			foreach ($_POST['am_article_cats'] as $tid) {
				$tid = absint($tid);
				if ($tid) { $cats[] = $tid; }
			}
			$cats = array_values(array_unique($cats));
		}

		if (trim($title) === '') {
			self::set_submit_result(false, 'missing_title', __('Inserisci il titolo dell’articolo.', 'archivio-micologico'));
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error'=>'missing_title','am_token'=>$am_token], $redirect));
			exit;
		}

		$default_status = (string) get_option('am_default_status', 'pending');
		$allowed_status = ['draft','pending','publish','private'];
		if (!in_array($default_status, $allowed_status, true)) { $default_status = 'pending'; }

		$post_id = wp_insert_post([
			'post_type' => AM_ARTICLE_CPT,
			'post_status' => $default_status,
			'post_title' => $title,
			'post_content' => wp_kses_post($content),
			'post_author' => get_current_user_id(),
		], true);

		if (is_wp_error($post_id) || !$post_id) {
			self::set_submit_result(false, 'insert_failed', __('Errore nella creazione dell’articolo.', 'archivio-micologico'));
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error'=>'insert_failed','am_token'=>$am_token], $redirect));
			exit;
		}

		// Categories (multiple)
		if (!empty($cats)) {
			wp_set_object_terms($post_id, $cats, AM_TAX_ARTICLE_CAT, false);
		} else {
			wp_set_object_terms($post_id, [], AM_TAX_ARTICLE_CAT, false);
		}

		// Media
		if ($featured_id) { set_post_thumbnail($post_id, $featured_id); } else { delete_post_thumbnail($post_id); }
		update_post_meta($post_id, 'am_gallery_ids', $gallery_ids);
		if ($pdf_id) { update_post_meta($post_id, 'am_pdf_id', $pdf_id); } else { delete_post_meta($post_id, 'am_pdf_id'); }

		self::am_clear_sticky($am_token);
		self::am_set_last_submit(['ok'=>1,'msg'=>'Articolo creato correttamente','post_id'=>intval($post_id),'status'=>get_post_status($post_id),'ts'=>time()]);

		wp_safe_redirect(add_query_arg(['am_submitted'=>'1','am_post_id'=>(int)$post_id], $redirect));
		exit;
	}

	public static function handle_update(): void {



		$redirect = wp_get_referer() ?: home_url('/');

		$am_token = isset($_POST['am_token']) ? sanitize_text_field((string) $_POST['am_token']) : '';
		if ($am_token === '') { $am_token = wp_generate_password(12, false, false); }



		self::am_debug_reason('UPDATE_START', [
			'post_id' => isset($_POST['am_post_id']) ? absint($_POST['am_post_id']) : 0,
			'has_nonce' => isset($_POST[self::NONCE_NAME]) ? 1 : 0,
			'am_action' => isset($_POST['am_action']) ? sanitize_text_field(wp_unslash($_POST['am_action'])) : '',
		]);
$nonce = $_POST[self::NONCE_NAME] ?? '';
		if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION_UPDATE)) {
			self::set_submit_result(false, 'nonce', __('Errore di sicurezza (nonce).', 'archivio-micologico'));
			wp_safe_redirect(add_query_arg(['am_error' => 'nonce'], $redirect));
			exit;
		}
if (empty($_POST['am_action']) || $_POST['am_action'] !== 'update') { return; }
if (!is_user_logged_in() || !self::am_roles_allowed('edit')) {
			self::set_submit_result(false, 'not_allowed', __('Non autorizzato a modificare schede.', 'archivio-micologico'));
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error'=>'not_allowed','am_token'=>$am_token], wp_get_referer() ?: home_url('/')));
			exit;
		}

$redirect = wp_get_referer() ?: home_url('/');

		$am_token = isset($_POST['am_token']) ? sanitize_text_field((string) $_POST['am_token']) : '';
		if ($am_token === '') { $am_token = wp_generate_password(12, false, false); }



		$post_id = isset($_POST['am_post_id']) ? absint($_POST['am_post_id']) : 0;
		if (!$post_id) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: update_failed','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'update_failed','am_token'=>$am_token], $redirect));
			exit;
		}
		$post = get_post($post_id);
		if (!$post || $post->post_type !== AM_CPT) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: update_failed','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'update_failed','am_token'=>$am_token], $redirect));
			exit;
		}

		if (!self::am_user_can_edit_post($post_id)) {
			self::set_submit_result(false, 'not_allowed', __('Non autorizzato a modificare questa scheda.', 'archivio-micologico'));
			wp_safe_redirect(add_query_arg(['am_error' => 'not_allowed'], $redirect));
			exit;
		}

		if (!current_user_can('edit_post', $post_id)) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: permission','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'permission','am_token'=>$am_token], $redirect));
			exit;
		}

		if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce($_POST[self::NONCE_NAME], self::NONCE_ACTION_UPDATE)) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: nonce','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'nonce','am_token'=>$am_token], $redirect));
			exit;
		}

		$title = sanitize_text_field($_POST['am_title'] ?? '');

		$species_id = isset($_POST['am_species']) ? absint($_POST['am_species']) : 0;
		$infra_id   = isset($_POST['am_infra']) ? absint($_POST['am_infra']) : 0;
		$author_id  = isset($_POST['am_author']) ? absint($_POST['am_author']) : 0;
		$am_classification = self::resolve_classification_from_post_1_5_34($species_id);
		$term_division = (int) $am_classification['division'];
		$term_family   = (int) $am_classification['family'];
		$term_genus    = (int) $am_classification['genus'];
		$term_species  = (int) $am_classification['species'];
		$species_id    = $term_species;

		$species = '';
		if ($species_id) {
			$ts = get_term($species_id, AM_TAX_CLASSIFICAZIONE);
			if ($ts && !is_wp_error($ts)) { $species = (string) $ts->name; }
		}

		$infra = '';
		if ($infra_id) {
			$ti = get_term($infra_id, AM_TAX_INFRASPEC);
			if ($ti && !is_wp_error($ti)) { $infra = (string) $ti->name; }
		}

		$author = '';
		if ($author_id) {
			$ta = get_term($author_id, AM_TAX_AUTHOR);
			if ($ta && !is_wp_error($ta)) { $author = (string) $ta->name; }
		}

		// Costruzione sempre del titolo canonico.
		$genus_name = '';
		$tg_tmp = $term_genus ? get_term($term_genus, AM_TAX_CLASSIFICAZIONE) : null;
		if ($tg_tmp && !is_wp_error($tg_tmp)) { $genus_name = $tg_tmp->name; }
		$parts = array_filter([trim($genus_name), trim($species)]);
		$title = trim(implode(' ', $parts));
		if ($infra)  { $title = trim($title . ' ' . trim($infra)); }
		if ($author) { $title = trim($title . ' ' . trim($author)); }
		$title = trim(preg_replace('/\s+/', ' ', $title));
		$content = EditorContent::sanitize(wp_unslash($_POST['am_content'] ?? ''));
		$macro_plain = trim(wp_strip_all_tags($content));
		if (mb_strlen($macro_plain) < 20) {
			self::am_debug_reason('UPDATE_FAILED_REASON', ['reason'=>'macro_too_short','post_id'=>$post_id]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error'=>'macro_too_short','am_token'=>$am_token], $redirect));
			exit;
		}
		$macro_micro = EditorContent::sanitize(wp_unslash($_POST['am_macro_micro'] ?? ''));

		$notes = EditorContent::sanitize(wp_unslash($_POST['am_notes'] ?? ''));
		// v1.5.34: classification already resolved from posted species/ancestors above.

		if (!$term_division || !$term_family || !$term_genus || !$term_species) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: missing_classification','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'missing_classification','am_token'=>$am_token], $redirect));
			exit;
		}

		$td = get_term($term_division, AM_TAX_CLASSIFICAZIONE);
		$tf = get_term($term_family, AM_TAX_CLASSIFICAZIONE);
		$tg = get_term($term_genus, AM_TAX_CLASSIFICAZIONE);
		$ts = get_term($term_species, AM_TAX_CLASSIFICAZIONE);
		if (!$td || is_wp_error($td) || !$tf || is_wp_error($tf) || !$tg || is_wp_error($tg) || !$ts || is_wp_error($ts)) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: missing_classification','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'missing_classification','am_token'=>$am_token], $redirect));
			exit;
		}
		if ((int) $tf->parent !== (int) $term_division || (int) $tg->parent !== (int) $term_family || (int) $ts->parent !== (int) $term_genus) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: missing_classification','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'missing_classification','am_token'=>$am_token], $redirect));
			exit;
		}

		$featured_id = isset($_POST['am_featured_id']) ? absint($_POST['am_featured_id']) : (int) get_post_thumbnail_id($post_id);
		$gallery_ids_raw = isset($_POST['am_gallery_ids']) ? sanitize_text_field($_POST['am_gallery_ids']) : implode(',', (array) get_post_meta($post_id, 'am_gallery_ids', true));
		$gallery_ids = array_filter(array_map('absint', explode(',', $gallery_ids_raw)));
		$pdf_id = isset($_POST['am_pdf_id']) ? absint($_POST['am_pdf_id']) : (int) get_post_meta($post_id, 'am_pdf_id', true);
		if ($pdf_id) {
			$mime = get_post_type($pdf_id) === 'attachment' ? (string) get_post_mime_type($pdf_id) : '';
			if ($mime !== 'application/pdf') {
				$pdf_id = 0;
			}
		}



		// Immagine anteprima facoltativa anche in modifica (nessuna validazione bloccante)

		// Build scientific name: Genere + Specie (+ Forma/Varietà) (+ Autore) (NO Famiglia).
		$genus_name = '';
		$tg2 = get_term($term_genus, AM_TAX_CLASSIFICAZIONE);
		if ($tg2 && !is_wp_error($tg2)) { $genus_name = (string) $tg2->name; }

		$scientific_parts = array_filter([trim($genus_name), trim($species), trim($infra), trim($author)]);
		$scientific_name = trim(implode(' ', $scientific_parts));

		if (!$scientific_name) {
			self::am_debug_reason('UPDATE_FAILED_REASON', ['reason'=>'missing_scientific_name','post_id'=>$post_id]);
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: missing_scientific_name','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'missing_title','am_token'=>$am_token], $redirect)); exit;
		}

		// Force title to scientific name for coherence
		$title = $scientific_name;

		if (!$content) { self::am_debug_reason('UPDATE_FAILED_REASON',['reason'=>'missing_macro_description','post_id'=>$post_id]);
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: missing_content','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'missing_content','am_token'=>$am_token], $redirect)); exit; }
$updated = wp_update_post([
			'ID' => $post_id,
			'post_title' => $title,
			'post_content' => $content,
		], true);

		if (is_wp_error($updated)) {
			self::am_debug_reason('UPDATE_FAILED_REASON',['reason'=>'wp_update_post_error','post_id'=>$post_id,'wp_error'=>$updated->get_error_message()]);
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: update_failed','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'update_failed','am_token'=>$am_token], $redirect));
			exit;
		}

		update_post_meta($post_id, 'am_macro_micro', $macro_micro);
		update_post_meta($post_id, 'am_notes', $notes);
		update_post_meta($post_id, 'am_species', $species);
		update_post_meta($post_id, 'am_infra', $infra);
		update_post_meta($post_id, 'am_author', $author);
		update_post_meta($post_id, 'am_infra_id', $infra_id);
		update_post_meta($post_id, 'am_author_id', $author_id);
		// Ultima revisione: stessi permessi di modifica della scheda.
		$save_review = (isset($_POST['am_save_review']) && (string) $_POST['am_save_review'] === '1');
		if ($save_review && am_user_can_review((int) $post_id)) {
			$user = wp_get_current_user();
			update_post_meta($post_id, 'am_last_reviewer_name', (string) $user->display_name);
			update_post_meta($post_id, 'am_last_review_datetime', current_time('mysql'));
			update_post_meta($post_id, 'am_last_review_user_id', (int) $user->ID);
		}

		// Checkbox: Specie sequenziata (facoltativo)
		$sequenced = (isset($_POST['am_sequenced']) && (string) $_POST['am_sequenced'] === '1') ? '1' : '0';
		if ($sequenced === '1') { update_post_meta($post_id, 'am_sequenced', '1'); } else { delete_post_meta($post_id, 'am_sequenced'); }



		$assign = $term_species ?: ($term_genus ?: $term_family);
		if ($assign) {
			wp_set_object_terms($post_id, [$assign], AM_TAX_CLASSIFICAZIONE, false);
			update_post_meta($post_id, 'am_tax_division_id', $term_division);
			update_post_meta($post_id, 'am_tax_family_id', $term_family);
			update_post_meta($post_id, 'am_tax_genus_id', $term_genus);
			update_post_meta($post_id, 'am_tax_species_id', $term_species);
		}

		// Secondary taxonomies (Forma/Varietà, Autore)
		if ($infra_id) { wp_set_object_terms($post_id, [$infra_id], AM_TAX_INFRASPEC, false); }
		else { wp_set_object_terms($post_id, [], AM_TAX_INFRASPEC, false); }
		if ($author_id) { wp_set_object_terms($post_id, [$author_id], AM_TAX_AUTHOR, false); }
		else { wp_set_object_terms($post_id, [], AM_TAX_AUTHOR, false); }

		if ($featured_id) { set_post_thumbnail($post_id, $featured_id); } else { delete_post_thumbnail($post_id); }
		update_post_meta($post_id, 'am_gallery_ids', $gallery_ids);
		if ($pdf_id) { update_post_meta($post_id, 'am_pdf_id', $pdf_id); } else { delete_post_meta($post_id, 'am_pdf_id'); }

		clean_post_cache($post_id);
			self::am_debug_reason('UPDATE_SAVED', [
				'post_id'=>$post_id,
				'saved_title'=>get_the_title($post_id),
				'saved_species'=>get_post_meta($post_id,'am_species',true),
				'saved_infra'=>get_post_meta($post_id,'am_infra',true),
				'saved_author'=>get_post_meta($post_id,'am_author',true),
				'saved_featured_id'=>(int)get_post_thumbnail_id($post_id),
				'saved_family_id'=>(int)get_post_meta($post_id,'am_tax_family_id',true),
				'saved_genus_id'=>(int)get_post_meta($post_id,'am_tax_genus_id',true),
				'saved_species_id'=>(int)get_post_meta($post_id,'am_tax_species_id',true),
				'content_len'=>strlen((string)get_post_field('post_content',$post_id)),
				'macro_micro_len'=>strlen((string)get_post_meta($post_id,'am_macro_micro',true)),
			]);

			self::am_debug_reason('UPDATE_OK', ['post_id'=>$post_id]);

		$single = get_permalink($post_id);
		if ( ! $single ) { $single = $redirect; }
		$single = add_query_arg('am_updated', '1', $single);
		wp_safe_redirect( $single );
		exit;
		}

	private static function am_debug_reason(string $tag, array $data = []): void {
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

	public static function handle_add_term_post(): void {
		if (!is_user_logged_in()) { return; }
		if (empty($_POST['am_add_term_post'])) { return; }

		$redirect = wp_get_referer();
		if (!$redirect) { $redirect = home_url('/'); }

		if (!isset($_POST['am_add_term_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['am_add_term_nonce'])), 'am_add_term_post')) {
			wp_safe_redirect(add_query_arg(['am_term_ok' => 0, 'am_term_msg' => rawurlencode(__('Nonce non valido.', 'archivio-micologico'))], $redirect));
			exit;
		}

		$level = isset($_POST['am_term_level']) ? sanitize_key((string) wp_unslash($_POST['am_term_level'])) : '';
		$name  = isset($_POST['am_term_name']) ? sanitize_text_field((string) wp_unslash($_POST['am_term_name'])) : '';
		$parent = isset($_POST['am_term_parent']) ? absint($_POST['am_term_parent']) : 0;

		$user = wp_get_current_user();
		$roles = is_array($user->roles) ? $user->roles : [];
		$allowed = self::am_current_user_is_admin_or_editor();
		if (!$allowed) {
			wp_safe_redirect(add_query_arg(['am_term_ok' => 0, 'am_term_msg' => rawurlencode(__('Permessi insufficienti.', 'archivio-micologico'))], $redirect));
			exit;
		}

		if (self::am_strlen($name) < 1) {
			wp_safe_redirect(add_query_arg(['am_term_ok' => '0', 'am_term_msg' => rawurlencode('Inserisci un nome per il termine tassonomico.')], wp_get_referer()));
			exit;
		}

		// Hierarchy enforcement (Divisione > Famiglia > Genere > Specie)
		if ($level === 'genus' && $parent <= 0) {
			wp_safe_redirect(add_query_arg(['am_term_ok' => 0, 'am_term_msg' => rawurlencode(__('Seleziona prima una Famiglia.', 'archivio-micologico'))], $redirect));
			exit;
		}
		if ($level === 'species' && $parent <= 0) {
			wp_safe_redirect(add_query_arg(['am_term_ok' => 0, 'am_term_msg' => rawurlencode(__('Seleziona prima un Genere.', 'archivio-micologico'))], $redirect));
			exit;
		}

		$tax = AM_TAX_CLASSIFICAZIONE;
		if ($level === 'infra') { $tax = AM_TAX_INFRASPEC; $parent = 0; }
		if ($level === 'author') { $tax = AM_TAX_AUTHOR; $parent = 0; }

		$exists = term_exists($name, $tax, $parent);
		if ($exists && !is_wp_error($exists)) {
			wp_safe_redirect(add_query_arg(['am_term_ok' => 1, 'am_term_msg' => rawurlencode(__('Termine già esistente.', 'archivio-micologico'))], $redirect));
			exit;
		}

		global $am_allow_frontend_term_insert;
		$am_allow_frontend_term_insert = true;
		$created = wp_insert_term($name, $tax, ['parent' => $parent]);
		$am_allow_frontend_term_insert = false;

		if (is_wp_error($created)) {
			wp_safe_redirect(add_query_arg(['am_term_ok' => 0, 'am_term_msg' => rawurlencode($created->get_error_message())], $redirect));
			exit;
		}

		wp_safe_redirect(add_query_arg(['am_term_ok' => 1, 'am_term_msg' => rawurlencode(__('Termine creato.', 'archivio-micologico'))], $redirect));
		exit;
	}

	private static function maybe_enable_frontend_debug(): void {
		if (!is_user_logged_in()) { return; }
		if (!current_user_can('manage_options')) { return; }
		if (empty($_GET['am_debug'])) { return; }

		// Show PHP errors on screen (for this request only).
		error_reporting(E_ALL);
		@ini_set('display_errors', '1');
		@ini_set('log_errors', '0');

		// Catch fatal errors at shutdown and print them in-page.
		register_shutdown_function(function () {
			$e = error_get_last();
			if (!$e) { return; }
			$fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
			if (!in_array($e['type'], $fatal_types, true)) { return; }

			headers_sent() || header('Content-Type: text/html; charset=utf-8');
			echo '<div style="padding:16px;margin:16px;border:2px solid #c00;background:#fff">';
			echo '<h2 style="margin:0 0 10px 0">AM DEBUG (fatal)</h2>';
			echo '<pre style="white-space:pre-wrap;margin:0">';
			echo esc_html(print_r($e, true));
			echo '</pre>';
			echo '</div>';
		});
	}

	public static function process_front_post(): void {




		// AM_FALLBACK_FRONT_UPDATE: handle edit-form self-post (some hosts block admin-post or require re-login).
		if ( isset($_POST['am_route']) && $_POST['am_route'] === 'front_update' ) {
			// Log hit
			$log = get_option('am_debug_log', []);
			if (!is_array($log)) { $log = []; }
			$user = wp_get_current_user();
			$entry = [
				'time' => time(),
				'tag'  => 'FRONT_UPDATE_HIT',
				'user' => ($user && $user->exists()) ? ($user->user_login . ' (' . $user->ID . ')') : 'guest',
				'data' => [
					'via' => 'self_post',
					'method' => $_SERVER['REQUEST_METHOD'] ?? '',
					'post_id' => isset($_POST['am_post_id']) ? absint($_POST['am_post_id']) : 0,
					'uri' => $_SERVER['REQUEST_URI'] ?? '',
					'has_nonce' => isset($_POST[self::NONCE_NAME]) ? 1 : 0,
				],
			];
			array_unshift($log, $entry);
			$log = array_slice($log, 0, 80);
			update_option('am_debug_log', $log, false);

			// Ensure flags for update handler
			$_POST['am_front_update'] = $_POST['am_front_update'] ?? '1';
			$_POST['am_action'] = $_POST['am_action'] ?? 'update';

			self::handle_update();
			return;
		}
if ( isset($_POST['action']) && $_POST['action'] === 'am_front_update' ) { return; }
		if ( !empty($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'admin-post.php') !== false ) { return; }
// AM_DIAG: log action seen on front posts
		if (!empty($_POST)) {
			$act = isset($_POST['action']) ? (string) $_POST['action'] : '';
			if (!empty($act) && in_array($act, ['am_front_update','am_update_fungo'], true)) {
			self::am_debug_log_append('FRONT_POST_ACTION_SEEN', ['action' => $act, 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
		}
		}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }

		// Quick add taxonomy term
		if (isset($_POST['am_add_term_post'])) {
			self::handle_add_term_post();
			return;
		}

		// Frontend submit fallback (self-post)
		if (isset($_POST['am_front_submit'])) {
			self::handle_submit();
			return;
		}

		if (isset($_POST['am_front_update'])) {
			self::handle_update();
			return;
		}

		if (isset($_POST['am_front_delete'])) {
			self::handle_front_delete();
			return;
		}
}

	private static function set_submit_result(bool $ok, string $code, string $message): void {
		self::am_set_last_submit(['ok' => $ok ? 1 : 0, 'code' => $code, 'msg' => $message, 'ts' => time()]);
	}

	private static function am_set_last_submit(array $data): void {
		if (!is_user_logged_in()) { return; }
		update_user_meta(get_current_user_id(), 'am_last_submit', $data);
	}

	public static function handle_front_delete(): void {
		self::handle_delete();
	}

	public static function handle_delete(): void {
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			wp_die(esc_html__('Metodo non consentito.', 'archivio-micologico'), '', ['response' => 405]);
		}
		if (!am_user_is_admin_or_editor()) {
			wp_die(esc_html__('Non autorizzato.', 'archivio-micologico'), '', ['response' => 403]);
		}
		$post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
		$post = $post_id ? get_post($post_id) : null;
		if (!$post || $post->post_type !== AM_CPT) {
			wp_die(esc_html__('Scheda non valida.', 'archivio-micologico'), '', ['response' => 400]);
		}
		if (!current_user_can('delete_post', $post_id)) {
			wp_die(esc_html__('Permessi insufficienti.', 'archivio-micologico'), '', ['response' => 403]);
		}
		$nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
		$valid = $nonce && wp_verify_nonce($nonce, 'am_delete_fungo_' . $post_id);
		// Compatibility with the old admin form; object permission remains mandatory.
		if (!$valid && isset($_POST['am_delete_nonce'])) {
			$valid = wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['am_delete_nonce'])), 'am_delete_fungo');
		}
		if (!$valid) {
			wp_die(esc_html__('Sessione scaduta. Ricarica la pagina e riprova.', 'archivio-micologico'), '', ['response' => 403]);
		}
		// Never force-delete: a zero-day WordPress trash setting must not turn this into a permanent deletion.
		if (defined('EMPTY_TRASH_DAYS') && (int) EMPTY_TRASH_DAYS === 0) {
			wp_die(esc_html__('Il cestino WordPress è disattivato. Nessuna scheda è stata eliminata.', 'archivio-micologico'), '', ['response' => 409]);
		}
		if (!wp_trash_post($post_id)) {
			wp_die(esc_html__('Impossibile spostare la scheda nel cestino.', 'archivio-micologico'), '', ['response' => 400]);
		}
		$redirect = isset($_POST['redirect_to']) ? esc_url_raw(wp_unslash($_POST['redirect_to'])) : '';
		if (!$redirect) { $redirect = admin_url('edit.php?post_type=' . AM_CPT); }
		wp_safe_redirect(add_query_arg('am_deleted', '1', $redirect));
		exit;
	}

	public static function handle_admin_post_update(): void {
		$redirect = wp_get_referer() ?: home_url('/');

		// Log hit (robust, same store used by settings page)
		$log = get_option('am_debug_log', []);
		if (!is_array($log)) { $log = []; }
		$user = wp_get_current_user();
		$entry = [
			'time' => time(),
			'tag'  => 'FRONT_UPDATE_HIT',
			'user' => ($user && $user->exists()) ? ($user->user_login . ' (' . $user->ID . ')') : 'guest',
			'data' => [
				'method' => $_SERVER['REQUEST_METHOD'] ?? '',
				'post_id' => isset($_POST['am_post_id']) ? absint($_POST['am_post_id']) : 0,
				'action' => isset($_POST['action']) ? sanitize_text_field(wp_unslash($_POST['action'])) : '',
				'uri' => $_SERVER['REQUEST_URI'] ?? '',
				'has_nonce' => isset($_POST[self::NONCE_NAME]) ? 1 : 0,
			],
		];
		array_unshift($log, $entry);
		$log = array_slice($log, 0, 80);
		update_option('am_debug_log', $log, false);

		if (!is_user_logged_in()) {
			wp_safe_redirect( wp_login_url( $redirect ) );
			exit;
		}

		// Delegate to the existing update handler which validates nonce, required fields, and saves.
		self::handle_update();
		// handle_update should redirect+exit; fallback:
		wp_safe_redirect($redirect);
		exit;
	}

	private static function resolve_classification_from_post_1_5_34(int $species_id = 0): array {
		$posted_division = isset($_POST['am_division']) ? absint(wp_unslash($_POST['am_division'])) : 0;
		$posted_family   = isset($_POST['am_family']) ? absint(wp_unslash($_POST['am_family'])) : 0;
		$posted_genus    = isset($_POST['am_genus']) ? absint(wp_unslash($_POST['am_genus'])) : 0;
		$posted_species  = $species_id ?: (isset($_POST['am_species']) ? absint(wp_unslash($_POST['am_species'])) : 0);

		$division = $posted_division;
		$family   = $posted_family;
		$genus    = $posted_genus;
		$species  = $posted_species;

		// If species is selected, the taxonomy hierarchy is the source of truth.
		// This prevents false "missing classification" errors when parent selects are visually selected
		// but not posted correctly by the browser/JS state.
		if ($species) {
			$species_term = get_term($species, AM_TAX_CLASSIFICAZIONE);
			if ($species_term && !is_wp_error($species_term) && !empty($species_term->parent)) {
				$genus = (int) $species_term->parent;
			}
		}

		if ($genus) {
			$genus_term = get_term($genus, AM_TAX_CLASSIFICAZIONE);
			if ($genus_term && !is_wp_error($genus_term) && !empty($genus_term->parent)) {
				$family = (int) $genus_term->parent;
			}
		}

		if ($family) {
			$family_term = get_term($family, AM_TAX_CLASSIFICAZIONE);
			if ($family_term && !is_wp_error($family_term) && !empty($family_term->parent)) {
				$division = (int) $family_term->parent;
			}
		}

		if ((int) get_option('am_enable_debug_panel', 0) === 1 && function_exists('am_debug_funghi_force_output_event')) {
			am_debug_funghi_force_output_event('AM_CLASSIFICATION_RESOLVED_1_5_34', [
				'posted' => [
					'division' => $posted_division,
					'family' => $posted_family,
					'genus' => $posted_genus,
					'species' => $posted_species,
				],
				'resolved' => [
					'division' => $division,
					'family' => $family,
					'genus' => $genus,
					'species' => $species,
				],
			]);
		}

		return [
			'division' => $division,
			'family'   => $family,
			'genus'    => $genus,
			'species'  => $species,
		];
	}
}
