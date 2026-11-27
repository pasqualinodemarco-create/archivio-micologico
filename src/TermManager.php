<?php

namespace ArchivioMicologico;

use WP_Term;

if (!defined('ABSPATH')) { exit; }

/**
 * Public management of the classification tree and of the controlled vocabularies
 * (form/variety, author) for Administrators and Editors: [am_tassonomia] and [am_vocabolari].
 *
 * Every change goes through one admin-post handler. A term can be deleted only when no
 * scheda (in any status) and no lower rank uses it. Renames, moves and merges rebuild the
 * stored titles of the schede involved, so "Genere specie forma autore" stays coherent.
 */
final class TermManager {
	private const ACTION = 'am_manage_term';
	private const RANKS = ['division', 'family', 'genus', 'species'];

	public static function init(): void {
		add_shortcode('am_tassonomia', [self::class, 'render_taxonomy']);
		add_shortcode('am_vocabolari', [self::class, 'render_vocabularies']);
		add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
		add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
	}

	public static function enqueue_assets(): void {
		$post = get_post();
		if (!$post || (!has_shortcode((string) $post->post_content, 'am_tassonomia') && !has_shortcode((string) $post->post_content, 'am_vocabolari'))) {
			return;
		}
		self::enqueue_files();
	}

	/** Same interface inside wp-admin: Archivio Micologico → Tassonomia / Vocabolari. */
	public static function enqueue_files(): void {
		wp_enqueue_style('am-manage', AM_URL . 'assets/css/am-manage.css', [], AM_VERSION);
		wp_enqueue_script('am-manage', AM_URL . 'assets/js/am-manage.js', [], AM_VERSION, true);
	}

	private static function can_manage(): bool {
		return EntryForms::am_current_user_is_admin_or_editor() && current_user_can('manage_terms');
	}

	private static function taxonomy_for(string $key): string {
		return ['classificazione' => AM_TAX_CLASSIFICAZIONE, 'infra' => AM_TAX_INFRASPEC, 'author' => AM_TAX_AUTHOR][$key] ?? '';
	}

	private static function rank_labels(): array {
		return [
			'division' => [__('Divisione', 'archivio-micologico'), __('divisioni', 'archivio-micologico')],
			'family' => [__('Famiglia', 'archivio-micologico'), __('famiglie', 'archivio-micologico')],
			'genus' => [__('Genere', 'archivio-micologico'), __('generi', 'archivio-micologico')],
			'species' => [__('Specie', 'archivio-micologico'), __('specie', 'archivio-micologico')],
		];
	}

	private static function messages(): array {
		return [
			'added' => __('Voce aggiunta.', 'archivio-micologico'),
			'renamed' => __('Nome aggiornato. Le schede collegate sono state riallineate.', 'archivio-micologico'),
			'moved' => __('Voce spostata. Le schede collegate sono state riallineate.', 'archivio-micologico'),
			'deleted' => __('Voce eliminata.', 'archivio-micologico'),
			'merged' => __('Voci unite. Le schede sono state assegnate alla voce scelta.', 'archivio-micologico'),
			'unchanged' => __('Nessuna modifica da salvare.', 'archivio-micologico'),
			'forbidden' => __('Non hai i permessi per questa operazione.', 'archivio-micologico'),
			'invalid' => __('Richiesta non valida. Ricarica la pagina e riprova.', 'archivio-micologico'),
			'empty_name' => __('Inserisci un nome.', 'archivio-micologico'),
			'exists' => __('Esiste già una voce con questo nome nello stesso livello.', 'archivio-micologico'),
			'exists_vocab' => __('Esiste già una voce con questo nome: per riunirle usa «Unisci».', 'archivio-micologico'),
			'bad_parent' => __('Il livello superiore scelto non è valido per questa voce.', 'archivio-micologico'),
			'max_depth' => __('La specie è l’ultimo livello: non può avere sotto-livelli.', 'archivio-micologico'),
			'in_use' => __('La voce è usata da almeno una scheda e non può essere eliminata.', 'archivio-micologico'),
			'has_children' => __('La voce contiene livelli inferiori e non può essere eliminata.', 'archivio-micologico'),
			'failed' => __('Operazione non riuscita.', 'archivio-micologico'),
		];
	}

	/* ------------------------------------------------------------------ data */

