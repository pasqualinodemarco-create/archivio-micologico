<?php
// Front-end edit form for "Scheda Fungo".
// Keep markup theme-friendly (Elementor), avoid fieldset/legend issues.

if (!defined('ABSPATH')) { exit; }

// Expected variables prepared in AM_Frontend::render_edit_form:
// $am_post, $am_selected_family, $am_selected_genus, $am_selected_species
// $am_selected_infra, $am_selected_author, $am_macro_micro, $am_notes
// $am_featured_id, $am_gallery_ids

$am_post_id = isset($am_post) ? (int) $am_post->ID : 0;
$am_title   = isset($am_post) ? (string) $am_post->post_title : '';
$am_content = isset($am_post) ? (string) $am_post->post_content : '';

$am_selected_family  = isset($am_selected_family) ? (int) $am_selected_family : 0;
$am_selected_genus   = isset($am_selected_genus) ? (int) $am_selected_genus : 0;
$am_selected_species = isset($am_selected_species) ? (int) $am_selected_species : 0;

$am_selected_infra  = isset($am_selected_infra) ? (int) $am_selected_infra : 0;
$am_selected_author = isset($am_selected_author) ? (int) $am_selected_author : 0;

$am_macro_micro = isset($am_macro_micro) ? (string) $am_macro_micro : '';
$am_notes       = isset($am_notes) ? (string) $am_notes : '';

$am_featured_id = isset($am_featured_id) ? (int) $am_featured_id : 0;
$am_gallery_ids = (isset($am_gallery_ids) && is_array($am_gallery_ids)) ? $am_gallery_ids : [];
$am_gallery_ids_csv = implode(',', array_map('absint', $am_gallery_ids));

// Token used for sticky values on validation errors (same mechanism as submit form).
$am_token = isset($_GET['am_token']) ? sanitize_text_field((string) $_GET['am_token']) : '';
if ($am_token === '') { $am_token = wp_generate_password(12, false, false); }
$am_sticky = AM_Frontend::am_get_sticky($am_token);

$user = wp_get_current_user();
$roles = is_array($user->roles) ? $user->roles : [];
$am_can_add_terms = (is_user_logged_in() && (in_array('editor', $roles, true) || in_array('author', $roles, true) || current_user_can('edit_others_posts') || current_user_can('manage_terms')));
?>

<form id="am-main-form" class="am-form am-wizard" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
  <input type="hidden" name="action" value="am_update_fungo" />
  <?php wp_nonce_field(AM_Frontend::NONCE_ACTION_UPDATE, AM_Frontend::NONCE_NAME); ?>
  <input type="hidden" name="am_token" value="<?php echo esc_attr($am_token); ?>" />
  <input type="hidden" name="am_action" value="update" />
  <input type="hidden" name="am_save_review" id="am_save_review" value="0" />
  <input type="hidden" name="am_post_id" value="<?php echo esc_attr($am_post_id); ?>" />
