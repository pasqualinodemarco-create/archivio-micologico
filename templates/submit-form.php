<?php

// AM_NO_MEDIA_BUTTONS_CLEAN: hide "Aggiungi media" buttons for wp_editor on this page
add_filter('wp_editor_settings', function($settings, $editor_id){
  $settings['media_buttons'] = false;
  return $settings;
}, 99, 2);
if (!defined('ABSPATH')) { exit; }
?>


<?php
// Sticky form values (to avoid losing user input on validation errors)
$am_token = isset($_GET['am_token']) ? sanitize_text_field((string) $_GET['am_token']) : '';
$am_has_submit_error_context = (isset($_GET['am_error']) && $am_token !== '');
if (!$am_has_submit_error_context) {
  $am_token = '';
}
if ($am_token === '') {
  $am_token = wp_generate_password(12, false, false);
}
$am_sticky = $am_has_submit_error_context ? AM_Frontend::am_get_sticky($am_token) : [];
?>
<form id="am-main-form" class="am-form am-wizard" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">

<?php
// Conferma invio (UNICO messaggio, sintetico e coerente)
// Usiamo la traccia salvata in user_meta per sopravvivere a cache/redirect.
if (is_user_logged_in() && (int) get_option('am_show_submit_result_admin', 1) === 1) {
  $uid = get_current_user_id();
  $last = get_user_meta($uid, 'am_last_submit', true);

  // TTL di sicurezza (evita conferme vecchie)
  $ttl = (int) get_option('am_submit_result_ttl', 20);
  if (is_array($last) && !empty($last['ts']) && $ttl > 0) {
    if (time() - (int) $last['ts'] > $ttl) {
      delete_user_meta($uid, 'am_last_submit');
      $last = null;
    }
  }

  if (is_array($last) && !empty($last['ok'])) {
    $status = isset($last['status']) ? (string) $last['status'] : '';
    $title  = __('Scheda creata correttamente', 'archivio-micologico');
    $text   = ($status === 'publish')
      ? __('La nuova scheda è stata creata con successo.', 'archivio-micologico')
      : __('La scheda è in attesa di revisione.', 'archivio-micologico');

        $am_created_id = isset($last['post_id']) ? absint($last['post_id']) : 0;
    if (!$am_created_id && isset($last['id'])) {
      $am_created_id = absint($last['id']);
    }

    $am_created_url = $am_created_id ? get_permalink($am_created_id) : '';
    $am_edit_url = '';
    if ($am_created_id) {
      $am_edit_page_id = (int) get_option('am_edit_page_id', 0);
      if ($am_edit_page_id > 0) {
        $am_edit_base_url = get_permalink($am_edit_page_id);
        if ($am_edit_base_url) {
          $am_edit_url = add_query_arg('am_edit', $am_created_id, $am_edit_base_url);
        }
      }

      if (!$am_edit_url && $am_created_url) {
        $am_edit_url = add_query_arg('am_edit', '1', $am_created_url);
      }
    }

    $am_template_notice_html = '<div class="am-notice am-notice--success am-submit-result">' .
      '<strong>' . esc_html($title) . '</strong><br>' .
      '<span>' . esc_html($text) . '</span>';

    if ($am_created_id || $am_created_url) {
      $am_template_notice_html .= '<div class="am-submit-result__actions">';

      if ($am_created_url) {
        $am_template_notice_html .= '<a class="am-btn am-btn--small" href="' . esc_url($am_created_url) . '">' . esc_html__('Visualizza scheda', 'archivio-micologico') . '</a>';
      }

      if ($am_edit_url && current_user_can('edit_post', $am_created_id)) {
        $am_template_notice_html .= '<a class="am-btn am-btn--small am-btn--secondary" href="' . esc_url($am_edit_url) . '">' . esc_html__('Modifica', 'archivio-micologico') . '</a>';
      }

      $am_template_notice_html .= '<a class="am-btn am-btn--small am-btn--ghost" href="' . esc_url(remove_query_arg(['am_created_id', 'am_term_msg', 'am_term_ok', 'am_error', 'am_token', 'am_notice'])) . '">' . esc_html__('Inserisci nuova scheda', 'archivio-micologico') . '</a>';
      $am_template_notice_html .= '</div>';
    }

    $am_template_notice_html .= '</div>';

    // Mostra una sola volta
    delete_user_meta($uid, 'am_last_submit');
  }
} else {
  $am_template_notice_html = '';
}

