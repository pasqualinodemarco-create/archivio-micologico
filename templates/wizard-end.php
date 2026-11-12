<?php if (!defined('ABSPATH')) { exit; } ?>
    <div class="am-section am-section--final-check" id="am-step-review" data-am-step="review">
      <h3 class="am-section__title" tabindex="-1"><?php echo esc_html__('Controllo finale', 'archivio-micologico'); ?></h3>
      <p class="am-section__intro"><?php echo esc_html($am_wizard_edit ? __('Controlla il titolo e i dati della scheda prima di salvare le modifiche.', 'archivio-micologico') : __('Controlla il titolo e i requisiti nel riepilogo prima di inviare la scheda. Puoi tornare a ogni passaggio per fare correzioni.', 'archivio-micologico')); ?></p>
      <div class="am-generated-title-card" data-am-final-title hidden>
        <span class="am-generated-title-card__label"><?php echo esc_html__('Titolo scheda generato', 'archivio-micologico'); ?></span>
        <strong class="am-generated-title-card__value" data-am-title></strong>
      </div>
    </div>
    </div><!-- /.am-wizard__panels -->

    <aside class="am-wizard__summary" aria-labelledby="am-wizard-summary-title">
      <h3 id="am-wizard-summary-title"><?php echo esc_html__('Stato della scheda', 'archivio-micologico'); ?></h3>
      <div data-am-progress-box hidden>
        <div id="am-wizard-progress-label" class="am-wizard__progress-label" aria-live="polite"><?php echo esc_html(sprintf(__('Requisiti completati: %1$d/%2$d', 'archivio-micologico'), 0, $am_wizard_edit ? 2 : 4)); ?></div>
        <progress data-am-progress value="0" max="<?php echo $am_wizard_edit ? 2 : 4; ?>" aria-labelledby="am-wizard-progress-label"></progress>
      </div>
      <?php $am_checks = [
        'classification' => ['classification', __('Classificazione', 'archivio-micologico')],
        'description' => ['descriptions', __('Descrizione macroscopica', 'archivio-micologico')],
        'featured' => ['media', __('Immagine di anteprima', 'archivio-micologico')],
        'gallery' => ['media', __('Galleria immagini', 'archivio-micologico')],
        'pdf' => ['media', __('PDF facoltativo', 'archivio-micologico')],
      ]; ?>
      <ul class="am-submit-checklist" id="am-submit-checklist">
        <?php foreach ($am_checks as $am_check => [$am_step, $am_label]) : ?>
          <li data-am-check="<?php echo esc_attr($am_check); ?>"<?php if ($am_check === 'pdf' || ($am_wizard_edit && in_array($am_check, ['featured', 'gallery'], true))) { echo ' data-am-optional'; } ?>>
            <span class="am-submit-checklist__status" aria-hidden="true">–</span>
            <a href="#am-step-<?php echo esc_attr($am_step); ?>" data-am-go="<?php echo esc_attr($am_step); ?>"><?php echo esc_html($am_label); ?><span class="screen-reader-text" data-am-check-state></span></a>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="am-form-meta" id="am-form-meta">
      <div class="am-form-meta__row">
        <span class="am-form-meta__label"><?php echo esc_html__('Autore della scheda', 'archivio-micologico'); ?></span>
        <span class="am-form-meta__value" id="am-author-top"><?php echo esc_html($am_author_top); ?></span>
        <span class="am-form-meta__review" id="am-review-top" style="<?php echo $am_review_display === '' ? 'display:none;' : ''; ?>"><?php echo esc_html($am_review_display); ?></span>
      </div>
    </div>
    </aside>
    </div><!-- /.am-wizard__layout -->

    <script type="application/json" data-am-wizard-config><?php echo wp_json_encode([
      'error' => is_string($_GET['am_error'] ?? null) ? sanitize_key($_GET['am_error']) : '',
      'progress' => __('Requisiti completati: %1$d/%2$d', 'archivio-micologico'),
      'complete' => __('completo', 'archivio-micologico'),
      'missing' => __('da completare', 'archivio-micologico'),
      'optional' => __('facoltativo', 'archivio-micologico'),
      'next' => [
        __('Continua alle descrizioni', 'archivio-micologico'),
        __('Continua ai media', 'archivio-micologico'),
        __('Vai al controllo finale', 'archivio-micologico'),
      ],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

