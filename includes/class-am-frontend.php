<?php
if (!defined('ABSPATH')) { exit; }

class AM_Frontend {
	/**
	 * Format scientific name: bold+italic for Genus species, rest normal.
	 */
		/**
	 * Format scientific name: Genus + species in bold+italic, rest normal.
	 * Uses allowed HTML (span + i) so sanitizers won't strip it.
	 */
	private static function format_species_title($full_name) {
		$full_name = trim((string) $full_name);
		if ($full_name === '') { return ''; }
		$parts = preg_split('/\\s+/', $full_name, 3);
		if (!$parts || count($parts) < 2) { return esc_html($full_name); }
		$genus_species = esc_html($parts[0] . ' ' . $parts[1]);
		$rest = isset($parts[2]) ? trim((string) $parts[2]) : '';
		$out = '<span class="am-taxon-gs" style="font-weight:700"><i>' . $genus_species . '</i></span>';
		if ($rest !== '') { $out .= ' ' . esc_html($rest); }
		return $out;
	}

	/**
	 * Filter post titles on single fungus pages (covers Elementor title widget).
	 * Returns safe HTML (span+i) for Genus + species, rest plain.
	 */
	public static function filter_the_title($title, $post_id) {
		if (is_admin()) { return $title; }
		if (empty($post_id)) { return $title; }
		// Only affect the custom post type single page.
		if (!is_singular(AM_CPT)) { return $title; }
		if (get_post_type($post_id) !== AM_CPT) { return $title; }
		// Prevent recursion or double-formatting.
		if (strpos($title, 'am-taxon-gs') !== false) { return $title; }
		return self::format_species_title($title);
	}




	// Sticky form storage (avoid losing input on validation errors)
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

	/**
	 * Collect posted fields for sticky restore (safe on missing keys).
	 */
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


	

	


private static function am_fatal_capture(string $tag, array $ctx = []): void {
		register_shutdown_function(function() use ($tag, $ctx) {
			$err = error_get_last();
			if (!$err) { return; }
			// Only store fatal-ish errors
			if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) { return; }
			$log = get_option('am_debug_log', []);
			if (!is_array($log)) { $log = []; }
			$user = wp_get_current_user();
			$entry = [
				'time' => time(),
				'tag'  => 'FATAL_' . $tag,
				'user' => ($user && $user->exists()) ? ($user->user_login . ' (' . $user->ID . ')') : 'guest',
				'data' => [
					'ctx' => $ctx,
					'error' => [
						'type' => $err['type'],
						'message' => $err['message'],
						'file' => $err['file'],
						'line' => $err['line'],
					],
				],
			];
			array_unshift($log, $entry);
			$log = array_slice($log, 0, 50);
			update_option('am_debug_log', $log, false);
		});
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

private static function am_current_user_is_admin_or_editor(): bool {
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
const NONCE_ACTION = 'am_submit_fungo';
	const NONCE_ACTION_UPDATE = 'am_update_fungo';
	const NONCE_NAME = 'am_nonce';


	public static function register_widgets(): void {
		register_widget('AM_Widget_Random_Funghi');
	}

	public static function render_random_funghi_shortcode($atts = []): string {
		$default_count = max(1, min(12, (int) get_option('am_random_widget_count', 5)));
		$default_title = sanitize_text_field((string) get_option('am_random_widget_title', ''));
		$default_autoplay = (int) get_option('am_random_widget_autoplay', 1) === 1;
		$default_interval = max(2, min(20, (int) get_option('am_random_widget_interval', 4)));
		$default_visible = max(1, min(3, (int) get_option('am_random_widget_visible', 3)));

		$atts = shortcode_atts([
			'numero' => $default_count,
			'titolo' => $default_title,
			'autoplay' => $default_autoplay ? '1' : '0',
			'intervallo' => $default_interval,
			'visibili' => $default_visible,
		], $atts, 'am_random_funghi');

		$count = max(1, min(12, absint($atts['numero'])));
		$title = sanitize_text_field((string) $atts['titolo']);
		$autoplay = in_array((string) $atts['autoplay'], ['1', 'true', 'yes', 'on'], true);
		$interval = max(2, min(20, absint($atts['intervallo'])));
		$visible = max(1, min(3, absint($atts['visibili'])));

		return self::get_random_funghi_markup($count, $title, [
			'autoplay' => $autoplay,
			'interval' => $interval,
			'visible' => $visible,
		]);
	}

	public static function get_random_funghi_markup(int $count = 5, string $title = '', array $args = []): string {
		$count = max(1, min(12, $count));
		$autoplay = isset($args['autoplay']) ? (bool) $args['autoplay'] : ((int) get_option('am_random_widget_autoplay', 1) === 1);
		$interval = isset($args['interval']) ? max(2, min(20, (int) $args['interval'])) : max(2, min(20, (int) get_option('am_random_widget_interval', 4)));
		$visible = isset($args['visible']) ? max(1, min(3, (int) $args['visible'])) : max(1, min(3, (int) get_option('am_random_widget_visible', 3)));
		$query = new WP_Query([
			'post_type' => AM_CPT,
			'post_status' => 'publish',
			'posts_per_page' => $count,
			'orderby' => 'rand',
			'ignore_sticky_posts' => true,
			'no_found_rows' => true,
		]);

		$carousel_id = 'am-random-carousel-' . wp_generate_uuid4();

		ob_start();
		echo '<div class="am-random-funghi-widget am-random-funghi-carousel" data-am-carousel data-am-autoplay="' . esc_attr($autoplay ? '1' : '0') . '" data-am-interval="' . esc_attr((string) $interval) . '" data-am-visible="' . esc_attr((string) $visible) . '" id="' . esc_attr($carousel_id) . '">';
		if ($title !== '') {
			echo '<h3 class="am-random-funghi-widget__title">' . esc_html($title) . '</h3>';
		}

		if ($query->have_posts()) {
			echo '<div class="am-random-funghi-carousel__viewport">';
			echo '<div class="am-random-funghi-carousel__track" data-am-carousel-track>';
			$slide_index = 0;
			while ($query->have_posts()) {
				$query->the_post();
				$permalink = get_permalink();
				$title_html = wp_kses_post(self::format_species_title(get_the_title()));
				echo '<article class="am-random-funghi-carousel__slide am-random-funghi-widget__item" data-am-carousel-slide data-slide-index="' . esc_attr((string) $slide_index) . '">';
				echo '<a class="am-random-funghi-widget__thumblink" href="' . esc_url($permalink) . '">';
				if (has_post_thumbnail()) {
					echo get_the_post_thumbnail(get_the_ID(), 'medium_large', [
						'class' => 'am-random-funghi-widget__thumb',
						'loading' => 'lazy',
						'alt' => wp_strip_all_tags(get_the_title()),
					]);
				} else {
					echo '<span class="am-random-funghi-widget__thumb am-random-funghi-widget__thumb--empty">' . esc_html__('Nessuna immagine', 'archivio-micologico') . '</span>';
				}
				echo '</a>';
				echo '<a class="am-random-funghi-widget__link" href="' . esc_url($permalink) . '">' . $title_html . '</a>';
				echo '</article>';
				$slide_index++;
			}
			echo '</div>';
			echo '</div>';
			if ($slide_index > 1) {
				echo '<div class="am-random-funghi-carousel__controls">';
				echo '<button type="button" class="am-random-funghi-carousel__button am-random-funghi-carousel__button--prev" data-am-carousel-prev aria-label="' . esc_attr__('Scheda precedente', 'archivio-micologico') . '">&lsaquo;</button>';
				echo '<div class="am-random-funghi-carousel__dots" data-am-carousel-dots>';
				for ($i = 0; $i < $slide_index; $i++) {
					echo '<button type="button" class="am-random-funghi-carousel__dot' . ($i === 0 ? ' is-active' : '') . '" data-am-carousel-dot="' . esc_attr((string) $i) . '" aria-label="' . esc_attr(sprintf(__('Vai alla posizione %d', 'archivio-micologico'), $i + 1)) . '"></button>';
				}
				echo '</div>';
				echo '<button type="button" class="am-random-funghi-carousel__button am-random-funghi-carousel__button--next" data-am-carousel-next aria-label="' . esc_attr__('Scheda successiva', 'archivio-micologico') . '">&rsaquo;</button>';
				echo '</div>';
			}
			wp_reset_postdata();
		} else {
			echo '<p class="am-random-funghi-widget__empty">' . esc_html__('Nessuna scheda disponibile.', 'archivio-micologico') . '</p>';
		}
		echo '</div>';

		return (string) ob_get_clean();
	}

	public static function init(): void {
		// Infinite scroll search page
		add_action('wp_ajax_am_search_more', [__CLASS__, 'ajax_search_more']);
		add_action('wp_ajax_nopriv_am_search_more', [__CLASS__, 'ajax_search_more']);

		// Archive tree ([am_archive])
		add_action('wp_ajax_am_archive_tree', [__CLASS__, 'ajax_archive_tree']);
		add_action('wp_ajax_nopriv_am_archive_tree', [__CLASS__, 'ajax_archive_tree']);
		add_action('wp_ajax_am_archive_search', [__CLASS__, 'ajax_archive_search']);
		add_action('wp_ajax_nopriv_am_archive_search', [__CLASS__, 'ajax_archive_search']);

		// Front-end forms
		add_action('admin_post_am_front_update', [__CLASS__, 'handle_admin_post_update']);
		add_action('admin_post_nopriv_am_front_update', [__CLASS__, 'handle_admin_post_update']);
		add_action('init', [__CLASS__, 'process_front_post']);

		// Shortcodes (Archivio Micologico)
		// Format title (Genus species bold+italic) also for Elementor/theme output
		add_filter('the_title', [__CLASS__, 'filter_the_title'], 10, 2);

		add_shortcode('am_submit_form', [__CLASS__, 'render_submit_form']);
		add_shortcode('am_article_form', [__CLASS__, 'render_article_form']);
		add_shortcode('am_edit_form', [__CLASS__, 'render_edit_form']);
		add_shortcode('am_archive_filters', [__CLASS__, 'render_archive_filters']);
		add_shortcode('am_archive', [__CLASS__, 'render_archive']);
		add_shortcode('am_search_page', [__CLASS__, 'render_search_page']);
		add_shortcode('am_random_funghi', [__CLASS__, 'render_random_funghi_shortcode']);
		// SEO & social meta — three separate concerns at different priorities.
		// Priority 0: Open Graph + Twitter Card AM, indipendenti dalla modalità SEO.
		add_action('wp_head', [__CLASS__, 'render_social_preview_meta'], 0);
		add_action('template_redirect', [__CLASS__, 'start_social_preview_head_buffer_1_5_40'], 0);
		// Priority 6: canonical + meta description + keywords + JSON-LD for single schede.
		add_action('wp_head', [__CLASS__, 'render_seo_base_meta'], 6);
		// Priority 6: canonical + meta description + JSON-LD for pages with AM shortcodes.
		add_action('wp_head', [__CLASS__, 'render_shortcode_pages_seo_meta'], 6);

		add_action('widgets_init', [__CLASS__, 'register_widgets']);

		add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
		add_filter('wp_handle_upload_prefilter', [__CLASS__, 'filter_am_image_upload']);
		add_filter('the_content', [__CLASS__, 'filter_single_content']);

		add_action('wp_ajax_am_get_terms', [__CLASS__, 'ajax_get_terms']);
		add_action('wp_ajax_nopriv_am_get_terms', [__CLASS__, 'ajax_get_terms']);

		
		// Save "ultima revisione" (admin only)
		add_action('wp_ajax_am_save_review', [__CLASS__, 'ajax_save_review']);

// Controlled creation of classification terms from front-end (Editor+).
		add_action('wp_ajax_am_add_term', [__CLASS__, 'ajax_add_term']);
		add_action('init', [__CLASS__, 'handle_add_term_post']);

		add_action('admin_post_am_submit_fungo', [__CLASS__, 'handle_submit']);
		add_action('admin_post_am_submit_article', [__CLASS__, 'handle_submit_article']);
		add_action('admin_post_nopriv_am_submit_article', [__CLASS__, 'handle_submit_article']);
		add_action('admin_post_nopriv_am_submit_fungo', [__CLASS__, 'handle_submit']);

		add_action('admin_post_am_update_fungo', [__CLASS__, 'handle_update']);
		add_action('admin_post_nopriv_am_update_fungo', [__CLASS__, 'handle_update']);

		add_action('admin_post_am_delete_fungo', [__CLASS__, 'handle_delete']);
		add_action('admin_post_nopriv_am_delete_fungo', [__CLASS__, 'handle_delete']);
	}

	/**
	 * AJAX: salva ultima revisione (Amministratore/Editore autorizzato sulla scheda).
	 */
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

	public static function enqueue_assets(): void {
		$should_load = false;
		$needs_editor_media = false;

		// Load assets on single "Scheda Fungo" to support gallery lightbox.
		if (is_singular(AM_CPT)) {
			$should_load = true;
		}

		// Load assets on pages where plugin shortcodes are present.
		global $post;
		if ($post && (
			strpos((string) $post->post_content, '[am_submit_form') !== false ||
			strpos((string) $post->post_content, '[am_article_form') !== false ||
			strpos((string) $post->post_content, '[am_edit_form') !== false ||
			strpos((string) $post->post_content, '[am_archive_filters') !== false ||
			strpos((string) $post->post_content, '[am_archive') !== false ||
			strpos((string) $post->post_content, '[am_search_page') !== false
		)) {
			 $should_load = true;
			$needs_editor_media = (
				strpos((string) $post->post_content, "[am_submit_form") !== false ||
				strpos((string) $post->post_content, "[am_article_form") !== false ||
				strpos((string) $post->post_content, "[am_edit_form") !== false
			);
		}

		if (!$should_load) {
			return;
		}

		wp_enqueue_style('am-frontend', AM_URL . 'assets/css/frontend.css', (defined('ELEMENTOR_VERSION') ? ['elementor-frontend'] : []), AM_VERSION . '.' . @filemtime(AM_PATH . 'assets/css/frontend.css'));
		
		// JS: format title (Genere+specie in corsivo+grassetto) anche con Elementor.
		wp_enqueue_script('am-title-format', AM_URL . 'assets/js/title-format.js', array(), AM_VERSION, true);
wp_add_inline_style('am-frontend', '.am-gallery--journal{width:100%!important;display:flex!important;flex-direction:column!important;gap:10px!important}.am-gallery--journal .am-entry-gallery__figure{width:100%!important;margin:0!important;padding:0 0 10px!important;border-bottom:1px solid rgba(0,0,0,.08)!important;text-align:left!important}.am-gallery--journal .am-entry-gallery__figure:last-child{border-bottom:0!important;padding-bottom:0!important}.am-gallery--journal img{width:100%!important;max-width:100%!important;height:auto!important;display:block!important;margin:0!important;border-radius:12px!important;box-shadow:0 1px 10px rgba(0,0,0,.08)!important}.am-gallery--journal figure.am-entry-gallery__figure figcaption.am-entry-caption{display:block!important;width:100%!important;text-align:left!important;margin:6px 0 0!important;font-size:16px!important;line-height:1.4!important;color:rgba(0,0,0,.65)!important}.am-gallery--journal .am-entry-caption p{margin:0!important;padding:0!important}.am-entry-gallery.am-gallery--journal figure.am-entry-gallery__figure{text-align:left!important}.am-entry-gallery.am-gallery--journal figure.am-entry-gallery__figure>figcaption.am-entry-caption{display:block!important;width:100%!important;text-align:left!important;direction:ltr!important}.am-entry-gallery figure.am-entry-gallery__figure{text-align:left!important}.am-entry-gallery figure.am-entry-gallery__figure figcaption.am-entry-caption{display:block!important;width:100%!important;text-align:left!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}.am-entry-gallery .am-entry-caption{display:block!important;width:100%!important;text-align:left!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}.am-entry-gallery figure.am-entry-gallery__figure{display:block!important;text-align:left!important}.am-entry-gallery figure.am-entry-gallery__figure>figcaption.am-entry-caption{display:block!important;width:100%!important;text-align:left!important;margin-left:0!important;margin-right:auto!important;align-self:flex-start!important;justify-self:start!important;float:none!important;white-space:nowrap!important}.am-entry-gallery .am-entry-caption{white-space:normal!important;overflow:visible!important;text-overflow:clip!important}.am-entry-gallery img,.am-entry-featured img,.am-entry-preview img{height:auto!important;max-height:none!important;width:100%!important;max-width:100%!important;object-fit:contain!important;object-position:center center!important}.am-entry-gallery__item,.am-entry-gallery__figure,.am-entry-featured,.am-entry-preview,.am-entry-featured figure,.am-entry-preview figure{height:auto!important;max-height:none!important;min-height:0!important;background:transparent!important;padding:0!important;margin:0!important;line-height:0!important}.am-entry-gallery img,.am-entry-featured img,.am-entry-preview img{width:auto!important;max-width:100%!important;height:auto!important;max-height:none!important;aspect-ratio:auto!important;object-fit:contain!important;object-position:center center!important;display:block!important;margin:0!important}.am-entry-gallery .am-gallery-image,.am-entry-gallery__item .am-gallery-image{width:auto!important;max-width:100%!important;height:auto!important;min-height:0!important;max-height:none!important;aspect-ratio:auto!important;object-fit:contain!important;object-position:center center!important;display:block!important}.am-entry-gallery__item,.am-entry-gallery__figure{overflow:hidden!important}.am-entry-title .am-taxon-gs{font-weight:700!important}.am-entry-title .am-taxon-gs i{font-style:italic!important;font-weight:700!important}');
wp_enqueue_script('am-frontend', AM_URL . 'assets/js/frontend.js', (defined('ELEMENTOR_VERSION') ? ['jquery','elementor-frontend'] : ['jquery']), AM_VERSION . '.' . @filemtime(AM_PATH . 'assets/js/frontend.js'), true);

		// Archive tree assets (only when [am_archive] is on the page)
		if ($post && (
			strpos((string) $post->post_content, '[am_archive') !== false
		)) {
			wp_enqueue_style('am-archive-tree', AM_URL . 'assets/css/am-archive-tree.css', [], AM_VERSION);
			wp_enqueue_script('am-archive-tree', AM_URL . 'assets/js/am-archive-tree.js', [], AM_VERSION, true);
			wp_localize_script('am-archive-tree', 'AMArchive', [
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce('am_ajax'),
			]);
		}

		// Search-page live preview assets (only when [am_search_page] is on the page)
		if ($post && (
			strpos((string) $post->post_content, '[am_search_page') !== false
		)) {
			wp_enqueue_style('am-search-preview', AM_URL . 'assets/css/am-search-preview.css', [], AM_VERSION);
			wp_enqueue_script('am-search-preview', AM_URL . 'assets/js/am-search-preview.js', [], AM_VERSION, true);
			wp_localize_script('am-search-preview', 'AMSearchPreview', [
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce'   => wp_create_nonce('am_ajax'),
			]);
		}

		if ($needs_editor_media && am_user_is_admin_or_editor() && current_user_can('upload_files')) {
			add_filter('plupload_default_params', [__CLASS__, 'am_upload_params']);
			// Front-end editor support.
			wp_enqueue_editor();
			// Media library uploader (required for wp.media on front-end).
			wp_enqueue_media();
			// Ensure Thickbox assets are available for some WP setups.
			wp_enqueue_script('thickbox');
			wp_enqueue_style('thickbox');
		}

		wp_localize_script('am-frontend', 'AMFront', [
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('am_ajax'),
			'currentUser' => (is_user_logged_in() ? wp_get_current_user()->display_name : ''),
			'canReview' => am_user_can_review(),

			'taxClass' => AM_TAX_CLASSIFICAZIONE,
			'taxInfra' => AM_TAX_INFRASPEC,
			'taxAuthor' => AM_TAX_AUTHOR,
			'canAddTerms' => self::am_current_user_is_admin_or_editor(),
		]);
	}

	public static function am_upload_params(array $params): array {
		$params['am_upload_nonce'] = wp_create_nonce('am_upload_image');
		return $params;
	}

	public static function filter_am_image_upload(array $file): array {
		if (empty($_POST['am_upload_nonce']) || !empty($file['error'])) { return $file; }
		if (!am_user_is_admin_or_editor() || !current_user_can('upload_files') || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['am_upload_nonce'])), 'am_upload_image')) {
			$file['error'] = __('Non autorizzato.', 'archivio-micologico');
			return $file;
		}
		$type = wp_check_filetype((string) ($file['name'] ?? ''));
		$max_mb = max(1, min(50, (int) get_option('am_max_upload_mb', 5)));
		if (strpos((string) ($type['type'] ?? ''), 'image/') === 0 && (int) ($file['size'] ?? 0) > $max_mb * MB_IN_BYTES) {
			$file['error'] = sprintf(__('L’immagine supera il limite AM di %d MB.', 'archivio-micologico'), $max_mb);
		}
		return $file;
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

		
		// Prevent duplicates across different parents (common source of "same genus in different families").
		$global_exists = term_exists($name, $tax);
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

