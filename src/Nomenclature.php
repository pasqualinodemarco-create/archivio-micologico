<?php

namespace ArchivioMicologico;

use WP_Post;

if (!defined('ABSPATH')) { exit; }

/**
 * Nomenclature and citation of the schede.
 *
 * The names are checked against the Catalogue of Life (ChecklistBank API, which for fungi
 * takes Species Fungorum / Index Fungorum): accepted or synonym, current name, synonyms and the
 * Index Fungorum record. The lookup runs in the editor's browser from Archivio Micologico →
 * Nomenclatura (the API allows it, and many hosts block outgoing connections from PHP); the
 * site only stores the result. Nothing changes the names of the schede: adopting a current name
 * stays an editorial choice (Tassonomia → Rinomina).
 */
final class Nomenclature {
	public const STATUSES = ['accepted', 'synonym', 'notfound', 'ambiguous'];
	private const META = 'am_nom';

	public static function init(): void {
		add_action('wp_ajax_am_nom_save', [self::class, 'ajax_save']);
		add_shortcode('am_nomenclatura', [self::class, 'shortcode']);
		add_action('wp_enqueue_scripts', [self::class, 'enqueue_public']);
	}

	public static function enabled(): bool {
		return (int) get_option('am_nom_enabled', 1) === 1;
	}

	public static function search_synonyms(): bool {
		return self::enabled() && (int) get_option('am_nom_search', 1) === 1;
	}

	public static function citation_enabled(): bool {
		return (int) get_option('am_cite_enabled', 1) === 1;
	}

	/** Stored result for a scheda, or [] when it was never checked. */
	public static function get(int $post_id): array {
		$data = get_post_meta($post_id, self::META, true);
		return is_array($data) ? $data : [];
	}

	/** Name to look up and its rank, from the parts of the scheda (author excluded). */
	public static function query(int $post_id): array {
		$parts = SpeciesTitle::post_parts($post_id);
		$base = trim((string) ($parts['base'] ?? ''));
		$infra = trim((string) ($parts['infra'] ?? ''));
		$rank = 'species';
		if (preg_match('/\bvar\.\s/u', $infra)) { $rank = 'variety'; }
		elseif (preg_match('/\bf\.\s/u', $infra)) { $rank = 'form'; }
		elseif (preg_match('/\bsubsp\.\s/u', $infra)) { $rank = 'subspecies'; }
		return [trim($base . ' ' . $infra), $rank, trim((string) ($parts['author'] ?? ''))];
	}

	/* --------------------------------------------------------------- saving */

	/** Stores what the editor's browser found. Only Administrators and Editors. */
	public static function ajax_save(): void {
		if (!check_ajax_referer('am_nom_save', 'nonce', false)) {
			wp_send_json_error(['message' => __('Sessione scaduta. Ricarica la pagina.', 'archivio-micologico')], 403);
		}
		if (!EntryForms::am_current_user_is_admin_or_editor()) {
			wp_send_json_error(['message' => __('Non autorizzato.', 'archivio-micologico')], 403);
		}
		$post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
		if (!$post_id || get_post_type($post_id) !== AM_CPT || !current_user_can('edit_post', $post_id)) {
			wp_send_json_error(['message' => __('Scheda non valida.', 'archivio-micologico')], 400);
		}
		$raw = isset($_POST['data']) ? json_decode(wp_unslash((string) $_POST['data']), true) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, every field is sanitized by self::sanitize().
		if (!is_array($raw)) {
			wp_send_json_error(['message' => __('Dati non validi.', 'archivio-micologico')], 400);
		}
		wp_send_json_success(['data' => self::store($post_id, $raw)]);
	}

	/** Sanitizes and saves a result; also the searchable text of the old and current names. */
	public static function store(int $post_id, array $raw): array {
		$data = self::sanitize($raw);
		update_post_meta($post_id, self::META, $data);
		update_post_meta($post_id, 'am_nom_synonyms_text', implode(' | ', array_merge(array_column($data['synonyms'], 0), $data['current'] !== '' ? [$data['current']] : [])));
		return $data;
	}