?>

  <input type="hidden" name="action" value="am_submit_fungo" />
  <?php wp_nonce_field(AM_Frontend::NONCE_ACTION, AM_Frontend::NONCE_NAME); ?>
    <input type="hidden" name="am_token" value="<?php echo esc_attr($am_token); ?>" />
    <input type="hidden" name="am_save_review" id="am_save_review" value="0" />

    <div id="am-form-messages" class="am-form-messages am-form-messages--top" aria-live="polite">
      <?php
      $am_has_submit_error_context = (isset($_GET['am_error']) && isset($_GET['am_token']) && $am_token !== '');
      if ($am_has_submit_error_context && isset($am_pre_notice_html) && $am_pre_notice_html) {
        echo $am_pre_notice_html; // already escaped inside
      }
      if (!empty($am_template_notice_html)) {
        echo $am_template_notice_html; // already escaped inside
      }
      if (isset($_GET['am_term_msg'])) {
        $ok = (isset($_GET['am_term_ok']) && $_GET['am_term_ok'] === '1');
        echo '<div class="am-notice ' . ($ok ? 'am-notice--success' : 'am-notice--error') . '">';
        echo esc_html(wp_unslash($_GET['am_term_msg']));
        echo '</div>';
      }
      ?>
    </div>

    <?php
    $am_wizard_edit = false;
    include __DIR__ . '/wizard-start.php';
    ?>
    <div class="am-section am-section--classification" id="am-step-classification" data-am-step="classification">
      <h3 class="am-section__title" tabindex="-1"><?php echo esc_html__('Classificazione', 'archivio-micologico'); ?></h3>

    <?php $user = wp_get_current_user();
$roles = is_array($user->roles) ? $user->roles : [];
$am_can_add_terms = (is_user_logged_in() && (in_array('editor', $roles, true) || in_array('author', $roles, true) || current_user_can('edit_others_posts') || current_user_can('manage_terms'))); ?>

    <div class="am-grid">

      <div class="am-field">
        <label for="am_division"><?php echo esc_html__('Divisione', 'archivio-micologico'); ?> <span class="am-required">*</span></label>
        <select id="am_division" name="am_division" form="am-main-form" data-selected="<?php echo absint($am_sticky['am_division'] ?? 0); ?>">
          <option value=""><?php echo esc_html__('Seleziona…', 'archivio-micologico'); ?></option>
        </select>
      </div>

      <div class="am-field">
        <label for="am_family"><?php echo esc_html__('Famiglia', 'archivio-micologico'); ?> <span class="am-required">*</span></label>
        <select id="am_family" name="am_family" form="am-main-form" data-selected="<?php echo absint($am_sticky['am_family'] ?? 0); ?>">
          <option value=""><?php echo esc_html__('Seleziona…', 'archivio-micologico'); ?></option>
        </select>

        <?php if ($am_can_add_terms) : ?>
          <button type="button" class="am-btn am-add-term" data-am-level="family"><?php echo esc_html__('+ Aggiungi nuova Famiglia', 'archivio-micologico'); ?></button>
          <div class="am-add-term__panel" data-am-panel="family" hidden>
            
	            
