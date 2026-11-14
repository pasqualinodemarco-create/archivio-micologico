<?php

namespace ArchivioMicologico;

use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) { exit; }

class Archive {
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
		$has_archive_shortcode = ($post instanceof WP_Post) && has_shortcode((string) $post->post_content, 'am_archive');
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
	self::render_recent_archive_entries();
		echo '<div class="am-archive__species-summary" role="status" aria-label="' . esc_attr__('Specie presenti nell’archivio', 'archivio-micologico') . '">';
		echo '<span class="am-archive__species-summary-icon" aria-hidden="true">🍄</span>';
		echo '<span class="am-archive__species-summary-label">' . esc_html__('Specie presenti nell’archivio', 'archivio-micologico') . '</span>';
		echo '<span class="am-archive__species-summary-count">' . esc_html((string) $total) . '</span>';
		echo '</div>';
		echo '<div class="am-archive__sentinel" aria-hidden="true"></div>';
		echo '</div>';
		return ob_get_clean();
	}

	private static function render_recent_archive_entries(): void {
		$count = max(0, min(20, (int) get_option('am_recent_entries_count', 5)));
		if ($count === 0) {
			return;
		}

		$recent = new WP_Query([
			'post_type' => AM_CPT,
			'post_status' => 'publish',
			'has_password' => false,
			'posts_per_page' => $count,
			'orderby' => ['date' => 'DESC', 'ID' => 'DESC'],
			'ignore_sticky_posts' => true,
			'no_found_rows' => true,
		]);

		echo '<details class="am-archive-branch am-archive__recent" id="ultime-schede">';
		echo '<summary class="am-archive-node am-archive-node--division">';
		echo '<span class="am-archive-node__chev" aria-hidden="true">▸</span>';
		echo '<span class="am-archive-node__label">' . esc_html__('Ultime schede pubblicate', 'archivio-micologico') . '</span>';
		echo '</summary>';

		if (!$recent->posts) {
			echo '<p class="am-archive-empty">' . esc_html__('Nessuna scheda pubblicata.', 'archivio-micologico') . '</p>';
		} else {
			echo '<div class="am-archive-entries">';
			foreach ($recent->posts as $entry) {
				echo '<a class="am-archive-entry" href="' . esc_url(get_permalink($entry)) . '">';
				echo '<span class="am-archive-entry__row">';
				echo get_the_post_thumbnail($entry, 'thumbnail', ['class' => 'am-archive-entry__thumb', 'alt' => '', 'loading' => 'lazy']);
				echo '<span class="am-archive-entry__body">';
				echo '<span class="am-archive-entry__title">' . SpeciesTitle::format(wp_strip_all_tags(get_the_title($entry))) . '</span>';
				echo '<time class="am-archive-entry__meta" datetime="' . esc_attr(get_the_date(DATE_W3C, $entry)) . '">' . esc_html(get_the_date('', $entry)) . '</time>';
				echo '</span></span></a>';
			}
			echo '</div>';
		}

		echo '</details>';
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
		$query = new WP_Query([
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
				echo '<h3 class="am-archive-card__title"><a href="' . esc_url(get_permalink($post_id)) . '">' . wp_kses(SpeciesTitle::render_post($post_id), ['span' => ['class' => true], 'i' => []]) . '</a></h3>';
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

		// Support external search widgets that submit with the standard WP param "s".
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
			$query = self::search_entries($search_term, 1, max(1, (int) $atts['per_page']));
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
					echo '<h3 class="am-archive-card__title"><a href="' . esc_url($perma) . '">' . wp_kses(SpeciesTitle::render_post((int)get_the_ID()), ['span'=>['class'=>true],'i'=>[]]) . '</a></h3>';$is_article = (get_post_type(get_the_ID()) === AM_ARTICLE_CPT);
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
	private static function search_entries(string $term, int $page, int $per_page): WP_Query {
		global $wpdb;
		$query = new WP_Query();
		$extend_search = static function (string $search, WP_Query $current) use ($query, $term, $wpdb): string {
			if ($current !== $query || $term === '' || trim($search) === '') {
				return $search;
			}
			$native = preg_replace('/^\s*AND\s+/i', '', $search);
			$like = '%' . $wpdb->esc_like($term) . '%';
			$extra = $wpdb->prepare(
				"EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} pm
					WHERE pm.post_id = {$wpdb->posts}.ID
					AND pm.meta_key IN ('am_infra', 'am_author') AND pm.meta_value LIKE %s
				) OR EXISTS (
					SELECT 1 FROM {$wpdb->term_relationships} tr
					INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
					WHERE tr.object_id = {$wpdb->posts}.ID
					AND tt.taxonomy IN (%s, %s, %s, %s) AND t.name LIKE %s
				)",
				$like, AM_TAX_CLASSIFICAZIONE, AM_TAX_INFRASPEC, AM_TAX_AUTHOR, AM_TAX_ARTICLE_CAT, $like
			);
			return " AND (($native) OR ($extra)) ";
		};

		add_filter('posts_search', $extend_search, 10, 2);
		try {
			$query->query([
				'post_type' => [AM_CPT, AM_ARTICLE_CPT],
				'post_status' => 'publish',
				'has_password' => false,
				'posts_per_page' => $per_page,
				'paged' => $page,
				'orderby' => ['title' => 'ASC', 'ID' => 'ASC'],
				's' => $term,
			]);
		} finally {
			remove_filter('posts_search', $extend_search, 10);
		}
		return $query;
	}

	public static function ajax_search_more(): void {
		if (!defined('ABSPATH')) { wp_send_json_error(['msg' => 'no_abspath']); }

		$term     = isset($_REQUEST['q']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['q'])) : '';
		$page     = isset($_REQUEST['page']) ? max(1, (int) $_REQUEST['page']) : 1;
		$per_page = isset($_REQUEST['per_page']) ? max(1, (int) $_REQUEST['per_page']) : 50;

		$q = self::search_entries($term, $page, $per_page);

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

				echo '<h3 class="am-archive-card__title"><a href="' . esc_url($perma) . '">' . wp_kses(SpeciesTitle::render_post((int)get_the_ID()), ['span'=>['class'=>true],'i'=>[]]) . '</a></h3>';$is_article = (get_post_type(get_the_ID()) === AM_ARTICLE_CPT);
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
		$q = new WP_Query([
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
			$q = new WP_Query([
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

			$q = new WP_Query([
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

		$q = self::search_entries($s, 1, 80);

		$items = [];
		foreach ((array) $q->posts as $p) {
			$forma  = (string) get_post_meta($p->ID, 'am_infra', true);
			$autore = (string) get_post_meta($p->ID, 'am_author', true);
			$thumb  = (string) get_the_post_thumbnail_url($p->ID, 'thumbnail');

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

		wp_send_json_success(['items' => $items]);
	}
}
