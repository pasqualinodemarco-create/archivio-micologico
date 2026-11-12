<?php if (!defined('ABSPATH')) { exit; } ?>
    <header class="am-wizard__header">
      <div>
        <span class="am-wizard__eyebrow"><?php echo esc_html__('Archivio Micologico', 'archivio-micologico'); ?></span>
        <h2><?php echo esc_html($am_wizard_edit ? __('Modifica scheda fungo', 'archivio-micologico') : __('Nuova scheda fungo', 'archivio-micologico')); ?></h2>
        <p><?php echo esc_html($am_wizard_edit ? __('Aggiorna i dati scientifici e gli allegati della scheda.', 'archivio-micologico') : __('Inserisci i dati scientifici e le immagini della scheda.', 'archivio-micologico')); ?></p>
      </div>
      <span class="am-wizard__badge"><?php echo esc_html($am_wizard_edit ? __('In modifica', 'archivio-micologico') : __('In compilazione', 'archivio-micologico')); ?></span>
    </header>
    <?php $am_steps = [
      'classification' => __('Classificazione', 'archivio-micologico'),
      'descriptions' => __('Descrizioni', 'archivio-micologico'),
      'media' => __('Media e allegati', 'archivio-micologico'),
      'review' => __('Controllo finale', 'archivio-micologico'),
    ]; ?>
    <nav class="am-wizard__nav" hidden aria-label="<?php echo esc_attr__('Passaggi compilazione', 'archivio-micologico'); ?>">
      <ol>
        <?php $am_step_number = 0; foreach ($am_steps as $am_step => $am_label) : ?>
          <li><button type="button" data-am-go="<?php echo esc_attr($am_step); ?>" aria-controls="am-step-<?php echo esc_attr($am_step); ?>"><span aria-hidden="true"><?php echo ++$am_step_number; ?></span><?php echo esc_html($am_label); ?></button></li>
        <?php endforeach; ?>
      </ol>
    </nav>
    <div class="am-wizard__layout">
    <div class="am-wizard__panels">
