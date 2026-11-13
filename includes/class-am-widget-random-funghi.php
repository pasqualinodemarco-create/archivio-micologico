<?php
if (!defined('ABSPATH')) { exit; }

class AM_Widget_Random_Funghi extends WP_Widget {
	public function __construct() {
		parent::__construct(
			'am_widget_random_funghi',
			__('Archivio Micologico - Schede casuali', 'archivio-micologico'),
			[
				'classname' => 'am_widget_random_funghi',
				'description' => __("Mostra un carosello orizzontale con impostazioni gestibili da Archivio Micologico → Impostazioni.", "archivio-micologico"),
			]
		);
	}

	public function widget($args, $instance): void {
		$title = isset($instance['title']) ? sanitize_text_field((string) $instance['title']) : '';
		echo $args['before_widget'];
		$default_count = max(1, min(12, (int) get_option('am_random_widget_count', 5)));
		$default_title = sanitize_text_field((string) get_option('am_random_widget_title', ''));
		$autoplay = (int) get_option('am_random_widget_autoplay', 1) === 1;
		$interval = max(2, min(20, (int) get_option('am_random_widget_interval', 4)));
		$visible = max(1, min(3, (int) get_option('am_random_widget_visible', 3)));
		if ($title === '') {
			$title = $default_title;
		}
		if ($title !== '') {
			echo $args['before_title'] . esc_html($title) . $args['after_title'];
		}
		echo AM_Frontend::get_random_funghi_markup($default_count, '', [
			'autoplay' => $autoplay,
			'interval' => $interval,
			'visible' => $visible,
		]);
		echo $args['after_widget'];
	}

	public function form($instance): void {
		$title = isset($instance['title']) ? (string) $instance['title'] : __('Carosello schede fungo', 'archivio-micologico');
		?>
		<p>
			<label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Titolo', 'archivio-micologico'); ?></label>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
		</p>
		<p><?php esc_html_e('Il widget usa le impostazioni definite in Archivio Micologico → Impostazioni: titolo, numero schede, colonne, righe, autoplay e velocità del carosello.', 'archivio-micologico'); ?></p>
		<?php
	}

	public function update($new_instance, $old_instance): array {
		$instance = [];
		$instance['title'] = isset($new_instance['title']) ? sanitize_text_field((string) $new_instance['title']) : '';
		return $instance;
	}
}
