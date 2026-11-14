<?php

namespace ArchivioMicologico;

use WP_Error;

if (!defined('ABSPATH')) { exit; }

class Taxonomies {
	public static function init(): void {
		add_action('init', [__CLASS__, 'register']);
		add_action('init', [__CLASS__, 'maybe_migrate_add_division_level'], 20);

		// Enforce: taxonomy terms can be created/edited only by reviewer/manager.
		add_filter('pre_insert_term', [__CLASS__, 'guard_term_insert'], 10, 2);
		add_filter('pre_update_term', [__CLASS__, 'guard_term_update'], 10, 3);
		add_filter('pre_delete_term', [__CLASS__, 'guard_term_delete'], 10, 2);
	}

	public static function register(): void {
		$labels = [
			'name' => __('Classificazione', 'archivio-micologico'),
			'singular_name' => __('Classificazione', 'archivio-micologico'),
			'search_items' => __('Cerca termini', 'archivio-micologico'),
			'all_items' => __('Tutti i termini', 'archivio-micologico'),
			'parent_item' => __('Categoria genitore', 'archivio-micologico'),
			'parent_item_colon' => __('Categoria genitore:', 'archivio-micologico'),
			'edit_item' => __('Modifica termine', 'archivio-micologico'),
			'update_item' => __('Aggiorna termine', 'archivio-micologico'),
			'add_new_item' => __('Aggiungi nuovo termine', 'archivio-micologico'),
			'new_item_name' => __('Nome nuovo termine', 'archivio-micologico'),
			'menu_name' => __('Classificazione', 'archivio-micologico'),
		];

		register_taxonomy(AM_TAX_CLASSIFICAZIONE, [AM_CPT], [
			'hierarchical' => true,
			'labels' => $labels,
			'show_ui' => true,
			'show_admin_column' => true,
			'show_in_rest' => true,
			'public' => true,
			'rewrite' => [
				'slug' => 'classificazione-fungo',
				'hierarchical' => true,
			],
			'capabilities' => [
				'manage_terms' => 'manage_terms',
				'edit_terms' => 'edit_terms',
				'delete_terms' => 'delete_terms',
				'assign_terms' => 'assign_terms',
			],
		]);

		// Controlled vocabularies (non-hierarchical) for Forma/Varietà and Autore.
		register_taxonomy(AM_TAX_INFRASPEC, [AM_CPT], [
			'labels' => [
				'name' => __('Forma / Varietà', 'archivio-micologico'),
				'singular_name' => __('Forma / Varietà', 'archivio-micologico'),
			],
			'public' => false,
			'show_ui' => false,
			'show_in_menu' => false,
			'show_admin_column' => false,
			'show_in_rest' => false,
						'meta_box_cb' => false,
			'hierarchical' => false,
			'rewrite' => false,
			'capabilities' => [
				'manage_terms' => 'manage_terms',
				'edit_terms' => 'edit_terms',
				'delete_terms' => 'delete_terms',
				'assign_terms' => 'assign_terms',
			],
		]);

		register_taxonomy(AM_TAX_AUTHOR, [AM_CPT], [
			'labels' => [
				'name' => __('Autore', 'archivio-micologico'),
				'singular_name' => __('Autore', 'archivio-micologico'),
			],
			'public' => false,
			'show_ui' => false,
			'show_in_menu' => false,
			'show_admin_column' => false,
			'show_in_rest' => false,
						'meta_box_cb' => false,
			'hierarchical' => false,
			'rewrite' => false,
			'capabilities' => [
				'manage_terms' => 'manage_terms',
				'edit_terms' => 'edit_terms',
				'delete_terms' => 'delete_terms',
				'assign_terms' => 'assign_terms',
			],
		]);

		// Categorie Articoli (gerarchica, multi-selezione).
		register_taxonomy(AM_TAX_ARTICLE_CAT, [AM_ARTICLE_CPT], [
			'hierarchical' => true,
			'labels' => [
				'name' => __('Categorie Articoli', 'archivio-micologico'),
				'singular_name' => __('Categoria Articolo', 'archivio-micologico'),
				'search_items' => __('Cerca categorie', 'archivio-micologico'),
				'all_items' => __('Tutte le categorie', 'archivio-micologico'),
				'edit_item' => __('Modifica categoria', 'archivio-micologico'),
				'update_item' => __('Aggiorna categoria', 'archivio-micologico'),
				'add_new_item' => __('Aggiungi nuova categoria', 'archivio-micologico'),
				'new_item_name' => __('Nome nuova categoria', 'archivio-micologico'),
				'menu_name' => __('Categorie Articoli', 'archivio-micologico'),
			],
			'public' => true,
			'show_ui' => true,
			'show_admin_column' => true,
			'show_in_rest' => true,
			'rewrite' => [
				'slug' => 'categorie-articoli',
				'hierarchical' => true,
			],
			'capabilities' => [
				'manage_terms' => 'manage_terms',
				'edit_terms' => 'edit_terms',
				'delete_terms' => 'delete_terms',
				'assign_terms' => 'assign_terms',
			],
		]);

	}

