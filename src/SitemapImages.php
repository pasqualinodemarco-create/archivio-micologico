<?php

namespace ArchivioMicologico;

if (!defined('ABSPATH')) { exit; }

/**
 * Yoast SEO lists only the featured image of a scheda in its XML sitemap, because the gallery
 * (macro and microscopy photos) is stored in the am_gallery_ids meta, not in the content.
 * This adds every gallery photo of schede and articles, so search engines can index them all.
 */
final class SitemapImages {
	public static function init(): void {
		add_filter('wpseo_sitemap_urlimages', [self::class, 'add_gallery'], 10, 2);
		add_action('init', [self::class, 'refresh_cache_once'], 20);
	}

	/** Attachment ids of the scheda gallery, in display order and without duplicates. */
	public static function gallery_ids(int $post_id): array {
		$ids = get_post_meta($post_id, 'am_gallery_ids', true);
		if (!is_array($ids)) { $ids = preg_split('/[,\s]+/', (string) $ids) ?: []; }
		return array_values(array_unique(array_filter(array_map('absint', $ids))));
	}

	public static function add_gallery($images, $post_id) {
		$post_id = (int) $post_id;
		if (!is_array($images) || !in_array(get_post_type($post_id), [AM_CPT, AM_ARTICLE_CPT], true)) { return $images; }
		$seen = [];
		foreach ($images as $image) {
			if (!empty($image['src'])) { $seen[self::key((string) $image['src'])] = true; }
		}
		foreach (self::gallery_ids($post_id) as $id) {
			if (!wp_attachment_is_image($id)) { continue; }
			$src = wp_get_attachment_image_url($id, 'full');
			if (!is_string($src) || $src === '' || isset($seen[self::key($src)])) { continue; }
			$seen[self::key($src)] = true;
			$alt = trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
			if ($alt === '') { $alt = trim((string) wp_get_attachment_caption($id)); }
			$images[] = ['src' => $src, 'title' => (string) get_the_title($id), 'alt' => $alt];
		}
		return $images;
	}

	/** Same photo at another size (-1024x768, -scaled) counts once. */
	private static function key(string $src): string {
		return (string) preg_replace('/-(?:\d+x\d+|scaled)(?=\.[a-z0-9]+$)/i', '', strtolower((string) strtok($src, '?')));
	}

	/** Yoast caches sitemaps: rebuild the schede sitemap once after each plugin update. */
	public static function refresh_cache_once(): void {
		if (get_option('am_sitemap_images_version') === AM_VERSION) { return; }
		if (class_exists('\WPSEO_Sitemaps_Cache')) { \WPSEO_Sitemaps_Cache::clear([AM_CPT, AM_ARTICLE_CPT]); }
		update_option('am_sitemap_images_version', AM_VERSION, false);
	}
}
