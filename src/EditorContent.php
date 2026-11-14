<?php

namespace ArchivioMicologico;

if (!defined('ABSPATH')) { exit; }

class EditorContent {
	public static function sanitize($html): string {
		$html = is_string($html) ? $html : '';
		$allowed = wp_kses_allowed_html('post');
		// Remove code-related tags to discourage code insertion.
		unset($allowed['code'], $allowed['pre'], $allowed['kbd'], $allowed['samp']);
		return (string) wp_kses($html, $allowed);
	}
}