include AM_PATH . 'templates/edit-form.php';
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode: [am_archive_filters]
	 * Renders the cascading filters (Famiglia > Genere > Specie) as a single horizontal row.
	 * Use it together with [am_archive] on the same page.
	 */
	public static function render_archive_filters($atts = []): string {
		$atts = shortcode_atts([
			// Optional: override the form action (URL). Default: current page permalink.
			'action' => '',
			'button' => __('Filtra', 'archivio-micologico'),
		], (array) $atts, 'am_archive_filters');

		$base_url = (string) $atts['action'];
		if (!$base_url) {
			$base_url = get_permalink();
			if (!$base_url) { $base_url = home_url('/'); }
		}

		$division = isset($_GET['am_division']) ? absint($_GET['am_division']) : 0;
		$family = isset($_GET['am_family']) ? absint($_GET['am_family']) : 0;
		$genus  = isset($_GET['am_genus']) ? absint($_GET['am_genus']) : 0;
		$species = isset($_GET['am_species']) ? absint($_GET['am_species']) : 0;

		// AM 1.5.51: normalizza la catena gerarchica anche quando l'URL contiene
		// solo un livello profondo (es. ?am_genus=123 dai breadcrumb/ritorno risultati).
		$chain = self::normalize_archive_filter_chain_1_5_51($division, $family, $genus, $species);
		$division = $chain['division'];
		$family   = $chain['family'];
		$genus    = $chain['genus'];
		$species  = $chain['species'];

		ob_start();
		echo '<div class="am-archive-filters">';
		echo '<form class="am-archive-filters__form" method="get" action="' . esc_url($base_url) . '">';
		echo '<div class="am-archive-filters__row">';

		echo '<div class="am-field am-archive-filters__field"><label for="am_filter_division">' . esc_html__('Divisione', 'archivio-micologico') . '</label>';
		echo '<select id="am_filter_division" name="am_division" disabled data-selected="' . esc_attr((string) $division) . '">';
		echo '<option value="">' . esc_html__('Tutte', 'archivio-micologico') . '</option>';
		echo '</select></div>';

		echo '<div class="am-field am-archive-filters__field"><label for="am_filter_family">' . esc_html__('Famiglia', 'archivio-micologico') . '</label>';
		echo '<select id="am_filter_family" name="am_family" disabled data-selected="' . esc_attr((string) $family) . '">';
		echo '<option value="">' . esc_html__('Tutte', 'archivio-micologico') . '</option>';
		echo '</select></div>';

		echo '<div class="am-field am-archive-filters__field"><label for="am_filter_genus">' . esc_html__('Genere', 'archivio-micologico') . '</label>';
		echo '<select id="am_filter_genus" name="am_genus" disabled data-selected="' . esc_attr((string) $genus) . '">';
		echo '<option value="">' . esc_html__('Tutti', 'archivio-micologico') . '</option>';
		echo '</select></div>';

		echo '<div class="am-field am-archive-filters__field"><label for="am_filter_species">' . esc_html__('Specie', 'archivio-micologico') . '</label>';
		echo '<select id="am_filter_species" name="am_species" disabled data-selected="' . esc_attr((string) $species) . '">';
		echo '<option value="">' . esc_html__('Tutte', 'archivio-micologico') . '</option>';
		echo '</select></div>';

		echo '<div class="am-archive-filters__actions">';
		echo '<button class="am-submit" type="submit">' . esc_html((string) $atts['button']) . '</button>';
		echo '</div>';

		echo '</div>';
		echo '</form>';
		echo '</div>';

		// AM 1.5.51: se la pagina usa solo [am_archive_filters] senza [am_archive],
		// lo shortcode diventa comunque utile e mostra i risultati filtrati sotto al form.
		global $post;
		$has_archive_shortcode = ($post instanceof \WP_Post) && has_shortcode((string) $post->post_content, 'am_archive');
		if (!$has_archive_shortcode) {
			echo self::render_archive_filtered_results_1_5_51(30);
		}

		return (string) ob_get_clean();
	}