	public static function sanitize(array $raw): array {
		$text = static fn($v): string => trim(sanitize_text_field((string) $v));
		$id = static fn($v): string => preg_match('/^[A-Za-z0-9]{1,16}$/', (string) $v) ? (string) $v : '';
		$synonyms = [];
		$seen = [];
		foreach (array_slice((array) ($raw['synonyms'] ?? []), 0, 80) as $s) {
			$n = $text(is_array($s) ? ($s[0] ?? '') : $s);
			$a = $text(is_array($s) ? ($s[1] ?? '') : '');
			if ($n !== '' && !isset($seen[$n])) { $seen[$n] = true; $synonyms[] = [$n, $a]; }
		}
		$status = (string) ($raw['status'] ?? '');
		return [
			'status' => in_array($status, self::STATUSES, true) ? $status : 'notfound',
			'name' => $text($raw['name'] ?? ''),
			'authorship' => $text($raw['authorship'] ?? ''),
			'if_id' => preg_match('/^\d{1,9}$/', (string) ($raw['if_id'] ?? '')) ? (string) $raw['if_id'] : '',
			'col_id' => $id($raw['col_id'] ?? ''),
			'current' => $text($raw['current'] ?? ''),
			'current_authorship' => $text($raw['current_authorship'] ?? ''),
			'current_if_id' => preg_match('/^\d{1,9}$/', (string) ($raw['current_if_id'] ?? '')) ? (string) $raw['current_if_id'] : '',
			'current_col_id' => $id($raw['current_col_id'] ?? ''),
			'synonyms' => $synonyms,
			'checked' => gmdate('Y-m-d'),
		];
	}

	/* ------------------------------------------------------------- public view */

	private static function if_url(string $id): string {
		return 'https://www.indexfungorum.org/Names/NamesRecord.asp?RecordID=' . rawurlencode($id);
	}

	private static function col_url(string $id): string {
		return 'https://www.catalogueoflife.org/data/taxon/' . rawurlencode($id);
	}

	private const RANKS = ['var.', 'f.', 'subsp.', 'forma'];

	/** Name in italics (rank abbreviations in roman), then the author in roman. */
	private static function name_html(string $name, string $author = ''): string {
		$out = [];
		foreach (preg_split('/\s+/u', trim($name)) ?: [] as $w) {
			$out[] = in_array($w, self::RANKS, true) ? esc_html($w) : '<i>' . esc_html($w) . '</i>';
		}
		$html = implode(' ', $out);
		return $author !== '' ? $html . ' <span class="am-nom__author">' . esc_html($author) . '</span>' : $html;
	}