	public static function guard_term_insert($term, string $taxonomy) {
		if (!in_array($taxonomy, [AM_TAX_CLASSIFICAZIONE, AM_TAX_INFRASPEC, AM_TAX_AUTHOR, AM_TAX_ARTICLE_CAT], true)) {
			return $term;
		}

		// Allow controlled front-end insertion via our AJAX endpoint (v1.2.11+), BUT still require permissions.
		global $am_allow_frontend_term_insert;
		if (!empty($am_allow_frontend_term_insert)) {
			if (!is_user_logged_in()) {
				return new WP_Error('am_terms_forbidden', __('Non autorizzato.', 'archivio-micologico'));
			}
			if (!current_user_can('manage_terms') && !current_user_can('manage_options')) {
				return new WP_Error('am_terms_forbidden', __('Non hai i permessi per creare termini di classificazione.', 'archivio-micologico'));
			}
			return $term;
		}

if (!is_user_logged_in()) {
			return new WP_Error('am_terms_forbidden', __('Non autorizzato.', 'archivio-micologico'));
		}

		// Only reviewer/manager (or admin) can create terms.
		if (!current_user_can('manage_terms') && !current_user_can('manage_options')) {
			return new WP_Error('am_terms_forbidden', __('Non hai i permessi per creare termini di classificazione.', 'archivio-micologico'));
		}

		return $term;
	}

	public static function guard_term_update($term_id, string $taxonomy, array $args) {
		if (!in_array($taxonomy, [AM_TAX_CLASSIFICAZIONE, AM_TAX_INFRASPEC, AM_TAX_AUTHOR, AM_TAX_ARTICLE_CAT], true)) {
			return $term_id;
		}
		if (!is_user_logged_in() || !current_user_can('edit_terms')) {
			return new WP_Error('am_terms_forbidden', __('Non hai i permessi per modificare termini di classificazione.', 'archivio-micologico'));
		}
		return $term_id;
	}

	public static function guard_term_delete($term, string $taxonomy) {
		if (!in_array($taxonomy, [AM_TAX_CLASSIFICAZIONE, AM_TAX_INFRASPEC, AM_TAX_AUTHOR, AM_TAX_ARTICLE_CAT], true)) {
			return $term;
		}
		if (!is_user_logged_in() || !current_user_can('delete_terms')) {
			return new WP_Error('am_terms_forbidden', __('Non hai i permessi per eliminare termini di classificazione.', 'archivio-micologico'));
		}
		return $term;
	}