	/** Usage of each term: schede in any status (trash included, so a restore never loses its term) and active ones. */
	private static function usage(string $taxonomy): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT tt.term_id, COUNT(p.ID) AS total, SUM(p.post_status NOT IN ('trash','auto-draft')) AS active
			 FROM {$wpdb->term_taxonomy} tt
			 JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
			 JOIN {$wpdb->posts} p ON p.ID = tr.object_id AND p.post_type = %s
			 WHERE tt.taxonomy = %s
			 GROUP BY tt.term_id",
			AM_CPT,
			$taxonomy
		));
		$out = [];
		foreach ((array) $rows as $row) {
			$out[(int) $row->term_id] = ['total' => (int) $row->total, 'active' => (int) $row->active];
		}
		return $out;
	}

	private static function object_ids(array $term_ids, string $taxonomy): array {
		$ids = get_objects_in_term(array_map('intval', $term_ids), $taxonomy);
		return is_wp_error($ids) ? [] : array_values(array_unique(array_map('intval', $ids)));
	}

	/** Classification terms keyed by id, each with its rank derived from its depth. */
	private static function tree(): array {
		$terms = get_terms(['taxonomy' => AM_TAX_CLASSIFICAZIONE, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
		if (is_wp_error($terms)) { return [[], [], []]; }
		$by_id = [];
		$children = [];
		foreach ($terms as $t) {
			$by_id[(int) $t->term_id] = $t;
			$children[(int) $t->parent][] = (int) $t->term_id;
		}
		$depth = [];
		$queue = [[0, -1]];
		while ($queue) {
			[$parent, $d] = array_shift($queue);
			foreach ($children[$parent] ?? [] as $id) {
				$depth[$id] = $d + 1;
				$queue[] = [$id, $d + 1];
			}
		}
		return [$by_id, $children, $depth];
	}

	private static function rank_of(int $term_id): int {
		$term = get_term($term_id, AM_TAX_CLASSIFICAZIONE);
		if (!$term instanceof WP_Term) { return -1; }
		return count(get_ancestors($term_id, AM_TAX_CLASSIFICAZIONE, 'taxonomy'));
	}

	/* ------------------------------------------------------------- handlers */

	public static function handle(): void {
		$back = self::return_url();
		if (!is_user_logged_in()) {
			wp_safe_redirect(wp_login_url($back));
			exit;
		}
		if (!self::can_manage()) { self::back($back, 'forbidden'); }
		check_admin_referer(self::ACTION);

		$op = sanitize_key((string) ($_POST['op'] ?? ''));
		$taxonomy = self::taxonomy_for(sanitize_key((string) ($_POST['tax'] ?? '')));
		$term_id = absint($_POST['term_id'] ?? 0);
		$name = trim(sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? ''))));
		if ($taxonomy === '' || !taxonomy_exists($taxonomy)) { self::back($back, 'invalid'); }
		$is_tree = $taxonomy === AM_TAX_CLASSIFICAZIONE;

		if ($op === 'add') {
			$parent = $is_tree ? absint($_POST['parent'] ?? 0) : 0;
			if ($name === '') { self::back($back, 'empty_name'); }
			if ($parent > 0) {
				$rank = self::rank_of($parent);
				if ($rank < 0) { self::back($back, 'bad_parent'); }
				if ($rank >= 3) { self::back($back, 'max_depth'); }
			}
			if (self::sibling_exists($name, $taxonomy, $parent, 0)) { self::back($back, $is_tree ? 'exists' : 'exists_vocab'); }
			$created = wp_insert_term($name, $taxonomy, ['parent' => $parent]);
			if (is_wp_error($created)) { self::back($back, 'failed'); }
			self::back($back, 'added', (int) $created['term_id']);
		}

		$term = $term_id ? get_term($term_id, $taxonomy) : null;
		if (!$term instanceof WP_Term) { self::back($back, 'invalid'); }

		if ($op === 'rename') {
			if ($name === '') { self::back($back, 'empty_name', $term_id); }
			if ($name === $term->name) { self::back($back, 'unchanged', $term_id); }
			if (self::sibling_exists($name, $taxonomy, (int) $term->parent, $term_id)) { self::back($back, $is_tree ? 'exists' : 'exists_vocab', $term_id); }
			$posts = self::affected_posts($term, $taxonomy);
			$res = wp_update_term($term_id, $taxonomy, ['name' => $name]);
			if (is_wp_error($res)) { self::back($back, 'failed', $term_id); }
			self::sync_posts($posts);
			self::back($back, 'renamed', $term_id);
		}

		if ($op === 'move' && $is_tree) {
			$parent = absint($_POST['parent'] ?? 0);
			$rank = self::rank_of($term_id);
			if ($rank < 1 || $parent <= 0 || self::rank_of($parent) !== $rank - 1) { self::back($back, 'bad_parent', $term_id); }
			if ($parent === (int) $term->parent) { self::back($back, 'unchanged', $term_id); }
			if (self::sibling_exists($term->name, $taxonomy, $parent, $term_id)) { self::back($back, 'exists', $term_id); }
			$res = wp_update_term($term_id, $taxonomy, ['parent' => $parent]);
			if (is_wp_error($res)) { self::back($back, 'failed', $term_id); }
			self::sync_posts(self::affected_posts($term, $taxonomy));
			self::back($back, 'moved', $term_id);
		}

		if ($op === 'delete') {
			if ($is_tree && get_term_children($term_id, $taxonomy)) { self::back($back, 'has_children', $term_id); }
			if (self::object_ids([$term_id], $taxonomy)) { self::back($back, 'in_use', $term_id); }
			$res = wp_delete_term($term_id, $taxonomy);
			if (!$res || is_wp_error($res)) { self::back($back, 'failed', $term_id); }
			self::back($back, 'deleted', (int) $term->parent);
		}

		if ($op === 'merge' && !$is_tree) {
			$target = get_term(absint($_POST['target_id'] ?? 0), $taxonomy);
			if (!$target instanceof WP_Term || (int) $target->term_id === $term_id) { self::back($back, 'invalid', $term_id); }
			$meta_key = $taxonomy === AM_TAX_INFRASPEC ? 'am_infra_id' : 'am_author_id';
			$posts = self::object_ids([$term_id], $taxonomy);
			foreach ($posts as $post_id) {
				wp_set_object_terms($post_id, [(int) $target->term_id], $taxonomy, false);
				update_post_meta($post_id, $meta_key, (int) $target->term_id);
			}
			$res = wp_delete_term($term_id, $taxonomy);
			if (!$res || is_wp_error($res)) { self::back($back, 'failed', $term_id); }
			self::sync_posts($posts);
			self::back($back, 'merged', (int) $target->term_id);
		}

		self::back($back, 'invalid');
	}

	private static function sibling_exists(string $name, string $taxonomy, int $parent, int $except): bool {
		$siblings = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'parent' => is_taxonomy_hierarchical($taxonomy) ? $parent : '', 'name' => $name]);
		if (is_wp_error($siblings)) { return false; }
		foreach ($siblings as $s) {
			if ((int) $s->term_id !== $except && mb_strtolower($s->name) === mb_strtolower($name)) { return true; }
		}
		return false;
	}

	private static function affected_posts(WP_Term $term, string $taxonomy): array {
		$ids = [(int) $term->term_id];
		if (is_taxonomy_hierarchical($taxonomy)) {
			$children = get_term_children((int) $term->term_id, $taxonomy);
			if (!is_wp_error($children)) { $ids = array_merge($ids, array_map('intval', $children)); }
		}
		return self::object_ids($ids, $taxonomy);
	}

	private static function sync_posts(array $post_ids): void {
		foreach ($post_ids as $post_id) {
			if (get_post_type($post_id) === AM_CPT) { self::sync_post((int) $post_id); }
		}
	}

	/** Rebuild classification ids, name metas and title of one scheda from its current terms. */
	public static function sync_post(int $post_id): void {
		$leaf = absint(get_post_meta($post_id, 'am_tax_species_id', true));
		if (!$leaf || !get_term($leaf, AM_TAX_CLASSIFICAZIONE) instanceof WP_Term) {
			$leaf = 0;
			$best = -1;
			$terms = wp_get_object_terms($post_id, AM_TAX_CLASSIFICAZIONE);
			foreach (is_wp_error($terms) ? [] : $terms as $t) {
				$d = count(get_ancestors((int) $t->term_id, AM_TAX_CLASSIFICAZIONE, 'taxonomy'));
				if ($d > $best) { $best = $d; $leaf = (int) $t->term_id; }
			}
		}
		if (!$leaf) { return; }

		$chain = array_reverse(array_merge([$leaf], get_ancestors($leaf, AM_TAX_CLASSIFICAZIONE, 'taxonomy')));
		$names = [];
		foreach (self::RANKS as $i => $rank) {
			$id = (int) ($chain[$i] ?? 0);
			update_post_meta($post_id, 'am_tax_' . $rank . '_id', $id);
			$t = $id ? get_term($id, AM_TAX_CLASSIFICAZIONE) : null;
			$names[$rank] = $t instanceof WP_Term ? (string) $t->name : '';
		}
		update_post_meta($post_id, 'am_species', $names['species']);

		$extra = [];
		foreach (['am_infra' => AM_TAX_INFRASPEC, 'am_author' => AM_TAX_AUTHOR] as $meta => $tax) {
			$id = absint(get_post_meta($post_id, $meta . '_id', true));
			$t = $id ? get_term($id, $tax) : null;
			$extra[$meta] = $t instanceof WP_Term ? (string) $t->name : '';
			update_post_meta($post_id, $meta, $extra[$meta]);
		}

		// Same rule as the entry forms: Genere + specie (+ forma/varietà) (+ autore).
		$title = trim((string) preg_replace('/\s+/', ' ', implode(' ', array_filter([$names['genus'], $names['species'], $extra['am_infra'], $extra['am_author']]))));
		if ($title !== '' && $title !== get_post_field('post_title', $post_id)) {
			wp_update_post(['ID' => $post_id, 'post_title' => $title]);
		}
	}

	/** Page the request came from: the form's own return field, then the referer, both kept on this site. */
	private static function return_url(): string {
		$url = isset($_POST['am_return']) ? esc_url_raw(wp_unslash((string) $_POST['am_return'])) : '';
		if ($url === '') { $url = (string) wp_get_referer(); }
		if ($url !== '' && $url[0] === '/' && (strlen($url) < 2 || $url[1] !== '/')) { $url = home_url($url); }
		$url = wp_validate_redirect($url, home_url('/'));
		return remove_query_arg(['am_tm', 'am_tm_term'], $url);
	}

	private static function current_url(): string {
		$uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '/';
		if (is_admin()) {
			// wp-admin may live under a different base than home_url (WordPress in a subfolder).
			$query = (string) wp_parse_url($uri, PHP_URL_QUERY);
			return remove_query_arg(['am_tm', 'am_tm_term'], admin_url('admin.php' . ($query !== '' ? '?' . $query : '')));
		}
		return remove_query_arg(['am_tm', 'am_tm_term'], home_url($uri));
	}

	private static function back(string $url, string $code, int $term_id = 0): void {
		$args = ['am_tm' => $code];
		if ($term_id > 0) { $args['am_tm_term'] = $term_id; }
		wp_safe_redirect(add_query_arg($args, $url) . '#am-manage');
		exit;
	}

	/**
	 * Published schede per rank, for the admin dashboard: how many terms each rank has and how
	 * many of them hold at least one published scheda in their branch.
	 */
	public static function coverage(): array {
		global $wpdb;
		[$by_id, $children, $depth] = self::tree();
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT tt.term_id, COUNT(DISTINCT p.ID) AS n FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			 WHERE tt.taxonomy = %s AND p.post_type = %s AND p.post_status = 'publish'
			 GROUP BY tt.term_id",
			AM_TAX_CLASSIFICAZIONE,
			AM_CPT
		), ARRAY_A) ?: [];
		$own = [];
		foreach ($rows as $r) { $own[(int) $r['term_id']] = (int) $r['n']; }
		$branch = [];
		$sum = static function (int $id) use (&$sum, &$branch, $children, $own): int {
			$n = $own[$id] ?? 0;
			foreach ($children[$id] ?? [] as $c) { $n += $sum($c); }
			return $branch[$id] = $n;
		};
		foreach ($children[0] ?? [] as $root) { $sum($root); }

		$ranks = array_fill_keys(self::RANKS, ['terms' => 0, 'documented' => 0]);
		foreach ($by_id as $id => $t) {
			$rank = self::RANKS[$depth[$id] ?? 0] ?? null;
			if ($rank === null) { continue; }
			$ranks[$rank]['terms']++;
			if (($branch[$id] ?? 0) > 0) { $ranks[$rank]['documented']++; }
		}
		$divisions = [];
		foreach ($children[0] ?? [] as $id) {
			$divisions[] = ['name' => (string) $by_id[$id]->name, 'count' => (int) ($branch[$id] ?? 0)];
		}
		return ['ranks' => $ranks, 'divisions' => $divisions, 'labels' => self::rank_labels()];
	}

	/* ------------------------------------------------------------ rendering */

	private static function gate(): string {
		if (!is_user_logged_in()) {
			return '<div class="am-manage"><p class="am-manage__notice am-manage__notice--info">' . esc_html__('Questa pagina è riservata ad Amministratori ed Editori.', 'archivio-micologico') . ' <a href="' . esc_url(wp_login_url(get_permalink() ?: home_url('/'))) . '">' . esc_html__('Accedi', 'archivio-micologico') . '</a></p></div>';
		}
		if (!self::can_manage()) {
			return '<div class="am-manage"><p class="am-manage__notice am-manage__notice--error">' . esc_html__('Non hai i permessi per gestire l’archivio.', 'archivio-micologico') . '</p></div>';
		}
		return '';
	}

	private static function notice(): string {
		$code = isset($_GET['am_tm']) ? sanitize_key((string) $_GET['am_tm']) : '';
		$messages = self::messages();
		if (!isset($messages[$code])) { return ''; }
		$ok = in_array($code, ['added', 'renamed', 'moved', 'deleted', 'merged', 'unchanged'], true);
		return '<p class="am-manage__notice am-manage__notice--' . ($ok ? 'success' : 'error') . '" role="' . ($ok ? 'status' : 'alert') . '">' . esc_html($messages[$code]) . '</p>';
	}

	private static function header(string $title, string $intro, array $stats): string {
		$html = '<header class="am-manage__header"><div>';
		$html .= '<p class="am-manage__eyebrow">' . esc_html__('Gestione archivio', 'archivio-micologico') . '</p>';
		// The page already shows its own title: the shortcode keeps only the label and the intro.
		$html .= '<p class="screen-reader-text">' . esc_html($title) . '</p>';
		$html .= '<p class="am-manage__intro">' . esc_html($intro) . '</p></div>';
		$html .= '<dl class="am-manage__stats">';
		foreach ($stats as [$label, $value, $class]) {
			$html .= '<div class="am-manage__stat ' . esc_attr($class) . '"><dt>' . esc_html($label) . '</dt><dd>' . esc_html(number_format_i18n($value)) . '</dd></div>';
		}
		return $html . '</dl></header>';
	}

	private static function form_fields(string $tax): string {
		return '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
			. '<input type="hidden" name="tax" value="' . esc_attr($tax) . '">'
			. '<input type="hidden" name="am_return" value="' . esc_url(self::current_url()) . '">'
			. wp_nonce_field(self::ACTION, '_wpnonce', true, false);
	}

	private static function dialog(string $tax): string {
		$html = '<dialog class="am-manage__dialog" data-am-dialog aria-labelledby="am-manage-dialog-title">';
		$html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="am-manage__dialog-form">';
		$html .= self::form_fields($tax);
		$html .= '<input type="hidden" name="op" value=""><input type="hidden" name="term_id" value=""><input type="hidden" name="parent" value="">';
		$html .= '<h3 id="am-manage-dialog-title" data-am-dialog-title></h3>';
		$html .= '<p class="am-manage__dialog-text" data-am-dialog-text></p>';
		$html .= '<label class="am-manage__field" data-am-field="name"><span>' . esc_html__('Nome', 'archivio-micologico') . '</span><input type="text" name="name" maxlength="200" autocomplete="off"></label>';
		$html .= '<label class="am-manage__field" data-am-field="parent"><span data-am-parent-label></span><select data-am-parent-select></select></label>';
		$html .= '<label class="am-manage__field" data-am-field="target"><span>' . esc_html__('Unisci nella voce', 'archivio-micologico') . '</span><select name="target_id" data-am-target-select></select></label>';
		$html .= '<div class="am-manage__dialog-actions"><button type="button" class="am-manage__btn am-manage__btn--ghost" data-am-dialog-cancel>' . esc_html__('Annulla', 'archivio-micologico') . '</button>';
		$html .= '<button type="submit" class="am-manage__btn" data-am-dialog-submit></button></div>';
		return $html . '</form></dialog>';
	}

	public static function render_taxonomy($atts = []): string {
		$gate = self::gate();
		if ($gate !== '') { return $gate; }

		[$by_id, $children, $depth] = self::tree();
		$usage = self::usage(AM_TAX_CLASSIFICAZIONE);
		$labels = self::rank_labels();

		$per_rank = array_fill_keys(self::RANKS, 0);
		$options = array_fill_keys(self::RANKS, []);
		foreach ($by_id as $id => $t) {
			$rank = self::RANKS[$depth[$id] ?? 0] ?? null;
			if ($rank === null) { continue; }
			$per_rank[$rank]++;
			$parent_name = isset($by_id[(int) $t->parent]) ? $by_id[(int) $t->parent]->name : '';
			$options[$rank][] = ['id' => $id, 'name' => $t->name, 'group' => $parent_name];
		}

		// Active schede in each branch, for the counters.
		$branch = [];
		$sum = static function (int $id) use (&$sum, &$branch, $children, $usage): int {
			$n = (int) ($usage[$id]['active'] ?? 0);
			foreach ($children[$id] ?? [] as $c) { $n += $sum($c); }
			return $branch[$id] = $n;
		};
		foreach ($children[0] ?? [] as $root) { $sum($root); }

		$stats = [];
		foreach (self::RANKS as $rank) {
			$stats[] = [ucfirst($labels[$rank][1]), $per_rank[$rank], 'am-manage__stat--' . $rank];
		}

		$focus = isset($_GET['am_tm_term']) ? absint($_GET['am_tm_term']) : 0;
		$html = '<div class="am-manage am-manage--taxonomy" id="am-manage" data-am-manage="tree" data-am-focus="' . esc_attr((string) $focus) . '">';
		$html .= self::header(
			__('Tassonomia', 'archivio-micologico'),
			__('Gestisci la classificazione dell’archivio: divisioni, famiglie, generi e specie. Rinomina o sposta una voce e le schede collegate si aggiornano da sole.', 'archivio-micologico'),
			$stats
		);
		$html .= self::notice();
		$html .= '<div class="am-manage__toolbar">';
		$html .= '<label class="am-manage__search"><span class="screen-reader-text">' . esc_html__('Cerca nella tassonomia', 'archivio-micologico') . '</span><input type="search" placeholder="' . esc_attr__('Cerca divisione, famiglia, genere o specie…', 'archivio-micologico') . '" data-am-filter></label>';
		$html .= '<button type="button" class="am-manage__btn am-manage__btn--ghost" data-am-expand="1">' . esc_html__('Espandi tutto', 'archivio-micologico') . '</button>';
		$html .= '<button type="button" class="am-manage__btn am-manage__btn--ghost" data-am-expand="0">' . esc_html__('Comprimi tutto', 'archivio-micologico') . '</button>';
		$html .= '<button type="button" class="am-manage__btn" data-am-op="add" data-am-parent="0" data-am-rank="division">' . esc_html__('+ Nuova divisione', 'archivio-micologico') . '</button>';
		$html .= '</div>';
		$html .= '<p class="am-manage__legend">';
		foreach (self::RANKS as $rank) {
			$html .= '<span class="am-manage__rank am-manage__rank--' . esc_attr($rank) . '">' . esc_html($labels[$rank][0]) . '</span>';
		}
		$html .= '</p>';
		$html .= '<noscript><p class="am-manage__notice am-manage__notice--info">' . esc_html__('Per modificare la tassonomia serve JavaScript attivo.', 'archivio-micologico') . '</p></noscript>';

		$render = static function (int $parent) use (&$render, $by_id, $children, $depth, $usage, $branch, $labels): string {
			if (empty($children[$parent])) { return ''; }
			$out = '<ul class="am-mtree"' . ($parent === 0 ? ' role="tree"' : ' role="group"') . '>';
			foreach ($children[$parent] as $id) {
				$t = $by_id[$id];
				$d = (int) ($depth[$id] ?? 0);
				$rank = self::RANKS[$d] ?? 'species';
				$kids = count($children[$id] ?? []);
				$uses = (int) ($usage[$id]['total'] ?? 0);
				$child_rank = self::RANKS[$d + 1] ?? '';
				$blocked = $kids > 0 ? __('Contiene livelli inferiori: non si può eliminare.', 'archivio-micologico') : ($uses > 0 ? sprintf(_n('Usata da %d scheda: non si può eliminare.', 'Usata da %d schede: non si può eliminare.', $uses, 'archivio-micologico'), $uses) : '');

				$out .= '<li class="am-mnode am-mnode--' . esc_attr($rank) . '" role="treeitem" aria-expanded="true" data-id="' . esc_attr((string) $id) . '" data-rank="' . esc_attr($rank) . '" data-name="' . esc_attr($t->name) . '" data-parent="' . esc_attr((string) $t->parent) . '">';
				$out .= '<div class="am-mnode__row">';
				$out .= $kids ? '<button type="button" class="am-mnode__toggle" aria-label="' . esc_attr(sprintf(__('Apri o chiudi %s', 'archivio-micologico'), $t->name)) . '" data-am-toggle></button>' : '<span class="am-mnode__toggle am-mnode__toggle--leaf" aria-hidden="true"></span>';
				$out .= '<span class="am-mnode__rank">' . esc_html($labels[$rank][0]) . '</span>';
				$out .= '<span class="am-mnode__name">' . esc_html($t->name) . '</span>';
				$count = $rank === 'species' ? (int) ($usage[$id]['active'] ?? 0) : (int) ($branch[$id] ?? 0);
				$out .= '<span class="am-mnode__count" title="' . esc_attr__('Schede', 'archivio-micologico') . '">' . esc_html(sprintf(_n('%d scheda', '%d schede', $count, 'archivio-micologico'), $count)) . '</span>';
				$out .= '<span class="am-mnode__actions">';
				if ($child_rank !== '') {
					$out .= '<button type="button" class="am-manage__btn am-manage__btn--small" data-am-op="add" data-am-parent="' . esc_attr((string) $id) . '" data-am-rank="' . esc_attr($child_rank) . '">' . esc_html('+ ' . $labels[$child_rank][0]) . '</button>';
				}
				$out .= '<button type="button" class="am-manage__btn am-manage__btn--small am-manage__btn--ghost" data-am-op="rename">' . esc_html__('Rinomina', 'archivio-micologico') . '</button>';
				if ($d > 0) {
					$out .= '<button type="button" class="am-manage__btn am-manage__btn--small am-manage__btn--ghost" data-am-op="move">' . esc_html__('Sposta', 'archivio-micologico') . '</button>';
				}
				$out .= '<button type="button" class="am-manage__btn am-manage__btn--small am-manage__btn--danger" data-am-op="delete"' . ($blocked !== '' ? ' disabled title="' . esc_attr($blocked) . '"' : '') . '>' . esc_html__('Elimina', 'archivio-micologico') . '</button>';
				$out .= '</span></div>';
				$out .= $render($id);
				$out .= '</li>';
			}
			return $out . '</ul>';
		};

		$html .= '<div class="am-manage__panel">' . ($render(0) ?: '<p class="am-manage__empty">' . esc_html__('La tassonomia è vuota: inizia aggiungendo una divisione.', 'archivio-micologico') . '</p>') . '</div>';
		$html .= '<p class="am-manage__empty" data-am-no-results hidden>' . esc_html__('Nessuna voce corrisponde alla ricerca.', 'archivio-micologico') . '</p>';
		$html .= self::dialog('classificazione');
		$html .= '<script type="application/json" data-am-config>' . wp_json_encode([
			'mode' => 'tree',
			'ranks' => array_map(static fn($l) => $l[0], $labels),
			'options' => $options,
			'text' => [
				'add' => __('Nuova voce: %s', 'archivio-micologico'),
				'addIn' => __('Sarà inserita in «%s».', 'archivio-micologico'),
				'rename' => __('Rinomina «%s»', 'archivio-micologico'),
				'renameHint' => __('Le schede collegate a questa voce e ai livelli inferiori aggiorneranno il titolo.', 'archivio-micologico'),
				'move' => __('Sposta «%s»', 'archivio-micologico'),
				'moveHint' => __('Scegli il nuovo livello superiore. I livelli inferiori e le schede si spostano insieme.', 'archivio-micologico'),
				'moveTo' => __('Nuovo livello superiore (%s)', 'archivio-micologico'),
				'delete' => __('Elimina «%s»', 'archivio-micologico'),
				'deleteHint' => __('La voce non è usata da nessuna scheda. L’eliminazione non si può annullare.', 'archivio-micologico'),
				'save' => __('Salva', 'archivio-micologico'),
				'confirmDelete' => __('Elimina definitivamente', 'archivio-micologico'),
			],
		]) . '</script>';
		return $html . '</div>';
	}

	public static function render_vocabularies($atts = []): string {
		$gate = self::gate();
		if ($gate !== '') { return $gate; }

		$vocabs = [
			'infra' => [AM_TAX_INFRASPEC, __('Forma / Varietà', 'archivio-micologico'), __('+ Nuova forma o varietà', 'archivio-micologico'), __('Es. f. alba, var. rubra', 'archivio-micologico')],
			'author' => [AM_TAX_AUTHOR, __('Autori', 'archivio-micologico'), __('+ Nuovo autore', 'archivio-micologico'), __('Es. (Fr.) P. Kumm.', 'archivio-micologico')],
		];
		$current = isset($_GET['am_vocab']) ? sanitize_key((string) $_GET['am_vocab']) : 'infra';
		if (!isset($vocabs[$current])) { $current = 'infra'; }
		[$taxonomy, $label, $add_label, $placeholder] = $vocabs[$current];

		$terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
		$terms = is_wp_error($terms) ? [] : $terms;
		$usage = self::usage($taxonomy);
		$used = 0;
		$options = [];
		foreach ($terms as $t) {
			if (!empty($usage[(int) $t->term_id]['total'])) { $used++; }
			$options[] = ['id' => (int) $t->term_id, 'name' => $t->name];
		}

		$focus = isset($_GET['am_tm_term']) ? absint($_GET['am_tm_term']) : 0;
		$html = '<div class="am-manage am-manage--vocab" id="am-manage" data-am-manage="vocab" data-am-focus="' . esc_attr((string) $focus) . '">';
		$html .= self::header(
			__('Vocabolari', 'archivio-micologico'),
			__('Le voci controllate usate nei titoli delle schede: forme o varietà e autori. Una correzione qui si riflette su tutte le schede che le usano.', 'archivio-micologico'),
			[
				[__('Voci', 'archivio-micologico'), count($terms), ''],
				[__('In uso', 'archivio-micologico'), $used, ''],
				[__('Non usate', 'archivio-micologico'), count($terms) - $used, ''],
			]
		);
		$html .= self::notice();

		$html .= '<nav class="am-manage__tabs" aria-label="' . esc_attr__('Vocabolari', 'archivio-micologico') . '">';
		foreach ($vocabs as $key => $v) {
			$url = add_query_arg('am_vocab', $key, remove_query_arg(['am_tm', 'am_tm_term'])) . '#am-manage';
			$html .= '<a href="' . esc_url($url) . '" class="am-manage__tab' . ($key === $current ? ' is-active' : '') . '"' . ($key === $current ? ' aria-current="page"' : '') . '>' . esc_html($v[1]) . '</a>';
		}
		$html .= '</nav>';

		$html .= '<div class="am-manage__toolbar">';
		$html .= '<label class="am-manage__search"><span class="screen-reader-text">' . esc_html__('Cerca', 'archivio-micologico') . '</span><input type="search" placeholder="' . esc_attr__('Cerca…', 'archivio-micologico') . '" data-am-filter></label>';
		$html .= '<button type="button" class="am-manage__btn" data-am-op="add" data-am-parent="0" data-am-placeholder="' . esc_attr($placeholder) . '">' . esc_html($add_label) . '</button>';
		$html .= '</div>';
		$html .= '<noscript><p class="am-manage__notice am-manage__notice--info">' . esc_html__('Per modificare i vocabolari serve JavaScript attivo.', 'archivio-micologico') . '</p></noscript>';

		$html .= '<div class="am-manage__panel"><table class="am-mtable"><caption class="screen-reader-text">' . esc_html($label) . '</caption>';
		$html .= '<thead><tr><th scope="col">' . esc_html__('Nome', 'archivio-micologico') . '</th><th scope="col">' . esc_html__('Schede', 'archivio-micologico') . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__('Azioni', 'archivio-micologico') . '</span></th></tr></thead><tbody>';
		foreach ($terms as $t) {
			$id = (int) $t->term_id;
			$uses = (int) ($usage[$id]['total'] ?? 0);
			$active = (int) ($usage[$id]['active'] ?? 0);
			$html .= '<tr class="am-mrow" data-id="' . esc_attr((string) $id) . '" data-name="' . esc_attr($t->name) . '">';
			$html .= '<th scope="row" class="am-mrow__name">' . esc_html($t->name) . '</th>';
			$html .= '<td class="am-mrow__count"><span class="am-mnode__count' . ($uses ? '' : ' is-zero') . '">' . esc_html(number_format_i18n($active)) . '</span></td>';
			$html .= '<td class="am-mrow__actions">';
			$html .= '<button type="button" class="am-manage__btn am-manage__btn--small am-manage__btn--ghost" data-am-op="rename">' . esc_html__('Rinomina', 'archivio-micologico') . '</button>';
			$html .= '<button type="button" class="am-manage__btn am-manage__btn--small am-manage__btn--ghost" data-am-op="merge"' . (count($terms) < 2 ? ' disabled' : '') . '>' . esc_html__('Unisci', 'archivio-micologico') . '</button>';
			$html .= '<button type="button" class="am-manage__btn am-manage__btn--small am-manage__btn--danger" data-am-op="delete"' . ($uses ? ' disabled title="' . esc_attr(sprintf(_n('Usata da %d scheda: non si può eliminare.', 'Usata da %d schede: non si può eliminare.', $uses, 'archivio-micologico'), $uses)) . '"' : '') . '>' . esc_html__('Elimina', 'archivio-micologico') . '</button>';
			$html .= '</td></tr>';
		}
		$html .= '</tbody></table>';
		if (!$terms) { $html .= '<p class="am-manage__empty">' . esc_html__('Ancora nessuna voce.', 'archivio-micologico') . '</p>'; }
		$html .= '</div>';
		$html .= '<p class="am-manage__empty" data-am-no-results hidden>' . esc_html__('Nessuna voce corrisponde alla ricerca.', 'archivio-micologico') . '</p>';
		$html .= self::dialog($current);
		$html .= '<script type="application/json" data-am-config>' . wp_json_encode([
			'mode' => 'vocab',
			'options' => $options,
			'text' => [
				'add' => $add_label,
				'addHint' => __('La nuova voce sarà subito disponibile nei moduli di inserimento e modifica.', 'archivio-micologico'),
				'rename' => __('Rinomina «%s»', 'archivio-micologico'),
				'renameHint' => __('Il titolo delle schede che usano questa voce verrà aggiornato.', 'archivio-micologico'),
				'merge' => __('Unisci «%s»', 'archivio-micologico'),
				'mergeHint' => __('Le schede passano alla voce scelta e «%s» viene eliminata. Utile per correggere doppioni.', 'archivio-micologico'),
				'delete' => __('Elimina «%s»', 'archivio-micologico'),
				'deleteHint' => __('La voce non è usata da nessuna scheda. L’eliminazione non si può annullare.', 'archivio-micologico'),
				'save' => __('Salva', 'archivio-micologico'),
				'confirmMerge' => __('Unisci', 'archivio-micologico'),
				'confirmDelete' => __('Elimina definitivamente', 'archivio-micologico'),
			],
		]) . '</script>';
		return $html . '</div>';
	}
}
