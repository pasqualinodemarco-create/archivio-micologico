<?php
if (!defined('ABSPATH')) { exit; }

if (class_exists('\ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base') && !class_exists('AM_Elementor_Condition_Funghi')) {
	class AM_Elementor_Condition_Funghi extends \ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base {
		public static function get_type(): string {
			return 'archive';
		}

		public function get_name(): string {
			return 'am_funghi';
		}

		public function get_label(): string {
			return __('Archivio Micologico - Schede Funghi', 'archivio-micologico');
		}

		public function check($args): bool {
			if (!defined('AM_CPT')) { return false; }
			return is_singular(AM_CPT) || is_post_type_archive(AM_CPT);
		}
	}
}

if (class_exists('\ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base') && !class_exists('AM_Elementor_Condition_All')) {
	class AM_Elementor_Condition_All extends \ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base {
		public static function get_type(): string {
			return 'general';
		}

		public function get_name(): string {
			return 'am_all';
		}

		public function get_label(): string {
			return __('Archivio Micologico - Tutto AM', 'archivio-micologico');
		}

		public function check($args): bool {
			if (defined('AM_CPT') && (is_singular(AM_CPT) || is_post_type_archive(AM_CPT))) {
				return true;
			}

			if (defined('AM_ARTICLE_CPT') && (is_singular(AM_ARTICLE_CPT) || is_post_type_archive(AM_ARTICLE_CPT))) {
				return true;
			}

			if (is_singular()) {
				$post = get_post();
				if ($post instanceof WP_Post) {
					$content = (string) $post->post_content;
					$shortcodes = [
						'am_archive_filters',
						'am_archive',
						'am_search_page',
						'am_submit_form',
						'am_edit_form',
						'am_article_form',
						'am_random_funghi',
					];

					foreach ($shortcodes as $shortcode) {
						if (has_shortcode($content, $shortcode)) {
							return true;
						}
					}
				}
			}

			return false;
		}
	}
}

/**
 * Lightweight Elementor Pro compatibility.
 *
 * This class does NOT render AM content through Elementor.
 * It only:
 * - marks AM_CPT as Elementor-supported so Elementor can recognize it better;
 * - registers optional Theme Builder display conditions when Elementor Pro is loaded.
 */
class AM_Elementor_Conditions {
	public static function init(): void {
		add_action('init', [__CLASS__, 'add_post_type_support'], 30);
		add_action('elementor/theme/register_conditions', [__CLASS__, 'register_conditions'], 20);
	}

	public static function add_post_type_support(): void {
		if (defined('AM_CPT') && post_type_exists(AM_CPT)) {
			add_post_type_support(AM_CPT, 'elementor');
		}

		if (defined('AM_ARTICLE_CPT') && post_type_exists(AM_ARTICLE_CPT)) {
			add_post_type_support(AM_ARTICLE_CPT, 'elementor');
		}

		self::debug('AM_ELEMENTOR_CONDITIONS_SUPPORT_1_5_6', [
			'am_fungo' => defined('AM_CPT') && post_type_exists(AM_CPT) && post_type_supports(AM_CPT, 'elementor') ? 1 : 0,
			'am_articolo' => defined('AM_ARTICLE_CPT') && post_type_exists(AM_ARTICLE_CPT) && post_type_supports(AM_ARTICLE_CPT, 'elementor') ? 1 : 0,
		]);
	}

	public static function register_conditions($conditions_manager): void {
		if (!class_exists('\ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base')) {
			self::debug('AM_ELEMENTOR_CONDITIONS_SKIPPED_1_5_6', [
				'reason' => 'elementor_pro_condition_base_missing',
			]);
			return;
		}

		if (!is_object($conditions_manager) || !method_exists($conditions_manager, 'get_condition')) {
			self::debug('AM_ELEMENTOR_CONDITIONS_SKIPPED_1_5_6', [
				'reason' => 'invalid_conditions_manager',
			]);
			return;
		}

		if (!class_exists('AM_Elementor_Condition_All') || !class_exists('AM_Elementor_Condition_Funghi')) {
			self::debug('AM_ELEMENTOR_CONDITIONS_SKIPPED_1_5_6', [
				'reason' => 'condition_classes_missing',
			]);
			return;
		}

		$general = $conditions_manager->get_condition('general');
		if ($general && method_exists($general, 'register_sub_condition')) {
			$general->register_sub_condition(new AM_Elementor_Condition_All());
			$general->register_sub_condition(new AM_Elementor_Condition_Funghi());

			self::debug('AM_ELEMENTOR_CONDITIONS_REGISTERED_1_5_6', [
				'conditions' => ['am_all', 'am_funghi'],
				'group' => 'general',
			]);
			return;
		}

		self::debug('AM_ELEMENTOR_CONDITIONS_SKIPPED_1_5_6', [
			'reason' => 'general_condition_group_missing',
		]);
	}

	private static function debug(string $tag, array $data = []): void {
		if ((int) get_option('am_enable_debug_panel', 0) !== 1) {
			return;
		}

		if (class_exists('AM_Admin') && method_exists('AM_Admin', 'am_debug_add')) {
			AM_Admin::am_debug_add($tag, $data);
		}
	}
}
