<?php

namespace ArchivioMicologico;

use WP_Post;

if (!defined('ABSPATH')) { exit; }

/**
 * Admin screens of Archivio Micologico: dashboard, taxonomy, vocabularies, settings and
 * instructions. Schede are created and edited with the front-end shortcodes; the admin side
 * is the control room: what is in the archive, what needs attention and where to fix it.
 */
final class AdminPages {
	/** Schede that still exist for the editors (everything except trash and auto-draft). */
	private const ACTIVE = ['publish', 'pending', 'draft', 'private', 'future'];

	/* ----------------------------------------------------------- building blocks */

	private static function open(string $title, string $intro, string $actions = ''): void {
		echo '<div class="wrap am-admin">';
		echo '<header class="am-admin__head"><div class="am-admin__head-text">';
		echo '<p class="am-admin__eyebrow">' . esc_html__('Archivio Micologico', 'archivio-micologico') . ' <span>v' . esc_html(AM_VERSION) . '</span></p>';
		echo '<h1>' . esc_html($title) . '</h1>';
		if ($intro !== '') { echo '<p class="am-admin__intro">' . esc_html($intro) . '</p>'; }
		echo '</div>';
		if ($actions !== '') { echo '<div class="am-admin__head-actions">' . $actions . '</div>'; }
		echo '</header><hr class="wp-header-end">';
	}

	private static function close(): void {
		echo '</div>';
		// Copy buttons ([data-am-copy]): clipboard API, with a selection fallback on plain http.
		echo "<script>document.addEventListener('click',function(e){var b=e.target.closest('[data-am-copy]');if(!b){return;}e.preventDefault();var t=b.getAttribute('data-am-copy'),l=b.textContent;"
			. "function ok(){b.textContent=b.getAttribute('data-am-done');b.classList.add('is-copied');setTimeout(function(){b.textContent=l;b.classList.remove('is-copied');},1800);}"
			. "function old(){var a=document.createElement('textarea');a.value=t;a.setAttribute('readonly','');a.style.position='fixed';a.style.opacity='0';document.body.appendChild(a);a.select();try{document.execCommand('copy');ok();}catch(x){}a.remove();}"
			. "if(navigator.clipboard&&window.isSecureContext){navigator.clipboard.writeText(t).then(ok,old);}else{old();}});</script>";
	}

	/** A shortcode with its "Copia shortcode" button. */
	public static function copy_code(string $code): string {
		return '<span class="am-copy"><code>' . esc_html($code) . '</code>'
			. '<button type="button" class="button button-small am-copy__btn" data-am-copy="' . esc_attr($code) . '" data-am-done="' . esc_attr__('Copiato ✓', 'archivio-micologico') . '" aria-label="' . esc_attr(sprintf(__('Copia lo shortcode %s', 'archivio-micologico'), $code)) . '">' . esc_html__('Copia shortcode', 'archivio-micologico') . '</button></span>';
	}

	private static function button(string $label, string $url, bool $primary = false, bool $external = false): string {
		if ($url === '') { return ''; }
		return '<a class="button' . ($primary ? ' button-primary' : '') . '" href="' . esc_url($url) . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . esc_html($label) . '</a>';
	}

	/**
	 * Pages of the plugin: key => [option, shortcode, label, what it is used for].
	 * Option 0 means automatic: the newest page holding the shortcode ("edit" has no automatic mode).
	 */
	public static function plugin_pages(): array {
		return [
			'archive' => ['am_page_archive', 'am_archive', __('Archivio micologico', 'archivio-micologico'), __('Pagina pubblica con l’albero delle classificazioni, la ricerca, le ultime schede e gli articoli. È la destinazione di “Torna all’archivio”, delle briciole di pane delle schede e del ritorno dopo l’eliminazione di un articolo.', 'archivio-micologico')],
			'submit' => ['am_page_submit', 'am_submit_form', __('Nuovo inserimento', 'archivio-micologico'), __('Modulo per creare una nuova scheda fungo. Usata dal pulsante “+ Nuova scheda” della Dashboard.', 'archivio-micologico')],
			'edit' => ['am_edit_page_id', 'am_edit_form', __('Modifica scheda', 'archivio-micologico'), __('Modulo per correggere una scheda esistente. Senza questa pagina non compare il pulsante “Modifica scheda” e la Dashboard apre l’editor di WordPress.', 'archivio-micologico')],
			'article' => ['am_page_article', 'am_article_form', __('Nuovo articolo', 'archivio-micologico'), __('Modulo per pubblicare un articolo scientifico, con la gestione delle categorie. Collegato dalla Dashboard.', 'archivio-micologico')],
			'taxonomy' => ['am_page_taxonomy', 'am_tassonomia', __('Tassonomia', 'archivio-micologico'), __('Gestione pubblica di divisioni, famiglie, generi e specie. Aperta dal pulsante “Pagina pubblica” in Archivio Micologico → Tassonomia.', 'archivio-micologico')],
			'vocab' => ['am_page_vocab', 'am_vocabolari', __('Vocabolari', 'archivio-micologico'), __('Gestione pubblica di forme, varietà e autori. Aperta dal pulsante “Pagina pubblica” in Archivio Micologico → Vocabolari.', 'archivio-micologico')],
			'search' => ['am_page_search', 'am_search_page', __('Ricerca', 'archivio-micologico'), __('Pagina facoltativa con i risultati di ricerca paginati; l’archivio ha già la sua casella di ricerca.', 'archivio-micologico')],
		];
	}

