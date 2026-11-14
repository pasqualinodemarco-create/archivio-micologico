<?php

namespace ArchivioMicologico;

use WP_Query;

if (!defined('ABSPATH')) { exit; }

class RandomFungiCarousel {
	public static function defaults(): array {
		return [
			'numero' => max(1, min(12, (int) get_option('am_random_widget_count', 5))),
			'titolo' => sanitize_text_field((string) get_option('am_random_widget_title', '')),
			'autoplay' => (int) get_option('am_random_widget_autoplay', 1) === 1 ? '1' : '0',
			'intervallo' => max(2, min(20, (int) get_option('am_random_widget_interval', 4))),
			'visibili' => max(1, min(3, (int) get_option('am_random_widget_visible', 3))),
			'righe' => get_option('am_random_widget_rows', 1),
		];
	}

	public static function shortcode($atts = []): string {
		$atts = shortcode_atts(self::defaults(), $atts, 'am_random_funghi');

		$count = max(1, min(12, absint($atts['numero'])));
		$title = sanitize_text_field((string) $atts['titolo']);
		$autoplay = in_array((string) $atts['autoplay'], ['1', 'true', 'yes', 'on'], true);
		$interval = max(2, min(20, absint($atts['intervallo'])));
		$visible = max(1, min(3, absint($atts['visibili'])));

		return self::render($count, $title, [
			'autoplay' => $autoplay,
			'interval' => $interval,
			'visible' => $visible,
			'rows' => (int) $atts['righe'],
		]);
	}

	public static function render(int $count = 5, string $title = '', array $args = []): string {
		$count = max(1, min(12, $count));
		$autoplay = isset($args['autoplay']) ? (bool) $args['autoplay'] : ((int) get_option('am_random_widget_autoplay', 1) === 1);
		$interval = isset($args['interval']) ? max(2, min(20, (int) $args['interval'])) : max(2, min(20, (int) get_option('am_random_widget_interval', 4)));
		$visible = isset($args['visible']) ? max(1, min(3, (int) $args['visible'])) : max(1, min(3, (int) get_option('am_random_widget_visible', 3)));
		$rows = max(1, min(4, (int) ($args['rows'] ?? get_option('am_random_widget_rows', 1))));
		$query = new WP_Query([
			'post_type' => AM_CPT,
			'post_status' => 'publish',
			'posts_per_page' => $count,
			'orderby' => 'rand',
			'ignore_sticky_posts' => true,
			'no_found_rows' => true,
		]);

		// Builders can render the shortcode outside the page's post_content.
		if ($query->have_posts() && !wp_script_is('am-frontend', 'enqueued') && !wp_script_is('am-frontend', 'done')) {
			Frontend::enqueue_assets(true);
		}
		$carousel_id = 'am-random-carousel-' . wp_generate_uuid4();

		ob_start();
		echo '<div class="am-random-funghi-widget am-random-funghi-carousel" data-am-carousel data-am-autoplay="' . esc_attr($autoplay ? '1' : '0') . '" data-am-interval="' . esc_attr((string) $interval) . '" data-am-visible="' . esc_attr((string) $visible) . '" data-am-rows="' . esc_attr($rows) . '" id="' . esc_attr($carousel_id) . '">';
		if ($title !== '') {
			echo '<h3 class="am-random-funghi-widget__title">' . esc_html($title) . '</h3>';
		}

		if ($query->have_posts()) {
			echo '<div class="am-random-funghi-carousel__viewport">';
			echo '<div class="am-random-funghi-carousel__track" data-am-carousel-track>';
			$slide_index = 0;
			while ($query->have_posts()) {
				$query->the_post();
				$permalink = get_permalink();
				$title_text = wp_strip_all_tags(get_the_title());
				$title_html = wp_kses_post(SpeciesTitle::format($title_text));
				echo '<article class="am-random-funghi-carousel__slide am-random-funghi-widget__item" data-am-carousel-slide data-slide-index="' . esc_attr((string) $slide_index) . '">';
				echo '<a class="am-random-funghi-widget__thumblink" href="' . esc_url($permalink) . '">';
				if (has_post_thumbnail()) {
					echo get_the_post_thumbnail(get_the_ID(), 'medium_large', [
						'class' => 'am-random-funghi-widget__thumb',
						'loading' => 'lazy',
						'alt' => $title_text,
					]);
				} else {
					echo '<span class="am-random-funghi-widget__thumb am-random-funghi-widget__thumb--empty">' . esc_html__('Nessuna immagine', 'archivio-micologico') . '</span>';
				}
				echo '</a>';
				echo '<a class="am-random-funghi-widget__link" href="' . esc_url($permalink) . '">' . $title_html . '</a>';
				echo '</article>';
				$slide_index++;
			}
			echo '</div>';
			echo '</div>';
			if ($slide_index > 1) {
				echo '<div class="am-random-funghi-carousel__controls">';
				echo '<button type="button" class="am-random-funghi-carousel__button am-random-funghi-carousel__button--prev" data-am-carousel-prev aria-label="' . esc_attr__('Scheda precedente', 'archivio-micologico') . '">&lsaquo;</button>';
				echo '<div class="am-random-funghi-carousel__dots" data-am-carousel-dots>';
				for ($i = 0; $i < $slide_index; $i++) {
					echo '<button type="button" class="am-random-funghi-carousel__dot' . ($i === 0 ? ' is-active' : '') . '" data-am-carousel-dot="' . esc_attr((string) $i) . '" aria-label="' . esc_attr(sprintf(__('Vai alla posizione %d', 'archivio-micologico'), $i + 1)) . '"></button>';
				}
				echo '</div>';
				echo '<button type="button" class="am-random-funghi-carousel__button am-random-funghi-carousel__button--next" data-am-carousel-next aria-label="' . esc_attr__('Scheda successiva', 'archivio-micologico') . '">&rsaquo;</button>';
				echo '</div>';
			}
			wp_reset_postdata();
		} else {
			echo '<p class="am-random-funghi-widget__empty">' . esc_html__('Nessuna scheda disponibile.', 'archivio-micologico') . '</p>';
		}
		echo '</div>';

		return (string) ob_get_clean();
	}
}
