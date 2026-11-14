<?php

namespace ArchivioMicologico;

use WP_Post;
use WP_Query;
use WP_Term;

if (!defined('ABSPATH')) { exit; }

class Frontend {
	/**
	 * Filter post titles on single fungus pages.
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
		return SpeciesTitle::format($title);
	}

	const NONCE_ACTION = EntryForms::NONCE_ACTION;
	const NONCE_ACTION_UPDATE = EntryForms::NONCE_ACTION_UPDATE;
	const NONCE_NAME = EntryForms::NONCE_NAME;

	public static function am_get_sticky(string $token): array {
		return EntryForms::am_get_sticky($token);
	}

	public static function register_widgets(): void {
		register_widget('AM_Widget_Random_Funghi');
	}

	public static function render_random_funghi_shortcode($atts = []): string {
		return RandomFungiCarousel::shortcode($atts);
	}

	public static function get_random_funghi_markup(int $count = 5, string $title = '', array $args = []): string {
		return RandomFungiCarousel::render($count, $title, $args);
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
		// Format title (Genus species bold+italic) also for the theme output
		add_filter('the_title', [__CLASS__, 'filter_the_title'], 10, 2);

		add_shortcode('am_submit_form', [__CLASS__, 'render_submit_form']);
		add_shortcode('am_article_form', [__CLASS__, 'render_article_form']);
		add_shortcode('am_edit_form', [__CLASS__, 'render_edit_form']);
		add_shortcode('am_archive_filters', [__CLASS__, 'render_archive_filters']);
		add_shortcode('am_archive', [__CLASS__, 'render_archive']);
		add_shortcode('am_search_page', [__CLASS__, 'render_search_page']);
		add_shortcode('am_random_funghi', [RandomFungiCarousel::class, 'shortcode']);
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
		EntryForms::ajax_save_review();
	}

	public static function enqueue_assets(bool $for_carousel = false): void {
		$should_load = $for_carousel || is_active_widget(false, false, 'am_widget_random_funghi');
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
			strpos((string) $post->post_content, '[am_search_page') !== false ||
			strpos((string) $post->post_content, '[am_random_funghi') !== false
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

		wp_enqueue_style('am-frontend', AM_URL . 'assets/css/frontend.css', [], AM_VERSION . '.' . @filemtime(AM_PATH . 'assets/css/frontend.css'));
		// JS: format title (Genere+specie in corsivo+grassetto).
		wp_enqueue_script('am-title-format', AM_URL . 'assets/js/title-format.js', array(), AM_VERSION, true);
wp_add_inline_style('am-frontend', '.am-gallery--journal{width:100%!important;display:flex!important;flex-direction:column!important;gap:10px!important}.am-gallery--journal .am-entry-gallery__figure{width:100%!important;margin:0!important;padding:0 0 10px!important;border-bottom:1px solid rgba(0,0,0,.08)!important;text-align:left!important}.am-gallery--journal .am-entry-gallery__figure:last-child{border-bottom:0!important;padding-bottom:0!important}.am-gallery--journal img{width:100%!important;max-width:100%!important;height:auto!important;display:block!important;margin:0!important;border-radius:12px!important;box-shadow:0 1px 10px rgba(0,0,0,.08)!important}.am-gallery--journal figure.am-entry-gallery__figure figcaption.am-entry-caption{display:block!important;width:100%!important;text-align:left!important;margin:6px 0 0!important;font-size:16px!important;line-height:1.4!important;color:rgba(0,0,0,.65)!important}.am-gallery--journal .am-entry-caption p{margin:0!important;padding:0!important}.am-entry-gallery.am-gallery--journal figure.am-entry-gallery__figure{text-align:left!important}.am-entry-gallery.am-gallery--journal figure.am-entry-gallery__figure>figcaption.am-entry-caption{display:block!important;width:100%!important;text-align:left!important;direction:ltr!important}.am-entry-gallery figure.am-entry-gallery__figure{text-align:left!important}.am-entry-gallery figure.am-entry-gallery__figure figcaption.am-entry-caption{display:block!important;width:100%!important;text-align:left!important;}.am-entry-gallery .am-entry-caption{display:block!important;width:100%!important;text-align:left!important;}.am-entry-gallery figure.am-entry-gallery__figure{display:block!important;text-align:left!important}.am-entry-gallery figure.am-entry-gallery__figure>figcaption.am-entry-caption{display:block!important;width:100%!important;text-align:left!important;margin-left:0!important;margin-right:auto!important;align-self:flex-start!important;justify-self:start!important;float:none!important;}.am-entry-gallery img,.am-entry-featured img,.am-entry-preview img{height:auto!important;max-height:none!important;width:100%!important;max-width:100%!important;object-fit:contain!important;object-position:center center!important}.am-entry-gallery__item,.am-entry-gallery__figure,.am-entry-featured,.am-entry-preview,.am-entry-featured figure,.am-entry-preview figure{height:auto!important;max-height:none!important;min-height:0!important;background:transparent!important;padding:0!important;margin:0!important;line-height:0!important}.am-entry-gallery img,.am-entry-featured img,.am-entry-preview img{width:auto!important;max-width:100%!important;height:auto!important;max-height:none!important;aspect-ratio:auto!important;object-fit:contain!important;object-position:center center!important;display:block!important;margin:0!important}.am-entry-gallery .am-gallery-image,.am-entry-gallery__item .am-gallery-image{width:auto!important;max-width:100%!important;height:auto!important;min-height:0!important;max-height:none!important;aspect-ratio:auto!important;object-fit:contain!important;object-position:center center!important;display:block!important}.am-entry-gallery__item,.am-entry-gallery__figure{overflow:hidden!important}.am-entry-title .am-taxon-gs{font-weight:700!important}.am-entry-title .am-taxon-gs i{font-style:italic!important;font-weight:700!important}');
wp_enqueue_script('am-frontend', AM_URL . 'assets/js/frontend.js', ['jquery'], AM_VERSION . '.' . @filemtime(AM_PATH . 'assets/js/frontend.js'), true);

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
			'canAddTerms' => EntryForms::am_current_user_is_admin_or_editor(),
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
		EntryForms::ajax_add_term();
	}

	public static function render_submit_form($atts = []): string {
		return EntryForms::render_submit_form($atts);
	}

	public static function render_article_form($atts = []): string {
		return EntryForms::render_article_form($atts);
	}

	public static function render_edit_form($atts = []): string {
		return EntryForms::render_edit_form($atts);
	}

	public static function render_archive_filters($atts = []): string {
		return Archive::render_archive_filters($atts);
	}

	public static function render_archive($atts = []): string {
		return Archive::render_archive($atts);
	}

	public static function ajax_get_terms(): void {
		EntryForms::ajax_get_terms();
	}

	public static function handle_submit(): void {
		EntryForms::handle_submit();
	}

	public static function handle_submit_article(): void {
		EntryForms::handle_submit_article();
	}

	public static function handle_update(): void {
		EntryForms::handle_update();
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

	/**
	 * Display title for Scheda Fungo:
	 * Genere + Specie + (Forma/Varietà) + (Autore)
	 */

private static function compute_single_display_title(int $post_id): string {
	$p = SpeciesTitle::post_parts($post_id);
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
		$display_title_html = SpeciesTitle::render_post((int) $post->ID);

		// Titolo scheda: per compatibilita' con i template che NON mostrano il titolo del CPT,
		// lo stampiamo anche qui (in modo controllabile).
		$show_title_inside = (int) get_option('am_single_show_title', 1);
		$show_title_inside = (int) apply_filters('am_single_show_title', $show_title_inside, (int) $post->ID);
		$title_heading_html = '';
		if ($show_title_inside === 1 && trim($display_title_plain) !== '') {
			$title_heading_html = '<h1 class="am-entry-title">' . wp_kses($display_title_html !== '' ? $display_title_html : esc_html($display_title_plain), ['span'=>['class'=>true],'i'=>[]]) . '</h1>';
		}

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
				/* translators: %s: name of the mushroom entry author. */
				$author_credit = sprintf(__('Testo di %s', 'archivio-micologico'), $author_name);
				$reviewer = (string) get_post_meta((int)$post->ID, 'am_last_reviewer_name', true);
				$review_dt = (string) get_post_meta((int)$post->ID, 'am_last_review_datetime', true);
				$review_html = '';
				if (trim($reviewer) !== '' && trim($review_dt) !== '') {
					$review_html = ' <span class="am-entry-review" style="margin-left:10px; opacity:.9;">' . esc_html(sprintf(__('Revisione: %1$s (%2$s)', 'archivio-micologico'), $reviewer, $review_dt)) . '</span>';
				}
				$title_html .= '<div class="am-entry-wp-author" style="margin:0 0 4px 0; display:flex; align-items:center; gap:8px;">' . '<svg width="28" height="28" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink:0; opacity:0.45;">' . '<circle cx="8" cy="5" r="3" stroke="currentColor" stroke-width="1.5"/>' . '<path d="M2 14c0-3.314 2.686-5 6-5s6 1.686 6 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>' . '</svg>' . '<span style="font-size:16px; opacity:.7;">' . esc_html($author_credit) . '</span>' . $review_html . '</div>';
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
				. '<div class="am-entry-section__content">' . wpautop(EditorContent::sanitize($macro_micro)) . '</div>'
				. '</section>';
		}