	/** "Nomenclatura" section of the scheda. */
	public static function section(int $post_id): string {
		if (!self::enabled()) { return ''; }
		$d = self::get($post_id);
		if (!$d || !in_array($d['status'] ?? '', ['accepted', 'synonym'], true)) { return ''; }

		$html = '<section class="am-entry-section am-nom" aria-labelledby="am-nom-' . $post_id . '"><h2 id="am-nom-' . $post_id . '">' . esc_html__('Nomenclatura', 'archivio-micologico') . '</h2><dl class="am-nom__list">';
		if ($d['status'] === 'synonym' && $d['current'] !== '') {
			$cur = self::name_html($d['current'], (string) ($d['current_authorship'] ?? ''));
			if ($d['current_if_id'] !== '') {
				$cur = '<a href="' . esc_url(self::if_url($d['current_if_id'])) . '" target="_blank" rel="noopener">' . $cur . '</a>';
			}
			$html .= '<dt>' . esc_html__('Nome corrente', 'archivio-micologico') . '</dt><dd>' . $cur . '<span class="am-nom__note">' . esc_html__('Il nome della scheda è oggi considerato un sinonimo.', 'archivio-micologico') . '</span></dd>';
		} else {
			$html .= '<dt>' . esc_html__('Stato del nome', 'archivio-micologico') . '</dt><dd>' . esc_html__('Nome accettato', 'archivio-micologico') . '</dd>';
		}
		if ($d['synonyms']) {
			$items = array_map(static fn($s) => '<li>' . self::name_html((string) ($s[0] ?? ''), (string) ($s[1] ?? '')) . '</li>', array_slice($d['synonyms'], 0, 40));
			$html .= '<dt>' . esc_html__('Sinonimi', 'archivio-micologico') . '</dt><dd><ul class="am-nom__synonyms">' . implode('', $items) . '</ul></dd>';
		}
		$links = [];
		if ($d['if_id'] !== '') { $links[] = '<a href="' . esc_url(self::if_url($d['if_id'])) . '" target="_blank" rel="noopener">Index Fungorum ' . esc_html($d['if_id']) . '</a>'; }
		$col = $d['status'] === 'synonym' && $d['current_col_id'] !== '' ? $d['current_col_id'] : $d['col_id'];
		if ($col !== '') { $links[] = '<a href="' . esc_url(self::col_url($col)) . '" target="_blank" rel="noopener">Catalogue of Life</a>'; }
		if ($links) {
			$html .= '<dt>' . esc_html__('Fonti', 'archivio-micologico') . '</dt><dd>' . implode(' · ', $links) . '</dd>';
		}
		$html .= '</dl>';
		/* translators: %s: date of the last check. */
		$html .= '<p class="am-nom__source">' . esc_html(sprintf(__('Fonte: Species Fungorum tramite Catalogue of Life, verificato il %s.', 'archivio-micologico'), date_i18n(get_option('date_format'), strtotime((string) $d['checked'])))) . '</p>';
		return $html . '</section>';
	}

	/** "Come citare questa scheda", with a copy button. */
	public static function citation(int $post_id): string {
		if (!self::citation_enabled()) { return ''; }
		$post = get_post($post_id);
		if (!$post instanceof WP_Post || $post->post_status !== 'publish') { return ''; }
		$u = get_userdata((int) $post->post_author);
		$author = $u ? trim($u->last_name . ($u->first_name !== '' ? ' ' . mb_substr($u->first_name, 0, 1) . '.' : '')) : '';
		if ($author === '' && $u) { $author = (string) $u->display_name; }
		$work = trim((string) get_option('am_cite_work', 'Archivio Micologico'));
		$publisher = trim((string) get_option('am_cite_publisher', get_bloginfo('name')));
		$title = wp_strip_all_tags(get_the_title($post));
		$url = (string) get_permalink($post);
		$year = get_the_date('Y', $post);
		$updated = get_the_modified_date('j F Y', $post);
		$consulted = date_i18n('j F Y');

		$plain = sprintf(
			/* translators: 1: author, 2: year, 3: scientific name, 4: work, 5: publisher, 6: last update, 7: URL, 8: date of consultation. */
			__('%1$s (%2$s). %3$s. In: %4$s, %5$s. Aggiornata il %6$s. %7$s (consultato il %8$s).', 'archivio-micologico'),
			$author !== '' ? $author : $publisher, $year, $title, $work, $publisher, $updated, $url, $consulted
		);
		$html_title = wp_kses(SpeciesTitle::render_post($post_id), ['span' => ['class' => true], 'i' => []]);
		$shown = sprintf(
			/* translators: 1: author, 2: year, 3: scientific name, 4: work, 5: publisher, 6: last update, 7: URL, 8: date of consultation. */
			__('%1$s (%2$s). %3$s. In: %4$s, %5$s. Aggiornata il %6$s. %7$s (consultato il %8$s).', 'archivio-micologico'),
			esc_html($author !== '' ? $author : $publisher), esc_html($year), $html_title !== '' ? $html_title : esc_html($title), '<em>' . esc_html($work) . '</em>', esc_html($publisher), esc_html($updated), '<a href="' . esc_url($url) . '">' . esc_html($url) . '</a>', esc_html($consulted)
		);
		return '<section class="am-entry-section am-cite" aria-labelledby="am-cite-' . $post_id . '"><h2 id="am-cite-' . $post_id . '">' . esc_html__('Come citare questa scheda', 'archivio-micologico') . '</h2>'
			. '<p class="am-cite__text">' . $shown . '</p>'
			. '<button type="button" class="am-btn am-btn--small am-btn--secondary am-cite__copy" data-am-copy-text="' . esc_attr($plain) . '" data-am-done="' . esc_attr__('Citazione copiata ✓', 'archivio-micologico') . '">' . esc_html__('Copia la citazione', 'archivio-micologico') . '</button>'
			. '</section>';
	}

