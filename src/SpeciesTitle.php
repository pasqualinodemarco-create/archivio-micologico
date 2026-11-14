<?php

namespace ArchivioMicologico;

use WP_Term;

if (!defined('ABSPATH')) { exit; }

class SpeciesTitle {
	public static function format($full_name) {
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

	public static function post_parts(int $post_id): array {
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

	public static function render_post(int $post_id): string {
		$p = self::post_parts($post_id);
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
}
