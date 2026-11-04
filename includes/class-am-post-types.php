<?php
if (!defined('ABSPATH')) { exit; }

class AM_Post_Types {
	public static function init(): void {
		add_action('init', [__CLASS__, 'register']);
	}

	public static function register(): void {
		$labels = [
			'name' => __('Schede Funghi', 'archivio-micologico'),
			'singular_name' => __('Scheda Fungo', 'archivio-micologico'),
			'menu_name' => __('Archivio Micologico', 'archivio-micologico'),
			'add_new' => __('Aggiungi nuova', 'archivio-micologico'),
			'add_new_item' => __('Aggiungi nuova scheda', 'archivio-micologico'),
			'edit_item' => __('Modifica scheda', 'archivio-micologico'),
			'new_item' => __('Nuovo inserimento', 'archivio-micologico'),
			'view_item' => __('Vedi scheda', 'archivio-micologico'),
			'search_items' => __('Cerca schede', 'archivio-micologico'),
			'not_found' => __('Nessuna scheda trovata', 'archivio-micologico'),
			'not_found_in_trash' => __('Nessuna scheda nel cestino', 'archivio-micologico'),
		];

		register_post_type(AM_CPT, [
			'labels' => $labels,
			'public' => true,
			'show_in_rest' => true,
			// We provide a custom top-level admin menu; avoid duplicate CPT menu.
			'show_in_menu' => false,
			'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'author'],
			'has_archive' => true,
			'rewrite' => ['slug' => 'funghi'],
			'capability_type' => ['am_fungo', 'am_funghi'],
			'map_meta_cap' => true,
			'capabilities' => [
				'create_posts' => AM_CAP_CREATE_POSTS,
				'edit_post' => AM_CAP_EDIT_POST,
				'read_post' => AM_CAP_READ_POST,
				'delete_post' => AM_CAP_DELETE_POST,
				'edit_posts' => AM_CAP_EDIT_POSTS,
				'edit_others_posts' => AM_CAP_EDIT_OTHERS_POSTS,
				'publish_posts' => AM_CAP_PUBLISH_POSTS,
				'read_private_posts' => AM_CAP_READ_PRIVATE_POSTS,
				'delete_posts' => AM_CAP_DELETE_POSTS,
				'delete_others_posts' => AM_CAP_DELETE_OTHERS_POSTS,
				'edit_published_posts' => AM_CAP_EDIT_PUBLISHED_POSTS,
				'delete_published_posts' => AM_CAP_DELETE_PUBLISHED_POSTS,
				'edit_private_posts' => AM_CAP_EDIT_PRIVATE_POSTS,
				'delete_private_posts' => AM_CAP_DELETE_PRIVATE_POSTS,
			],
		]);

		// Articoli (Archivio Micologico)
		$labels_a = [
			'name' => __('Articoli', 'archivio-micologico'),
			'singular_name' => __('Articolo', 'archivio-micologico'),
			'add_new' => __('Aggiungi articolo', 'archivio-micologico'),
			'add_new_item' => __('Aggiungi nuovo articolo', 'archivio-micologico'),
			'edit_item' => __('Modifica articolo', 'archivio-micologico'),
			'new_item' => __('Nuovo articolo', 'archivio-micologico'),
			'view_item' => __('Vedi articolo', 'archivio-micologico'),
			'search_items' => __('Cerca articoli', 'archivio-micologico'),
			'not_found' => __('Nessun articolo trovato', 'archivio-micologico'),
			'not_found_in_trash' => __('Nessun articolo nel cestino', 'archivio-micologico'),
		];

		register_post_type(AM_ARTICLE_CPT, [
			'labels' => $labels_a,
			'public' => true,
			'show_in_rest' => true,
			'show_in_menu' => false,
			'supports' => ['title', 'editor', 'excerpt', 'thumbnail', 'author'],
			'has_archive' => false,
			'rewrite' => ['slug' => 'am-articoli'],
			'capability_type' => ['am_articolo', 'am_articoli'],
			'map_meta_cap' => true,
			'capabilities' => [
				'create_posts' => AM_CAP_CREATE_POSTS,
				'edit_post' => AM_CAP_EDIT_POST,
				'read_post' => AM_CAP_READ_POST,
				'delete_post' => AM_CAP_DELETE_POST,
				'edit_posts' => AM_CAP_EDIT_POSTS,
				'edit_others_posts' => AM_CAP_EDIT_OTHERS_POSTS,
				'publish_posts' => AM_CAP_PUBLISH_POSTS,
				'read_private_posts' => AM_CAP_READ_PRIVATE_POSTS,
				'delete_posts' => AM_CAP_DELETE_POSTS,
				'delete_others_posts' => AM_CAP_DELETE_OTHERS_POSTS,
				'edit_published_posts' => AM_CAP_EDIT_PUBLISHED_POSTS,
				'delete_published_posts' => AM_CAP_DELETE_PUBLISHED_POSTS,
				'edit_private_posts' => AM_CAP_EDIT_PRIVATE_POSTS,
				'delete_private_posts' => AM_CAP_DELETE_PRIVATE_POSTS,
			],
		]);

	}
}