	/* --------------------------------------------------------------- admin page */

	public static function enqueue(): void {
		wp_enqueue_style('am-manage', AM_URL . 'assets/css/am-manage.css', [], AM_VERSION);
		wp_enqueue_script('am-nomenclature', AM_URL . 'assets/js/am-nomenclature.js', [], AM_VERSION, true);
		wp_localize_script('am-nomenclature', 'AM_NOM', [
			'ajax' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('am_nom_save'),
			'i18n' => [
				'accepted' => __('Accettato', 'archivio-micologico'),
				'synonym' => __('Sinonimo', 'archivio-micologico'),
				'ambiguous' => __('Da controllare', 'archivio-micologico'),
				'notfound' => __('Non trovato', 'archivio-micologico'),
				'checking' => __('Verifica…', 'archivio-micologico'),
				'error' => __('Errore di rete, riprova più tardi.', 'archivio-micologico'),
				/* translators: 1: done, 2: total. */
				'progress' => __('%1$d di %2$d verificate', 'archivio-micologico'),
				'done' => __('Verifica completata.', 'archivio-micologico'),
				'author_diff' => __('autore diverso da quello della scheda', 'archivio-micologico'),
			],
		]);
	}

	/** Archivio Micologico → Nomenclatura: same view as the public page [am_nomenclatura]. */
	public static function admin_page(): void {
		if (!EntryForms::am_current_user_is_admin_or_editor()) {
			wp_die(esc_html__('Non autorizzato.', 'archivio-micologico'));
		}
		$public = AdminPages::page_url('nomenclature');
		AdminPages::open(
			__('Nomenclatura', 'archivio-micologico'),
			'',
			$public !== '' ? '<a class="button" href="' . esc_url($public) . '" target="_blank" rel="noopener">' . esc_html__('Pagina pubblica', 'archivio-micologico') . '</a>' : ''
		);
		echo self::board(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		AdminPages::close();
	}

	/** [am_nomenclatura]: public page for Administrators and Editors. */
	public static function shortcode($atts = []): string {
		if (!is_user_logged_in()) {
			return '<div class="am-manage"><p class="am-manage__notice am-manage__notice--info">' . esc_html__('Questa pagina è riservata ad Amministratori ed Editori.', 'archivio-micologico') . ' <a href="' . esc_url(wp_login_url(get_permalink() ?: home_url('/'))) . '">' . esc_html__('Accedi', 'archivio-micologico') . '</a></p></div>';
		}
		if (!EntryForms::am_current_user_is_admin_or_editor()) {
			return '<div class="am-manage"><p class="am-manage__notice am-manage__notice--error">' . esc_html__('Non hai i permessi per gestire l’archivio.', 'archivio-micologico') . '</p></div>';
		}
		return self::board();
	}

	public static function enqueue_public(): void {
		$post = get_post();
		if ($post && has_shortcode((string) $post->post_content, 'am_nomenclatura') && EntryForms::am_current_user_is_admin_or_editor()) {
			self::enqueue();
		}
	}

	/** The checking board, shared by the admin page and the public page. */
	public static function board(): string {
		$posts = get_posts(['post_type' => AM_CPT, 'post_status' => ['publish', 'pending', 'draft', 'private'], 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
		$counts = array_fill_keys(array_merge(self::STATUSES, ['unchecked']), 0);
		$rows = '';
		foreach ($posts as $p) {
			[$name, $rank, $author] = self::query((int) $p->ID);
			$d = self::get((int) $p->ID);
			$status = $d['status'] ?? 'unchecked';
			$counts[$status] = ($counts[$status] ?? 0) + 1;
			$src_author = (string) ($d['authorship'] ?? '');
			$diff = $src_author !== '' && $author !== '' && self::squash($src_author) !== self::squash($author);
			$rows .= '<tr class="am-mrow" data-nom-row="' . esc_attr((string) $p->ID) . '" data-name="' . esc_attr($name) . '" data-rank="' . esc_attr($rank) . '" data-author="' . esc_attr($author) . '" data-status="' . esc_attr($status) . '">'
				. '<td class="am-mrow__name"><a href="' . esc_url((string) get_permalink($p)) . '" target="_blank" rel="noopener">' . esc_html(wp_strip_all_tags(get_the_title($p))) . '</a></td>'
				. '<td class="am-nom-result">' . self::result_cell($d) . '</td>'
				. '<td class="am-nom-author">' . esc_html($src_author) . ($diff ? '<span class="am-nom-diff"> (' . esc_html__('autore diverso da quello della scheda', 'archivio-micologico') . ': ' . esc_html($author) . ')</span>' : '') . '</td>'
				. '<td class="am-nom-syn am-mrow__count">' . esc_html(isset($d['synonyms']) ? (string) count($d['synonyms']) : '') . '</td>'
				. '<td class="am-mrow__actions"><button type="button" class="am-manage__btn am-manage__btn--ghost am-manage__btn--small" data-nom-one>' . esc_html__('Verifica', 'archivio-micologico') . '</button></td></tr>';
		}
		$labels = ['accepted' => __('Accettati', 'archivio-micologico'), 'synonym' => __('Sinonimi', 'archivio-micologico'), 'ambiguous' => __('Da controllare', 'archivio-micologico'), 'notfound' => __('Non trovati', 'archivio-micologico'), 'unchecked' => __('Mai verificati', 'archivio-micologico')];

		$html = '<div class="am-manage am-manage--nomenclature">';
		$html .= '<header class="am-manage__header"><div><p class="am-manage__eyebrow">' . esc_html__('Gestione archivio', 'archivio-micologico') . '</p>';
		$html .= '<p class="am-manage__intro">' . esc_html__('Confronta i nomi delle schede con Species Fungorum (tramite Catalogue of Life): nome accettato o sinonimo, nome corrente, sinonimi e record di Index Fungorum. I nomi delle schede non vengono cambiati: per adottare il nome corrente rinomina la voce in Tassonomia.', 'archivio-micologico') . '</p></div>';
		$html .= '<div class="am-manage__stats am-nom-stats">';
		foreach ($labels as $k => $l) {
			$html .= '<button type="button" class="am-manage__stat am-nom-filter am-nom-stat--' . esc_attr($k) . '" data-filter="' . esc_attr($k) . '" aria-pressed="false"><span class="am-nom-stat__label">' . esc_html($l) . '</span><span class="am-nom-stat__value" data-count="' . esc_attr($k) . '">' . esc_html(number_format_i18n($counts[$k] ?? 0)) . '</span></button>';
		}
		$html .= '</div></header>';
		$html .= '<div class="am-manage__toolbar">';
		$html .= '<button type="button" class="am-manage__btn" data-nom-run="unchecked">' . esc_html__('Verifica le schede mai verificate', 'archivio-micologico') . '</button>';
		$html .= '<button type="button" class="am-manage__btn am-manage__btn--ghost" data-nom-run="all">' . esc_html__('Verifica di nuovo tutte', 'archivio-micologico') . '</button>';
		$html .= '<button type="button" class="am-manage__btn am-manage__btn--danger" data-nom-stop hidden>' . esc_html__('Interrompi', 'archivio-micologico') . '</button>';
		$html .= '<button type="button" class="am-manage__btn am-manage__btn--ghost am-nom-filter" data-filter="">' . esc_html__('Mostra tutte', 'archivio-micologico') . '</button>';
		$html .= '<span class="am-nom-progress" aria-live="polite"></span>';
		$html .= '</div>';
		$html .= '<p class="am-manage__legend">' . esc_html__('Le richieste partono dal tuo browser verso api.checklistbank.org, una scheda alla volta: per tutto l’archivio servono alcuni minuti. Puoi chiudere la pagina e riprendere: le schede già verificate restano salvate. Clic su un riquadro in alto per vedere solo quel gruppo.', 'archivio-micologico') . '</p>';
		$html .= '<noscript><p class="am-manage__notice am-manage__notice--info">' . esc_html__('Per la verifica serve JavaScript attivo.', 'archivio-micologico') . '</p></noscript>';
		$html .= '<div class="am-manage__panel"><table class="am-mtable am-nom-table"><caption class="screen-reader-text">' . esc_html__('Nomenclatura delle schede', 'archivio-micologico') . '</caption>';
		$html .= '<thead><tr><th scope="col">' . esc_html__('Scheda', 'archivio-micologico') . '</th><th scope="col">' . esc_html__('Esito', 'archivio-micologico') . '</th><th scope="col">' . esc_html__('Autore secondo la fonte', 'archivio-micologico') . '</th><th scope="col">' . esc_html__('Sinonimi', 'archivio-micologico') . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__('Azioni', 'archivio-micologico') . '</span></th></tr></thead>';
		$html .= '<tbody>' . $rows . '</tbody></table></div></div>';
		return $html;
	}

	private static function squash(string $s): string {
		// The sanctioning author (": Fr.", ": Pers.") is optional in citations: not a difference.
		return mb_strtolower((string) preg_replace(['/:\s*[^\s()]+/u', '/[\s.]/u'], '', $s));
	}

	/** Result cell, also rebuilt by the script after each check. */
	public static function result_cell(array $d): string {
		$status = $d['status'] ?? '';
		$map = ['accepted' => __('Accettato', 'archivio-micologico'), 'synonym' => __('Sinonimo', 'archivio-micologico'), 'ambiguous' => __('Da controllare', 'archivio-micologico'), 'notfound' => __('Non trovato', 'archivio-micologico')];
		if (!isset($map[$status])) { return '<span class="am-status">' . esc_html__('Mai verificato', 'archivio-micologico') . '</span>'; }
		$html = '<span class="am-status am-status--nom-' . esc_attr($status) . '">' . esc_html($map[$status]) . '</span>';
		if ($status === 'synonym' && ($d['current'] ?? '') !== '') {
			$html .= ' ' . esc_html__('→', 'archivio-micologico') . ' <em>' . esc_html($d['current']) . '</em>';
		}
		if (($d['if_id'] ?? '') !== '') {
			$html .= ' <a href="' . esc_url(self::if_url($d['if_id'])) . '" target="_blank" rel="noopener">IF ' . esc_html($d['if_id']) . '</a>';
		}
		return $html;
	}

	/* ------------------------------------------------------------- search hook */

	/** Meta keys searched by the archive besides the title. */
	public static function search_meta_keys(): array {
		return self::search_synonyms() ? ['am_infra', 'am_author', 'am_nom_synonyms_text'] : ['am_infra', 'am_author'];
	}
}
