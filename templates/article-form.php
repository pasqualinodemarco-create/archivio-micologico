<?php
use ArchivioMicologico\Frontend;

if (!defined('ABSPATH')) { exit; }

// The media pickers below replace the editor "Aggiungi media" button on this page.
add_filter('wp_editor_settings', function ($settings) {
  $settings['media_buttons'] = false;
  return $settings;
}, 99);

// Values kept after a failed submission.
$am_token = isset($_GET['am_token']) ? sanitize_text_field((string) $_GET['am_token']) : '';
if ($am_token === '') {
  $am_token = wp_generate_password(12, false, false);
}
$am_sticky = Frontend::am_get_sticky($am_token);
$am_gallery_value = $am_sticky['am_gallery_ids'] ?? '';
if (is_array($am_gallery_value)) {
  $am_gallery_value = implode(',', array_filter(array_map('absint', $am_gallery_value)));
}
$am_featured_value = absint($am_sticky['am_featured_id'] ?? 0);
$am_pdf_value = absint($am_sticky['am_pdf_id'] ?? 0);

$cats = get_terms(['taxonomy' => AM_TAX_ARTICLE_CAT, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC']);
$selected_cats = isset($am_sticky['am_article_cats']) && is_array($am_sticky['am_article_cats'])
  ? array_values(array_filter(array_map('absint', $am_sticky['am_article_cats'])))
  : [];

$am_status = \ArchivioMicologico\EntryForms::article_status();
$am_publishes = $am_status === 'publish';

// Outcome of the last submission (same mechanism as the scheda form).
$am_notice = '';
if (isset($_GET['am_error'])) {
  $am_errors = [
    'missing_title' => __('Inserisci il titolo dell’articolo.', 'archivio-micologico'),
    'missing_content' => __('Scrivi il testo dell’articolo.', 'archivio-micologico'),
    'nonce' => __('Sessione scaduta. Ricarica la pagina e riprova.', 'archivio-micologico'),
    'not_allowed' => __('Non sei autorizzato a inserire articoli.', 'archivio-micologico'),
    'permission' => __('Devi effettuare l’accesso per inserire un articolo.', 'archivio-micologico'),
    'insert_failed' => __('Impossibile salvare l’articolo. Riprova.', 'archivio-micologico'),
  ];
  $am_code = sanitize_key((string) $_GET['am_error']);
  $am_notice = '<div class="am-notice am-notice--error" role="alert">' . esc_html($am_errors[$am_code] ?? __('Si è verificato un errore durante l’invio. Riprova.', 'archivio-micologico')) . '</div>';
} elseif (($_GET['am_submitted'] ?? '') === '1' && is_user_logged_in() && (int) get_option('am_show_submit_result_admin', 1) === 1) {
  $am_last = get_user_meta(get_current_user_id(), 'am_last_submit', true);
  $am_ttl = (int) get_option('am_submit_result_ttl', 20);
  if (is_array($am_last) && $am_ttl > 0 && time() - (int) ($am_last['ts'] ?? 0) > $am_ttl) { $am_last = null; }
  $am_id = absint($_GET['am_post_id'] ?? 0);
  if ($am_id && is_array($am_last) && !empty($am_last['ok']) && absint($am_last['post_id'] ?? 0) === $am_id && get_post_type($am_id) === AM_ARTICLE_CPT) {
    $am_published = get_post_status($am_id) === 'publish';
    $am_notice = '<div class="am-notice am-notice--success am-submit-result" role="status">'
      . '<strong>' . esc_html($am_published ? __('Articolo pubblicato', 'archivio-micologico') : __('Articolo inviato', 'archivio-micologico')) . '</strong> '
      . esc_html($am_published ? __('È già visibile nell’Archivio micologico.', 'archivio-micologico') : __('È in attesa di revisione prima della pubblicazione.', 'archivio-micologico'))
      . '<div class="am-submit-result__actions">'
      . '<a class="am-btn am-btn--small" href="' . esc_url((string) get_permalink($am_id)) . '">' . esc_html($am_published ? __('Apri articolo', 'archivio-micologico') : __('Anteprima', 'archivio-micologico')) . '</a>'
      . (current_user_can('edit_post', $am_id) ? '<a class="am-btn am-btn--small am-btn--secondary" href="' . esc_url((string) get_edit_post_link($am_id)) . '">' . esc_html__('Modifica', 'archivio-micologico') . '</a>' : '')
      . '</div></div>';
  }
}
?>
<div id="am-article-form-wrap" class="am-scope">
<form id="am-article-form" class="am-form am-wizard am-article-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
  <input type="hidden" name="action" value="am_submit_article" />
  <?php wp_nonce_field(Frontend::NONCE_ACTION, Frontend::NONCE_NAME); ?>
  <input type="hidden" name="am_token" value="<?php echo esc_attr($am_token); ?>" />

  <header class="am-wizard__header">
    <div>
      <span class="am-wizard__eyebrow"><?php echo esc_html__('Archivio Micologico', 'archivio-micologico'); ?></span>
      <h2><?php echo esc_html__('Nuovo articolo scientifico', 'archivio-micologico'); ?></h2>
      <p><?php echo esc_html__('Studi, chiavi, monografie e relazioni: compariranno nell’archivio accanto alle schede.', 'archivio-micologico'); ?></p>
    </div>
    <span class="am-wizard__badge"><?php echo esc_html__('In compilazione', 'archivio-micologico'); ?></span>
  </header>

  <?php echo $am_notice; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while built. ?>

  <div class="am-wizard__layout">
    <div class="am-wizard__panels">

      <div class="am-section" id="am-article-text">
        <h3 class="am-section__title"><?php echo esc_html__('Testo', 'archivio-micologico'); ?></h3>

        <div class="am-field">
          <label for="am_title"><?php echo esc_html__('Titolo', 'archivio-micologico'); ?> <span class="am-required">*</span></label>
          <input type="text" id="am_title" name="am_title" value="<?php echo esc_attr($am_sticky['am_title'] ?? ''); ?>" required maxlength="200" />
          <p class="am-help"><?php echo esc_html__('I nomi scientifici nel testo vanno in corsivo (pulsante I dell’editor).', 'archivio-micologico'); ?></p>
        </div>

        <div class="am-field">
          <label for="am_excerpt"><?php echo esc_html__('Sommario', 'archivio-micologico'); ?></label>
          <textarea id="am_excerpt" name="am_excerpt" rows="3" maxlength="400"><?php echo esc_textarea((string) ($am_sticky['am_excerpt'] ?? '')); ?></textarea>
          <p class="am-help"><?php echo esc_html__('Facoltativo: due o tre righe che riassumono l’articolo. Appaiono nell’archivio e nei risultati di ricerca.', 'archivio-micologico'); ?></p>
        </div>

        <fieldset class="am-field am-article-cats">
          <legend><?php echo esc_html__('Categorie', 'archivio-micologico'); ?></legend>
          <div class="am-chipbox" data-am-cat-chips>
            <?php foreach ((!is_wp_error($cats) ? $cats : []) as $t) : ?>
              <label class="am-chip" data-am-cat-chip="<?php echo esc_attr((string) (int) $t->term_id); ?>">
                <input type="checkbox" name="am_article_cats[]" value="<?php echo esc_attr((string) (int) $t->term_id); ?>" <?php checked(in_array((int) $t->term_id, $selected_cats, true)); ?> />
                <span><?php echo esc_html((string) $t->name); ?></span>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="am-help" data-am-cat-empty<?php echo (!is_wp_error($cats) && $cats) ? ' hidden' : ''; ?>><?php echo esc_html__('Nessuna categoria: creane una qui sotto.', 'archivio-micologico'); ?></p>

          <?php if (current_user_can('manage_terms')) : $am_cat_usage = \ArchivioMicologico\EntryForms::article_cat_usage(); ?>
          <details class="am-catman" data-am-catman data-nonce="<?php echo esc_attr(wp_create_nonce('am_article_cat')); ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
            <summary><?php echo esc_html__('Gestisci categorie', 'archivio-micologico'); ?></summary>
            <div class="am-catman__add">
              <input type="text" data-am-cat-new maxlength="80" placeholder="<?php echo esc_attr__('Nuova categoria, es. Monografie', 'archivio-micologico'); ?>" aria-label="<?php echo esc_attr__('Nome della nuova categoria', 'archivio-micologico'); ?>" />
              <button type="button" class="am-btn" data-am-cat-add><?php echo esc_html__('Aggiungi', 'archivio-micologico'); ?></button>
            </div>
            <ul class="am-catman__list" data-am-cat-list>
              <?php foreach ((!is_wp_error($cats) ? $cats : []) as $t) : $am_n = (int) ($am_cat_usage[(int) $t->term_id] ?? 0); ?>
                <li data-id="<?php echo esc_attr((string) (int) $t->term_id); ?>" data-count="<?php echo esc_attr((string) $am_n); ?>">
                  <input type="text" value="<?php echo esc_attr((string) $t->name); ?>" maxlength="80" aria-label="<?php echo esc_attr__('Nome della categoria', 'archivio-micologico'); ?>" />
                  <span class="am-catman__count"><?php echo esc_html(sprintf(_n('%d articolo', '%d articoli', $am_n, 'archivio-micologico'), $am_n)); ?></span>
                  <button type="button" class="am-btn am-btn--small" data-am-cat-rename><?php echo esc_html__('Rinomina', 'archivio-micologico'); ?></button>
                  <button type="button" class="am-btn am-btn--small am-btn--danger" data-am-cat-delete<?php echo $am_n > 0 ? ' disabled title="' . esc_attr__('Usata da articoli: non si può eliminare', 'archivio-micologico') . '"' : ''; ?>><?php echo esc_html__('Elimina', 'archivio-micologico'); ?></button>
                </li>
              <?php endforeach; ?>
            </ul>
            <p class="am-help"><?php echo esc_html__('Si elimina solo una categoria che nessun articolo usa (anche in bozza o nel cestino). Rinominarla aggiorna tutti gli articoli.', 'archivio-micologico'); ?></p>
            <p class="am-catman__msg" data-am-cat-msg role="status" aria-live="polite"></p>
          </details>
          <?php endif; ?>
        </fieldset>

        <div class="am-field">
          <label for="am_content"><?php echo esc_html__('Testo dell’articolo', 'archivio-micologico'); ?> <span class="am-required">*</span></label>
          <div class="am-editor">
            <?php
            wp_editor((string) ($am_sticky['am_content'] ?? ''), 'am_content', [
              'textarea_name' => 'am_content',
              'textarea_rows' => 16,
              'teeny' => false,
              'quicktags' => true,
              'media_buttons' => false,
            ]);
            ?>
          </div>
          <p class="am-help"><?php echo esc_html__('Usa Titolo 2 e Titolo 3 dal menu Paragrafo per dividere le sezioni (es. Materiali e metodi, Risultati, Bibliografia).', 'archivio-micologico'); ?></p>
        </div>
      </div>

      <div class="am-section am-section--media" id="am-article-media">
        <h3 class="am-section__title"><?php echo esc_html__('Immagini e allegati', 'archivio-micologico'); ?></h3>
        <p class="am-section__intro"><?php echo esc_html__('Tutti facoltativi. Le didascalie delle immagini si scrivono nella libreria media e compaiono sotto ogni foto.', 'archivio-micologico'); ?></p>

        <div class="am-field">
          <label><?php echo esc_html__('Immagine di anteprima', 'archivio-micologico'); ?></label>
          <input type="hidden" name="am_featured_id" id="am_featured_id" value="<?php echo esc_attr($am_featured_value ? (string) $am_featured_value : ''); ?>" />
          <div class="am-media-actions">
            <button type="button" class="button am-btn" id="am_pick_featured"><?php echo esc_html__('Seleziona immagine', 'archivio-micologico'); ?></button>
            <button type="button" class="button am-btn am-btn--ghost am-clear-media" data-am-clear-media="featured"><?php echo esc_html__('Rimuovi', 'archivio-micologico'); ?></button>
          </div>
          <div id="am_featured_preview" class="am-media-preview"></div>
          <p id="am-media-status-featured" class="am-media-status"><?php echo esc_html__('Nessuna immagine di anteprima selezionata.', 'archivio-micologico'); ?></p>
          <p class="am-help"><?php echo esc_html__('Mostrata in archivio e nelle anteprime sui social.', 'archivio-micologico'); ?></p>
        </div>

        <div class="am-field">
          <label><?php echo esc_html__('Galleria', 'archivio-micologico'); ?></label>
          <input type="hidden" name="am_gallery_ids" id="am_gallery_ids" value="<?php echo esc_attr((string) $am_gallery_value); ?>" />
          <div class="am-media-actions">
            <button type="button" class="button am-btn" id="am_pick_gallery"><?php echo esc_html__('Seleziona più immagini', 'archivio-micologico'); ?></button>
            <button type="button" class="button am-btn am-btn--ghost am-clear-media" data-am-clear-media="gallery"><?php echo esc_html__('Rimuovi galleria', 'archivio-micologico'); ?></button>
          </div>
          <div id="am_gallery_preview" class="am-media-preview am-media-preview--grid"></div>
          <p id="am-media-status-gallery" class="am-media-status"><?php echo esc_html__('Nessuna immagine in galleria selezionata.', 'archivio-micologico'); ?></p>
          <p class="am-help"><?php echo esc_html__('Tavole, foto e microscopia, mostrate in fondo all’articolo.', 'archivio-micologico'); ?></p>
        </div>

        <div class="am-field">
          <label><?php echo esc_html__('Allegato PDF', 'archivio-micologico'); ?></label>
          <input type="hidden" name="am_pdf_id" id="am_pdf_id" value="<?php echo esc_attr($am_pdf_value ? (string) $am_pdf_value : ''); ?>" />
          <div class="am-media-actions">
            <button type="button" class="button am-btn" id="am_pick_pdf"><?php echo esc_html__('Seleziona PDF', 'archivio-micologico'); ?></button>
            <button type="button" class="button am-btn am-btn--ghost am-clear-media" data-am-clear-media="pdf"><?php echo esc_html__('Rimuovi PDF', 'archivio-micologico'); ?></button>
          </div>
          <div id="am_pdf_preview" class="am-media-preview"></div>
          <p id="am-media-status-pdf" class="am-media-status"><?php echo esc_html__('Nessun PDF selezionato.', 'archivio-micologico'); ?></p>
          <p class="am-help"><?php echo esc_html__('Per esempio la versione impaginata o l’estratto della pubblicazione.', 'archivio-micologico'); ?></p>
        </div>
      </div>
    </div><!-- /.am-wizard__panels -->

    <aside class="am-wizard__summary" aria-labelledby="am-article-summary-title">
      <h3 id="am-article-summary-title"><?php echo esc_html__('Stato dell’articolo', 'archivio-micologico'); ?></h3>
      <ul class="am-submit-checklist">
        <?php foreach ([
          'title' => [__('Titolo', 'archivio-micologico'), false, 'am-article-text'],
          'content' => [__('Testo dell’articolo', 'archivio-micologico'), false, 'am-article-text'],
          'featured' => [__('Immagine di anteprima', 'archivio-micologico'), true, 'am-article-media'],
          'gallery' => [__('Galleria', 'archivio-micologico'), true, 'am-article-media'],
          'pdf' => [__('PDF', 'archivio-micologico'), true, 'am-article-media'],
        ] as $am_key => [$am_label, $am_optional, $am_anchor]) : ?>
          <li data-am-check="<?php echo esc_attr($am_key); ?>"<?php echo $am_optional ? ' data-am-optional' : ''; ?>>
            <span class="am-submit-checklist__status" aria-hidden="true"><?php echo $am_optional ? '–' : '○'; ?></span>
            <a href="#<?php echo esc_attr($am_anchor); ?>"><?php echo esc_html($am_label); ?><?php if ($am_optional) : ?> <small><?php echo esc_html__('(facoltativo)', 'archivio-micologico'); ?></small><?php endif; ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="am-form-meta">
        <div class="am-form-meta__row">
          <span class="am-form-meta__label"><?php echo esc_html__('Autore', 'archivio-micologico'); ?></span>
          <span class="am-form-meta__value"><?php echo esc_html(wp_get_current_user()->display_name); ?></span>
        </div>
        <div class="am-form-meta__row">
          <span class="am-form-meta__label"><?php echo esc_html__('All’invio', 'archivio-micologico'); ?></span>
          <span class="am-form-meta__value"><?php echo esc_html($am_publishes ? __('pubblicato subito nell’archivio', 'archivio-micologico') : __('in attesa di revisione', 'archivio-micologico')); ?></span>
        </div>
      </div>
    </aside>
  </div><!-- /.am-wizard__layout -->

  <div class="am-actions am-wizard__actions">
    <button class="am-btn am-submit" type="submit"><?php echo esc_html($am_publishes ? __('Pubblica articolo', 'archivio-micologico') : __('Invia articolo', 'archivio-micologico')); ?></button>
  </div>
</form>
</div>