	/** URL of a plugin page: the one chosen in the settings, else the automatic one. */
	public static function page_url(string $key): string {
		$map = self::plugin_pages();
		if (!isset($map[$key])) { return ''; }
		[$option, $tag] = $map[$key];
		$id = (int) get_option($option, 0);
		if ($id && get_post_type($id) === 'page' && in_array(get_post_status($id), ['publish', 'private'], true)) {
			$url = get_permalink($id);
			if (is_string($url) && $url !== '') { return $url; }
		}
		return $key === 'edit' ? '' : self::page_with($tag);
	}

	/** Permalink of the newest page that holds the shortcode, or '' when there is none. */
	public static function page_with(string $tag): string {
		global $wpdb;
		$id = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status IN ('publish','private')
			 AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY post_date DESC LIMIT 1",
			'%' . $wpdb->esc_like('[' . $tag . ']') . '%',
			'%' . $wpdb->esc_like('[' . $tag . ' ') . '%'
		));
		$url = $id ? get_permalink($id) : '';
		return is_string($url) ? $url : '';
	}

	private static function list_url(string $status = ''): string {
		$args = ['post_type' => AM_CPT];
		if ($status !== '') { $args['post_status'] = $status; }
		return add_query_arg($args, admin_url('edit.php'));
	}

	/** The front-end edit form when it is configured (it is the complete one), else the WordPress editor. */
	private static function edit_url(int $post_id): string {
		$page = (int) get_option('am_edit_page_id', 0);
		$url = $page ? get_permalink($page) : '';
		if (is_string($url) && $url !== '') { return add_query_arg('am_edit', $post_id, $url); }
		return (string) get_edit_post_link($post_id, 'raw');
	}

	/* ------------------------------------------------------------------ dashboard */

	/** One pass over the active schede: what each one is missing, and titles used more than once. */
	private static function quality(): array {
		global $wpdb;
		$in = "'" . implode("','", self::ACTIVE) . "'";
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT p.ID, p.post_title,
			 (SELECT COUNT(*) FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_thumbnail_id' AND m.meta_value NOT IN ('', '0')) AS img,
			 (SELECT COUNT(*) FROM {$wpdb->term_relationships} r INNER JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id WHERE r.object_id = p.ID AND t.taxonomy = %s) AS cls,
			 (SELECT COUNT(*) FROM {$wpdb->term_relationships} r INNER JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id = r.term_taxonomy_id WHERE r.object_id = p.ID AND t.taxonomy = %s) AS aut
			 FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status IN ($in) ORDER BY p.post_date DESC",
			AM_TAX_CLASSIFICAZIONE,
			AM_TAX_AUTHOR,
			AM_CPT
		), ARRAY_A) ?: [];

		$out = ['no_image' => [], 'no_class' => [], 'no_author' => [], 'duplicates' => []];
		$titles = [];
		foreach ($rows as $r) {
			$item = [(int) $r['ID'], (string) $r['post_title']];
			if (!(int) $r['img']) { $out['no_image'][] = $item; }
			if (!(int) $r['cls']) { $out['no_class'][] = $item; }
			if (!(int) $r['aut']) { $out['no_author'][] = $item; }
			$key = strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $r['post_title'])));
			if ($key !== '') { $titles[$key][] = $item; }
		}
		foreach ($titles as $group) {
			if (count($group) > 1) { $out['duplicates'] = array_merge($out['duplicates'], $group); }
		}

		$out['unused_terms'] = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy IN (%s, %s)
			 AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} r WHERE r.term_taxonomy_id = tt.term_taxonomy_id)",
			AM_TAX_INFRASPEC,
			AM_TAX_AUTHOR
		));
		return $out;
	}

	private static function author_counts(): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT post_author, COUNT(*) AS n FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'
			 GROUP BY post_author ORDER BY n DESC, post_author ASC LIMIT 8",
			AM_CPT
		), ARRAY_A) ?: [];
		$out = [];
		foreach ($rows as $r) {
			$name = (string) get_the_author_meta('display_name', (int) $r['post_author']);
			$out[] = [$name !== '' ? $name : __('Utente non più presente', 'archivio-micologico'), (int) $r['n']];
		}
		return $out;
	}

	private static function kpi(string $label, string $value, string $note, string $url, string $class = ''): void {
		echo '<a class="am-kpi ' . esc_attr($class) . '" href="' . esc_url($url) . '">';
		echo '<span class="am-kpi__label">' . esc_html($label) . '</span>';
		echo '<span class="am-kpi__value">' . esc_html($value) . '</span>';
		echo '<span class="am-kpi__note">' . esc_html($note) . '</span></a>';
	}

	private static function check(string $label, string $hint, array $items, string $action_label, string $action_url, bool $notice_only = false, bool $wp_editor = false): void {
		$n = count($items);
		$state = $n === 0 ? 'ok' : ($notice_only ? 'info' : 'warn');
		echo '<li class="am-check am-check--' . esc_attr($state) . '">';
		echo '<span class="am-check__count" aria-hidden="true">' . ($n === 0 ? '✓' : esc_html(number_format_i18n($n))) . '</span>';
		echo '<div class="am-check__body"><strong>' . esc_html($label) . '</strong>';
		echo '<span class="screen-reader-text">: ' . esc_html(number_format_i18n($n)) . '</span>';
		echo '<p>' . esc_html($n === 0 ? __('Nessun caso: tutto in ordine.', 'archivio-micologico') : $hint) . '</p>';
		if ($n > 0 && !empty($items[0][0])) {
			echo '<details><summary>' . esc_html__('Mostra le schede', 'archivio-micologico') . '</summary><ul>';
			foreach (array_slice($items, 0, 12) as [$id, $title]) {
				echo '<li><a href="' . esc_url($wp_editor ? (string) get_edit_post_link($id, 'raw') : self::edit_url($id)) . '">' . esc_html($title !== '' ? $title : __('(senza titolo)', 'archivio-micologico')) . '</a></li>';
			}
			if ($n > 12) { echo '<li class="am-check__more">' . esc_html(sprintf(__('e altre %d', 'archivio-micologico'), $n - 12)) . '</li>'; }
			echo '</ul></details>';
		}
		echo '</div>';
		if ($n > 0 && $action_url !== '') {
			echo '<a class="am-check__action" href="' . esc_url($action_url) . '">' . esc_html($action_label) . '</a>';
		}
		echo '</li>';
	}

	public static function dashboard(): void {
		if (!current_user_can(AM_CAP_EDIT_POSTS)) {
			wp_die(esc_html__('Non hai il permesso di accedere a questa pagina.', 'archivio-micologico'));
		}
		$counts = wp_count_posts(AM_CPT);
		$count = static fn(string $status): int => (int) ($counts->$status ?? 0);
		$articles = (int) (wp_count_posts(AM_ARTICLE_CPT)->publish ?? 0);
		$coverage = TermManager::coverage();
		$ranks = $coverage['ranks'];
		$labels = $coverage['labels'];
		$quality = self::quality();
		$pages = [
			'submit' => self::page_url('submit'),
			'article' => self::page_url('article'),
			'archive' => self::page_url('archive'),
			'taxonomy' => self::page_url('taxonomy'),
			'vocab' => self::page_url('vocab'),
		];
		$tax_url = admin_url('admin.php?page=am_classificazione');
		$vocab_url = admin_url('admin.php?page=am_vocabolari');

		self::open(
			__('Dashboard', 'archivio-micologico'),
			__('Lo stato dell’archivio in un colpo d’occhio: quante schede ci sono, quanta parte della tassonomia è documentata e cosa va sistemato.', 'archivio-micologico'),
			self::button(__('+ Nuova scheda', 'archivio-micologico'), $pages['submit'], true) . self::button(__('Archivio pubblico', 'archivio-micologico'), $pages['archive'], false, true)
		);

		$species = $ranks['species'];
		echo '<div class="am-kpis">';
		self::kpi(__('Schede pubblicate', 'archivio-micologico'), number_format_i18n($count('publish')), __('Visibili nell’archivio', 'archivio-micologico'), self::list_url('publish'), 'am-kpi--main');
		self::kpi(__('In revisione', 'archivio-micologico'), number_format_i18n($count('pending')), __('Da controllare e pubblicare', 'archivio-micologico'), self::list_url('pending'), $count('pending') ? 'am-kpi--warn' : '');
		self::kpi(__('Bozze', 'archivio-micologico'), number_format_i18n($count('draft')), __('Schede non ancora inviate', 'archivio-micologico'), self::list_url('draft'));
		self::kpi(__('Specie documentate', 'archivio-micologico'), number_format_i18n($species['documented']) . ' / ' . number_format_i18n($species['terms']), __('Specie con almeno una scheda pubblicata', 'archivio-micologico'), $tax_url);
		self::kpi(__('Articoli', 'archivio-micologico'), number_format_i18n($articles), __('Articoli pubblicati', 'archivio-micologico'), add_query_arg('post_type', AM_ARTICLE_CPT, admin_url('edit.php')));
		echo '</div>';

		echo '<div class="am-admin__grid">';
		echo '<div class="am-admin__col">';

		// Taxonomic coverage.
		echo '<section class="am-card"><h2>' . esc_html__('Copertura tassonomica', 'archivio-micologico') . '</h2>';
		echo '<p class="am-card__intro">' . esc_html__('Per ogni rango: voci presenti in tassonomia e quante hanno almeno una scheda pubblicata nel proprio ramo.', 'archivio-micologico') . '</p>';
		echo '<table class="am-coverage"><tbody>';
		foreach ($ranks as $rank => $r) {
			$pct = $r['terms'] ? (int) round($r['documented'] * 100 / $r['terms']) : 0;
			echo '<tr class="am-coverage--' . esc_attr($rank) . '"><th scope="row">' . esc_html($labels[$rank][0]) . '</th>';
			echo '<td class="am-coverage__bar"><span class="am-bar"><span style="width:' . esc_attr((string) $pct) . '%"></span></span></td>';
			echo '<td class="am-coverage__num">' . esc_html(sprintf(__('%1$s di %2$s', 'archivio-micologico'), number_format_i18n($r['documented']), number_format_i18n($r['terms']))) . '</td>';
			echo '<td class="am-coverage__pct">' . esc_html($pct . '%') . '</td></tr>';
		}
		echo '</tbody></table>';
		if ($coverage['divisions']) {
			echo '<p class="am-chips">';
			foreach ($coverage['divisions'] as $d) {
				echo '<span class="am-chip"><b>' . esc_html($d['name']) . '</b> ' . esc_html(sprintf(_n('%d scheda', '%d schede', $d['count'], 'archivio-micologico'), $d['count'])) . '</span>';
			}
			echo '</p>';
		}
		echo '<p class="am-card__foot"><a href="' . esc_url($tax_url) . '">' . esc_html__('Gestisci la tassonomia →', 'archivio-micologico') . '</a></p>';
		echo '</section>';

		// Latest schede.
		$recent = get_posts(['post_type' => AM_CPT, 'post_status' => self::ACTIVE, 'numberposts' => 8, 'orderby' => 'modified', 'order' => 'DESC']);
		echo '<section class="am-card"><h2>' . esc_html__('Ultime schede modificate', 'archivio-micologico') . '</h2>';
		if ($recent) {
			echo '<table class="am-recent"><thead><tr><th scope="col">' . esc_html__('Scheda', 'archivio-micologico') . '</th><th scope="col">' . esc_html__('Stato', 'archivio-micologico') . '</th><th scope="col">' . esc_html__('Aggiornata', 'archivio-micologico') . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__('Azioni', 'archivio-micologico') . '</span></th></tr></thead><tbody>';
			foreach ($recent as $p) {
				/** @var WP_Post $p */
				$status = get_post_status_object($p->post_status);
				echo '<tr><td class="am-recent__title">' . esc_html(get_the_title($p)) . '<span>' . esc_html((string) get_the_author_meta('display_name', (int) $p->post_author)) . '</span></td>';
				echo '<td><span class="am-status am-status--' . esc_attr($p->post_status) . '">' . esc_html($status ? $status->label : $p->post_status) . '</span></td>';
				echo '<td>' . esc_html(sprintf(__('%s fa', 'archivio-micologico'), human_time_diff((int) get_post_modified_time('U', true, $p), time()))) . '</td>';
				echo '<td class="am-recent__actions"><a href="' . esc_url(self::edit_url((int) $p->ID)) . '">' . esc_html__('Modifica', 'archivio-micologico') . '</a>';
				echo ' <a href="' . esc_url((string) get_permalink($p)) . '" target="_blank" rel="noopener">' . esc_html($p->post_status === 'publish' ? __('Apri', 'archivio-micologico') : __('Anteprima', 'archivio-micologico')) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__('Ancora nessuna scheda. Inizia da Nuova scheda.', 'archivio-micologico') . '</p>';
		}
		echo '</section>';
		echo '</div>';

		echo '<div class="am-admin__col am-admin__col--side">';

		// Data quality.
		echo '<section class="am-card"><h2>' . esc_html__('Controllo qualità dei dati', 'archivio-micologico') . '</h2>';
		echo '<p class="am-card__intro">' . esc_html__('Controlli sulle schede attive (pubblicate, in revisione, bozze e private).', 'archivio-micologico') . '</p><ul class="am-checks">';
		$pending = array_map(static fn($p) => [(int) $p->ID, (string) $p->post_title], get_posts(['post_type' => AM_CPT, 'post_status' => 'pending', 'numberposts' => 50, 'orderby' => 'date', 'order' => 'ASC']));
		self::check(__('In attesa di revisione', 'archivio-micologico'), __('Controlla classificazione, testi e immagini, poi pubblica dall’editor o con Modifica rapida.', 'archivio-micologico'), $pending, __('Apri elenco', 'archivio-micologico'), self::list_url('pending'), true, true);
		self::check(__('Senza classificazione', 'archivio-micologico'), __('La scheda non è collegata a nessuna specie: non compare nell’albero dell’archivio.', 'archivio-micologico'), $quality['no_class'], '', '');
		self::check(__('Senza autore del nome', 'archivio-micologico'), __('Manca l’autore (autorità) del nome scientifico, es. (Fr.) P. Kumm.', 'archivio-micologico'), $quality['no_author'], '', '');
		self::check(__('Senza immagine di anteprima', 'archivio-micologico'), __('La scheda appare senza foto in archivio e nelle anteprime social.', 'archivio-micologico'), $quality['no_image'], '', '', true);
		self::check(__('Possibili doppioni', 'archivio-micologico'), __('Più schede con lo stesso nome completo: verifica se vanno unite o distinte.', 'archivio-micologico'), $quality['duplicates'], '', '');
		$unused = $quality['unused_terms'] > 0 ? array_fill(0, $quality['unused_terms'], [0, '']) : [];
		self::check(__('Voci di vocabolario non usate', 'archivio-micologico'), __('Forme, varietà o autori che nessuna scheda usa: elimina o unisci i doppioni.', 'archivio-micologico'), $unused, __('Apri Vocabolari', 'archivio-micologico'), $vocab_url, true);
		echo '</ul></section>';

		// Schede lists, now that the menu has no Schede entry.
		echo '<section class="am-card"><h2>' . esc_html__('Elenchi schede', 'archivio-micologico') . '</h2>';
		echo '<p class="am-card__intro">' . esc_html__('Le schede si creano e si modificano dai moduli pubblici. Qui trovi gli elenchi WordPress per stato, utili per revisioni e cestino.', 'archivio-micologico') . '</p>';
		echo '<nav class="am-links">';
		foreach ([
			['', __('Tutte', 'archivio-micologico'), $count('publish') + $count('pending') + $count('draft') + $count('private') + $count('future')],
			['pending', __('In revisione', 'archivio-micologico'), $count('pending')],
			['draft', __('Bozze', 'archivio-micologico'), $count('draft')],
			['private', __('Private', 'archivio-micologico'), $count('private')],
			['trash', __('Cestino', 'archivio-micologico'), $count('trash')],
		] as [$status, $label, $n]) {
			echo '<a href="' . esc_url(self::list_url($status)) . '"><span>' . esc_html($label) . '</span><b>' . esc_html(number_format_i18n($n)) . '</b></a>';
		}
		echo '</nav></section>';

		// Public management pages.
		echo '<section class="am-card"><h2>' . esc_html__('Pagine di gestione', 'archivio-micologico') . '</h2><nav class="am-links">';
		foreach ([
			['submit', __('Nuovo inserimento', 'archivio-micologico'), '[am_submit_form]'],
			['article', __('Nuovo articolo', 'archivio-micologico'), '[am_article_form]'],
			['taxonomy', __('Tassonomia (pubblica)', 'archivio-micologico'), '[am_tassonomia]'],
			['vocab', __('Vocabolari (pubblica)', 'archivio-micologico'), '[am_vocabolari]'],
			['archive', __('Archivio', 'archivio-micologico'), '[am_archive]'],
		] as [$key, $label, $code]) {
			if ($pages[$key] !== '') {
				echo '<a href="' . esc_url($pages[$key]) . '" target="_blank" rel="noopener"><span>' . esc_html($label) . '</span><b aria-hidden="true">↗</b></a>';
			} else {
				echo '<span class="am-links__missing"><span>' . esc_html($label) . '</span><small>' . esc_html(sprintf(__('Nessuna pagina con %s', 'archivio-micologico'), $code)) . '</small></span>';
			}
		}
		echo '</nav></section>';

		$authors = self::author_counts();
		if ($authors) {
			echo '<section class="am-card"><h2>' . esc_html__('Chi ha pubblicato', 'archivio-micologico') . '</h2><ol class="am-authors">';
			foreach ($authors as [$name, $n]) {
				echo '<li><span>' . esc_html($name) . '</span><b>' . esc_html(number_format_i18n($n)) . '</b></li>';
			}
			echo '</ol></section>';
		}
		echo '</div></div>';

		echo '<p class="am-admin__credits">' . esc_html__('Archivio Micologico di Pasqualino De Marco per l’Associazione Micologica Bassa Friulana', 'archivio-micologico') . ' · <a href="https://www.micologiabf.org" target="_blank" rel="noopener">micologiabf.org</a></p>';
		self::close();
	}

	/* ---------------------------------------------------- taxonomy and vocabularies */

	public static function taxonomy(): void {
		if (!current_user_can('manage_terms') && !current_user_can('manage_options')) {
			wp_die(esc_html__('Non autorizzato.', 'archivio-micologico'));
		}
		self::open(
			__('Tassonomia', 'archivio-micologico'),
			'',
			self::button(__('Pagina pubblica', 'archivio-micologico'), self::page_url('taxonomy'), false, true)
			. self::button(__('Slug e descrizioni', 'archivio-micologico'), admin_url('edit-tags.php?taxonomy=' . AM_TAX_CLASSIFICAZIONE . '&post_type=' . AM_CPT))
		);
		echo TermManager::render_taxonomy(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built.
		self::close();
	}

	public static function vocabularies(): void {
		if (!current_user_can('manage_terms') && !current_user_can('manage_options')) {
			wp_die(esc_html__('Non autorizzato.', 'archivio-micologico'));
		}
		self::open(__('Vocabolari', 'archivio-micologico'), '', self::button(__('Pagina pubblica', 'archivio-micologico'), self::page_url('vocab'), false, true));
		echo TermManager::render_vocabularies(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built.
		self::close();
	}

	/* ------------------------------------------------------------------- settings */

	/** Settings sections in display order: id => [title, what it controls]. */
	public static function setting_sections(): array {
		return [
			'am_pages' => [__('Pagine del plugin', 'archivio-micologico'), __('Quale pagina del sito ospita ogni funzione. Con “Automatica” il plugin usa la pagina più recente che contiene lo shortcode; ✓ indica le pagine che lo contengono.', 'archivio-micologico')],
						'am_entry' => [__('Inserimento schede', 'archivio-micologico'), __('Come funzionano i moduli pubblici Nuovo inserimento e Modifica.', 'archivio-micologico')],
			'am_articles' => [__('Articoli scientifici', 'archivio-micologico'), __('Modulo Nuovo articolo ([am_article_form]) e riquadro Articoli scientifici dell’archivio.', 'archivio-micologico')],
			'am_display' => [__('Visualizzazione pubblica', 'archivio-micologico'), __('Cosa vedono i visitatori nelle schede e nell’archivio.', 'archivio-micologico')],
			'am_panels' => [__('Pannelli dell’archivio', 'archivio-micologico'), __('Quali pannelli collassabili dell’Archivio micologico sono già aperti quando si apre la pagina. Il visitatore può sempre aprirli e chiuderli con un clic.', 'archivio-micologico')],
						'am_book' => [__('Sfoglia le schede', 'archivio-micologico'), __('Frecce ai lati della scheda per passare alla precedente o alla successiva, come in un libro, e riquadro Sfoglia l’archivio con le scelte offerte ai visitatori.', 'archivio-micologico')],
			'am_widget_random' => [__('Carosello schede casuali', 'archivio-micologico'), __('Valori predefiniti del widget e dello shortcode [am_random_funghi]; lo shortcode può sovrascriverli.', 'archivio-micologico')],
			'am_seo' => [__('SEO', 'archivio-micologico'), __('Meta description, dati strutturati e immagini comunicate ai motori di ricerca.', 'archivio-micologico')],
			'am_tools' => [__('Manutenzione', 'archivio-micologico'), __('Strumenti per diagnosticare un problema.', 'archivio-micologico')],
		];
	}

	public static function settings(): void {
		$sections = self::setting_sections();
		self::open(__('Impostazioni', 'archivio-micologico'), __('Ogni gruppo indica cosa controlla. Le modifiche valgono subito dopo il salvataggio.', 'archivio-micologico'));

		if (isset($_GET['am_err'])) {
			echo '<div class="notice notice-error"><p>' . esc_html(sprintf(__('Salvataggio non completato (errore: %s). Riprova; se si ripete, attiva il pannello debug in Manutenzione.', 'archivio-micologico'), sanitize_key((string) $_GET['am_err']))) . '</p></div>';
		} elseif (isset($_GET['am_saved'])) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Impostazioni salvate.', 'archivio-micologico') . '</p></div>';
		}

		echo '<nav class="am-toc" aria-label="' . esc_attr__('Sezioni', 'archivio-micologico') . '">';
		foreach ($sections as $id => [$title]) { echo '<a href="#' . esc_attr($id) . '">' . esc_html($title) . '</a>'; }
		echo '<a href="#am_permissions">' . esc_html__('Permessi', 'archivio-micologico') . '</a></nav>';

		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="am-settings">';
		echo '<input type="hidden" name="action" value="am_save_settings">';
		wp_nonce_field('am_save_settings');
		foreach ($sections as $id => [$title, $intro]) {
			echo '<section class="am-card" id="' . esc_attr($id) . '"><h2>' . esc_html($title) . '</h2><p class="am-card__intro">' . esc_html($intro) . '</p>';
			echo '<table class="form-table" role="presentation">';
			do_settings_fields('am_settings', $id);
			echo '</table></section>';
		}
		echo '<div class="am-settings__save">';
		submit_button(__('Salva impostazioni', 'archivio-micologico'), 'primary', 'submit', false);
		echo '</div></form>';

		echo '<section class="am-card" id="am_permissions"><h2>' . esc_html__('Permessi', 'archivio-micologico') . '</h2>';
		echo '<p class="am-card__intro">' . esc_html__('Fissi, basati sui ruoli WordPress: non serve configurarli.', 'archivio-micologico') . '</p>';
		echo '<table class="widefat striped am-perms"><thead><tr><th scope="col">' . esc_html__('Ruolo', 'archivio-micologico') . '</th><th scope="col">' . esc_html__('Cosa può fare', 'archivio-micologico') . '</th></tr></thead><tbody>';
		echo '<tr><th scope="row">' . esc_html__('Amministratore', 'archivio-micologico') . '</th><td>' . esc_html__('Tutto: schede, articoli, tassonomia, vocabolari, impostazioni.', 'archivio-micologico') . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Editore', 'archivio-micologico') . '</th><td>' . esc_html__('Come l’Amministratore per l’archivio, impostazioni AM comprese.', 'archivio-micologico') . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__('Altri ruoli e visitatori', 'archivio-micologico') . '</th><td>' . esc_html__('Solo consultazione dell’archivio pubblicato.', 'archivio-micologico') . '</td></tr>';
		echo '</tbody></table></section>';

		echo '<section class="am-card"><h2>' . esc_html__('Log di debug', 'archivio-micologico') . '</h2>';
		$show = (int) get_option('am_enable_debug_panel', 0) === 1 || (defined('WP_DEBUG') && WP_DEBUG);
		$log = get_option('am_debug_log', []);
		if ($show && is_array($log) && $log) {
			echo '<details class="am-debug"><summary>' . esc_html(sprintf(__('Ultimi %d eventi', 'archivio-micologico'), count($log))) . '</summary><div>';
			foreach ($log as $row) {
				$time = isset($row['time']) ? date_i18n('Y-m-d H:i:s', (int) $row['time']) : (string) ($row['ts'] ?? '');
				echo '<p><code>' . esc_html($time) . '</code> <strong>' . esc_html((string) ($row['tag'] ?? '')) . '</strong> ' . esc_html((string) ($row['user'] ?? '')) . '<br><code>' . esc_html((string) wp_json_encode($row['data'] ?? [])) . '</code></p>';
			}
			echo '</div></details>';
		}
		Admin::render_clear_debug_log_tool();
		echo '</section>';
		self::close();
	}

	/* ---------------------------------------------------------------- instructions */

	public static function instructions(): void {
		if (!current_user_can(AM_CAP_EDIT_POSTS)) {
			wp_die(esc_html__('Non hai i permessi per visualizzare questa pagina.', 'archivio-micologico'));
		}
		self::open(__('Istruzioni', 'archivio-micologico'), __('Tutto quello che serve per lavorare sull’archivio, in breve.', 'archivio-micologico'));

		echo '<div class="am-howto">';
		$cards = [
			[__('Inserire una scheda', 'archivio-micologico'), __('Apri la pagina Nuovo inserimento dal menu Gestione archivio del sito. Scegli la specie nell’albero, aggiungi forma o varietà e autore, scrivi le descrizioni e carica le foto. Il titolo si compone da solo.', 'archivio-micologico')],
			[__('Correggere un nome', 'archivio-micologico'), __('Usa Tassonomia per divisioni, famiglie, generi e specie, Vocabolari per forme, varietà e autori. Rinomina o sposta la voce: tutte le schede collegate si aggiornano.', 'archivio-micologico')],
			[__('Pubblicare un articolo', 'archivio-micologico'), __('Da Gestione archivio → Nuovo articolo: titolo, sommario, categorie, testo, immagini e PDF. Compare nel riquadro Articoli scientifici dell’archivio. Amministratori ed Editori lo eliminano dal pulsante Elimina articolo nella sua pagina (va nel cestino).', 'archivio-micologico')],
						[__('Revisionare e pubblicare', 'archivio-micologico'), __('Le schede in revisione sono elencate nella Dashboard. Controllale e pubblicale dall’editor WordPress o con Modifica rapida. Nel modulo di modifica, Salva revisione registra chi ha controllato e quando.', 'archivio-micologico')],
		];
		foreach ($cards as $i => [$title, $text]) {
			echo '<section class="am-card am-howto__step"><span class="am-howto__num">' . esc_html((string) ($i + 1)) . '</span><h2>' . esc_html($title) . '</h2><p>' . esc_html($text) . '</p></section>';
		}
		echo '</div>';

		echo '<div class="am-admin__grid">';
		echo '<div class="am-admin__col">';
		echo '<section class="am-card"><h2>' . esc_html__('Regole di nomenclatura', 'archivio-micologico') . '</h2><dl class="am-rules">';
		$rules = [
			[__('Gerarchia', 'archivio-micologico'), __('Quattro ranghi fissi: Divisione → Famiglia → Genere → Specie. Una voce sta sempre sotto il rango immediatamente superiore.', 'archivio-micologico')],
			[__('Titolo della scheda', 'archivio-micologico'), __('Genere + epiteto specifico + forma/varietà + autore, es. Armillaria mellea (Vahl) P. Kumm. Non si scrive a mano: si ricompone a ogni correzione.', 'archivio-micologico')],
			[__('Epiteto specifico', 'archivio-micologico'), __('Sempre minuscolo, anche se deriva da un nome proprio. Il Genere ha l’iniziale maiuscola.', 'archivio-micologico')],
			[__('Forma e varietà', 'archivio-micologico'), __('Con l’abbreviazione del rango: var. per varietà, f. per forma.', 'archivio-micologico')],
			[__('Autori', 'archivio-micologico'), __('Abbreviazioni standard di Index Fungorum (Authors of Fungal Names). Una sola voce per autore: i doppioni si correggono con Unisci.', 'archivio-micologico')],
			[__('Categorie degli articoli', 'archivio-micologico'), __('Raggruppano gli articoli per argomento (es. Monografie, Chiavi, Relazioni). Si creano in Archivio Micologico → Categorie Articoli, si scelgono nel modulo (anche più di una) e ognuna ha la sua pagina pubblica con l’elenco degli articoli.', 'archivio-micologico')],
			[__('Sfogliare le schede', 'archivio-micologico'), __('Le frecce ai lati della scheda seguono l’ordine scelto in Impostazioni → Sfoglia le schede. Nell’archivio il riquadro Sfoglia l’archivio lascia scegliere al visitatore ordine e capitolo (una divisione, famiglia o genere): le frecce restano dentro quella scelta.', 'archivio-micologico')],
						[__('Eliminare', 'archivio-micologico'), __('Possibile solo per voci senza schede e senza livelli inferiori, così nessuna scheda perde la classificazione.', 'archivio-micologico')],
		];
		foreach ($rules as [$term, $def]) { echo '<dt>' . esc_html($term) . '</dt><dd>' . esc_html($def) . '</dd>'; }
		echo '</dl></section>';

		echo '<section class="am-card"><h2>' . esc_html__('Shortcode', 'archivio-micologico') . '</h2>';
		echo '<p class="am-card__intro">' . esc_html__('Inseriscili nell’editor a blocchi con un blocco Shortcode, uno per pagina.', 'archivio-micologico') . '</p>';
		echo '<table class="widefat striped am-codes"><tbody>';
		foreach ([
			['[am_archive]', __('Archivio: albero delle classificazioni, ricerca e ultime schede.', 'archivio-micologico')],
			['[am_archive_filters]', __('Filtri per rango, da mettere sopra [am_archive].', 'archivio-micologico')],
			['[am_search_page]', __('Pagina di ricerca con risultati paginati.', 'archivio-micologico')],
			['[am_submit_form]', __('Nuovo inserimento di una scheda.', 'archivio-micologico')],
			['[am_edit_form]', __('Modifica di una scheda: indica la pagina in Impostazioni → Inserimento schede.', 'archivio-micologico')],
			['[am_tassonomia]', __('Gestione della tassonomia (solo Amministratori ed Editori).', 'archivio-micologico')],
			['[am_vocabolari]', __('Gestione di forme, varietà e autori (solo Amministratori ed Editori).', 'archivio-micologico')],
			['[am_article_form]', __('Inserimento di un articolo con immagini, galleria e PDF.', 'archivio-micologico')],
			['[am_random_funghi]', __('Carosello di schede casuali. Parametri: numero, titolo, visibili, righe, autoplay, intervallo.', 'archivio-micologico')],
		] as [$code, $what]) {
			echo '<tr><td>' . self::copy_code($code) . '</td><td>' . esc_html($what) . '</td></tr>';
		}
		echo '</tbody></table></section>';
		echo '</div>';

		echo '<div class="am-admin__col am-admin__col--side">';
		echo '<section class="am-card"><h2>' . esc_html__('Chi può fare cosa', 'archivio-micologico') . '</h2>';
		echo '<p>' . esc_html__('Amministratori ed Editori gestiscono tutto l’archivio. Gli altri utenti e i visitatori consultano solo le schede pubblicate.', 'archivio-micologico') . '</p></section>';

		echo '<section class="am-card"><h2>' . esc_html__('Se qualcosa non va', 'archivio-micologico') . '</h2>';
		foreach ([
			[__('Il modulo non compare', 'archivio-micologico'), __('Controlla di aver effettuato l’accesso come Amministratore o Editore e che la pagina usi un blocco Shortcode.', 'archivio-micologico')],
			[__('Una scheda non è nell’archivio', 'archivio-micologico'), __('Deve essere Pubblicata e collegata a una specie: la Dashboard elenca quelle senza classificazione.', 'archivio-micologico')],
			[__('Il pulsante Modifica non c’è', 'archivio-micologico'), __('Imposta la pagina con [am_edit_form] in Impostazioni → Inserimento schede.', 'archivio-micologico')],
			[__('Dopo un aggiornamento', 'archivio-micologico'), __('Svuota la cache del sito e del browser. Se il problema resta, attiva il pannello debug in Impostazioni → Manutenzione.', 'archivio-micologico')],
		] as [$q, $a]) {
			echo '<details class="am-faq"><summary>' . esc_html($q) . '</summary><p>' . esc_html($a) . '</p></details>';
		}
		echo '</section>';
		echo '</div></div>';
		self::close();
	}
}