	/**
	 * Migrazione classificazione: aggiunge il livello Divisione sopra Famiglia.
	 * Tutte le classificazioni esistenti vengono spostate sotto Basidiomycota,
	 * inclusi eventuali figli del termine legacy "Ordine non assegnato".
	 */
	public static function maybe_migrate_add_division_level(): void {
		$done = (int) get_option('am_migrated_division_level', 0);
		if ($done === 1) { return; }
		if (!taxonomy_exists(AM_TAX_CLASSIFICAZIONE)) { return; }

		$basidio = get_term_by('slug', 'basidiomycota', AM_TAX_CLASSIFICAZIONE);
		if (!$basidio || is_wp_error($basidio)) {
			$ins = wp_insert_term('Basidiomycota', AM_TAX_CLASSIFICAZIONE, ['slug' => 'basidiomycota', 'parent' => 0]);
			if (is_wp_error($ins)) { return; }
			$basidio_id = (int) ($ins['term_id'] ?? 0);
		} else {
			$basidio_id = (int) $basidio->term_id;
		}
		if ($basidio_id <= 0) { return; }

		$asco = get_term_by('slug', 'ascomycota', AM_TAX_CLASSIFICAZIONE);
		if (!$asco || is_wp_error($asco)) {
			wp_insert_term('Ascomycota', AM_TAX_CLASSIFICAZIONE, ['slug' => 'ascomycota', 'parent' => 0]);
		}

		$legacy_order = get_term_by('slug', 'ordine-non-assegnato', AM_TAX_CLASSIFICAZIONE);
		if ($legacy_order && !is_wp_error($legacy_order)) {
			$children = get_terms([
				'taxonomy' => AM_TAX_CLASSIFICAZIONE,
				'hide_empty' => false,
				'parent' => (int) $legacy_order->term_id,
			]);
			if (!is_wp_error($children) && $children) {
				foreach ($children as $child) {
					wp_update_term((int) $child->term_id, AM_TAX_CLASSIFICAZIONE, ['parent' => $basidio_id]);
				}
			}
		}

		$top = get_terms([
			'taxonomy' => AM_TAX_CLASSIFICAZIONE,
			'hide_empty' => false,
			'parent' => 0,
			'orderby' => 'name',
			'order' => 'ASC',
		]);
		if (!is_wp_error($top) && $top) {
			foreach ($top as $t) {
				$tid = (int) $t->term_id;
				$slug = (string) $t->slug;
				if (in_array($slug, ['basidiomycota', 'ascomycota', 'ordine-non-assegnato'], true)) { continue; }
				wp_update_term($tid, AM_TAX_CLASSIFICAZIONE, ['parent' => $basidio_id]);
			}
		}

		$post_ids = get_posts([
			'post_type' => AM_CPT,
			'post_status' => ['publish', 'pending', 'draft', 'private'],
			'numberposts' => -1,
			'fields' => 'ids',
		]);
		foreach ((array) $post_ids as $post_id) {
			$terms = wp_get_object_terms((int) $post_id, AM_TAX_CLASSIFICAZIONE);
			if (is_wp_error($terms) || empty($terms)) { continue; }
			$best = null;
			$best_depth = -1;
			foreach ($terms as $term) {
				$anc = get_ancestors((int) $term->term_id, AM_TAX_CLASSIFICAZIONE);
				$depth = is_array($anc) ? count($anc) : 0;
				if ($depth > $best_depth) { $best_depth = $depth; $best = $term; }
			}
			if (!$best || is_wp_error($best)) { continue; }
			$chain = array_reverse(get_ancestors((int) $best->term_id, AM_TAX_CLASSIFICAZIONE));
			$chain[] = (int) $best->term_id;
			if (isset($chain[0])) { update_post_meta((int) $post_id, 'am_tax_division_id', (int) $chain[0]); }
			if (isset($chain[1])) { update_post_meta((int) $post_id, 'am_tax_family_id', (int) $chain[1]); }
			if (isset($chain[2])) { update_post_meta((int) $post_id, 'am_tax_genus_id', (int) $chain[2]); }
			if (isset($chain[3])) { update_post_meta((int) $post_id, 'am_tax_species_id', (int) $chain[3]); }
		}

		update_option('am_migrated_division_level', 1);
		update_option('am_default_division_term_id', $basidio_id);
	}

}
