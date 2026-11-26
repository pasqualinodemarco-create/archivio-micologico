<?php

namespace ArchivioMicologico;

if (!defined('ABSPATH')) { exit; }

/**
 * Leaf through the schede like a book: two large arrows fixed on the sides of the page, in the
 * order of the archive (division, family, scientific name), with a page-turn transition between
 * schede (cross-document View Transitions; browsers without support simply change page).
 */
final class BookNav {
	private const CACHE = 'am_book_order';
	public const EFFECTS = ['libro', 'scorrimento', 'dissolvenza', 'nessuno'];

	public static function init(): void {
		add_action('wp_head', [self::class, 'head'], 1);
		add_action('wp_footer', [self::class, 'footer'], 20);
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

	public static function speed(): int {
		return max(200, min(1500, (int) get_option('am_book_speed', 650)));
	}

	/** Published schede in archive order: division, family, then scientific name. */
	public static function order(): array {
		$cached = get_transient(self::CACHE);
		if (is_array($cached)) { return $cached; }

		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare(
			"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = ''",
			AM_CPT
		), ARRAY_A) ?: [];
		$ids = array_map(static fn($r) => (int) $r['ID'], $rows);
		update_meta_cache('post', $ids);
		$term_ids = [];
		foreach ($ids as $id) {
			$term_ids[] = (int) get_post_meta($id, 'am_tax_division_id', true);
			$term_ids[] = (int) get_post_meta($id, 'am_tax_family_id', true);
		}
		$names = [];
		$term_ids = array_values(array_filter(array_unique($term_ids)));
		if ($term_ids) {
			foreach (get_terms(['taxonomy' => AM_TAX_CLASSIFICAZIONE, 'include' => $term_ids, 'hide_empty' => false]) as $t) {
				$names[(int) $t->term_id] = (string) $t->name;
			}
		}
		$keyed = [];
		foreach ($rows as $r) {
			$id = (int) $r['ID'];
			$keyed[] = [
				$names[(int) get_post_meta($id, 'am_tax_division_id', true)] ?? '',
				$names[(int) get_post_meta($id, 'am_tax_family_id', true)] ?? '',
				(string) $r['post_title'],
				$id,
			];
		}
		usort($keyed, static function ($a, $b) {
			for ($i = 0; $i < 3; $i++) {
				$c = strnatcasecmp($a[$i], $b[$i]);
				if ($c !== 0) { return $c; }
			}
			return $a[3] <=> $b[3];
		});
		$order = array_map(static fn($k) => $k[3], $keyed);
		set_transient(self::CACHE, $order, 12 * HOUR_IN_SECONDS);
		return $order;
	}

	/** [previous id, next id, 1-based position, total]; 0 where the book ends. */
	public static function neighbours(int $post_id): array {
		$order = self::order();
		$pos = array_search($post_id, $order, true);
		if ($pos === false) { return [0, 0, 0, count($order)]; }
		return [$order[$pos - 1] ?? 0, $order[$pos + 1] ?? 0, $pos + 1, count($order)];
	}

	private static function current_id(): int {
		return (self::enabled() && is_singular(AM_CPT)) ? (int) get_queried_object_id() : 0;
	}

	/** Keeps the "Torna ai risultati" context while leafing through. */
	private static function link(int $id): string {
		$url = (string) get_permalink($id);
		$return = isset($_GET['am_return']) ? esc_url_raw(wp_unslash((string) $_GET['am_return'])) : '';
		return $return !== '' ? add_query_arg('am_return', rawurlencode($return), $url) : $url;
	}

	public static function head(): void {
		$id = self::current_id();
		if (!$id) { return; }
		[$prev, $next] = self::neighbours($id);
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
		[$prev, $next, $pos, $total] = self::neighbours($post_id);
		if (!$prev && !$next) { return ''; }
		$arrow = static function (int $id, string $side, int $n) use ($total): string {
			$is_prev = $side === 'prev';
			$path = $is_prev ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7';
			$label = $is_prev ? __('Scheda precedente', 'archivio-micologico') : __('Scheda successiva', 'archivio-micologico');
			$title = wp_kses(SpeciesTitle::render_post($id), ['span' => ['class' => true], 'i' => []]);
			if ($title === '') { $title = esc_html(get_the_title($id)); }
			return '<a class="am-book__arrow am-book__arrow--' . $side . '" href="' . esc_url(self::link($id)) . '" rel="' . $side . '" data-am-dir="' . ($is_prev ? 'am-backward' : 'am-forward') . '">'
				. '<span class="am-book__btn" aria-hidden="true"><svg viewBox="0 0 24 24" width="30" height="30" focusable="false"><path d="' . $path . '" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>'
				. '<span class="am-book__label"><small>' . esc_html($label) . ' · ' . esc_html(sprintf(__('%1$d di %2$d', 'archivio-micologico'), $n, $total)) . '</small><span class="am-book__title">' . $title . '</span></span>'
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
