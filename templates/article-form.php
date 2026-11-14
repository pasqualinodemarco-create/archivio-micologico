<?php
use ArchivioMicologico\Frontend;
// AM_NO_MEDIA_BUTTONS_CLEAN: hide "Aggiungi media" buttons for wp_editor on this page
add_filter('wp_editor_settings', function($settings, $editor_id){
  $settings['media_buttons'] = false;
  return $settings;
}, 99, 2);

if (!defined('ABSPATH')) { exit; }

// Sticky form values (reuse same mechanism)
$am_token = isset($_GET['am_token']) ? sanitize_text_field((string) $_GET['am_token']) : '';
if ($am_token === '') {
  $am_token = wp_generate_password(12, false, false);
}
$am_sticky = Frontend::am_get_sticky($am_token);

// Categories (multiple)
$cats = get_terms([
  'taxonomy' => AM_TAX_ARTICLE_CAT,
  'hide_empty' => false,
  'orderby' => 'name',
  'order' => 'ASC',
]);
$selected_cats = [];
if (isset($am_sticky['am_article_cats']) && is_array($am_sticky['am_article_cats'])) {
  $selected_cats = array_values(array_filter(array_map('absint', $am_sticky['am_article_cats'])));
}
?>
<form id="am-article-form" class="am-form" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
  <input type="hidden" name="action" value="am_submit_article" />
  <?php wp_nonce_field(Frontend::NONCE_ACTION, Frontend::NONCE_NAME); ?>
  <input type="hidden" name="am_token" value="<?php echo esc_attr($am_token); ?>" />

  <div class="am-section">
    <h3 class="am-section__title"><?php echo esc_html__('Nuovo Articolo', 'archivio-micologico'); ?></h3>

    <div class="am-field">
      <label for="am_title"><?php echo esc_html__('Titolo', 'archivio-micologico'); ?></label>
      <input type="text" id="am_title" name="am_title" value="<?php echo esc_attr($am_sticky['am_title'] ?? ''); ?>" required />
    </div>

    <div class="am-field">
      <label><?php echo esc_html__('Categorie (multiple)', 'archivio-micologico'); ?></label>
      <div class="am-chipbox">
        <?php if (!is_wp_error($cats) && !empty($cats)) : ?>
          <?php foreach ($cats as $t) : ?>
            <?php $checked = in_array((int)$t->term_id, $selected_cats, true) ? 'checked' : ''; ?>
            <label class="am-chip">
              <input type="checkbox" name="am_article_cats[]" value="<?php echo esc_attr((int)$t->term_id); ?>" <?php echo $checked; ?> />
              <span><?php echo esc_html((string)$t->name); ?></span>
            </label>
          <?php endforeach; ?>
        <?php else : ?>
          <p class="description"><?php echo esc_html__('Nessuna categoria disponibile. Creale dal pannello del plugin: Categorie Articoli.', 'archivio-micologico'); ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="am-field">
      <label for="am_content"><?php echo esc_html__('Contenuto', 'archivio-micologico'); ?></label>
      <?php
      $content = isset($am_sticky['am_content']) ? (string) $am_sticky['am_content'] : '';
      wp_editor($content, 'am_content', [
        'textarea_name' => 'am_content',
        'textarea_rows' => 10,
        'teeny' => true,
        'quicktags' => true,
      ]);
      ?>
    </div>
  </div>

  <div class="am-section am-section--media">
    <h3 class="am-section__title"><?php echo esc_html__('Media', 'archivio-micologico'); ?></h3>

    <div class="am-field">
      <label><?php echo esc_html__('Immagine in evidenza', 'archivio-micologico'); ?></label>
      <input type="hidden" name="am_featured_id" id="am_featured_id" value="<?php echo esc_attr($am_sticky['am_featured_id'] ?? ''); ?>" />
      <button type="button" class="button" id="am_pick_featured"><?php echo esc_html__('Seleziona immagine', 'archivio-micologico'); ?></button>
      <div id="am_featured_preview" class="am-media-preview"></div>
    </div>

    <div class="am-field">
      <label><?php echo esc_html__('Galleria', 'archivio-micologico'); ?></label>
      <input type="hidden" name="am_gallery_ids" id="am_gallery_ids" value="<?php echo esc_attr($am_sticky['am_gallery_ids'] ?? ''); ?>" />
      <button type="button" class="button" id="am_pick_gallery"><?php echo esc_html__('Seleziona più immagini', 'archivio-micologico'); ?></button>
      <div id="am_gallery_preview" class="am-media-preview am-media-preview--grid"></div>
    </div>

    <div class="am-field">
      <label><?php echo esc_html__('Allegato PDF (facoltativo)', 'archivio-micologico'); ?></label>
      <input type="hidden" name="am_pdf_id" id="am_pdf_id" value="<?php echo esc_attr($am_sticky['am_pdf_id'] ?? ''); ?>" />
      <button type="button" class="button" id="am_pick_pdf"><?php echo esc_html__('Seleziona PDF', 'archivio-micologico'); ?></button>
      <div id="am_pdf_preview" class="am-media-preview"></div>
    </div>
  </div>

  <div class="am-form__actions">
    <button type="submit" class="button button-primary"><?php echo esc_html__('Pubblica / Invia', 'archivio-micologico'); ?></button>
  </div>
</form>