<div id="am-form-messages" class="am-form-messages am-form-messages--top" aria-live="polite">
  <?php
  if (isset($am_pre_notice_html) && $am_pre_notice_html) {
    echo $am_pre_notice_html; // already escaped inside
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
  $am_wizard_edit = true;
  // Autore scheda: sempre l'utente WordPress autore del post (post_author).
  // Non ha relazione con l'autore tassonomico del fungo (am_author_id).
  $am_author_top = '';
  $u = isset($am_post) ? get_userdata((int) $am_post->post_author) : null;
  if ($u) {
    $tmp = trim((string)$u->first_name . ' ' . (string)$u->last_name);
    $am_author_top = $tmp !== '' ? $tmp : (string) $u->display_name;
  }
  $am_last_reviewer_name = (string) get_post_meta($am_post_id, 'am_last_reviewer_name', true);
  $am_last_review_datetime = (string) get_post_meta($am_post_id, 'am_last_review_datetime', true);
  $am_review_display = '';
  if (trim($am_last_reviewer_name) !== '' && trim($am_last_review_datetime) !== '') {
    $am_review_display = sprintf(__('Revisione: %1$s (%2$s)', 'archivio-micologico'), $am_last_reviewer_name, $am_last_review_datetime);
  }
  include __DIR__ . '/wizard-start.php';
  ?>
  <div class="am-section am-section--classification" id="am-step-classification" data-am-step="classification">
    <h3 class="am-section__title" tabindex="-1"><?php echo esc_html__('Classificazione', 'archivio-micologico'); ?></h3>

    <div class="am-grid">

      <div class="am-field">
        <label for="am_division"><?php echo esc_html__('Divisione', 'archivio-micologico'); ?> <span class="am-required">*</span></label>
        <select id="am_division" name="am_division" form="am-main-form" data-selected="<?php echo isset($am_selected_division) ? (int) $am_selected_division : 0; ?>">
          <option value=""><?php echo esc_html__('Seleziona…', 'archivio-micologico'); ?></option>
        </select>
      </div>
      <div class="am-field">
        <label for="am_family"><?php echo esc_html__('Famiglia', 'archivio-micologico'); ?> <span class="am-required">*</span></label>
        <select id="am_family" name="am_family" form="am-main-form" data-selected="<?php echo esc_attr($am_selected_family); ?>">
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
        <select id="am_genus" name="am_genus" form="am-main-form" data-selected="<?php echo esc_attr($am_selected_genus); ?>" disabled>
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
        <label for="am_species"><?php echo esc_html__('Specie', 'archivio-micologico'); ?></label>
        <select id="am_species" name="am_species" form="am-main-form" data-selected="<?php echo esc_attr($am_selected_species); ?>" disabled>
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
        <select id="am_infra" name="am_infra" data-selected="<?php echo esc_attr($am_selected_infra); ?>">
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
        <select id="am_author" name="am_author" data-selected="<?php echo esc_attr($am_selected_author); ?>">
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

    <p class="am-help">
      <?php echo $am_can_add_terms
        ? esc_html__('Puoi selezionare i termini dalle liste oppure crearli al volo (creazione rapida disponibile per Editor e superiori).', 'archivio-micologico')
        : esc_html__('I termini sono selezionabili solo da elenchi esistenti; la creazione/modifica dei termini è riservata ai ruoli reviewer/manager.', 'archivio-micologico'); ?>
    </p>
    <div class="am-generated-title-card" aria-live="polite">
      <span class="am-generated-title-card__label"><?php echo esc_html__('Nome scientifico', 'archivio-micologico'); ?></span>
      <strong id="am-generated-title-preview" class="am-generated-title-card__value"><?php echo esc_html($am_title); ?></strong>
    </div>
  </div>

  <div class="am-section am-section--descriptions" id="am-step-descriptions" data-am-step="descriptions">
    <h3 class="am-section__title" tabindex="-1"><?php echo esc_html__('Descrizioni e osservazioni', 'archivio-micologico'); ?></h3>
  <div class="am-field">
    <label for="am_content"><?php echo esc_html__('Descrizione macroscopica', 'archivio-micologico'); ?></label>
    <div class="am-editor">
      <?php
      wp_editor(
        isset($am_sticky['am_content']) ? wp_kses_post((string) $am_sticky['am_content']) : $am_content,
        'am_content',
        [
          'textarea_name' => 'am_content',
          'media_buttons' => true,
          'teeny' => false,
          'textarea_rows' => 10,
          'tinymce' => true,
          'quicktags' => false,
        ]
      );
      ?>
      <script>(function(){var ta=document.getElementById('am_content');if(ta&&!ta.getAttribute('form')){ta.setAttribute('form','am-main-form');}})();</script>
    </div>
  </div>

  <div class="am-field">
    <label for="am_macro_micro"><?php echo esc_html__('Descrizione microscopica', 'archivio-micologico'); ?></label>
    <div class="am-editor">
      <?php
      wp_editor(
        isset($am_sticky['am_macro_micro']) ? wp_kses_post((string) $am_sticky['am_macro_micro']) : $am_macro_micro,
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
      <script>(function(){var ta=document.getElementById('am_macro_micro');if(ta&&!ta.getAttribute('form')){ta.setAttribute('form','am-main-form');}})();</script>
    </div>
  </div>

  <div class="am-field">
    <label for="am_notes"><?php echo esc_html__('Commenti e note', 'archivio-micologico'); ?></label>
    <div class="am-editor">
      <?php
      wp_editor(
        isset($am_sticky['am_notes']) ? wp_kses_post((string) $am_sticky['am_notes']) : $am_notes,
        'am_notes',
        [
          'textarea_name' => 'am_notes',
          'media_buttons' => false,
          'textarea_rows' => 8,
          'teeny' => true,
          'tinymce' => true,
          'quicktags' => false,
        ]
      );
      ?>
      <script>(function(){var ta=document.getElementById('am_notes');if(ta&&!ta.getAttribute('form')){ta.setAttribute('form','am-main-form');}})();</script>
    </div>
  </div>

  </div><!-- /.am-section--descriptions -->

  <div class="am-section am-section--media" id="am-step-media" data-am-step="media">
    <h3 class="am-section__title" tabindex="-1"><?php echo esc_html__('Media e allegati', 'archivio-micologico'); ?></h3>

    <div class="am-field">
      <label><?php echo esc_html__('Immagine anteprima', 'archivio-micologico'); ?></label>
      <input type="hidden" name="am_featured_id" id="am_featured_id" value="<?php echo esc_attr($am_featured_id); ?>" />
      <button type="button" class="button am-btn" id="am_pick_featured"><?php echo esc_html__('Seleziona immagine', 'archivio-micologico'); ?></button>
      <div id="am_featured_preview" class="am-media-preview">
        <?php if ($am_featured_id) : ?>
          <span class="am-thumb" data-kind="featured" data-id="<?php echo (int) $am_featured_id; ?>">
            <?php echo wp_get_attachment_image($am_featured_id, 'full'); ?>
            <button type="button" class="am-thumb-remove" aria-label="Rimuovi" data-am-remove="featured" data-id="<?php echo (int) $am_featured_id; ?>">×</button>
          </span>
        <?php endif; ?>
      </div>
      <p class="am-help"><?php echo esc_html__('Puoi aggiungere, sostituire o rimuovere l’immagine in evidenza.', 'archivio-micologico'); ?></p>
    </div>

    <div class="am-field">
      <label><?php echo esc_html__('Galleria', 'archivio-micologico'); ?></label>
      <input type="hidden" name="am_gallery_ids" id="am_gallery_ids" value="<?php echo esc_attr($am_gallery_ids_csv); ?>" />
      <button type="button" class="button am-btn" id="am_pick_gallery"><?php echo esc_html__('Seleziona più immagini', 'archivio-micologico'); ?></button>
      <div id="am_gallery_preview" class="am-media-preview am-media-preview--grid">
        <?php if (!empty($am_gallery_ids)) : ?>
          <?php foreach ($am_gallery_ids as $aid) : $aid = absint($aid); if (!$aid) continue; ?>
            <span class="am-thumb am-gallery-thumb" data-kind="gallery" data-id="<?php echo (int) $aid; ?>">
              <?php echo wp_get_attachment_image($aid, 'full'); ?>
              <button type="button" class="am-thumb-remove" aria-label="Rimuovi" data-am-remove="gallery" data-id="<?php echo (int) $aid; ?>">×</button>
            </span>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <p class="am-help"><?php echo esc_html__('Puoi aggiungere una o più immagini alla galleria.', 'archivio-micologico'); ?></p>
    </div>


    <?php $am_pdf_id = (int) get_post_meta($am_post_id, 'am_pdf_id', true); ?>
    <div class="am-field">
      <label><?php echo esc_html__('PDF allegato', 'archivio-micologico'); ?></label>
      <input type="hidden" name="am_pdf_id" id="am_pdf_id" value="<?php echo esc_attr($am_pdf_id); ?>" />
      <button type="button" class="button am-btn" id="am_pick_pdf"><?php echo esc_html__('Seleziona PDF', 'archivio-micologico'); ?></button>
      <div id="am_pdf_preview" class="am-media-preview">
        <?php if ($am_pdf_id) : ?>
          <?php $am_pdf_url = wp_get_attachment_url($am_pdf_id); ?>
          <?php if ($am_pdf_url) : ?>
            <a href="<?php echo esc_url($am_pdf_url); ?>" target="_blank" rel="noopener"><?php echo esc_html(basename($am_pdf_url)); ?></a>
            <button type="button" class="am-thumb-remove" style="margin-left:10px;" data-am-remove="pdf" data-id="<?php echo (int) $am_pdf_id; ?>">×</button>
          <?php endif; ?>
        <?php endif; ?>
      </div>
      <p class="am-help"><?php echo esc_html__('Facoltativo: puoi allegare un file PDF (es. scheda, articolo, relazione).', 'archivio-micologico'); ?></p>
    </div>

    <?php $am_seq = (string) get_post_meta($am_post_id, 'am_sequenced', true); ?>
    <div class="am-field am-field--checkbox">
      <label>
        <input type="checkbox" name="am_sequenced" id="am_sequenced" value="1" <?php checked($am_seq === '1' || $am_seq === 'yes' || $am_seq === 'on'); ?> />
        <?php echo esc_html__('Specie sequenziata', 'archivio-micologico'); ?>
      </label>
      <p class="am-help"><?php echo esc_html__('Facoltativo: spunta se la specie è stata sequenziata.', 'archivio-micologico'); ?></p>
    </div>
  </div>

  <?php include __DIR__ . '/wizard-end.php'; ?>

  <div class="am-actions am-wizard__actions">
    <button class="am-btn am-wizard__back" type="button" data-am-back hidden><?php echo esc_html__('Indietro', 'archivio-micologico'); ?></button>
    <button class="am-btn am-wizard__next" type="button" data-am-next hidden></button>
    <?php if (am_user_can_review($am_post_id)) : ?>
      <button type="button" class="am-btn am-save-review" id="am-save-review-btn" data-post-id="<?php echo (int) $am_post_id; ?>"><?php echo esc_html__('Salva revisione', 'archivio-micologico'); ?></button>
    <?php endif; ?>
    <button type="submit" class="am-btn am-submit" id="am-save-btn"><?php echo esc_html__('Salva modifiche', 'archivio-micologico'); ?></button>
  </div>

</form>

<?php if (am_user_is_admin_or_editor() && current_user_can('delete_post', $am_post_id)) : ?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="am-delete-form" style="margin-top:16px">
  <input type="hidden" name="action" value="am_delete_fungo" />
  <input type="hidden" name="post_id" value="<?php echo (int) $am_post_id; ?>" />
  <?php wp_nonce_field('am_delete_fungo_' . $am_post_id); ?>
  <button type="submit" class="am-btn" onclick="return confirm(<?php echo esc_attr(wp_json_encode(__('Spostare questa scheda nel cestino?', 'archivio-micologico'))); ?>);"><?php echo esc_html__('Sposta nel cestino', 'archivio-micologico'); ?></button>
</form>
<?php endif; ?>

<?php if ($am_can_add_terms) : ?>
  <!-- Microforms for term creation (kept outside main form; invalid nesting otherwise) -->
  <form id="am_add_term_family_form" method="post" class="am-add-term__microform" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
    <input type="hidden" name="action" value="am_add_term" />
    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('am_ajax')); ?>" />
    <input type="hidden" name="context" value="frontend" />
    <input type="hidden" name="level" value="family" />
    <input type="hidden" name="parent" value="0" />
  </form>

  <form id="am_add_term_genus_form" method="post" class="am-add-term__microform" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
    <input type="hidden" name="action" value="am_add_term" />
    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('am_ajax')); ?>" />
    <input type="hidden" name="context" value="frontend" />
    <input type="hidden" name="level" value="genus" />
    <input type="hidden" name="parent" id="am_term_parent_genus" value="0" />
  </form>

  <form id="am_add_term_species_form" method="post" class="am-add-term__microform" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
    <input type="hidden" name="action" value="am_add_term" />
    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('am_ajax')); ?>" />
    <input type="hidden" name="context" value="frontend" />
    <input type="hidden" name="level" value="species" />
    <input type="hidden" name="parent" id="am_term_parent_species" value="0" />
  </form>

  <form id="am_add_term_infra_form" method="post" class="am-add-term__microform" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
    <input type="hidden" name="action" value="am_add_term" />
    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('am_ajax')); ?>" />
    <input type="hidden" name="context" value="frontend" />
    <input type="hidden" name="level" value="infra" />
    <input type="hidden" name="parent" value="0" />
  </form>

  <form id="am_add_term_author_form" method="post" class="am-add-term__microform" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
    <input type="hidden" name="action" value="am_add_term" />
    <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('am_ajax')); ?>" />
    <input type="hidden" name="context" value="frontend" />
    <input type="hidden" name="level" value="author" />
    <input type="hidden" name="parent" value="0" />
  </form>
<?php endif; ?>
