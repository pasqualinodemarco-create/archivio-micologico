<?php

namespace ArchivioMicologico;

if (!defined('ABSPATH')) { exit; }

/**
 * Leaf through the schede like a book: two large arrows fixed on the sides of the page, in the
 * order chosen in the settings (by default the archive's: division, family, scientific name), with a page-turn transition between
 * schede (cross-document View Transitions; browsers without support simply change page).
 */
final class BookNav {
	private const CACHE = 'am_book_order';
	public const EFFECTS = ['libro', 'scorrimento', 'dissolvenza', 'nessuno'];
	public const ORDERS = ['archivio', 'alfabetico', 'recenti'];

	public static function init(): void {
		add_action('wp_head', [self::class, 'head'], 1);
		add_action('wp_footer', [self::class, 'footer'], 20);
		add_action('template_redirect', [self::class, 'start'], 5);
		foreach (['transition_post_status', 'deleted_post', 'save_post_' . AM_CPT, 'edited_' . AM_TAX_CLASSIFICAZIONE, 'delete_' . AM_TAX_CLASSIFICAZIONE] as $hook) {
			add_action($hook, [self::class, 'flush']);
		}
	}

	public static function flush(): void {
		delete_transient(self::CACHE);
	}

	public static function enabled(): bool {
		return (int) get_option('am_book_nav', 1) === 1;
	}

	public static function effect(): string {
		$effect = (string) get_option('am_book_effect', 'libro');
		return in_array($effect, self::EFFECTS, true) ? $effect : 'libro';
	}

	public static function order_mode(): string {
		$mode = (string) get_option('am_book_order', 'archivio');
		return in_array($mode, self::ORDERS, true) ? $mode : 'archivio';
	}

	public static function speed(): int {
		return max(200, min(1500, (int) get_option('am_book_speed', 650)));
	}