<input type="text" class="am-add-term__input" data-am-input="family" name="am_term_name" form="am_add_term_family_form" placeholder="<?php echo esc_attr__('Nome famiglia', 'archivio-micologico'); ?>" />
            <button type="submit" class="button am-btn am-add-term__save_term" form="am_add_term_family_form" formnovalidate><?php echo esc_html__('Salva', 'archivio-micologico'); ?></button>
          </div>
        <?php endif; ?>
      </div>

      <div class="am-field">
        <label for="am_genus"><?php echo esc_html__('Genere', 'archivio-micologico'); ?> <span class="am-required">*</span></label>
        <select id="am_genus" name="am_genus" form="am-main-form" data-selected="<?php echo absint($am_sticky['am_genus'] ?? 0); ?>">
          <option value=""><?php echo esc_html__('Seleziona…', 'archivio-micologico'); ?></option>
        </select>

        <?php if ($am_can_add_terms) : ?>
          <button type="button" class="am-btn am-add-term" data-am-level="genus"><?php echo esc_html__('+ Aggiungi nuovo Genere', 'archivio-micologico'); ?></button>
          <div class="am-add-term__panel" data-am-panel="genus" hidden>
            <input type="text" class="am-add-term__input" data-am-input="genus" name="am_term_name" form="am_add_term_genus_form" placeholder="<?php echo esc_attr__('Nome genere', 'archivio-micologico'); ?>" />
            <button type="submit" class="button am-btn am-add-term__save_term" form="am_add_term_genus_form" formnovalidate><?php echo esc_html__('Salva', 'archivio-micologico'); ?></button>
          </div>
        <?php endif; ?>
      </div>

      
      <div class="am-field">
        <label for="am_species"><?php echo esc_html__('Specie', 'archivio-micologico'); ?> <span class="am-required">*</span></label>
        <select id="am_species" name="am_species" form="am-main-form" data-selected="<?php echo absint($am_sticky['am_species'] ?? 0); ?>">
          <option value=""><?php echo esc_html__('Seleziona…', 'archivio-micologico'); ?></option>
        </select>

        <?php if ($am_can_add_terms) : ?>
          <button type="button" class="am-btn am-add-term" data-am-level="species"><?php echo esc_html__('+ Aggiungi nuova Specie', 'archivio-micologico'); ?></button>
          <div class="am-add-term__panel" data-am-panel="species" hidden>
            <input type="text" class="am-add-term__input" data-am-input="species" name="am_term_name" form="am_add_term_species_form" placeholder="<?php echo esc_attr__('Nome specie', 'archivio-micologico'); ?>" />
            <button type="submit" class="button am-btn am-add-term__save_term" form="am_add_term_species_form" formnovalidate><?php echo esc_html__('Salva', 'archivio-micologico'); ?></button>
          </div>
        <?php endif; ?>
      </div>

      <div class="am-field">
        <label for="am_infra"><?php echo esc_html__('Forma / Varietà', 'archivio-micologico'); ?></label>
        <select id="am_infra" name="am_infra" form="am-main-form" data-selected="<?php echo absint($am_sticky['am_infra'] ?? 0); ?>">
          <option value=""><?php echo esc_html__('Seleziona…', 'archivio-micologico'); ?></option>
        </select>

        <?php if ($am_can_add_terms) : ?>
          <button type="button" class="am-btn am-add-term" data-am-level="infra"><?php echo esc_html__('+ Aggiungi nuova Forma/Varietà', 'archivio-micologico'); ?></button>
          <div class="am-add-term__panel" data-am-panel="infra" hidden>
            <input type="text" class="am-add-term__input" data-am-input="infra" name="am_term_name" form="am_add_term_infra_form" placeholder="<?php echo esc_attr__('Forma / Varietà', 'archivio-micologico'); ?>" />
            <button type="submit" class="button am-btn am-add-term__save_term" form="am_add_term_infra_form" formnovalidate><?php echo esc_html__('Salva', 'archivio-micologico'); ?></button>
          </div>
        <?php endif; ?>
      </div>

      <div class="am-field">
        <label for="am_author"><?php echo esc_html__('Autore del nome scientifico', 'archivio-micologico'); ?></label>
        <select id="am_author" name="am_author" form="am-main-form" data-selected="<?php echo absint($am_sticky['am_author'] ?? 0); ?>">
          <option value=""><?php echo esc_html__('Seleziona…', 'archivio-micologico'); ?></option>
        </select>