		$notes = (string) get_post_meta($post->ID, 'am_notes', true);
		$notes_html = '';
		if ($notes !== '') {
			$notes_html = '<section class="am-entry-section am-entry-section--notes" style="margin-bottom:20px;">'
				. '<h2>' . esc_html__('Commenti e note', 'archivio-micologico') . '</h2>'
				. '<div class="am-entry-section__content">' . wpautop(EditorContent::sanitize($notes)) . '</div>'
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

	public static function render_search_page($atts = []): string {
		return Archive::render_search_page($atts);
	}

	/**
	 * Fallback (no-JS) term creation for front-end only.
	 * Currently used mainly for creating "Ordine" when JS/AJAX is blocked by cache/minify or hosting rules.
	 */
	public static function handle_add_term_post(): void {
		EntryForms::handle_add_term_post();
	}

	/**
	 * Lightweight on-screen debug for managed hosting (no access to wp-config.php).
	 * Enable by adding ?am_debug=1 to the front-end "Nuovo Inserimento" URL (admin only).
	 */

	public static function process_front_post(): void {
		EntryForms::process_front_post();
	}

	public static function handle_front_delete(): void {
		EntryForms::handle_front_delete();
	}

	/** Single deletion path for admin-post and front-end fallback requests. */
	public static function handle_delete(): void {
		EntryForms::handle_delete();
	}

	public static function handle_admin_post_update(): void {
		EntryForms::handle_admin_post_update();
	}

	public static function ajax_search_more(): void {
		Archive::ajax_search_more();
	}

	public static function ajax_archive_tree(): void {
		Archive::ajax_archive_tree();
	}

	public static function ajax_archive_search(): void {
		Archive::ajax_archive_search();
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

}