	/** Published schede with the classification metas used for ordering and for the scope. */
	private static function rows(): array {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = ''",
			AM_CPT
		), ARRAY_A) ?: [];
		update_meta_cache('post', array_map(static fn($r) => (int) $r['ID'], $rows));
		foreach ($rows as &$r) {
			foreach (['division', 'family', 'genus'] as $rank) {
				$r[$rank] = (int) get_post_meta((int) $r['ID'], 'am_tax_' . $rank . '_id', true);
			}
		}
		unset($r);
		return $rows;
	}

	/** Term names for the given ids. */
	private static function names(array $ids): array {
		$ids = array_values(array_filter(array_unique(array_map('intval', $ids))));
		$names = [];
		if ($ids) {
			foreach (get_terms(['taxonomy' => AM_TAX_CLASSIFICAZIONE, 'include' => $ids, 'hide_empty' => false]) as $t) {
				$names[(int) $t->term_id] = (string) $t->name;
			}
		}
		return $names;
	}

	/**
	 * Published schede in the given order, optionally only inside one branch (a division, family or
	 * genus term id). Defaults: the order of the settings, the whole archive.
	 */
	public static function order(?string $mode = null, int $scope = 0): array {
		$mode = in_array($mode, self::ORDERS, true) ? $mode : self::order_mode();
		$key = $mode . '.' . $scope;
		$cache = get_transient(self::CACHE);
		$cache = is_array($cache) && !isset($cache[0]) ? $cache : []; // lists come from versions before 1.6.27
		if (isset($cache[$key]) && is_array($cache[$key])) { return $cache[$key]; }

		$rows = self::rows();
		if ($scope) {
			$rows = array_values(array_filter($rows, static fn($r) => in_array($scope, [$r['division'], $r['family'], $r['genus']], true)));
		}
		if ($mode === 'archivio') {
			$names = self::names(array_merge(array_column($rows, 'division'), array_column($rows, 'family')));
			usort($rows, static function ($a, $b) use ($names) {
				foreach (['division', 'family'] as $rank) {
					$c = strnatcasecmp($names[$a[$rank]] ?? '', $names[$b[$rank]] ?? '');
					if ($c !== 0) { return $c; }
				}
				$c = strnatcasecmp($a['post_title'], $b['post_title']);
				return $c !== 0 ? $c : ((int) $a['ID'] <=> (int) $b['ID']);
			});
		} else {
			usort($rows, static function ($a, $b) use ($mode) {
				$c = $mode === 'recenti' ? strcmp($b['post_date'], $a['post_date']) : strnatcasecmp($a['post_title'], $b['post_title']);
				return $c !== 0 ? $c : ((int) $a['ID'] <=> (int) $b['ID']);
			});
		}
		$cache[$key] = array_map(static fn($r) => (int) $r['ID'], $rows);
		set_transient(self::CACHE, $cache, 12 * HOUR_IN_SECONDS);
		return $cache[$key];
	}

	/**
	 * Branches a visitor can leaf through, in archive order: [term id, name, rank, published schede].
	 * Only branches with at least one published scheda.
	 */
	public static function scopes(): array {
		$rows = self::rows();
		$count = [];
		$tree = [];
		foreach ($rows as $r) {
			foreach (['division', 'family', 'genus'] as $rank) {
				if ($r[$rank]) { $count[$r[$rank]] = ($count[$r[$rank]] ?? 0) + 1; }
			}
			if ($r['division']) {
				$tree[$r['division']][$r['family']][$r['genus']] = true;
			}
		}
		$names = self::names(array_keys($count));
		$by_name = static function (array $ids) use ($names): array {
			$ids = array_values(array_filter($ids));
			usort($ids, static fn($x, $y) => strnatcasecmp($names[$x] ?? '', $names[$y] ?? ''));
			return $ids;
		};
		$out = [];
		foreach ($by_name(array_keys($tree)) as $d) {
			$out[] = [$d, $names[$d] ?? '', 'division', $count[$d]];
			foreach ($by_name(array_keys($tree[$d])) as $f) {
				$out[] = [$f, $names[$f] ?? '', 'family', $count[$f]];
				foreach ($by_name(array_keys($tree[$d][$f])) as $g) {
					$out[] = [$g, $names[$g] ?? '', 'genus', $count[$g]];
				}
			}
		}
		return $out;
	}

	/** Visitor's choice from ?am_book=<order>.<term id>: [order, scope, chosen by the visitor]. */
	public static function context(): array {
		$raw = isset($_GET['am_book']) ? sanitize_text_field(wp_unslash((string) $_GET['am_book'])) : '';
		if (preg_match('/^([a-z]+)\.(\d+)$/', $raw, $m) && in_array($m[1], self::ORDERS, true)) {
			$scope = (int) $m[2];
			if ($scope === 0 || term_exists($scope, AM_TAX_CLASSIFICAZIONE)) {
				return [$m[1], $scope, true];
			}
		}
		return [self::order_mode(), 0, false];
	}

	/** [previous id, next id, 1-based position, total]; 0 where the book ends. */
	public static function neighbours(int $post_id, ?string $mode = null, int $scope = 0): array {
		$order = self::order($mode, $scope);
		$pos = array_search($post_id, $order, true);
		if ($pos === false) { return [0, 0, 0, count($order)]; }
		return [$order[$pos - 1] ?? 0, $order[$pos + 1] ?? 0, $pos + 1, count($order)];
	}

	private static function current_id(): int {
		return (self::enabled() && is_singular(AM_CPT)) ? (int) get_queried_object_id() : 0;
	}

	/** Keeps the "Torna ai risultati" context and the visitor's way of leafing through. */
	private static function link(int $id): string {
		$url = (string) get_permalink($id);
		$return = isset($_GET['am_return']) ? esc_url_raw(wp_unslash((string) $_GET['am_return'])) : '';
		if ($return !== '') { $url = add_query_arg('am_return', rawurlencode($return), $url); }
		[$mode, $scope, $chosen] = self::context();
		return $chosen ? add_query_arg('am_book', $mode . '.' . $scope, $url) : $url;
	}

	/** "Inizia a sfogliare" on the archive page: open the first scheda of the visitor's choice. */
	public static function start(): void {
		if (!isset($_GET['am_book_start']) || !self::enabled() || is_admin()) { return; }
		$mode = isset($_GET['am_book_order']) ? sanitize_key((string) $_GET['am_book_order']) : '';
		$mode = in_array($mode, self::ORDERS, true) ? $mode : self::order_mode();
		$scope = isset($_GET['am_book_scope']) ? absint($_GET['am_book_scope']) : 0;
		if ($scope && !term_exists($scope, AM_TAX_CLASSIFICAZIONE)) { $scope = 0; }
		$order = self::order($mode, $scope);
		if (!$order) { return; }
		$archive = AdminPages::page_url('archive');
		$back = $archive !== '' ? $archive : home_url('/');
		$url = add_query_arg([
			'am_return' => rawurlencode($back),
			'am_book' => $mode . '.' . $scope,
		], (string) get_permalink($order[0]));
		wp_safe_redirect($url);
		exit;
	}

	/** The "Sfoglia l'archivio" panel of the archive page. */
	public static function panel(): string {
		if (!self::enabled()) { return ''; }
		$scopes = self::scopes();
		$labels = ['division' => __('Divisione', 'archivio-micologico'), 'family' => __('Famiglia', 'archivio-micologico'), 'genus' => __('Genere', 'archivio-micologico')];
		$indent = ['division' => '', 'family' => "\u{2003}", 'genus' => "\u{2003}\u{2003}"];
		$current = self::order_mode();
		$total = count(self::order($current, 0));

		$html = '<form class="am-bookpanel" method="get" action="" aria-labelledby="am-bookpanel-title">';
		$html .= '<input type="hidden" name="am_book_start" value="1">';
		$html .= '<div class="am-bookpanel__intro"><span class="am-bookpanel__icon" aria-hidden="true"></span><div>';
		$html .= '<h2 id="am-bookpanel-title">' . esc_html__('Sfoglia l’archivio', 'archivio-micologico') . '</h2>';
		$html .= '<p>' . esc_html__('Scegli ordine e capitolo, poi scorri le schede una dopo l’altra con le frecce ai lati o con i tasti ← →.', 'archivio-micologico') . '</p></div></div>';

		$html .= '<div class="am-bookpanel__fields"><label class="am-bookpanel__field"><span>' . esc_html__('Ordine', 'archivio-micologico') . '</span><select name="am_book_order">';
		foreach ([
			'archivio' => __('Tassonomico: divisione, famiglia, specie', 'archivio-micologico'),
			'alfabetico' => __('Alfabetico per nome scientifico', 'archivio-micologico'),
			'recenti' => __('Dalle schede più recenti', 'archivio-micologico'),
		] as $value => $label) {
			$html .= '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
		}
		$html .= '</select></label>';

		$html .= '<label class="am-bookpanel__field"><span>' . esc_html__('Da sfogliare', 'archivio-micologico') . '</span><select name="am_book_scope">';
		/* translators: %d: number of published schede. */
		$html .= '<option value="0">' . esc_html(sprintf(__('Tutto l’archivio (%d schede)', 'archivio-micologico'), $total)) . '</option>';
		foreach ($scopes as [$id, $name, $rank, $n]) {
			$html .= '<option value="' . esc_attr((string) $id) . '">' . esc_html($indent[$rank] . $labels[$rank] . ' ' . $name . ' (' . $n . ')') . '</option>';
		}
		$html .= '</select></label>';
		$html .= '<button type="submit" class="am-bookpanel__go">' . esc_html__('Inizia a sfogliare', 'archivio-micologico') . ' <span aria-hidden="true">→</span></button></div>';
		return $html . '</form>';
	}

	public static function head(): void {
		$id = self::current_id();
		if (!$id) { return; }
		[$mode, $scope] = self::context();
		[$prev, $next] = self::neighbours($id, $mode, $scope);
		foreach ([$prev, $next] as $n) {
			if ($n) { echo '<link rel="prefetch" href="' . esc_url(self::link($n)) . '">' . "\n"; }
		}
		$css = self::effect_css(self::effect(), self::speed());
		if ($css === '') { return; }
		echo '<style id="am-book-transition">' . $css . "</style>\n";
		// The new page learns the direction (forward/backward) chosen with the arrows.
		echo "<script>addEventListener('pagereveal',function(e){var d=null;try{d=sessionStorage.getItem('amBookDir');sessionStorage.removeItem('amBookDir');}catch(x){}if(e.viewTransition&&(d==='am-forward'||d==='am-backward')){e.viewTransition.types.add(d);}});</script>\n";
	}

	/** Transition CSS for the chosen effect; '' disables transitions. */
	public static function effect_css(string $effect, int $ms): string {
		if (!in_array($effect, self::EFFECTS, true) || $effect === 'nessuno') { return ''; }
		$d = $ms . 'ms';
		$f = 'html:active-view-transition-type(am-forward)';
		$b = 'html:active-view-transition-type(am-backward)';
		$css = '@view-transition{navigation:auto}'
			. 'body.single-' . AM_CPT . ' main{view-transition-name:am-sheet}'
			. '.am-book__arrow--prev{view-transition-name:am-book-prev}.am-book__arrow--next{view-transition-name:am-book-next}'
			. '::view-transition-group(am-book-prev),::view-transition-group(am-book-next),::view-transition-new(am-book-prev),::view-transition-new(am-book-next){animation:none}'
			. '::view-transition-old(am-book-prev),::view-transition-old(am-book-next){animation:none;opacity:0}'
			. $f . '::view-transition-group(am-sheet),' . $b . '::view-transition-group(am-sheet){animation:none}';
		if ($effect === 'libro') {
			$css .= $f . '::view-transition-image-pair(am-sheet),' . $b . '::view-transition-image-pair(am-sheet){perspective:2400px}'
				. $f . '::view-transition-old(am-sheet){transform-origin:left center;z-index:2;animation:am-book-turn ' . $d . ' cubic-bezier(.45,.05,.35,1) both}'
				. $f . '::view-transition-new(am-sheet){animation:am-book-under ' . $d . ' ease-out both}'
				. $b . '::view-transition-new(am-sheet){transform-origin:left center;z-index:2;animation:am-book-turn ' . $d . ' cubic-bezier(.45,.05,.35,1) reverse both}'
				. $b . '::view-transition-old(am-sheet){animation:am-book-under ' . $d . ' ease-out reverse both}'
				. '@keyframes am-book-turn{from{transform:rotateY(0);filter:brightness(1);opacity:1}70%{opacity:1}to{transform:rotateY(-100deg);filter:brightness(.6);opacity:0}}'
				. '@keyframes am-book-under{from{opacity:.4;filter:brightness(.85)}to{opacity:1;filter:none}}';
		} elseif ($effect === 'scorrimento') {
			$css .= $f . '::view-transition-old(am-sheet){animation:am-book-out-l ' . $d . ' ease both}'
				. $f . '::view-transition-new(am-sheet){animation:am-book-in-r ' . $d . ' ease both}'
				. $b . '::view-transition-old(am-sheet){animation:am-book-out-r ' . $d . ' ease both}'
				. $b . '::view-transition-new(am-sheet){animation:am-book-in-l ' . $d . ' ease both}'
				. '@keyframes am-book-out-l{to{transform:translateX(-12%);opacity:0}}@keyframes am-book-out-r{to{transform:translateX(12%);opacity:0}}'
				. '@keyframes am-book-in-r{from{transform:translateX(12%);opacity:0}}@keyframes am-book-in-l{from{transform:translateX(-12%);opacity:0}}';
		} else {
			$css .= $f . '::view-transition-old(am-sheet),' . $b . '::view-transition-old(am-sheet){animation:am-book-fade ' . $d . ' ease both reverse}'
				. $f . '::view-transition-new(am-sheet),' . $b . '::view-transition-new(am-sheet){animation:am-book-fade ' . $d . ' ease both}'
				. '@keyframes am-book-fade{from{opacity:0}}';
		}
		return $css . '@media (prefers-reduced-motion:reduce){::view-transition-group(*),::view-transition-old(*),::view-transition-new(*){animation:none!important}}';
	}

	public static function footer(): void {
		$id = self::current_id();
		if ($id) { echo self::markup($id); } // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built.
	}

	public static function markup(int $post_id): string {
		[$mode, $scope] = self::context();
		[$prev, $next, $pos, $total] = self::neighbours($post_id, $mode, $scope);
		if (!$prev && !$next) { return ''; }
		$chapter = '';
		if ($scope) {
			$term = get_term($scope, AM_TAX_CLASSIFICAZIONE);
			$chapter = ($term && !is_wp_error($term)) ? (string) $term->name . ' · ' : '';
		}
		$arrow = static function (int $id, string $side, int $n) use ($total, $chapter): string {
			$is_prev = $side === 'prev';
			$path = $is_prev ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7';
			$label = $is_prev ? __('Scheda precedente', 'archivio-micologico') : __('Scheda successiva', 'archivio-micologico');
			$title = wp_kses(SpeciesTitle::render_post($id), ['span' => ['class' => true], 'i' => []]);
			if ($title === '') { $title = esc_html(get_the_title($id)); }
			return '<a class="am-book__arrow am-book__arrow--' . $side . '" href="' . esc_url(self::link($id)) . '" rel="' . $side . '" data-am-dir="' . ($is_prev ? 'am-backward' : 'am-forward') . '">'
				. '<span class="am-book__btn" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" focusable="false"><path d="' . $path . '" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>'
				. '<span class="am-book__label"><small>' . esc_html($label) . ' · ' . esc_html($chapter . sprintf(__('%1$d di %2$d', 'archivio-micologico'), $n, $total)) . '</small><span class="am-book__title">' . $title . '</span></span>'
				. '</a>';
		};
		$html = '<nav class="am-book" aria-label="' . esc_attr__('Sfoglia le schede', 'archivio-micologico') . '">';
		if ($prev) { $html .= $arrow($prev, 'prev', $pos - 1); }
		if ($next) { $html .= $arrow($next, 'next', $pos + 1); }
		$html .= '</nav>';
		$html .= "<script>(function(){var nav=document.querySelector('.am-book');if(!nav){return;}"
			. "function go(a){try{sessionStorage.setItem('amBookDir',a.getAttribute('data-am-dir'));}catch(x){}}"
			. "nav.addEventListener('click',function(e){var a=e.target.closest('a');if(a){go(a);}});"
			. "document.addEventListener('keydown',function(e){if(e.defaultPrevented||e.altKey||e.ctrlKey||e.metaKey||e.shiftKey){return;}"
			. "var t=e.target;if(t&&(t.isContentEditable||/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))){return;}"
			. "if(document.documentElement.classList.contains('am-lightbox-open')){return;}"
			. "var a=nav.querySelector(e.key==='ArrowLeft'?'.am-book__arrow--prev':(e.key==='ArrowRight'?'.am-book__arrow--next':'#am-none'));"
			. "if(a){e.preventDefault();go(a);location.href=a.href;}});})();</script>";
		return $html;
	}
}