public static function render_archive($atts = []): string {
		// AM Archive Tree (Divisione -> Famiglia -> Genere -> Schede) with infinite scroll, no table.
		$atts = shortcode_atts([
			'families_per_page' => 30,
			'entries_per_page'  => 30,
		], (array) $atts, 'am_archive');

		ob_start();

		// AM 1.5.51: se arrivano parametri da [am_archive_filters], l'archivio deve
		// mostrare risultati filtrati invece del solo albero completo.
		$filtered_html = self::render_archive_filtered_results_1_5_51((int) $atts['entries_per_page']);
		if ($filtered_html !== '') {
			echo $filtered_html;
			return (string) ob_get_clean();
		}

		echo '<div class="am-archive" id="am-archive" data-per-page-families="' . esc_attr((string) (int) $atts['families_per_page']) . '" data-per-page-entries="' . esc_attr((string) (int) $atts['entries_per_page']) . '">';
		echo '<div class="am-archive__top">';
		$total = 0;
		if (defined('AM_CPT')) {
			$cnt = wp_count_posts(AM_CPT);
			if ($cnt && isset($cnt->publish)) {
				$total = (int) $cnt->publish;
			}
		}
		$ph = __('Cerca per nome, descrizione, contenuti…', 'archivio-micologico');
		echo '<input type="search" class="am-archive__search" placeholder="' . esc_attr($ph) . '" />';
		echo '<button type="button" class="am-archive__search-btn">' . esc_html(__('Cerca', 'archivio-micologico')) . '</button>';
		echo '<div class="am-archive__status" aria-live="polite"></div>';
		echo '</div>';
		// Search previews must appear directly under the search field.
		echo '<div class="am-archive__results" hidden></div>';
		echo '<div class="am-archive__articles" role="region" aria-label="' . esc_attr__('Articoli', 'archivio-micologico') . '"></div>';
		echo '<div class="am-archive__tree" role="tree"></div>';
		echo '<div class="am-archive__species-summary" role="status" aria-label="' . esc_attr__('Specie presenti nell’archivio', 'archivio-micologico') . '">';
		echo '<span class="am-archive__species-summary-icon" aria-hidden="true">🍄</span>';
		echo '<span class="am-archive__species-summary-label">' . esc_html__('Specie presenti nell’archivio', 'archivio-micologico') . '</span>';
		echo '<span class="am-archive__species-summary-count">' . esc_html((string) $total) . '</span>';
		echo '</div>';
		echo '<div class="am-archive__sentinel" aria-hidden="true"></div>';
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * AM 1.5.51 - Completa e corregge la catena Divisione > Famiglia > Genere > Specie.
	 */
	private static function normalize_archive_filter_chain_1_5_51(int $division = 0, int $family = 0, int $genus = 0, int $species = 0): array {
		$taxonomy = defined('AM_TAX_CLASSIFICAZIONE') ? AM_TAX_CLASSIFICAZIONE : '';
		if ($taxonomy === '') {
			return ['division' => $division, 'family' => $family, 'genus' => $genus, 'species' => $species];
		}

		if ($species > 0) {
			$term = get_term($species, $taxonomy);
			if ($term && !is_wp_error($term)) {
				$genus = $genus ?: (int) $term->parent;
			}
		}
		if ($genus > 0) {
			$term = get_term($genus, $taxonomy);
			if ($term && !is_wp_error($term)) {
				$family = $family ?: (int) $term->parent;
			}
		}
		if ($family > 0) {
			$term = get_term($family, $taxonomy);
			if ($term && !is_wp_error($term)) {
				$division = $division ?: (int) $term->parent;
			}
		}

		return [
			'division' => max(0, $division),
			'family'   => max(0, $family),
			'genus'    => max(0, $genus),
			'species'  => max(0, $species),
		];
	}

	/**
	 * AM 1.5.51 - Risultati filtrati da [am_archive_filters].
	 */
	private static function render_archive_filtered_results_1_5_51(int $per_page = 30): string {
		$division = isset($_GET['am_division']) ? absint(wp_unslash($_GET['am_division'])) : 0;
		$family   = isset($_GET['am_family']) ? absint(wp_unslash($_GET['am_family'])) : 0;
		$genus    = isset($_GET['am_genus']) ? absint(wp_unslash($_GET['am_genus'])) : 0;
		$species  = isset($_GET['am_species']) ? absint(wp_unslash($_GET['am_species'])) : 0;

		$chain = self::normalize_archive_filter_chain_1_5_51($division, $family, $genus, $species);
		$division = $chain['division'];
		$family   = $chain['family'];
		$genus    = $chain['genus'];
		$species  = $chain['species'];

		$selected_term = $species ?: ($genus ?: ($family ?: $division));
		if (!$selected_term || !defined('AM_CPT') || !defined('AM_TAX_CLASSIFICAZIONE')) {
			return '';
		}

		$include_children = $species ? false : true;
		$query = new \WP_Query([
			'post_type'      => AM_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => max(1, $per_page),
			'orderby'        => 'title',
			'order'          => 'ASC',
			'tax_query'      => [[
				'taxonomy'         => AM_TAX_CLASSIFICAZIONE,
				'field'            => 'term_id',
				'terms'            => [$selected_term],
				'include_children' => $include_children,
			]],
		]);

		$term = get_term($selected_term, AM_TAX_CLASSIFICAZIONE);
		$label = ($term && !is_wp_error($term)) ? (string) $term->name : '';
		$reset_url = remove_query_arg(['am_division', 'am_family', 'am_genus', 'am_species', 'am_restore']);

		ob_start();
		echo '<div class="am-archive am-archive--filtered">';
		echo '<div class="am-archive-filtered__head">';
		if ($label !== '') {
			echo '<p class="am-archive-message am-success">' . esc_html(sprintf(__('Risultati filtrati per: %s', 'archivio-micologico'), $label)) . '</p>';
		}
		echo '<a class="am-archive-filtered__reset" href="' . esc_url($reset_url) . '">' . esc_html__('Reset filtri', 'archivio-micologico') . '</a>';
		echo '</div>';

		if ($query->have_posts()) {
			echo '<div class="am-archive__results am-archive__results--filtered">';
			while ($query->have_posts()) {
				$query->the_post();
				$post_id = (int) get_the_ID();
				$thumb = get_the_post_thumbnail($post_id, 'thumbnail', ['class' => 'am-archive-card__thumbimg', 'loading' => 'lazy']);
				echo '<div class="am-archive-card">';
				if ($thumb) {
					echo '<div class="am-archive-card__thumb">' . $thumb . '</div>';
				}
				echo '<div class="am-archive-card__body">';
				echo '<h3 class="am-archive-card__title"><a href="' . esc_url(get_permalink($post_id)) . '">' . wp_kses(self::render_scientific_name_html($post_id), ['span' => ['class' => true], 'i' => []]) . '</a></h3>';
				$excerpt = get_the_excerpt($post_id);
				if ($excerpt) {
					echo '<div class="am-archive-card__excerpt">' . esc_html(wp_trim_words($excerpt, 28)) . '</div>';
				}
				echo '</div></div>';
			}
			echo '</div>';
		} else {
			echo '<p class="am-archive-message am-empty">' . esc_html__('Nessuna scheda trovata con i filtri selezionati.', 'archivio-micologico') . '</p>';
		}
		wp_reset_postdata();
		echo '</div>';
		return (string) ob_get_clean();
	}

	/**
	 * Add JOIN to postmeta for archive meta search (am_*).
	 */

	/**
	 * Add WHERE clause for archive meta search (am_* meta_value).
	 */

	/**
	 * Ensure DISTINCT to avoid duplicates due to JOIN.
	 */

	/**
	 * Add JOINs for taxonomy term name search (Famiglia/Genere/...).
	 */

	/**
	 * Add WHERE for taxonomy term name search (OR bfr_t.name LIKE ...).
	 */

	/**
	 * Ensure DISTINCT for taxonomy join search.
	 */


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

		$redirect = wp_get_referer() ?: home_url('/');

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
		$content = self::sanitize_editor_content(wp_unslash($_POST['am_content'] ?? ''));
		$macro_plain = trim(wp_strip_all_tags($content));
		if (mb_strlen($macro_plain) < 20) {
			self::am_set_last_submit(['ok'=>0,'msg'=>'Errore submit: macro_too_short','ts'=>time()]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error' => 'macro_too_short','am_token'=>$am_token], $redirect));
			exit;
		}

		$macro_micro = self::sanitize_editor_content(wp_unslash($_POST['am_macro_micro'] ?? ''));

		$notes = self::sanitize_editor_content(wp_unslash($_POST['am_notes'] ?? ''));
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
		$content = self::sanitize_editor_content(wp_unslash($_POST['am_content'] ?? ''));
		$macro_plain = trim(wp_strip_all_tags($content));
		if (mb_strlen($macro_plain) < 20) {
			self::am_debug_reason('UPDATE_FAILED_REASON', ['reason'=>'macro_too_short','post_id'=>$post_id]);
			self::am_save_sticky($am_token, self::am_collect_sticky_post());
			wp_safe_redirect(add_query_arg(['am_error'=>'macro_too_short','am_token'=>$am_token], $redirect));
			exit;
		}
		$macro_micro = self::sanitize_editor_content(wp_unslash($_POST['am_macro_micro'] ?? ''));

		$notes = self::sanitize_editor_content(wp_unslash($_POST['am_notes'] ?? ''));
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


	private static function get_edit_link_html(int $post_id): string {
		$edit_page_id = (int) get_option('am_edit_page_id', 0);
		if (!$edit_page_id) {
			return '';
		}
		if (!is_user_logged_in()) {
			return '';
		}
		if (!current_user_can('edit_post', $post_id)) {
			return '';
		}
		$url = get_permalink($edit_page_id);
		if (!$url) {
			return '';
		}
		$url = add_query_arg('am_edit', $post_id, $url);

		return '<div class="am-entry-actions">' .
			'<a class="am-edit-link__btn" href="' . esc_url($url) . '">' . esc_html__('Modifica scheda', 'archivio-micologico') . '</a>' .
		'</div>';
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

private static function sanitize_editor_content($html): string {
		$html = is_string($html) ? $html : '';
		$allowed = wp_kses_allowed_html('post');
		// Remove code-related tags to discourage code insertion.
		unset($allowed['code'], $allowed['pre'], $allowed['kbd'], $allowed['samp']);
		return (string) wp_kses($html, $allowed);
	}

	/**
	 * Display title for Scheda Fungo:
	 * Genere + Specie + (Forma/Varietà) + (Autore)
	 */

private static function compute_scientific_name_parts(int $post_id): array {
	// Base fields
	$species = trim((string) get_post_meta($post_id, 'am_species', true));
	$infra   = trim((string) get_post_meta($post_id, 'am_infra', true));
	$author  = trim((string) get_post_meta($post_id, 'am_author', true));

	$genus_name = '';
	// Prefer stored taxonomy IDs if present.
	$genus_id = absint(get_post_meta($post_id, 'am_tax_genus_id', true));
	$species_id = absint(get_post_meta($post_id, 'am_tax_species_id', true));
	if ($genus_id) {
		$tg = get_term($genus_id, AM_TAX_CLASSIFICAZIONE);
		if ($tg && !is_wp_error($tg)) { $genus_name = (string) $tg->name; }
	}
	// If species meta is empty, try taxonomy term name.
	if ($species === '' && $species_id) {
		$ts = get_term($species_id, AM_TAX_CLASSIFICAZIONE);
		if ($ts && !is_wp_error($ts)) { $species = (string) $ts->name; }
	}
	// If genus still unknown, derive from species parent when possible.
	if ($genus_name === '' && $species_id) {
		$ts = get_term($species_id, AM_TAX_CLASSIFICAZIONE);
		if ($ts && !is_wp_error($ts) && (int) $ts->parent > 0) {
			$tg = get_term((int) $ts->parent, AM_TAX_CLASSIFICAZIONE);
			if ($tg && !is_wp_error($tg)) { $genus_name = (string) $tg->name; }
		}
	}

	// Last resort: inspect assigned terms (deepest) to derive genus/species.
	if (($genus_name === '' || $species === '') && function_exists('wp_get_object_terms')) {
		$terms = wp_get_object_terms($post_id, AM_TAX_CLASSIFICAZIONE);
		if (is_array($terms) && !empty($terms) && !is_wp_error($terms)) {
			$best = null;
			$best_depth = -1;
			foreach ($terms as $t) {
				if (!($t instanceof WP_Term)) { continue; }
				$anc = function_exists('get_ancestors') ? get_ancestors((int)$t->term_id, AM_TAX_CLASSIFICAZIONE) : [];
				$depth = is_array($anc) ? count($anc) : 0;
				if ($depth > $best_depth) { $best_depth = $depth; $best = $t; }
			}
			if ($best instanceof WP_Term) {
				// Depth 0 = Famiglia, 1 = Genere, 2 = Specie.
				if ($best_depth >= 2) {
					if ($species === '') { $species = (string) $best->name; }
					if ($genus_name === '' && (int)$best->parent > 0) {
						$tg = get_term((int)$best->parent, AM_TAX_CLASSIFICAZIONE);
						if ($tg && !is_wp_error($tg)) { $genus_name = (string) $tg->name; }
					}
				} elseif ($best_depth === 1) {
					if ($genus_name === '') { $genus_name = (string) $best->name; }
				}
			}
		}
	}

	$base = trim($species);
	if ($genus_name !== '' && $base !== '') {
		// Avoid duplicating genus if species already contains it.
		if (stripos($base, $genus_name) !== 0) {
			$base = trim($genus_name . ' ' . $base);
		}
	} elseif ($genus_name !== '' && $base === '') {
		$base = $genus_name;
	}

	$plain = trim(preg_replace('/\s+/', ' ', trim($base . ' ' . $infra . ' ' . $author)));
	return [
		'genus' => $genus_name,
		'species' => $species,
		'base' => $base,
		'infra' => $infra,
		'author' => $author,
		'plain' => $plain,
	];
}

private static function render_scientific_name_html(int $post_id): string {
	$p = self::compute_scientific_name_parts($post_id);
	$base = trim((string) ($p['base'] ?? ''));
	$infra = trim((string) ($p['infra'] ?? ''));
	$author = trim((string) ($p['author'] ?? ''));

	if ($base === '' && $infra === '' && $author === '') { return ''; }

	$html = '<span class="am-sciname-base"><i>' . esc_html($base) . '</i></span>';
	// Requirement: Genere + specie in corsivo; forma/varieta' e autore in normale.
	if ($infra !== '') {
		$html .= ' <span class="am-sciname-infra">' . esc_html($infra) . '</span>';
	}
	if ($author !== '') {
		$html .= ' <span class="am-sciname-author">' . esc_html($author) . '</span>';
	}
	return $html;
}

private static function compute_single_display_title(int $post_id): string {
	$p = self::compute_scientific_name_parts($post_id);
	$title = (string) ($p['plain'] ?? '');
	if (trim($title) !== '') { return $title; }
	$post = get_post($post_id);
	return ($post && !is_wp_error($post)) ? (string) $post->post_title : '';
}

	/**
	 * Mini breadcrumb above title: Famiglia › Genere
	 */
	
	/**
	 * Find a page URL that contains [am_archive] shortcode (cached).
	 */


	private static function get_archive_page_url_1_5_43(): string {
		$q = new WP_Query([
			'post_type'              => 'page',
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			's'                      => '[am_archive',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]);

		if ($q->have_posts()) {
			$page_id = (int) $q->posts[0]->ID;
			wp_reset_postdata();
			$url = get_permalink($page_id);
			if (is_string($url) && $url !== '') {
				return $url;
			}
		}
		wp_reset_postdata();

		if (defined('AM_CPT')) {
			$archive = get_post_type_archive_link(AM_CPT);
			if (is_string($archive) && $archive !== '') {
				return $archive;
			}
		}

		return home_url('/');
	}



private static function get_family_genus_breadcrumb_html(int $post_id): string {
		if (!$post_id || !defined('AM_TAX_CLASSIFICAZIONE')) {
			return '';
		}

		$archive_url = self::get_archive_page_url_1_5_43();
		$species_id = (int) get_post_meta($post_id, 'am_tax_species_id', true);
		$genus_id   = (int) get_post_meta($post_id, 'am_tax_genus_id', true);
		$family_id  = (int) get_post_meta($post_id, 'am_tax_family_id', true);

		if (!$species_id || !$genus_id || !$family_id) {
			$terms = wp_get_post_terms($post_id, AM_TAX_CLASSIFICAZIONE);
			if (!is_wp_error($terms) && !empty($terms)) {
				$deepest = null;
				$deepest_depth = -1;
				foreach ($terms as $term) {
					$ancestors = get_ancestors((int) $term->term_id, AM_TAX_CLASSIFICAZIONE);
					$depth = is_array($ancestors) ? count($ancestors) : 0;
					if ($depth > $deepest_depth) {
						$deepest = $term;
						$deepest_depth = $depth;
					}
				}

				if ($deepest instanceof WP_Term) {
					if (!$species_id && $deepest_depth >= 2) {
						$species_id = (int) $deepest->term_id;
					}
					$ancestors = array_reverse(get_ancestors((int) $deepest->term_id, AM_TAX_CLASSIFICAZIONE));
					if (!$family_id && isset($ancestors[1])) {
						$family_id = (int) $ancestors[1];
					}
					if (!$genus_id && isset($ancestors[2])) {
						$genus_id = (int) $ancestors[2];
					}
				}
			}
		}

		$items = [];
		$items[] = '<a class="am-entry-breadcrumb__link" href="' . esc_url($archive_url) . '">' . esc_html__('Archivio Micologico', 'archivio-micologico') . '</a>';

		if ($family_id) {
			$t = get_term($family_id, AM_TAX_CLASSIFICAZIONE);
			if ($t && !is_wp_error($t)) {
				$items[] = '<a class="am-entry-breadcrumb__link" href="' . esc_url(add_query_arg(['am_family' => $family_id], $archive_url)) . '">' . esc_html($t->name) . '</a>';
			}
		}

		if ($genus_id) {
			$t = get_term($genus_id, AM_TAX_CLASSIFICAZIONE);
			if ($t && !is_wp_error($t)) {
				$items[] = '<a class="am-entry-breadcrumb__link" href="' . esc_url(add_query_arg(['am_genus' => $genus_id], $archive_url)) . '">' . esc_html($t->name) . '</a>';
			}
		}

		if ($species_id) {
			$t = get_term($species_id, AM_TAX_CLASSIFICAZIONE);
			if ($t && !is_wp_error($t)) {
				$items[] = '<span class="am-entry-breadcrumb__current" aria-current="page">' . esc_html($t->name) . '</span>';
			}
		}

		if (count($items) <= 1) {
			return '';
		}

		return '<nav class="am-entry-breadcrumb am-entry-breadcrumb--tax" aria-label="' . esc_attr__('Navigazione tassonomica scheda', 'archivio-micologico') . '">'
			. implode('<span class="am-entry-breadcrumb__sep" aria-hidden="true">&rsaquo;</span>', $items)
			. '</nav>';
	}

private static function get_safe_return_results_url_1_5_45(string $fallback_url): string {
		$raw = '';
		if (isset($_GET['am_return'])) {
			$raw = (string) wp_unslash($_GET['am_return']);
		} elseif (isset($_GET['am_back'])) {
			$raw = (string) wp_unslash($_GET['am_back']);
		}

		$raw = trim($raw);
		if ($raw === '') {
			return '';
		}

		$url = esc_url_raw($raw);
		if ($url === '') {
			return '';
		}

		$home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
		$url_host  = wp_parse_url($url, PHP_URL_HOST);

		// Allow relative URLs and same-host absolute URLs only.
		if ($url_host && $home_host && strtolower((string) $url_host) !== strtolower((string) $home_host)) {
			return '';
		}

		// Avoid returning to the same scheda and avoid admin URLs.
		if (stripos($url, 'wp-admin') !== false || stripos($url, 'wp-login.php') !== false) {
			return '';
		}

		return $url;
	}


private static function get_entry_navigation_bar_html_1_5_44(int $post_id, string $position = 'top'): string {
		if (!$post_id || !defined('AM_TAX_CLASSIFICAZIONE')) {
			return '';
		}

		$archive_url = self::get_archive_page_url_1_5_43();
		$family_id  = (int) get_post_meta($post_id, 'am_tax_family_id', true);
		$genus_id   = (int) get_post_meta($post_id, 'am_tax_genus_id', true);

		$links = [];

		$return_url = self::get_safe_return_results_url_1_5_45($archive_url);
		if ($return_url !== '') {
			$links[] = '<a class="am-entry-nav__link am-entry-nav__link--return" href="' . esc_url($return_url) . '">' . esc_html__('← Torna ai risultati', 'archivio-micologico') . '</a>';
		} else {
			$links[] = '<a class="am-entry-nav__link am-entry-nav__link--archive" href="' . esc_url($archive_url) . '">' . esc_html__('← Torna all’archivio', 'archivio-micologico') . '</a>';
		}

		if ($family_id) {
			$t = get_term($family_id, AM_TAX_CLASSIFICAZIONE);
			if ($t && !is_wp_error($t)) {
				$links[] = '<a class="am-entry-nav__link" href="' . esc_url(add_query_arg(['am_family' => $family_id], $archive_url)) . '">' . sprintf(esc_html__('Famiglia: %s', 'archivio-micologico'), esc_html($t->name)) . '</a>';
			}
		}

		if ($genus_id) {
			$t = get_term($genus_id, AM_TAX_CLASSIFICAZIONE);
			if ($t && !is_wp_error($t)) {
				$links[] = '<a class="am-entry-nav__link" href="' . esc_url(add_query_arg(['am_genus' => $genus_id], $archive_url)) . '">' . sprintf(esc_html__('Genere: %s', 'archivio-micologico'), esc_html($t->name)) . '</a>';
			}
		}

		$links[] = '<a class="am-entry-nav__link am-entry-nav__link--search" href="' . esc_url(add_query_arg(['am_focus' => 'search'], $archive_url)) . '">' . esc_html__('Cerca altra scheda', 'archivio-micologico') . '</a>';

		if (empty($links)) {
			return '';
		}

		$position = $position === 'bottom' ? 'bottom' : 'top';

		return '<nav class="am-entry-nav am-entry-nav--' . esc_attr($position) . '" aria-label="' . esc_attr__('Navigazione scheda fungo', 'archivio-micologico') . '">'
			. implode('', $links)
			. '</nav>';
	}



public static function filter_single_content(string $content): string {
		if (is_admin() || !is_singular(AM_CPT) || !in_the_loop() || !is_main_query()) {
			return $content;
		}

		global $post;
		if (!$post || $post->post_type !== AM_CPT) {
			return $content;
		}

		$breadcrumb_html = self::get_family_genus_breadcrumb_html((int) $post->ID);
		$display_title_plain = self::compute_single_display_title((int) $post->ID);
		$display_title_html = self::render_scientific_name_html((int) $post->ID);

		// Titolo scheda: per compatibilita' con i template che NON mostrano il titolo del CPT,
		// lo stampiamo anche qui (in modo controllabile).
		$show_title_inside = (int) get_option('am_single_show_title', 1);
		$show_title_inside = (int) apply_filters('am_single_show_title', $show_title_inside, (int) $post->ID);
		$title_heading_html = '';
		if ($show_title_inside === 1 && trim($display_title_plain) !== '') {
			$title_heading_html = '<h1 class="am-entry-title">' . wp_kses($display_title_html !== '' ? $display_title_html : esc_html($display_title_plain), ['span'=>['class'=>true],'i'=>[]]) . '</h1>';
		}

		$has_micro = (trim((string) get_post_meta((int)$post->ID, 'am_macro_micro', true)) !== '');
		$title_html = '';
		if (trim($display_title_plain) !== '') {
			// Meta compatta sotto il titolo del tema (autore WP + badge).
			$u = get_userdata((int)$post->post_author);
			$author_name = '';
			if ($u) {
				$author_name = trim((string)$u->first_name . ' ' . (string)$u->last_name);
				if ($author_name === '') {
					$author_name = (string)$u->display_name;
				}
			}
			if (trim($author_name) !== '') {
				$reviewer = (string) get_post_meta((int)$post->ID, 'am_last_reviewer_name', true);
				$review_dt = (string) get_post_meta((int)$post->ID, 'am_last_review_datetime', true);
				$review_html = '';
				if (trim($reviewer) !== '' && trim($review_dt) !== '') {
					$review_html = ' <span class="am-entry-review" style="margin-left:10px; opacity:.9;">' . esc_html(sprintf(__('Revisione: %1$s (%2$s)', 'archivio-micologico'), $reviewer, $review_dt)) . '</span>';
				}
				$title_html .= '<div class="am-entry-wp-author" style="margin:0 0 4px 0; display:flex; align-items:center; gap:8px;">' . '<svg width="28" height="28" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0; opacity:0.45;">' . '<circle cx="8" cy="5" r="3" stroke="currentColor" stroke-width="1.5"/>' . '<path d="M2 14c0-3.314 2.686-5 6-5s6 1.686 6 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>' . '</svg>' . '<span style="font-size:16px; opacity:.7;">' . esc_html($author_name) . '</span>' . $review_html . '</div>';
			}
			$sequenced = (string) get_post_meta($post->ID, 'am_sequenced', true);
			if ($sequenced === '1' || $sequenced === 'yes' || $sequenced === 'on') {
				$title_html .= '<div class="am-entry-badges" style="margin:0 0 14px 0;">'
					. '<span class="am-badge am-badge--seq">' . esc_html__('Specie sequenziata', 'archivio-micologico') . '</span>'
					. '</div>';
			}
		}
		$edit_link_html = self::get_edit_link_html((int) $post->ID);

		$featured_html = '';
		if (has_post_thumbnail($post)) {
			$thumb_id = (int) get_post_thumbnail_id($post);
			$full = wp_get_attachment_image_url($thumb_id, 'full');
			$img = wp_get_attachment_image($thumb_id, 'full', false, [
				'class'   => 'am-entry-featured-image wp-post-image',
				'loading' => 'eager',
				'style'   => 'display:block;width:100% !important;max-width:100% !important;height:auto !important;margin:0 !important;',
			]);
			$cap = function_exists('wp_get_attachment_caption') ? (string) wp_get_attachment_caption($thumb_id) : '';
			$cap = trim($cap);
			$cap = preg_replace('/[<>]/u', '', $cap);
			$cap = trim($cap);
			$inner = $img;
			$featured_html = '<figure class="am-entry-featured" style="display:block;width:100% !important;max-width:100% !important;margin-left:0 !important;margin-right:0 !important;">' . $inner;
			if ($cap !== '') {
				$featured_html .= '<figcaption class="am-entry-caption" style="display:block !important;width:100% !important;text-align:left !important;direction:ltr !important;margin:6px 0 0 !important;">' . esc_html($cap) . '</figcaption>';
			}
			$featured_html .= '</figure>';
		}

		$gallery_ids = get_post_meta($post->ID, 'am_gallery_ids', true);
		if (!is_array($gallery_ids)) {
			$gallery_ids = array_filter(array_map('trim', explode(',', (string) $gallery_ids)));
		}
		// v1.5.33: normalizza e deduplica gli ID galleria prima del rendering pubblico,
		// così la stessa immagine/didascalia non viene stampata più volte se salvata due volte.
		$gallery_ids = array_values(array_unique(array_filter(array_map('absint', $gallery_ids))));
		$gallery_html = '';
		if (!empty($gallery_ids)) {
			$items = '';
			$am_rendered_gallery_ids = [];
			foreach ($gallery_ids as $aid) {
				$aid = absint($aid);
				if (!$aid || isset($am_rendered_gallery_ids[$aid])) { continue; }
				$am_rendered_gallery_ids[$aid] = true;
				$url = wp_get_attachment_image_url($aid, 'full');
				$img = wp_get_attachment_image($aid, 'full', false, [
					'loading'  => 'eager',
					'decoding' => 'async',
					'class'    => 'am-gallery-image',
				]);
				$thumb_url = wp_get_attachment_image_url($aid, 'thumbnail');
				if (!$url || !$img) { continue; }
				// Chrome can reserve incorrect space or distort gallery images when width/height
				// attributes from attachment markup conflict with the final responsive layout.
				$img = preg_replace('/\s(?:width|height)="[^"]*"/i', '', $img);
				$img = preg_replace('/\sloading="[^"]*"/i', '', $img);
				$cap = function_exists('wp_get_attachment_caption') ? (string) wp_get_attachment_caption($aid) : '';
				$cap = trim($cap);
				// Clean captions from stray angle brackets (often pasted by mistake)
				$cap = preg_replace('/[<>]/u', '', $cap);
				$cap = trim($cap);
				$items .= '<figure class="am-entry-gallery__figure" style="text-align:left !important;">'
					. '<div class="am-entry-gallery__item">' . $img . '</div>'
					. ($cap !== '' ? '<figcaption class="am-entry-caption" style="display:block !important;width:100% !important;text-align:left !important;direction:ltr !important;margin:6px 0 0 !important;">' . esc_html($cap) . '</figcaption>' : '')
					. '</figure>';
			}
			if ($items) {
				$gallery_html = '<div class="am-entry-gallery am-gallery--journal">' . $items . '</div>';
			}
		}

		// Optional PDF attachment link (am_pdf_id).
		$pdf_html = '';
		$pdf_id = get_post_meta($post->ID, 'am_pdf_id', true);
		$pdf_id = is_numeric($pdf_id) ? absint($pdf_id) : 0;
		if ($pdf_id) {
			$att = get_post($pdf_id);
			if ($att && $att->post_type === 'attachment') {
				// Determine if it's a PDF (robust for hosts that rewrite attachment URLs).
				$mime = (string) ($att->post_mime_type ?? '');
				$file = function_exists('get_attached_file') ? (string) get_attached_file($pdf_id) : '';
				$url  = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($pdf_id) : '';
				$filename = '';
				if ($file !== '') {
					$filename = basename($file);
				} elseif ($url !== '') {
					$filename = basename(parse_url($url, PHP_URL_PATH) ?: 'allegato.pdf');
				} elseif (!empty($att->guid)) {
					$filename = basename(parse_url((string)$att->guid, PHP_URL_PATH) ?: 'allegato.pdf');
				}
				$is_pdf = (stripos($mime, 'pdf') !== false)
					|| ($filename && preg_match('/\.pdf$/i', $filename))
					|| ($file && preg_match('/\.pdf$/i', $file))
					|| ($url && preg_match('/\.pdf($|\?)/i', $url));
				if ($is_pdf && $url) {
					if ($filename === '') { $filename = 'allegato.pdf'; }
					$pdf_html =
						'<section id="am-pdf" class="am-entry-section am-entry-section--pdf">'
						. '<h2>' . esc_html__('Allegato PDF', 'archivio-micologico') . '</h2>'
						. '<div class="am-entry-section__content">'
						. '<a class="am-entry-pdf-link" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">'
						. '<span class="dashicons dashicons-media-document" aria-hidden="true"></span>'
						. '<span class="am-entry-pdf-link__text">' . esc_html($filename) . '</span>'
						. '</a>'
						. '</div></section>';
				}
			}
		}

		// Microscopia (meta am_macro_micro): se presente, mostra la sezione e consente il relativo badge/indicatore.
		$macro_micro = (string) get_post_meta($post->ID, 'am_macro_micro', true);
		$macro_micro_html = '';
		if (trim($macro_micro) !== '') {
			$macro_micro_html = '<section id="am-micro" class="am-entry-section am-entry-section--micro">'
				. '<h2>' . esc_html__('Descrizione microscopica', 'archivio-micologico') . '</h2>'
				. '<div class="am-entry-section__content">' . wpautop(self::sanitize_editor_content($macro_micro)) . '</div>'
				. '</section>';
		}

		$notes = (string) get_post_meta($post->ID, 'am_notes', true);
		$notes_html = '';
		if ($notes !== '') {
			$notes_html = '<section class="am-entry-section am-entry-section--notes" style="margin-bottom:20px;">'
				. '<h2>' . esc_html__('Commenti e note', 'archivio-micologico') . '</h2>'
				. '<div class="am-entry-section__content">' . wpautop(self::sanitize_editor_content($notes)) . '</div>'
				. '</section>';
		}
$macro_html = '';
		if (trim((string) $content) !== '') {
			$macro_html = '<section class="am-entry-section am-entry-section--macro">'
				. '<h2>' . esc_html__('Descrizione macroscopica', 'archivio-micologico') . '</h2>'
				. '<div class="am-entry-section__content">' . $content . '</div>'
				. '</section>';
		}

			// Layout: la galleria deve comparire in basso, dopo "Commenti e note".
			$entry_nav_bottom = self::get_entry_navigation_bar_html_1_5_44((int) $post->ID, 'bottom');
			return $breadcrumb_html . $title_heading_html . $title_html . $edit_link_html . $featured_html . $pdf_html . $macro_html . $macro_micro_html . $notes_html . $gallery_html . $entry_nav_bottom;
	}

	/**
	 * Shortcode: [am_search_page]
	 * Pagina dedicata alla ricerca (separata dall'Archivio).
	 *
	 * Usa il parametro URL: am_s
	 * Esempio: ?am_s=boletus
	 */
	public static function render_search_page($atts = []): string {
		if (!defined('ABSPATH')) { return ''; }

		$atts = shortcode_atts([
			// Placeholder auto: verrà completato con il numero totale di specie (schede) presenti.
			'placeholder' => '',
			'button'      => __('Cerca', 'archivio-micologico'),
			'per_page'    => 50,
		], (array) $atts, 'am_search_page');

		// Placeholder dinamico: mostra il numero di specie (schede) presenti.
		if ('' === trim((string) $atts['placeholder'])) {
			$total = 0;
			if (defined('AM_CPT')) {
				$cnt = wp_count_posts(AM_CPT);
				if ($cnt && isset($cnt->publish)) {
					$total = (int) $cnt->publish;
				}
			}
			$atts['placeholder'] = sprintf(
				/* translators: %d = number of species (posts) */
				__('Cerca per nome, descrizione, contenuti… (Specie presenti: %d)', 'archivio-micologico'),
				$total
			);
		}

		// Support external search widgets (e.g., Elementor) that submit with the standard WP param "s".
		$search_term = '';
		if (isset($_GET['am_s'])) {
			$search_term = sanitize_text_field(wp_unslash($_GET['am_s']));
		} elseif (isset($_GET['s'])) {
			$search_term = sanitize_text_field(wp_unslash($_GET['s']));
		}
		$base_url = get_permalink();
		if (!$base_url) { $base_url = home_url('/'); }

		ob_start();
		echo '<div class="am-search-page">';
		echo '<style>.am-search-page__bar{display:flex;gap:10px;align-items:center}.am-search-page__input{flex:1;min-width:0;height:44px;padding:10px 12px;border-radius:8px;border:1px solid rgba(0,0,0,.25)}.am-search-page__button{height:44px;padding:10px 18px;border-radius:8px}.am-search-page__footer{margin-top:18px;font-size:12px;opacity:.65}.am-search-page__loader{margin:14px 0;font-size:13px;opacity:.75;text-align:center}.am-archive-card{display:flex;gap:12px;align-items:flex-start;border:1px solid rgba(0,0,0,.08);border-radius:12px;padding:12px;margin:0 0 12px 0}.am-archive-card__thumb{width:56px;height:56px;overflow:hidden;border-radius:10px;flex:0 0 auto}.am-archive-card__thumbimg{width:56px;height:56px;object-fit:cover;display:block}.am-archive-card__body{flex:1;min-width:0}.am-archive-card__title{margin:0 0 6px 0;font-size:16px}.am-archive-card__excerpt{color:rgba(0,0,0,.75);font-size:14px;line-height:1.35}</style>';
		echo '<form class="am-search-page__form" method="get" action="' . esc_url($base_url) . '">';
		echo '<div class="am-search-page__bar">';
		echo '<label class="screen-reader-text" for="am_s">' . esc_html__('Cerca', 'archivio-micologico') . '</label>';
		echo '<input type="text" id="am_s" name="am_s" value="' . esc_attr($search_term) . '" placeholder="' . esc_attr($atts['placeholder']) . '" class="am-search-page__input" />';
		echo '<button type="submit" class="am-search-page__button">' . esc_html($atts['button']) . '</button>';
		echo '</div>'; // .am-search-page__bar
		echo '<div class="am-search-preview" id="am-search-preview" aria-live="polite" aria-label="' . esc_attr__('Suggerimenti', 'archivio-micologico') . '"></div>';
		echo '</form>';

		if ($search_term !== '') {
			$args = [
				'post_type'      => [AM_CPT, AM_ARTICLE_CPT],
				'post_status'    => 'publish',
				'posts_per_page' => max(1, (int) $atts['per_page']),
				'paged'          => 1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				's'              => $search_term,
				// Abilita la ricerca anche nei meta am_* tramite i filtri già presenti (step1)
				'am_meta_search' => $search_term,
				'am_tax_search'  => $search_term,
			];

			$query = new WP_Query($args);
			$found = (int) $query->found_posts;

			if ($found > 0) {
				echo '<p class="am-archive-message am-success">';
				echo esc_html(sprintf(__('Trovati %d risultati per: %s', 'archivio-micologico'), $found, $search_term));
				echo '</p>';
			} else {
				echo '<p class="am-archive-message am-empty">';
				echo esc_html(sprintf(__('Nessun risultato per: %s', 'archivio-micologico'), $search_term));
				echo '</p>';
			}

			if ($query->have_posts()) {
				echo '<div id="am-search-results" class="am-archive__results" data-q="' . esc_attr($search_term) . '" data-page="1" data-per="' . esc_attr((int)$atts['per_page']) . '" data-max="' . esc_attr((int)$query->max_num_pages) . '">';
				while ($query->have_posts()) {
					$query->the_post();
					$perma = get_permalink();
					echo '<div class="am-archive-card">';
					$thumb = get_the_post_thumbnail(get_the_ID(), 'thumbnail', ['class'=>'am-archive-card__thumbimg','loading'=>'lazy']);
					if ($thumb) {
						echo '<div class="am-archive-card__thumb">' . $thumb . '</div>';
					}
					echo '<div class="am-archive-card__body">';
					echo '<h3 class="am-archive-card__title"><a href="' . esc_url($perma) . '">' . wp_kses(self::render_scientific_name_html((int)get_the_ID()), ['span'=>['class'=>true],'i'=>[]]) . '</a></h3>';$is_article = (get_post_type(get_the_ID()) === AM_ARTICLE_CPT);
				if ($is_article) { echo '<div class=\"am-archive-card__type\">' . esc_html__('Articolo', 'archivio-micologico') . '</div>'; }
					$excerpt = get_the_excerpt();
					if ($excerpt) {
						echo '<div class="am-archive-card__excerpt">' . esc_html(wp_trim_words($excerpt, 28)) . '</div>';
					}
					echo '</div>'; // .am-archive-card__body
					echo '</div>'; // .am-archive-card
				}
				echo '</div>';
				wp_reset_postdata();
				if ((int)$query->max_num_pages > 1) {
					echo '<div id="am-search-loader" class="am-search-page__loader">' . esc_html__('Scorri per caricare altri risultati…', 'archivio-micologico') . '</div>';
					echo '<script>(function(){var res=document.getElementById("am-search-results");var loader=document.getElementById("am-search-loader");if(!res||!loader){return;}var loading=false;function nearBottom(){return (window.innerHeight+window.scrollY) >= (document.body.offsetHeight-250);}function loadMore(){if(loading){return;}var page=parseInt(res.getAttribute("data-page")||"1",10);var max=parseInt(res.getAttribute("data-max")||"1",10);if(page>=max){loader.textContent="";return;}loading=true;loader.textContent="Caricamento…";var q=res.getAttribute("data-q")||"";var per=res.getAttribute("data-per")||"50";var xhr=new XMLHttpRequest();xhr.open("POST","' . esc_url(admin_url('admin-ajax.php')) . '");xhr.setRequestHeader("Content-Type","application/x-www-form-urlencoded; charset=UTF-8");xhr.onload=function(){loading=false;if(xhr.status!==200){loader.textContent="";return;}try{var data=JSON.parse(xhr.responseText);}catch(e){loader.textContent="";return;}if(!data||!data.success){loader.textContent="";return;}if(data.data&&data.data.html){res.insertAdjacentHTML("beforeend", data.data.html);res.setAttribute("data-page", String(page+1));}var newMax=(data.data&&data.data.max_pages)?parseInt(data.data.max_pages,10):max;res.setAttribute("data-max", String(newMax));if(page+1>=newMax){loader.textContent="";}else{loader.textContent="Scorri per caricare altri risultati…";}};xhr.send("action=am_search_more&q="+encodeURIComponent(q)+"&page="+(page+1)+"&per_page="+encodeURIComponent(per));}
window.addEventListener("scroll", function(){if(nearBottom()){loadMore();}});})();</script>';
				}
			}
		} else {
			
		}

		echo '</div>';
		return (string) ob_get_clean();
	}


	/**
	 * Fallback (no-JS) term creation for front-end only.
	 * Currently used mainly for creating "Ordine" when JS/AJAX is blocked by cache/minify or hosting rules.
	 */
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


	/**
	 * Lightweight on-screen debug for managed hosting (no access to wp-config.php).
	 * Enable by adding ?am_debug=1 to the front-end "Nuovo Inserimento" URL (admin only).
	 */
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
// AM_FIX: if we're posting to admin-post.php with our action, let admin_post handlers run.
		if ( isset($_POST['action']) && $_POST['action'] === 'am_front_update' ) {
			return;
		}
		if ( defined('WP_ADMIN') && WP_ADMIN && !empty($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'admin-post.php') !== false ) {
			return;
		}
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

	/** Single deletion path for admin-post and front-end fallback requests. */
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



	public static function ajax_search_more(): void {
		if (!defined('ABSPATH')) { wp_send_json_error(['msg' => 'no_abspath']); }

		$term     = isset($_REQUEST['q']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['q'])) : '';
		$page     = isset($_REQUEST['page']) ? max(1, (int) $_REQUEST['page']) : 1;
		$per_page = isset($_REQUEST['per_page']) ? max(1, (int) $_REQUEST['per_page']) : 50;

		$pt = ($term !== '') ? [AM_CPT, AM_ARTICLE_CPT] : AM_CPT;

		$args = [
			'post_type'      => [AM_CPT, AM_ARTICLE_CPT],
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		if ($term !== '') {
			$args['s']             = $term;
			$args['am_meta_search']= $term;
			$args['am_tax_search'] = $term;
		}

		$q = new WP_Query($args);

		ob_start();
		if ($q->have_posts()) {
			while ($q->have_posts()) {
				$q->the_post();
				$perma = get_permalink();
				echo '<div class="am-archive-card">';
					$thumb = get_the_post_thumbnail(get_the_ID(), 'thumbnail', ['class'=>'am-archive-card__thumbimg','loading'=>'lazy']);
					if ($thumb) {
						echo '<div class="am-archive-card__thumb">' . $thumb . '</div>';
					}

				echo '<h3 class="am-archive-card__title"><a href="' . esc_url($perma) . '">' . wp_kses(self::render_scientific_name_html((int)get_the_ID()), ['span'=>['class'=>true],'i'=>[]]) . '</a></h3>';$is_article = (get_post_type(get_the_ID()) === AM_ARTICLE_CPT);
				if ($is_article) { echo '<div class=\"am-archive-card__type\">' . esc_html__('Articolo', 'archivio-micologico') . '</div>'; }
				$excerpt = get_the_excerpt();
				if ($excerpt) {
					echo '<div class="am-archive-card__excerpt">' . esc_html(wp_trim_words($excerpt, 28)) . '</div>';
				}
				echo '</div>';
			}
			wp_reset_postdata();
		}
		$html = (string) ob_get_clean();

		wp_send_json_success([
			'html'      => $html,
			'found'     => (int) $q->found_posts,
			'max_pages' => (int) $q->max_num_pages,
			'page'      => $page,
		]);
	}


	private static function archive_term_entry_count(int $term_id, bool $include_children = true): int {
		if ($term_id <= 0) { return 0; }
		$q = new \WP_Query([
			'post_type'              => [AM_CPT, AM_ARTICLE_CPT],
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tax_query'              => [[
				'taxonomy'         => AM_TAX_CLASSIFICAZIONE,
				'field'            => 'term_id',
				'terms'            => [$term_id],
				'include_children' => $include_children,
			]],
		]);
		return max(0, (int) $q->found_posts);
	}

	public static function ajax_archive_tree(): void {
		check_ajax_referer('am_ajax', 'nonce');

		$level = isset($_POST['level']) ? sanitize_key((string) $_POST['level']) : '';
		$page  = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;


		if ($level === 'articles') {
			$per_page = isset($_POST['per_page']) ? max(1, (int) $_POST['per_page']) : 20;
			$q = new \WP_Query([
				'post_type'      => AM_ARTICLE_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => ((string) get_option('am_articles_order', 'date') === 'alpha') ? 'title' : 'date',
				'order'          => ((string) get_option('am_articles_order', 'date') === 'alpha') ? 'ASC' : 'DESC',
				'no_found_rows'  => false,
			]);

			$items = [];
			foreach ((array) $q->posts as $p) {
				$thumb = (string) get_the_post_thumbnail_url($p->ID, 'thumbnail');
				$items[] = [
					'post_id' => (int) $p->ID,
					'title'   => wp_specialchars_decode(get_the_title($p->ID), ENT_QUOTES),
					'link'    => get_permalink($p->ID),
					'type'    => 'article',
					'thumb'   => $thumb ? esc_url_raw($thumb) : '',
				];
			}

			wp_send_json_success([
				'items'    => $items,
				'has_more' => ($q->max_num_pages > $page),
			]);
		}

		if ($level === 'divisions') {
			$per_page = isset($_POST['per_page']) ? max(1, (int) $_POST['per_page']) : 30;
			$offset   = ($page - 1) * $per_page;

			$terms = get_terms([
				'taxonomy'   => AM_TAX_CLASSIFICAZIONE,
				'hide_empty' => false,
				'parent'     => 0,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => $per_page,
				'offset'     => $offset,
				'slug'       => ['basidiomycota','ascomycota'],
			]);

			if (is_wp_error($terms)) {
				wp_send_json_error(['message' => $terms->get_error_message()], 500);
			}

			$next = get_terms([
				'taxonomy'   => AM_TAX_CLASSIFICAZIONE,
				'hide_empty' => false,
				'parent'     => 0,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => 1,
				'offset'     => $offset + $per_page,
				'fields'     => 'ids',
				'slug'       => ['basidiomycota','ascomycota'],
			]);
			$has_more = !is_wp_error($next) && !empty($next);

			$items = array_map(function($t){
				return [
					'id'    => (int) $t->term_id,
					'name'  => (string) $t->name,
					'slug'  => (string) $t->slug,
					'count' => self::archive_term_entry_count((int) $t->term_id, true),
				];
			}, $terms);

			wp_send_json_success(['items' => $items, 'has_more' => $has_more]);
		}

		if ($level === 'families') {
			$division_id = isset($_POST['division_id']) ? absint($_POST['division_id']) : 0;
			if (!$division_id) {
				wp_send_json_error(['message' => 'division_id mancante'], 400);
			}

			$terms = get_terms([
				'taxonomy'   => AM_TAX_CLASSIFICAZIONE,
				'hide_empty' => false,
				'parent'     => $division_id,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]);

			if (is_wp_error($terms)) {
				wp_send_json_error(['message' => $terms->get_error_message()], 500);
			}

			$items = array_map(function($t){
				return [
					'id'    => (int) $t->term_id,
					'name'  => (string) $t->name,
					'slug'  => (string) $t->slug,
					'count' => self::archive_term_entry_count((int) $t->term_id, true),
				];
			}, $terms);

			wp_send_json_success(['items' => $items]);
		}

		if ($level === 'genera') {
			$family_id = isset($_POST['family_id']) ? absint($_POST['family_id']) : 0;
			if (!$family_id) {
				wp_send_json_error(['message' => 'family_id mancante'], 400);
			}

			$terms = get_terms([
				'taxonomy'   => AM_TAX_CLASSIFICAZIONE,
				// Same reason as above: counts may not be padded to parent terms.
				'hide_empty' => false,
				'parent'     => $family_id,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]);

			if (is_wp_error($terms)) {
				wp_send_json_error(['message' => $terms->get_error_message()], 500);
			}

			$items = array_map(function($t){
				return [
					'id'    => (int) $t->term_id,
					'name'  => (string) $t->name,
					'slug'  => (string) $t->slug,
					'count' => self::archive_term_entry_count((int) $t->term_id, true),
				];
			}, $terms);

			wp_send_json_success(['items' => $items]);
		}

		if ($level === 'species') {
			$genus_id = isset($_POST['genus_id']) ? absint($_POST['genus_id']) : 0;
			if (!$genus_id) {
				wp_send_json_error(['message' => 'genus_id mancante'], 400);
			}

			$terms = get_terms([
				'taxonomy'   => AM_TAX_CLASSIFICAZIONE,
				'hide_empty' => false,
				'parent'     => $genus_id,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]);

			if (is_wp_error($terms)) {
				wp_send_json_error(['message' => $terms->get_error_message()], 500);
			}

			$items = array_map(function($t){
				return [
					'id'    => (int) $t->term_id,
					'name'  => (string) $t->name,
					'slug'  => (string) $t->slug,
					'count' => self::archive_term_entry_count((int) $t->term_id, true),
				];
			}, $terms);

			wp_send_json_success(['items' => $items]);
		}

		if ($level === 'entries') {
			$genus_id = isset($_POST['genus_id']) ? absint($_POST['genus_id']) : 0;
			$species_id = isset($_POST['species_id']) ? absint($_POST['species_id']) : 0;
			if (!$genus_id && !$species_id) {
				wp_send_json_error(['message' => 'genus_id o species_id mancante'], 400);
			}

			$per_page = isset($_POST['per_page']) ? max(1, (int) $_POST['per_page']) : 30;

			$q = new \WP_Query([
				'post_type'      => [AM_CPT, AM_ARTICLE_CPT],
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => [[
					'taxonomy' => AM_TAX_CLASSIFICAZIONE,
					'field'    => 'term_id',
					'terms'    => [$species_id ?: $genus_id],
					'include_children' => $species_id ? false : true,
				]],
				'no_found_rows'  => false,
			]);

			$items = [];
			foreach ((array) $q->posts as $p) {
				$forma  = (string) get_post_meta($p->ID, 'am_infra', true);
				$autore = (string) get_post_meta($p->ID, 'am_author', true);
				$thumb  = (string) get_the_post_thumbnail_url($p->ID, 'thumbnail');

				$items[] = [
					'post_id' => (int) $p->ID,
					'title'   => wp_specialchars_decode(get_the_title($p->ID), ENT_QUOTES),
					'link'    => get_permalink($p->ID),
					'forma'   => trim($forma),
					'autore'  => trim($autore),
					'thumb'   => $thumb ? esc_url_raw($thumb) : '',
				];
			}

			wp_send_json_success([
				'items'    => $items,
				'has_more' => ($q->max_num_pages > $page),
			]);
		}

		wp_send_json_error(['message' => 'level non valido'], 400);
	}

	public static function ajax_archive_search(): void {
		check_ajax_referer('am_ajax', 'nonce');

		$s = isset($_POST['s']) ? sanitize_text_field((string) $_POST['s']) : '';
		$s = trim($s);
		if ($s === '') {
			wp_send_json_success(['items' => []]);
		}

		// Search in title + meta (forma/varietà + autore)
		$q = new \WP_Query([
			'post_type'      => [AM_CPT, AM_ARTICLE_CPT],
			'post_status'    => 'publish',
			'posts_per_page' => 80,
			's'              => $s,
			'orderby'        => 'relevance',
			'order'          => 'DESC',
		]);

		$items = [];
		foreach ((array) $q->posts as $p) {
			$forma  = (string) get_post_meta($p->ID, 'am_infra', true);
			$autore = (string) get_post_meta($p->ID, 'am_author', true);
			$thumb  = (string) get_the_post_thumbnail_url($p->ID, 'thumbnail');

			// Also match meta if WP native search doesn't match title/content
			$hay = strtolower($forma . ' ' . $autore);
			if (!empty($q->posts) && strpos(strtolower(get_the_title($p->ID)), strtolower($s)) === false) {
				if (strpos($hay, strtolower($s)) === false) {
					// keep WP relevance results anyway
				}
			}

			$items[] = [
				'post_id' => (int) $p->ID,
				'title'   => wp_specialchars_decode(get_the_title($p->ID), ENT_QUOTES),
				'type'    => (get_post_type($p->ID) === AM_ARTICLE_CPT ? 'article' : 'fungo'),
				'link'    => get_permalink($p->ID),
				'forma'   => trim($forma),
				'autore'  => trim($autore),
				'thumb'   => $thumb ? esc_url_raw($thumb) : '',
			];
		}

		// If user searches only meta (not in title/content), do a meta LIKE query and merge
		global $wpdb;
		$like = '%' . $wpdb->esc_like($s) . '%';
		$meta_ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.post_type = %s AND p.post_status='publish'
			   AND pm.meta_key IN ('am_infra','am_author')
			   AND pm.meta_value LIKE %s
			 LIMIT 80",
			AM_CPT, $like
		));
		$meta_ids = array_values(array_filter(array_map('absint', (array) $meta_ids)));

		if (!empty($meta_ids)) {
			foreach ($meta_ids as $pid) {
				$thumb = (string) get_the_post_thumbnail_url($pid, 'thumbnail');
				$items[] = [
					'post_id' => (int) $pid,
					'title'   => get_the_title($pid),
					'link'    => get_permalink($pid),
					'forma'   => trim((string) get_post_meta($pid, 'am_infra', true)),
					'autore'  => trim((string) get_post_meta($pid, 'am_author', true)),
					'thumb'   => $thumb ? esc_url_raw($thumb) : '',
				];
			}
		}

		// Deduplicate by post_id and sort by title
		$uniq = [];
		foreach ($items as $it) { $uniq[$it['post_id']] = $it; }
		$items = array_values($uniq);
		usort($items, function($a,$b){
			return strcasecmp((string)$a['title'], (string)$b['title']);
		});

		wp_send_json_success(['items' => $items]);
	}




	private static function resolve_current_fungo_id_for_meta_1_5_39(): int {
		if (!defined('AM_CPT')) {
			return 0;
		}

		$post_id = (int) get_queried_object_id();
		if ($post_id && get_post_type($post_id) === AM_CPT) {
			return $post_id;
		}

		global $post;
		if ($post instanceof WP_Post && (int) $post->ID > 0 && get_post_type((int) $post->ID) === AM_CPT) {
			return (int) $post->ID;
		}

		$name = (string) get_query_var('name');
		if ($name !== '') {
			$found = get_page_by_path($name, OBJECT, AM_CPT);
			if ($found instanceof WP_Post) {
				return (int) $found->ID;
			}
		}

		$uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
		$path = trim((string) parse_url($uri, PHP_URL_PATH), '/');
		if ($path !== '') {
			$parts = explode('/', $path);
			$slug = end($parts);
			if (is_string($slug) && $slug !== '') {
				$found = get_page_by_path(sanitize_title($slug), OBJECT, AM_CPT);
				if ($found instanceof WP_Post) {
					return (int) $found->ID;
				}
			}
		}

		return 0;
	}




	public static function render_social_preview_meta(): void {
		if (is_admin() || !defined('AM_CPT')) {
			return;
		}

		$post_id = self::resolve_current_fungo_id_for_meta_1_5_39();
		if (!$post_id || get_post_type($post_id) !== AM_CPT) {
			return;
		}

		echo self::build_social_preview_meta_html_1_5_40($post_id, 'wp_head');
	}

	public static function start_social_preview_head_buffer_1_5_40(): void {
		if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || is_feed()) {
			return;
		}

		$post_id = self::resolve_current_fungo_id_for_meta_1_5_39();
		if (!$post_id || get_post_type($post_id) !== AM_CPT) {
			return;
		}

		if (headers_sent()) {
			return;
		}

		ob_start(static function ($html) use ($post_id) {
			if (!is_string($html) || $html === '') {
				return $html;
			}

			// If AM social tags are already present through wp_head, do not duplicate them.
			if (strpos($html, 'Archivio Micologico social preview') !== false) {
				return $html;
			}

			if (stripos($html, '<head') === false) {
				return $html;
			}

			$meta = self::build_social_preview_meta_html_1_5_40($post_id, 'head_fallback');
			if ($meta === '') {
				return $html;
			}

			// Insert immediately after opening <head>, before possible generic SEO/theme tags.
			return preg_replace('/<head([^>]*)>/i', '<head$1>' . "\n" . $meta, $html, 1) ?: $html;
		});
	}

	private static function build_social_preview_meta_html_1_5_40(int $post_id, string $source = 'wp_head'): string {
		if (!$post_id || get_post_type($post_id) !== AM_CPT) {
			return '';
		}

		$title = get_the_title($post_id);
		$url = get_permalink($post_id);
		$url = is_string($url) ? set_url_scheme($url, 'https') : '';
		$description = self::build_social_preview_description($post_id);
		$image = self::get_social_preview_image_url($post_id);
		$image = $image !== '' ? set_url_scheme($image, 'https') : '';

		if ($description === '') {
			$description = sprintf(__('Scheda micologica: %s', 'archivio-micologico'), $title);
		}

		$am_social_image_id = 0;
		$am_img_width = 0;
		$am_img_height = 0;
		$am_img_alt = '';

		if ($image !== '') {
			$am_social_image_id = (int) get_post_thumbnail_id($post_id);
			if (!$am_social_image_id) {
				$am_gallery_ids = get_post_meta($post_id, 'am_gallery_ids', true);
				if (is_array($am_gallery_ids)) {
					$am_gallery_ids = array_values(array_filter(array_unique(array_map('absint', $am_gallery_ids))));
				} else {
					$am_gallery_ids = array_values(array_filter(array_unique(array_map('absint', preg_split('/[,\s]+/', (string) $am_gallery_ids)))));
				}
				$am_social_image_id = !empty($am_gallery_ids) ? (int) reset($am_gallery_ids) : 0;
			}

			if ($am_social_image_id) {
				$am_img_src = wp_get_attachment_image_src($am_social_image_id, 'large');
				if (is_array($am_img_src)) {
					$am_img_width = isset($am_img_src[1]) ? absint($am_img_src[1]) : 0;
					$am_img_height = isset($am_img_src[2]) ? absint($am_img_src[2]) : 0;
				}
				$am_img_alt = (string) get_post_meta($am_social_image_id, '_wp_attachment_image_alt', true);
				if ($am_img_alt === '') {
					$am_img_alt = (string) get_the_title($am_social_image_id);
				}
			}
		}

		$out = "\n<!-- Archivio Micologico social preview 1.5.40 source:" . esc_html($source) . " -->\n";
		$out .= '<meta property="og:type" content="article" />' . "\n";
		$out .= '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '" />' . "\n";
		$out .= '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
		$out .= '<meta property="og:description" content="' . esc_attr($description) . '" />' . "\n";
		$out .= '<meta property="og:url" content="' . esc_url($url) . '" />' . "\n";

		if ($image !== '') {
			$out .= '<meta property="og:image" content="' . esc_url($image) . '" />' . "\n";
			$out .= '<meta property="og:image:secure_url" content="' . esc_url($image) . '" />' . "\n";
			if ($am_img_width) {
				$out .= '<meta property="og:image:width" content="' . esc_attr((string) $am_img_width) . '" />' . "\n";
			}
			if ($am_img_height) {
				$out .= '<meta property="og:image:height" content="' . esc_attr((string) $am_img_height) . '" />' . "\n";
			}
			if ($am_img_alt !== '') {
				$out .= '<meta property="og:image:alt" content="' . esc_attr($am_img_alt) . '" />' . "\n";
			}
		}

		$out .= '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		$out .= '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
		$out .= '<meta name="twitter:description" content="' . esc_attr($description) . '" />' . "\n";

		if ($image !== '') {
			$out .= '<meta name="twitter:image" content="' . esc_url($image) . '" />' . "\n";
			if ($am_img_alt !== '') {
				$out .= '<meta name="twitter:image:alt" content="' . esc_attr($am_img_alt) . '" />' . "\n";
			}
		}

		$out .= "<!-- /Archivio Micologico social preview -->\n";

		if ((int) get_option('am_enable_debug_panel', 0) === 1 && function_exists('am_debug_funghi_force_output_event')) {
			am_debug_funghi_force_output_event('AM_SOCIAL_PREVIEW_1_5_40', [
				'post_id' => $post_id,
				'source' => $source,
				'has_image' => $image !== '' ? 1 : 0,
				'image_width' => $am_img_width,
				'image_height' => $am_img_height,
				'description_len' => strlen($description),
			]);
		}

		return $out;
	}

	private static function get_social_preview_image_url(int $post_id): string {
		$image_id = get_post_thumbnail_id($post_id);
		if ($image_id) {
			$image = wp_get_attachment_image_url($image_id, 'large');
			if (is_string($image) && $image !== '') {
				return $image;
			}

			$image = wp_get_attachment_image_url($image_id, 'full');
			if (is_string($image) && $image !== '') {
				return $image;
			}
		}

		// Fallback: first gallery image, if the scheda uses a gallery meta field.
		$gallery_meta_keys = ['am_gallery_ids', '_am_gallery_ids', 'am_gallery'];
		foreach ($gallery_meta_keys as $key) {
			$value = get_post_meta($post_id, $key, true);
			if (is_array($value)) {
				$ids = array_filter(array_map('absint', $value));
			} else {
				$ids = array_filter(array_map('absint', preg_split('/[,\s]+/', (string) $value)));
			}

			if (!empty($ids)) {
				$image = wp_get_attachment_image_url((int) reset($ids), 'large');
				if (is_string($image) && $image !== '') {
					return $image;
				}
			}
		}

		$content = (string) get_post_field('post_content', $post_id);
		if ($content !== '' && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m)) {
			$url = esc_url_raw((string) $m[1]);
			if ($url !== '') {
				return $url;
			}
		}

		return '';
	}


	private static function build_social_preview_description(int $post_id): string {
		// Primary source for social previews: the public macroscopic description saved as post_content.
		$content = (string) get_post_field('post_content', $post_id);
		$text = self::extract_macroscopic_section_from_content($content);
		if ($text === '') {
			$text = $content;
		}

		// Fallback: historical/meta fields. am_macro_micro is used in current rendering as microscopica,
		// so it must not override the public macroscopic description.
		if (trim((string) $text) === '') {
			$meta_keys = [
				'am_macro',
				'am_macroscopic_description',
				'am_descrizione_macroscopica',
				'_am_macro',
				'_am_descrizione_macroscopica',
				'am_morphology_macro',
				'am_macro_micro',
			];
			foreach ($meta_keys as $key) {
				$value = (string) get_post_meta($post_id, $key, true);
				if (trim($value) !== '') {
					$text = $value;
					break;
				}
			}
		}

		$text = strip_shortcodes((string) $text);
		$text = wp_strip_all_tags($text);
		$text = html_entity_decode($text, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
		$text = preg_replace('/\s+/', ' ', $text);
		$text = trim((string) $text);

		if ($text === '') {
			return '';
		}

		return self::first_two_sentence_like_lines($text);
	}

	private static function extract_macroscopic_section_from_content(string $content): string {
		if ($content === '') {
			return '';
		}

		$plain = wp_strip_all_tags($content);
		$plain = html_entity_decode($plain, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');

		$patterns = [
			'/descrizione\s+macroscopica\s*[:\-]?\s*(.+?)(?:descrizione\s+microscopica|microscopia|note|ecologia|commestibilit|osservazioni|$)/isu',
			'/macroscopica\s*[:\-]?\s*(.+?)(?:microscopica|microscopia|note|ecologia|commestibilit|osservazioni|$)/isu',
			'/macroscopia\s*[:\-]?\s*(.+?)(?:microscopia|note|ecologia|commestibilit|osservazioni|$)/isu',
		];

		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $plain, $matches) && !empty($matches[1])) {
				return trim((string) $matches[1]);
			}
		}

		return '';
	}

	private static function first_two_sentence_like_lines(string $text): string {
		$text = trim($text);
		if ($text === '') {
			return '';
		}

		$sentences = preg_split('/(?<=[.!?;])\s+/u', $text);
		if (is_array($sentences) && count($sentences) >= 2) {
			$out = trim($sentences[0] . ' ' . $sentences[1]);
			return wp_html_excerpt($out, 220, '...');
		}

		return wp_html_excerpt($text, 220, '...');
	}


	public static function render_seo_base_meta(): void {
		if (is_admin() || !defined('AM_CPT')) {
			return;
		}

		$post_id = self::resolve_current_fungo_id_for_meta_1_5_39();
		if (!$post_id || get_post_type($post_id) !== AM_CPT) {
			return;
		}

		if (!self::am_should_render_own_seo_meta_1_5_11($post_id)) {
			return;
		}

		$title = get_the_title($post_id);
		$url = get_permalink($post_id);
		$description = self::build_social_preview_description($post_id);
		if ($description === '') {
			$description = wp_trim_words(wp_strip_all_tags((string) get_post_field('post_content', $post_id)), 32, '...');
		}
		if ($description === '') {
			$description = sprintf(__('Scheda micologica: %s', 'archivio-micologico'), $title);
		}

		$image = self::get_social_preview_image_url($post_id);
		$keywords = self::build_seo_keywords($post_id);
		$schema = self::build_schema_org_for_fungo($post_id, $title, $description, $url, $image, $keywords);

		echo "\n<!-- Archivio Micologico SEO base 1.5.10 -->\n";
		echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
		echo '<link rel="canonical" href="' . esc_url($url) . '" />' . "\n";
		if ($keywords !== '') {
			echo '<meta name="keywords" content="' . esc_attr($keywords) . '" />' . "\n";
		}
		echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
		echo "<!-- /Archivio Micologico SEO base -->\n";

		if ((int) get_option('am_enable_debug_panel', 0) === 1 && function_exists('am_debug_funghi_force_output_event')) {
			am_debug_funghi_force_output_event('AM_SEO_BASE_1_5_10', [
				'post_id' => $post_id,
				'has_image' => $image !== '' ? 1 : 0,
				'description_len' => strlen($description),
				'keywords_len' => strlen($keywords),
			]);
		}
	}

	private static function build_seo_keywords(int $post_id): string {
		$keywords = [];

		$title = get_the_title($post_id);
		if ($title !== '') {
			$keywords[] = $title;
		}

		$taxonomies = get_object_taxonomies(AM_CPT, 'names');
		if (is_array($taxonomies)) {
			foreach ($taxonomies as $taxonomy) {
				$terms = get_the_terms($post_id, $taxonomy);
				if (is_array($terms)) {
					foreach ($terms as $term) {
						if (!empty($term->name)) {
							$keywords[] = $term->name;
						}
					}
				}
			}
		}

		$keywords[] = 'micologia';
		$keywords[] = 'funghi';
		$keywords[] = 'scheda micologica';
		$keywords[] = 'Archivio Micologico';

		$keywords = array_values(array_unique(array_filter(array_map(static function($value) {
			$value = wp_strip_all_tags((string) $value);
			$value = trim(preg_replace('/\s+/', ' ', $value));
			return $value;
		}, $keywords))));

		return implode(', ', array_slice($keywords, 0, 25));
	}

	private static function build_schema_org_for_fungo(int $post_id, string $title, string $description, string $url, string $image, string $keywords): array {
		$author_name = '';
		$author_id = (int) get_post_field('post_author', $post_id);
		if ($author_id > 0) {
			$user = get_userdata($author_id);
			if ($user) {
				$author_name = (string) $user->display_name;
			}
		}
		if ($author_name === '') {
			$author_name = get_bloginfo('name');
		}

		$schema = [
			'@context' => 'https://schema.org',
			'@type' => 'ScholarlyArticle',
			'headline' => $title,
			'description' => $description,
			'url' => $url,
			'datePublished' => get_the_date('c', $post_id),
			'dateModified' => get_the_modified_date('c', $post_id),
			'author' => [
				'@type' => 'Person',
				'name' => $author_name,
			],
			'publisher' => [
				'@type' => 'Organization',
				'name' => get_bloginfo('name'),
				'url' => home_url('/'),
			],
			'about' => [
				'@type' => 'Thing',
				'name' => $title,
			],
			'mainEntityOfPage' => [
				'@type' => 'WebPage',
				'@id' => $url,
			],
		];

		if ($image !== '') {
			$schema['image'] = [$image];
		}

		if ($keywords !== '') {
			$schema['keywords'] = $keywords;
		}

		$taxon_terms = self::get_taxon_terms_for_schema($post_id);
		if (!empty($taxon_terms)) {
			$schema['about'] = [
				'@type' => 'Taxon',
				'name' => $title,
				'taxonRank' => implode(', ', array_keys($taxon_terms)),
				'parentTaxon' => array_values($taxon_terms),
			];
		}

		return $schema;
	}

	private static function get_taxon_terms_for_schema(int $post_id): array {
		$out = [];
		$taxonomies = get_object_taxonomies(AM_CPT, 'objects');
		if (!is_array($taxonomies)) {
			return $out;
		}

		foreach ($taxonomies as $taxonomy => $object) {
			$terms = get_the_terms($post_id, (string) $taxonomy);
			if (!is_array($terms) || empty($terms)) {
				continue;
			}

			$label = isset($object->labels->singular_name) ? (string) $object->labels->singular_name : (string) $taxonomy;
			$names = [];
			foreach ($terms as $term) {
				if (!empty($term->name)) {
					$names[] = $term->name;
				}
			}

			if (!empty($names)) {
				$out[$label] = implode(', ', array_unique($names));
			}
		}

		return $out;
	}


	private static function am_should_render_own_seo_meta_1_5_11(int $post_id): bool {
		$mode = (string) get_option('am_seo_mode', 'auto');

		if ($mode === 'off') {
			self::debug_seo_compat_1_5_11($post_id, 'off', []);
			return false;
		}

		$plugins = self::detect_active_seo_plugins_1_5_11();

		if ($mode === 'force') {
			self::debug_seo_compat_1_5_11($post_id, 'force', $plugins);
			return true;
		}

		if (!empty($plugins)) {
			self::debug_seo_compat_1_5_11($post_id, 'skipped_plugin_detected', $plugins);
			return false;
		}

		self::debug_seo_compat_1_5_11($post_id, 'auto_render', []);
		return true;
	}

	private static function detect_active_seo_plugins_1_5_11(): array {
		$active = [];

		if (defined('WPSEO_VERSION') || class_exists('WPSEO_Frontend') || defined('WPSEO_FILE')) {
			$active[] = 'Yoast SEO';
		}

		if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
			$active[] = 'Rank Math';
		}

		if (defined('SEOPRESS_VERSION') || function_exists('seopress_init') || class_exists('SEOPress')) {
			$active[] = 'SEOPress';
		}

		if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin\\AIOSEO') || function_exists('aioseo')) {
			$active[] = 'All in One SEO';
		}

		return array_values(array_unique($active));
	}

	private static function debug_seo_compat_1_5_11(int $post_id, string $status, array $plugins): void {
		if ((int) get_option('am_enable_debug_panel', 0) !== 1 || !function_exists('am_debug_funghi_force_output_event')) {
			return;
		}

		am_debug_funghi_force_output_event('AM_SEO_COMPAT_1_5_11', [
			'post_id' => $post_id,
			'mode' => (string) get_option('am_seo_mode', 'auto'),
			'status' => $status,
			'plugins' => $plugins,
		]);
	}


	public static function render_shortcode_pages_seo_meta(): void {
		if (is_admin() || !is_singular()) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if (!$post_id) {
			return;
		}

		// Do not duplicate single scheda SEO.
		if (defined('AM_CPT') && get_post_type($post_id) === AM_CPT) {
			return;
		}

		if (!self::am_should_render_own_seo_meta_1_5_11($post_id)) {
			return;
		}

		$post = get_post($post_id);
		if (!$post instanceof WP_Post) {
			return;
		}

		$shortcodes = self::detect_am_shortcodes_in_content_1_5_12((string) $post->post_content);
		if (empty($shortcodes)) {
			return;
		}

		$title = get_the_title($post_id);
		$url = self::clean_canonical_url_1_5_12($post_id);
		$description = self::build_shortcode_page_description_1_5_12($post_id, $shortcodes);
		$schema = self::build_shortcode_page_schema_1_5_12($post_id, $title, $description, $url, $shortcodes);

		echo "\n<!-- Archivio Micologico shortcode page SEO 1.5.12 -->\n";
		echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
		echo '<link rel="canonical" href="' . esc_url($url) . '" />' . "\n";
		echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
		echo "<!-- /Archivio Micologico shortcode page SEO -->\n";

		if ((int) get_option('am_enable_debug_panel', 0) === 1 && function_exists('am_debug_funghi_force_output_event')) {
			am_debug_funghi_force_output_event('AM_SHORTCODE_PAGE_SEO_1_5_12', [
				'post_id' => $post_id,
				'shortcodes' => $shortcodes,
				'description_len' => strlen($description),
			]);
		}
	}

	private static function detect_am_shortcodes_in_content_1_5_12(string $content): array {
		$map = [
			'am_archive_filters' => 'filters',
			'am_archive' => 'archive',
			'am_search_page' => 'search',
			'am_submit_form' => 'submit',
			'am_edit_form' => 'edit',
			'am_article_form' => 'article_form',
			'am_random_funghi' => 'random',
		];

		$found = [];
		foreach ($map as $shortcode => $type) {
			if (has_shortcode($content, $shortcode)) {
				$found[$shortcode] = $type;
			}
		}

		return $found;
	}

	private static function clean_canonical_url_1_5_12(int $post_id): string {
		$url = get_permalink($post_id);
		if (!is_string($url) || $url === '') {
			return home_url('/');
		}

		return remove_query_arg([
			'am_s',
			'am_family',
			'am_genus',
			'am_species',
			'am_page',
			'paged',
			'_gl',
			'fbclid',
			'gclid',
		], $url);
	}

	private static function build_shortcode_page_description_1_5_12(int $post_id, array $shortcodes): string {
		$title = get_the_title($post_id);
		$types = array_values($shortcodes);

		if (in_array('search', $types, true)) {
			return sprintf(__('Pagina di ricerca dell’Archivio Micologico: consulta le schede fungo disponibili per nome, genere, specie e contenuti descrittivi.', 'archivio-micologico'), $title);
		}

		if (in_array('archive', $types, true) || in_array('filters', $types, true)) {
			return sprintf(__('Archivio Micologico: elenco e filtri tassonomici delle schede fungo, organizzate per famiglia, genere e specie.', 'archivio-micologico'), $title);
		}

		if (in_array('submit', $types, true)) {
			return __('Pagina riservata all’inserimento di nuove schede fungo nell’Archivio Micologico.', 'archivio-micologico');
		}

		if (in_array('edit', $types, true)) {
			return __('Pagina riservata alla modifica delle schede fungo dell’Archivio Micologico.', 'archivio-micologico');
		}

		if (in_array('article_form', $types, true)) {
			return __('Pagina riservata all’inserimento di articoli nell’Archivio Micologico.', 'archivio-micologico');
		}

		if (in_array('random', $types, true)) {
			return __('Selezione di schede fungo casuali dall’Archivio Micologico.', 'archivio-micologico');
		}

		return sprintf(__('Pagina dell’Archivio Micologico: %s.', 'archivio-micologico'), $title);
	}

	private static function build_shortcode_page_schema_1_5_12(int $post_id, string $title, string $description, string $url, array $shortcodes): array {
		$types = array_values($shortcodes);
		$type = in_array('search', $types, true) ? 'SearchResultsPage' : 'CollectionPage';

		if (in_array('submit', $types, true) || in_array('edit', $types, true) || in_array('article_form', $types, true)) {
			$type = 'WebPage';
		}

		$schema = [
			'@context' => 'https://schema.org',
			'@type' => $type,
			'name' => $title,
			'description' => $description,
			'url' => $url,
			'isPartOf' => [
				'@type' => 'WebSite',
				'name' => get_bloginfo('name'),
				'url' => home_url('/'),
			],
			'publisher' => [
				'@type' => 'Organization',
				'name' => get_bloginfo('name'),
				'url' => home_url('/'),
			],
		];

		if (in_array('archive', $types, true) || in_array('filters', $types, true) || in_array('random', $types, true)) {
			$schema['about'] = [
				'@type' => 'Thing',
				'name' => __('Archivio Micologico e schede fungo', 'archivio-micologico'),
			];
		}

		return $schema;
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