<?php if ($am_can_add_terms) : ?>
          <button type="button" class="am-btn am-add-term" data-am-level="author"><?php echo esc_html__('+ Aggiungi nuovo Autore', 'archivio-micologico'); ?></button>
          <div class="am-add-term__panel" data-am-panel="author" hidden>
            <input type="text" class="am-add-term__input" data-am-input="author" name="am_term_name" form="am_add_term_author_form" placeholder="<?php echo esc_attr__('Autore', 'archivio-micologico'); ?>" />
            <button type="submit" class="button am-btn am-add-term__save_term" form="am_add_term_author_form" formnovalidate><?php echo esc_html__('Salva', 'archivio-micologico'); ?></button>
          </div>
        <?php endif; ?>
      </div>


    </div>

    <?php if ($am_can_add_terms) : ?>
      <p class="am-help"><?php echo esc_html__('Puoi selezionare i termini dalle liste oppure crearli al volo (creazione rapida disponibile per Editor e superiori).', 'archivio-micologico'); ?></p>
    <?php else : ?>
      <p class="am-help"><?php echo esc_html__('I termini sono selezionabili solo da elenchi esistenti; la creazione/modifica dei termini è riservata ad Amministratore ed Editore.', 'archivio-micologico'); ?></p>
    <?php endif; ?>

    <div class="am-generated-title-card" aria-live="polite">
      <span class="am-generated-title-card__label"><?php echo esc_html__('Titolo scheda generato', 'archivio-micologico'); ?></span>
      <strong id="am-generated-title-preview" class="am-generated-title-card__value"><?php echo esc_html__('Seleziona genere e specie per generare il titolo.', 'archivio-micologico'); ?></strong>
      <p class="am-help"><?php echo esc_html__('Il titolo viene costruito automaticamente dalla classificazione scelta. Controllalo prima dell’invio.', 'archivio-micologico'); ?></p>
    </div>

    <input type="hidden" id="am_title" name="am_title" form="am-main-form" value="" />

  </div>

  <div class="am-section am-section--descriptions" id="am-step-descriptions" data-am-step="descriptions">
    <h3 class="am-section__title" tabindex="-1"><?php echo esc_html__('Descrizioni e osservazioni', 'archivio-micologico'); ?></h3>
    <p class="am-section__intro"><?php echo esc_html__('Inserisci prima la descrizione macroscopica. Le note e la descrizione microscopica possono essere completate anche con informazioni aggiuntive.', 'archivio-micologico'); ?></p>

  <div class="am-field">
    <label for="am_content"><?php echo esc_html__('Descrizione macroscopica', 'archivio-micologico'); ?> *</label>
    <p class="am-help"><?php echo esc_html__('Suggerimento: descrivi cappello, lamelle, gambo, carne, odore, sapore, habitat e osservazioni principali.', 'archivio-micologico'); ?></p>
    <div class="am-guided-template">
      <button type="button" class="button am-btn am-btn--secondary" id="am-insert-macro-template">
        <?php echo esc_html__('Inserisci traccia descrittiva', 'archivio-micologico'); ?>
      </button>
      <span class="am-guided-template__hint">
        <?php echo esc_html__('Inserisce una scaletta modificabile nel campo descrizione macroscopica.', 'archivio-micologico'); ?>
      </span>
    </div>
    <script type="application/json" id="am-macro-template-json"><?php echo wp_json_encode([
      'Cappello: ',
      'Lamelle: ',
      'Gambo: ',
      'Carne: ',
      'Odore: ',
      'Sapore: ',
      'Habitat: ',
      'Osservazioni: ',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <div class="am-editor">
      <?php
      // Editor classico (TinyMCE) sul front-end.
      wp_editor(
        (string) ($am_sticky['am_content'] ?? ''),
        'am_content',
        [
          'textarea_name' => 'am_content',
          'media_buttons' => true,
          'teeny' => false,
          'textarea_rows' => 10,
          'tinymce' => true,
          // Disabilita la scheda "Codice" (quicktags) per evitare inserimento HTML diretto.
          'quicktags' => false,
        ]
      );
      ?>
<script>/*am_content_inline_form_attr*/(function(){var ta=document.getElementById('am_content');if(ta&&!ta.getAttribute('form')){ta.setAttribute('form','am-main-form');}})();</script>
    </div>
  </div>

  


  <div class="am-field">
    <label for="am_macro_micro"><?php echo esc_html__('Descrizione microscopica', 'archivio-micologico'); ?></label>
        <p class="am-help"><?php echo esc_html__('Campo facoltativo per caratteri microscopici, misure sporali, cistidi, pileipellis e note tecniche.', 'archivio-micologico'); ?></p>
    <div class="am-editor">
      <?php
      // Editor classico (TinyMCE) anche per macro/micro, senza scheda "Codice".
      wp_editor(
        (string) ($am_sticky['am_macro_micro'] ?? ''),
        'am_macro_micro',
        [
          'textarea_name' => 'am_macro_micro',
          'media_buttons' => true,
          'teeny' => false,
          'textarea_rows' => 8,
          'tinymce' => true,
          'quicktags' => false,
        ]
      );
      ?>
<script>/*am_macro_micro_inline_form_attr*/(function(){var ta=document.getElementById('am_macro_micro');if(ta&&!ta.getAttribute('form')){ta.setAttribute('form','am-main-form');}})();</script>
    </div>
  </div>

      <div class="am-field">
        <label for="am_notes"><?php echo esc_html__('Commenti e note', 'archivio-micologico'); ?></label>
        <?php
          wp_editor(
            (string) ($am_sticky['am_notes'] ?? ''),
            'am_notes',
            [
              'textarea_name' => 'am_notes',
              'media_buttons' => false,
              'textarea_rows' => 8,
              'teeny' => true,
              'quicktags' => false,
            ]
          );
        ?>
      </div>
  </div><!-- /.am-section--descriptions -->

  <div class="am-section am-section--media" id="am-step-media" data-am-step="media">
    <h3 class="am-section__title" tabindex="-1"><?php echo esc_html__('Media e allegati', 'archivio-micologico'); ?></h3>
    <p class="am-section__intro"><?php echo esc_html__('Seleziona l’immagine di anteprima, la galleria e, se disponibile, un PDF collegato alla scheda.', 'archivio-micologico'); ?></p>

    <div class="am-field">
      <label><?php echo esc_html__('Immagine anteprima', 'archivio-micologico'); ?></label>
      <input type="hidden" name="am_featured_id" id="am_featured_id" value="<?php echo esc_attr($am_sticky['am_featured_id'] ?? ''); ?>" form="am-main-form" />
      <div class="am-media-actions">
        <button type="button" class="button am-btn" id="am_pick_featured"><?php echo esc_html__('Seleziona immagine', 'archivio-micologico'); ?></button>
        <button type="button" class="button am-btn am-btn--ghost am-clear-media" data-am-clear-media="featured"><?php echo esc_html__('Rimuovi', 'archivio-micologico'); ?></button>
      </div>
      <div id="am_featured_preview" class="am-media-preview"></div>
      <p id="am-media-status-featured" class="am-media-status"><?php echo esc_html__('Nessuna immagine di anteprima selezionata.', 'archivio-micologico'); ?></p>
      <p class="am-help"><?php echo esc_html__('Campo obbligatorio: seleziona un’immagine in evidenza.', 'archivio-micologico'); ?></p>
    </div>

    <div class="am-field">
      <label><?php echo esc_html__('Galleria', 'archivio-micologico'); ?></label>
      <input type="hidden" name="am_gallery_ids" id="am_gallery_ids" value="<?php echo esc_attr($am_sticky['am_gallery_ids'] ?? ''); ?>" form="am-main-form" />
      <div class="am-media-actions">
        <button type="button" class="button am-btn" id="am_pick_gallery"><?php echo esc_html__('Seleziona più immagini', 'archivio-micologico'); ?></button>
        <button type="button" class="button am-btn am-btn--ghost am-clear-media" data-am-clear-media="gallery"><?php echo esc_html__('Rimuovi galleria', 'archivio-micologico'); ?></button>
      </div>
      <div id="am_gallery_preview" class="am-media-preview am-media-preview--grid"></div>
      <p id="am-media-status-gallery" class="am-media-status"><?php echo esc_html__('Nessuna immagine in galleria selezionata.', 'archivio-micologico'); ?></p>
      <p class="am-help"><?php echo esc_html__('Campo obbligatorio: seleziona almeno un’immagine per la galleria.', 'archivio-micologico'); ?></p>
    </div>

    <div class="am-field">
      <label><?php echo esc_html__('PDF (facoltativo)', 'archivio-micologico'); ?></label>
      <input type="hidden" name="am_pdf_id" id="am_pdf_id" value="<?php echo esc_attr($am_sticky['am_pdf_id'] ?? ''); ?>" form="am-main-form" />
      <div class="am-media-actions">
        <button type="button" class="button am-btn" id="am_pick_pdf"><?php echo esc_html__('Seleziona PDF', 'archivio-micologico'); ?></button>
        <button type="button" class="button am-btn am-btn--ghost am-clear-media" data-am-clear-media="pdf"><?php echo esc_html__('Rimuovi PDF', 'archivio-micologico'); ?></button>
      </div>
      <div id="am_pdf_preview" class="am-media-preview"></div>
      <p id="am-media-status-pdf" class="am-media-status"><?php echo esc_html__('Nessun PDF selezionato.', 'archivio-micologico'); ?></p>
      <p class="am-help"><?php echo esc_html__('Facoltativo: puoi allegare un file PDF (es. scheda, articolo, relazione).', 'archivio-micologico'); ?></p>
    </div>
  
    <div class="am-field am-field--checkbox">
      <label>
        <input type="checkbox" name="am_sequenced" id="am_sequenced" value="1" form="am-main-form" <?php checked($am_sticky['am_sequenced'] ?? '0', '1'); ?> />
        <?php echo esc_html__('Specie sequenziata', 'archivio-micologico'); ?>
      </label>
      <p class="am-help"><?php echo esc_html__('Facoltativo: spunta se la specie è stata sequenziata.', 'archivio-micologico'); ?></p>
    </div>

  </div>

  
    <?php
    $am_author_top = wp_get_current_user()->display_name;
    $am_review_display = '';
    include __DIR__ . '/wizard-end.php';
    ?>

<?php
$am_show_tax_debug = (am_user_can_review() && isset($_GET['am_debug']));
if ($am_show_tax_debug && isset($_GET['am_created_id'])) :
  $pid = intval($_GET['am_created_id']);
?>
<div class="am-notice am-notice--info" style="margin-top:12px">
  <strong>Esito Classificazione (debug)</strong><br>
  <div style="font-family:monospace; font-size:12px; white-space:pre-wrap">
CREATED ID: <?php echo $pid; ?>

Meta:
- am_tax_order_id=<?php echo (int) get_post_meta($pid, 'am_tax_order_id', true); ?>
- am_tax_family_id=<?php echo (int) get_post_meta($pid, 'am_tax_family_id', true); ?>
- am_tax_genus_id=<?php echo (int) get_post_meta($pid, 'am_tax_genus_id', true); ?>
  </div>
</div>
<?php endif; ?>

<div class="am-actions am-wizard__actions">
  <button class="am-btn am-wizard__back" type="button" data-am-back hidden><?php echo esc_html__('Indietro', 'archivio-micologico'); ?></button>
  <button class="am-btn am-wizard__next" type="button" data-am-next hidden></button>
  <button class="am-btn am-submit" type="submit" form="am-main-form"><?php echo esc_html__('Invia scheda', 'archivio-micologico'); ?></button>
</div>
</form>

<!-- Microforms (fuori dal form principale) per abilitare l'intercettazione JS dei submit -->
<form id="am_add_term_family_form" method="post" class="am-add-term__microform" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
  <input type="hidden" name="am_add_term_nonce" value="<?php echo esc_attr(wp_create_nonce('am_add_term_post')); ?>" />
  <?php wp_referer_field(); ?>
  <input type="hidden" name="action" value="am_add_term_post" />
  <input type="hidden" name="am_add_term_post" value="1" />
  <input type="hidden" name="am_term_level" value="family" />
  <input type="hidden" name="am_term_parent" id="am_term_parent_family" value="0" />
</form>

<form id="am_add_term_genus_form" method="post" class="am-add-term__microform" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
  <input type="hidden" name="am_add_term_nonce" value="<?php echo esc_attr(wp_create_nonce('am_add_term_post')); ?>" />
  <?php wp_referer_field(); ?>
  <input type="hidden" name="action" value="am_add_term_post" />
  <input type="hidden" name="am_add_term_post" value="1" />
  <input type="hidden" name="am_term_level" value="genus" />
  <input type="hidden" name="am_term_parent" id="am_term_parent_genus" value="0" />
</form>

<form id="am_add_term_species_form" method="post" class="am-add-term__microform" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
  <input type="hidden" name="am_add_term_nonce" value="<?php echo esc_attr(wp_create_nonce('am_add_term_post')); ?>" />
  <?php wp_referer_field(); ?>
  <input type="hidden" name="action" value="am_add_term_post" />
  <input type="hidden" name="am_add_term_post" value="1" />
  <input type="hidden" name="am_term_level" value="species" />
  <input type="hidden" name="am_term_parent" id="am_term_parent_species" value="0" />
</form>

<form id="am_add_term_infra_form" method="post" class="am-add-term__microform" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
  <input type="hidden" name="am_add_term_nonce" value="<?php echo esc_attr(wp_create_nonce('am_add_term_post')); ?>" />
  <?php wp_referer_field(); ?>
  <input type="hidden" name="action" value="am_add_term_post" />
  <input type="hidden" name="am_add_term_post" value="1" />
  <input type="hidden" name="am_term_level" value="infra" />
  <input type="hidden" name="am_term_parent" value="0" />
</form>

<form id="am_add_term_author_form" method="post" class="am-add-term__microform" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
  <input type="hidden" name="am_add_term_nonce" value="<?php echo esc_attr(wp_create_nonce('am_add_term_post')); ?>" />
  <?php wp_referer_field(); ?>
  <input type="hidden" name="action" value="am_add_term_post" />
  <input type="hidden" name="am_add_term_post" value="1" />
  <input type="hidden" name="am_term_level" value="author" />
  <input type="hidden" name="am_term_parent" value="0" />
</form>

