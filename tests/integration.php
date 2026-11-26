<?php
// Run only against a disposable WordPress installation. Never use a production database.
$case = json_decode($argv[1] ?? '{}', true);
if (getenv('AM_TEST_DISPOSABLE') !== '1') { throw new RuntimeException('Disposable test environment required'); }
if (!empty($case['trash_disabled'])) { define('EMPTY_TRASH_DAYS', 0); }
require rtrim(getenv('AM_WP_ROOT'), '/') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
$checks = [];
$error = null;
$plugin_warnings = [];
set_error_handler(function($severity, $message, $file) use (&$plugin_warnings) {
    if (str_contains($file, '/archivio-micologico/') || str_contains($file, '/project/')) { $plugin_warnings[] = $message; }
    return false;
});
$kind = $case['kind'];
$role = $case['role'] ?? 'editor';
$login = 'am_test_' . $role;
$user = get_user_by('login', $login);
if (!$user) {
    $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => $role, 'display_name' => 'Test ' . $role]);
    $user = get_user_by('id', $id);
}
$user->set_role($role);
$user->remove_cap('edit_others_am_funghi');
$admin = get_user_by('login', 'test_admin');
wp_set_current_user($admin->ID);
$terms = [];
foreach (['Basidiomycota', 'Test Family', 'Test Genus', 'test_species'] as $name) {
    $found = term_exists($name, AM_TAX_CLASSIFICAZIONE, end($terms) ?: 0);
    $t = $found ?: wp_insert_term($name, AM_TAX_CLASSIFICAZIONE, ['parent' => end($terms) ?: 0]);
    if (is_wp_error($t)) { throw new RuntimeException($t->get_error_message()); }
    $terms[] = (int) $t['term_id'];
}
$post_id = wp_insert_post(['post_type' => AM_CPT, 'post_status' => 'publish', 'post_author' => $admin->ID, 'post_title' => 'Original title', 'post_content' => 'Original description long enough for validation.']);
wp_set_object_terms($post_id, [$terms[3]], AM_TAX_CLASSIFICAZIONE);
$vocab = wp_insert_term('Test vocabulary ' . $post_id, AM_TAX_INFRASPEC);
$vocab_id = (int) $vocab['term_id'];
$pdf = wp_insert_attachment(['post_title' => 'Test PDF', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit'], false, $post_id);
update_post_meta($post_id, 'am_pdf_id', $pdf);
update_post_meta($post_id, 'am_gallery_ids', [999]);
wp_set_current_user($role === 'guest' ? 0 : $user->ID);
if (!empty($case['extra_author']) && $role === 'editor') { $user->add_role('author'); wp_set_current_user(0); wp_set_current_user($user->ID); }
if (!empty($case['revoke_object'])) { $user->add_cap('edit_others_am_funghi', false); wp_set_current_user(0); wp_set_current_user($user->ID); }
$_SERVER['REQUEST_METHOD'] = $case['method'] ?? 'POST';
$_POST = []; $_GET = [];
$can_manage = in_array($role, ['administrator', 'editor'], true);
$redirects = [];
add_filter('wp_redirect', function($url) use (&$redirects) { $redirects[] = $url; return $url; });
$die_handler = function() { return function($message, $title = '', $args = []) { throw new RuntimeException(is_scalar($message) ? (string) $message : 'wp_die'); }; };
foreach (['wp_die_handler','wp_die_ajax_handler','wp_die_json_handler'] as $filter) { add_filter($filter, $die_handler); }
ob_start();
register_shutdown_function(function() use (&$checks, &$error, &$redirects, &$plugin_warnings, $vocab_id, $kind, $case, $role, $post_id, $pdf, $terms, $user, $can_manage) {
    $output = ob_get_clean();
    $fatal = error_get_last();
    if ($fatal && in_array($fatal['type'], [E_ERROR,E_PARSE,E_COMPILE_ERROR], true)) { $error = $fatal['message']; }
    clean_post_cache($post_id);
    $denied = !$can_manage || !empty($case['bad_nonce']) || !empty($case['revoke_object']) || ($case['method'] ?? 'POST') !== 'POST';
    if ($kind === 'update') {
        $checks['update_permission'] = get_post_field('post_title', $post_id) !== 'Original title' ? !$denied : $denied;
        if (!$denied) {
            $checks['scientific_title'] = get_post_field('post_title', $post_id) === 'Test Genus test_species';
            $checks['classification'] = (int) get_post_meta($post_id, 'am_tax_species_id', true) === $terms[3];
            $checks['pdf'] = (int) get_post_meta($post_id, 'am_pdf_id', true) === (!empty($case['clear_media']) ? 0 : $pdf);
            $checks['gallery'] = get_post_meta($post_id, 'am_gallery_ids', true) === (!empty($case['clear_media']) ? [] : [999]);
            $checks['review_flag'] = (int) get_post_meta($post_id, 'am_last_review_user_id', true) === $user->ID;
            $checks['content_roundtrip'] = get_post_field('post_content', $post_id) === "Descrizione dell'albero e del fungo abbastanza lunga.";
        }
    } elseif ($kind === 'review') {
        $checks['review_permission'] = ((int) get_post_meta($post_id, 'am_last_review_user_id', true) === $user->ID) === !$denied;
    } elseif ($kind === 'delete') {
        if (!empty($case['wrong_type']) || !empty($case['trash_disabled'])) { $denied = true; }
        $checks['trash_permission'] = (get_post_status($post_id) === 'trash') === !$denied;
        $checks['no_permanent_delete'] = get_post($post_id) !== null;
        if (!$denied) { wp_untrash_post($post_id); $checks['restorable'] = get_post_status($post_id) !== 'trash'; }
    } elseif ($kind === 'settings') {
        $checks['settings_permission'] = get_option('am_seo_mode') === ($denied ? 'auto' : 'off');
        $checks['articles_order'] = get_option('am_articles_order') === ($denied ? 'date' : 'alpha');
        if (!$denied) {
            $expected = ['am_require_login'=>1,'am_default_status'=>'private','am_max_upload_mb'=>50,'am_edit_page_id'=>0,'am_show_submit_result_admin'=>0,'am_enable_debug_panel'=>0,'am_submit_result_ttl'=>600,'am_random_widget_title'=>'Titolo test','am_random_widget_count'=>12,'am_random_widget_autoplay'=>0,'am_random_widget_visible'=>3,'am_random_widget_interval'=>2];
            foreach ($expected as $key=>$value) { $checks[$key] = (string) get_option($key) === (string) $value; }
            $checks['no_manage_options_escalation'] = $role === 'administrator' || !user_can($user, 'manage_options');
        }
    } elseif (in_array($kind, ['tax_add','tax_update','tax_delete','tax_front'], true)) {
        $term = term_exists('Changed vocabulary ' . $post_id, AM_TAX_INFRASPEC);
        if ($kind === 'tax_delete') { $checks['term_permission'] = (get_term($vocab_id, AM_TAX_INFRASPEC) === null) === !$denied; }
        else { $checks['term_permission'] = (bool) $term === !$denied; }
    } elseif ($kind === 'submit') {
        $last = get_user_meta($user->ID, 'am_last_submit', true);
        $new_id = (int) ($last['post_id'] ?? 0);
        // Images are optional: a scheda may start with one photo, or none, and be completed later.
        $checks['submit_permission'] = ($new_id > 0) === !$denied;
        if (!$denied) { $checks['default_status'] = get_post_status($new_id) === $case['status']; }
    } elseif ($kind === 'article') {
        $found = get_posts(['post_type' => AM_ARTICLE_CPT, 'post_status' => 'any', 'numberposts' => 1, 'title' => 'Articolo di prova']);
        $article = $found ? $found[0] : null;
        $last = (string) end($redirects);
        if (!empty($case['empty'])) {
            $checks['empty_text_rejected'] = $article === null && str_contains($last, 'am_error=missing_content');
        } else {
            $checks['article_permission'] = ($article !== null) === !$denied;
            if ($article && !$denied) {
                $id = (int) $article->ID;
                $checks['status_published'] = $article->post_status === 'publish';
                $checks['excerpt_saved'] = $article->post_excerpt === 'Sommario breve.';
                $checks['category_saved'] = wp_list_pluck((array) get_the_terms($id, AM_TAX_ARTICLE_CAT), 'name') === ['Monografie'];
                $checks['media_saved'] = (int) get_post_thumbnail_id($id) > 0 && (int) get_post_meta($id, 'am_pdf_id', true) === $pdf && count((array) get_post_meta($id, 'am_gallery_ids', true)) === 1;
                $checks['redirect_clean'] = str_contains($last, 'am_submitted=1') && !str_contains($last, 'am_error');
                $archive = ArchivioMicologico\Archive::render_archive();
                $checks['archive_lists_article'] = str_contains($archive, 'id="articoli"') && str_contains($archive, 'Articolo di prova') && str_contains($archive, 'Sommario breve.') && str_contains($archive, 'am-archive-entry__pdf');
                $checks['archive_panel_closed'] = !str_contains($archive, 'id="articoli" open');
                update_option('am_archive_articles', 0);
                $checks['archive_panel_can_be_hidden'] = !str_contains(ArchivioMicologico\Archive::render_archive(), 'id="articoli"');
                $GLOBALS['post'] = $article;
                $single = (new ReflectionMethod('ArchivioMicologico\\Frontend', 'render_article_single'))->invoke(null, '<p>Testo.</p>');
                $checks['single_article'] = str_contains($single, 'Articolo scientifico') && str_contains($single, 'Monografie') && str_contains($single, 'am-article-abstract') && str_contains($single, 'am-entry-section--pdf') && str_contains($single, 'am-entry-gallery') && str_contains($single, 'am-lightbox-item') && str_contains($single, 'am-article-figure');
            }
        }
    } elseif ($kind === 'article_delete') {
        $found = get_posts(['post_type' => AM_ARTICLE_CPT, 'post_status' => ['publish', 'trash'], 'numberposts' => 1, 'title' => 'Da cestinare']);
        $status = $found ? $found[0]->post_status : '';
        $checks['trashed_only_if_allowed'] = ($status === 'trash') === !$denied;
        if (!$denied) {
            $checks['back_to_articles'] = str_contains((string) end($redirects), 'am_deleted=article');
        }
    } elseif ($kind === 'duplicates') {
        $raw = (string) ($GLOBALS['am_test_json'] ?? '');
        $json = json_decode(trim(substr($raw, (int) strpos($raw, '{'))), true);
        if ($can_manage) {
            $titles = array_column($json['data']['items'] ?? [], 'title');
            $checks['duplicate_found'] = ($json['success'] ?? false) && $titles === ['Original title'];
        } else {
            $checks['duplicate_denied'] = ($json['success'] ?? true) === false;
        }
    }
    if ($kind === 'manage') {
        $op = $case['op'];
        $extra = $GLOBALS['am_manage_extra'] ?? [];
        $code = '';
        foreach ($redirects as $url) { if (preg_match('/am_tm=([a-z_]+)/', $url, $m)) { $code = $m[1]; } }
        $title = (string) get_post_field('post_title', $post_id);
        if ($op !== 'render') {
            $last = (string) end($redirects);
            $checks['returns_to_page'] = $can_manage ? str_starts_with($last, home_url('/tassonomia/?am_vocab=author')) : true;
        }
        $exists = static fn($id, $tax = AM_TAX_CLASSIFICAZIONE) => get_term((int) $id, $tax) instanceof WP_Term;
        switch ($op) {
            case 'add':
                $added = (bool) term_exists('Nuovo Genere', AM_TAX_CLASSIFICAZIONE, $terms[1]);
                $checks['add_permission'] = $can_manage ? ($added && $code === 'added') : (!$added && $code === 'forbidden');
                break;
            case 'rename_species':
                $checks['title_rebuilt'] = str_starts_with($title, 'Test Genus renamed_species') && $code === 'renamed';
                $checks['species_meta'] = get_post_meta($post_id, 'am_species', true) === 'renamed_species';
                break;
            case 'rename_genus':
                $checks['title_rebuilt'] = str_starts_with($title, 'Other Genus test_species') && $code === 'renamed';
                break;
            case 'move_genus':
                $checks['moved'] = (int) get_term($terms[2], AM_TAX_CLASSIFICAZIONE)->parent === $extra['family2'] && $code === 'moved';
                $checks['family_meta'] = (int) get_post_meta($post_id, 'am_tax_family_id', true) === $extra['family2'];
                break;
            case 'move_bad':
                $checks['rank_enforced'] = (int) get_term($terms[3], AM_TAX_CLASSIFICAZIONE)->parent === $terms[2] && $code === 'bad_parent';
                break;
            case 'delete_used':
                $checks['in_use_kept'] = $exists($terms[3]) && $code === 'in_use';
                break;
            case 'delete_children':
                $checks['children_kept'] = $exists($terms[2]) && $code === 'has_children';
                break;
            case 'delete_unused':
                $checks['unused_deleted'] = !$exists($extra['unused']) && $code === 'deleted';
                break;
            case 'merge':
                $checks['merged'] = !$exists($vocab_id, AM_TAX_INFRASPEC) && has_term($extra['vocab2'], AM_TAX_INFRASPEC, $post_id) && $code === 'merged';
                $checks['merge_meta_title'] = (int) get_post_meta($post_id, 'am_infra_id', true) === $extra['vocab2'] && str_ends_with($title, 'var. merged');
                break;
            case 'render':
                [$tree, $vocab] = $GLOBALS['am_render'] ?? ['', ''];
                $checks['render'] = $can_manage
                    ? (str_contains($tree, 'am-mtree') && str_contains($tree, 'test_species') && str_contains($vocab, 'am-mtable'))
                    : (str_contains($tree, 'Non hai i permessi') && !str_contains($tree, 'am-mtree') && !str_contains($vocab, 'am-mtable'));
                break;
        }
    }
    // Expected permission/nonce rejections are ordinary test outcomes; undefined callbacks are never allowed.
    $checks['no_plugin_warnings'] = count($plugin_warnings) === 0;
    $checks['no_php_fatal'] = !($fatal && in_array($fatal['type'], [E_ERROR,E_PARSE,E_COMPILE_ERROR], true));
    if (!$denied && in_array($kind,['update','settings','submit','info','info_missing','changelog_parser','admin_pages','sitemap_images','article','book','article_delete','pages'],true)) { $checks['completed_without_exception'] = $error === null; }
    echo "\nAMTEST:" . json_encode(['case'=>$case,'checks'=>$checks,'error'=>$error,'warnings'=>$plugin_warnings,'redirects'=>$redirects,'output'=>substr($output,0,600)]) . "\n";
});
try {
    switch ($kind) {
        case 'caps':
            $before = [];
            foreach (wp_roles()->roles as $name=>$data) { $before[$name] = $data['capabilities']; }
            am_ensure_admin_editor_caps_runtime();
            foreach ($before as $name=>$caps) {
                foreach ($caps as $cap=>$value) {
                    if (strpos($cap,'am_') === 0 || strpos($cap,'_am_') !== false) { continue; }
                    $checks['preserve_'.$name.'_'.$cap] = get_role($name)->has_cap($cap) === (bool)$value;
                }
            }
            $checks['editor_no_manage_options'] = !get_role('editor')->has_cap('manage_options');
            $checks['author_upload_files'] = get_role('author')->has_cap('upload_files');
            $checks['only_authorized_am_roles'] = get_role('editor')->has_cap(AM_CAP_CREATE_POSTS) && !get_role('author')->has_cap(AM_CAP_CREATE_POSTS);
            foreach (['draft','pending','publish','private'] as $status) {
                wp_update_post(['ID'=>$post_id,'post_status'=>$status]);
                $checks['edit_'.$status] = current_user_can('edit_post',$post_id) === $can_manage;
                $checks['delete_'.$status] = current_user_can('delete_post',$post_id) === $can_manage;
            }
            break;
        case 'review':
            $_POST=['post_id'=>$post_id,'nonce'=>!empty($case['bad_nonce'])?'bad':wp_create_nonce('am_ajax')];
            $_REQUEST=$_POST;
            AM_Frontend::ajax_save_review(); break;
        case 'update':
            $_POST=['am_action'=>'update','am_post_id'=>$post_id,'am_nonce'=>!empty($case['bad_nonce'])?'bad':wp_create_nonce('am_update_fungo'),'am_species'=>$terms[3],'am_content'=>wp_slash("Descrizione dell'albero e del fungo abbastanza lunga."),'am_save_review'=>'1'];
            if (!empty($case['clear_media'])) { $_POST['am_pdf_id']='0'; $_POST['am_gallery_ids']=''; }
            AM_Frontend::handle_update(); break;
        case 'delete':
            if (!empty($case['wrong_type'])) { wp_update_post(['ID'=>$post_id,'post_type'=>'post']); }
            if (!empty($case['trash_disabled'])) { /* tested by a separate boot constant */ }
            $_POST=['post_id'=>$post_id,'_wpnonce'=>!empty($case['bad_nonce'])?'bad':wp_create_nonce('am_delete_fungo_'.$post_id)];
            AM_Frontend::handle_delete(); break;
        case 'settings':
            update_option('am_seo_mode','auto'); update_option('am_articles_order','date');
            $_POST=['_wpnonce'=>!empty($case['bad_nonce'])?'bad':wp_create_nonce('am_save_settings'),'am_seo_mode'=>'off','am_articles_order'=>'alpha','am_require_login'=>'1','am_default_status'=>'private','am_max_upload_mb'=>999,'am_edit_page_id'=>$post_id,'am_submit_result_ttl'=>9999,'am_random_widget_title'=>'Titolo test','am_random_widget_count'=>999,'am_random_widget_visible'=>999,'am_random_widget_interval'=>-1];
            AM_Admin::handle_save_settings(); break;
        case 'submit':
            update_option('am_default_status',$case['status']);
            delete_user_meta($user->ID,'am_last_submit');
            $_POST=['am_nonce'=>!empty($case['bad_nonce'])?'bad':wp_create_nonce('am_submit_fungo'),'am_species'=>$terms[3],'am_content'=>'Descrizione del fungo con informazioni sufficienti per la validazione.'];
            $media = $case['media'] ?? 'full';
            if ($media !== 'none') {
                $_POST['am_featured_id'] = (string) wp_insert_attachment(['post_title'=>'Anteprima','post_mime_type'=>'image/jpeg','post_status'=>'inherit'], 'test-featured.jpg');
                $_POST['am_gallery_ids'] = $media === 'full' ? (string) wp_insert_attachment(['post_title'=>'Galleria','post_mime_type'=>'image/jpeg','post_status'=>'inherit'], 'test-gallery.jpg') : '';
            }
            AM_Frontend::handle_submit(); break;
        case 'article_delete':
            $aid = wp_insert_post(['post_type' => AM_ARTICLE_CPT, 'post_status' => 'publish', 'post_author' => $admin->ID, 'post_title' => 'Da cestinare', 'post_content' => 'Testo.']);
            $_POST = ['post_id' => (string) $aid, '_wpnonce' => !empty($case['bad_nonce']) ? 'bad' : wp_create_nonce('am_delete_article_' . $aid)];
            ArchivioMicologico\EntryForms::handle_delete_article(); break;
        case 'pages':
            $auto = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Archivio', 'post_content' => '[am_archive]']);
            $other = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Altro archivio', 'post_content' => 'Testo']);
            $P = 'ArchivioMicologico\\AdminPages';
            $checks['automatic_page'] = $P::page_url('archive') === get_permalink($auto);
            update_option('am_page_archive', $other);
            $checks['chosen_page_wins'] = $P::page_url('archive') === get_permalink($other);
            update_option('am_page_archive', $post_id);
            $checks['non_page_ignored'] = $P::page_url('archive') === get_permalink($auto);
            $checks['edit_has_no_automatic'] = $P::page_url('edit') === '';
            $checks['every_page_has_help'] = count(array_filter($P::plugin_pages(), static fn($p) => count($p) === 4 && $p[3] !== '')) === 7;
            break;
        case 'article_cat':
            add_filter('wp_doing_ajax', '__return_true');
            $ajax = static function (array $data): array {
                $_POST = $data + ['nonce' => wp_create_nonce('am_article_cat')]; $_REQUEST = $_POST;
                ob_start();
                try { ArchivioMicologico\EntryForms::ajax_article_cat(); } catch (Throwable $e) { /* wp_die after JSON */ }
                $raw = (string) ob_get_clean();
                return (array) json_decode(trim(substr($raw, (int) strpos($raw, '{'))), true);
            };
            $add = $ajax(['op' => 'add', 'name' => 'Monografie']);
            $id = (int) ($add['data']['id'] ?? 0);
            if (!$can_manage) {
                $checks['denied'] = empty($add['success']) && !term_exists('Monografie', AM_TAX_ARTICLE_CAT);
                break;
            }
            $checks['added'] = !empty($add['success']) && $id > 0;
            $checks['duplicate_refused'] = empty($ajax(['op' => 'add', 'name' => 'Monografie'])['success']);
            $checks['renamed'] = !empty($ajax(['op' => 'rename', 'term_id' => $id, 'name' => 'Chiavi'])['success']) && get_term($id)->name === 'Chiavi';
            $used = wp_insert_post(['post_type' => AM_ARTICLE_CPT, 'post_status' => 'draft', 'post_title' => 'Bozza', 'post_content' => 'Testo.']);
            wp_set_object_terms($used, [$id], AM_TAX_ARTICLE_CAT);
            $checks['used_not_deleted'] = empty($ajax(['op' => 'delete', 'term_id' => $id])['success']) && get_term($id) !== null;
            wp_set_object_terms($used, [], AM_TAX_ARTICLE_CAT);
            $checks['unused_deleted'] = !empty($ajax(['op' => 'delete', 'term_id' => $id])['success']) && get_term($id, AM_TAX_ARTICLE_CAT) === null;
            $checks['bad_nonce_refused'] = empty($ajax(['op' => 'add', 'name' => 'X', 'nonce' => 'bad'])['success']);
            break;
        case 'book':
            $make = static function (string $title, int $family) use ($terms, $admin): int {
                $id = wp_insert_post(['post_type' => AM_CPT, 'post_status' => 'publish', 'post_author' => $admin->ID, 'post_title' => $title, 'post_content' => 'Testo.']);
                update_post_meta($id, 'am_tax_division_id', $terms[0]);
                update_post_meta($id, 'am_tax_family_id', $family);
                return $id;
            };
            update_post_meta($post_id, 'am_tax_division_id', $terms[0]);
            update_post_meta($post_id, 'am_tax_family_id', $terms[1]);
            $alpha = wp_insert_term('Agaricaceae', AM_TAX_CLASSIFICAZIONE, ['parent' => $terms[0]]);
            $a = $make('Zeta first family', (int) $alpha['term_id']);   // family "Agaricaceae" sorts before "Test Family"
            $b = $make('Alpha title', $terms[1]);
            ArchivioMicologico\BookNav::flush();
            $order = ArchivioMicologico\BookNav::order();
            $checks['archive_order'] = $order === [$a, $b, $post_id];
            [$prev, $next, $pos, $total] = ArchivioMicologico\BookNav::neighbours($b);
            $checks['neighbours'] = $prev === $a && $next === $post_id && $pos === 2 && $total === 3;
            $checks['book_ends'] = ArchivioMicologico\BookNav::neighbours($a)[0] === 0 && ArchivioMicologico\BookNav::neighbours($post_id)[1] === 0;
            $html = ArchivioMicologico\BookNav::markup($b);
            $checks['arrows'] = str_contains($html, 'rel="prev"') && str_contains($html, 'rel="next"') && str_contains($html, '1 di 3') && str_contains($html, '3 di 3') && str_contains($html, 'am-backward');
            $c = $make('Aaa newest', $terms[1]);
            $checks['cache_refreshed'] = ArchivioMicologico\BookNav::order()[1] === $c;
            wp_update_post(['ID' => $c, 'post_status' => 'draft']);
            $checks['unpublished_left_out'] = !in_array($c, ArchivioMicologico\BookNav::order(), true);
            $checks['effects'] = str_contains(ArchivioMicologico\BookNav::effect_css('libro', 650), 'am-book-turn 650ms')
                && str_contains(ArchivioMicologico\BookNav::effect_css('scorrimento', 400), '@view-transition')
                && ArchivioMicologico\BookNav::effect_css('nessuno', 650) === '' && ArchivioMicologico\BookNav::effect_css('bogus', 650) === '';
            update_option('am_book_speed', 99999);
            $checks['speed_clamped'] = ArchivioMicologico\BookNav::speed() === 1500;
            break;
        case 'article':
            update_option('am_default_status', 'publish');
            $cat = wp_insert_term('Monografie', AM_TAX_ARTICLE_CAT);
            $plate = (int) wp_insert_attachment(['post_title' => 'Tavola', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], '2026/01/tavola.jpg');
            $_POST = ['am_nonce' => wp_create_nonce('am_submit_fungo'), 'am_title' => 'Articolo di prova', 'am_excerpt' => 'Sommario breve.',
                'am_content' => empty($case['empty']) ? '<h2>Metodi</h2><p>Testo dell’articolo.</p>' : '<p>&nbsp;</p>',
                'am_article_cats' => [(string) $cat['term_id']], 'am_featured_id' => (string) $plate, 'am_gallery_ids' => (string) $plate, 'am_pdf_id' => (string) $pdf];
            $_SERVER['HTTP_REFERER'] = home_url('/nuovo-articolo/?am_error=missing_title');
            AM_Frontend::handle_submit_article(); break;
        case 'duplicates':
            // Behave as a real admin-ajax request: wp_send_json then ends through the (throwing) wp_die handler.
            add_filter('wp_doing_ajax', '__return_true');
            $_POST=['nonce'=>wp_create_nonce('am_ajax'),'taxon_id'=>$terms[3],'infra_id'=>0];
            $_REQUEST=$_POST;
            // WordPress flushes every output buffer in its own shutdown hook, which runs before
            // the one above: capture the JSON here.
            ob_start();
            try { ArchivioMicologico\EntryForms::ajax_find_duplicates(); } catch (Throwable $e) { /* wp_die after the JSON */ }
            $GLOBALS['am_test_json'] = (string) ob_get_clean();
            break;
        case 'ui':
            $_GET['am_edit']=$post_id;
            $html=AM_Frontend::render_edit_form();
            $checks['review_button']=(strpos($html,'id="am-save-review-btn"')!==false)===$can_manage;
            $checks['trash_button']=(strpos($html,'name="action" value="am_delete_fungo"')!==false)===$can_manage;
            $checks['nonce_in_form']=!$can_manage || strpos($html,'name="_wpnonce"')!==false;
            break;
        case 'upload':
            update_option('am_max_upload_mb',2);
            $_POST=['am_upload_nonce'=>wp_create_nonce('am_upload_image')];
            $large=['name'=>'photo.jpg','size'=>3*MB_IN_BYTES,'error'=>0];
            $small=['name'=>'photo.jpg','size'=>MB_IN_BYTES,'error'=>0];
            $checks['large_image_rejected']=!empty(AM_Frontend::filter_am_image_upload($large)['error']);
            $checks['small_image_allowed']=empty(AM_Frontend::filter_am_image_upload($small)['error'])===$can_manage;
            $_POST=[];
            $checks['ordinary_upload_unchanged']=AM_Frontend::filter_am_image_upload($large)===$large;
            break;
        case 'tax_add':
        case 'tax_update':
        case 'tax_delete':
            $action = ['tax_add'=>'am_vocab_add','tax_update'=>'am_vocab_update','tax_delete'=>'am_vocab_delete'][$kind];
            $_POST=['_wpnonce'=>wp_create_nonce($action),'tab'=>'infra','term_id'=>$vocab_id,'name'=>'Changed vocabulary '.$post_id];
            $method=['tax_add'=>'handle_vocab_add','tax_update'=>'handle_vocab_update','tax_delete'=>'handle_vocab_delete'][$kind];
            $_REQUEST=$_POST;
            AM_Admin::$method(); break;
        case 'tax_front':
            $_POST=['am_add_term_post'=>'1','am_add_term_nonce'=>wp_create_nonce('am_add_term_post'),'am_term_level'=>'infra','am_term_name'=>'Changed vocabulary '.$post_id];
            AM_Frontend::handle_add_term_post(); break;
        case 'assets':
            global $post;
            $post = new WP_Post((object)['ID'=>0,'post_content'=>'[am_edit_form]','post_type'=>'page']);
            AM_Frontend::enqueue_assets();
            $data = wp_scripts()->get_data('am-frontend','data');
            preg_match('/var AMFront = (.*);/', $data, $match);
            $config=json_decode($match[1] ?? '{}', true);
            $checks['review_js_permission']=((bool) ($config['canReview'] ?? false))===$can_manage;
            $params=apply_filters('plupload_default_params',[]);
            $checks['scoped_upload_marker']=isset($params['am_upload_nonce'])===$can_manage;
            break;
        case 'changelog_parser':
            $parser=new ReflectionMethod('AM_Admin','parse_changelog_items');
            $checks['current_heading']=$parser->invoke(null,"= 1.5.56 =\n* Current entry\n= 1.5.55 =\n* Older entry",'1.5.56')===['Current entry'];
            $checks['legacy_heading']=$parser->invoke(null,"v1.5.54 - title\n- Legacy entry\nv1.5.53 - old\n- Other entry",'1.5.54')===['Legacy entry'];
            $checks['missing_version']=$parser->invoke(null,"= 1.0.0 =\n* Older entry",AM_VERSION)===[];
            $checks['empty_file']=$parser->invoke(null,'',AM_VERSION)===[];
            break;
        case 'info_missing':
            $path=AM_PATH.'CHANGELOG.txt';
            $backup=file_get_contents($path);
            unlink($path);
            try {
                ob_start(); AM_Admin::render_info_version_page(); $html=ob_get_clean();
                $checks['missing_file_fallback']=str_contains($html,'Nessuna voce changelog');
            } finally { file_put_contents($path,$backup); }
            break;
        case 'info':
            ob_start();
            AM_Admin::render_info_version_page();
            $html=ob_get_clean();
            $checks['info_rendered']=str_contains($html,'Info / Versione') && str_contains($html,AM_VERSION);
            $changelog = file_get_contents(AM_PATH . 'CHANGELOG.txt');
            $heading = '= ' . preg_quote(AM_VERSION, '/') . ' =';
            preg_match('/^' . $heading . '\h*\R(.*?)(?=^= |^v\d|\z)/ms', $changelog, $section);
            preg_match_all('/^[-*]\h+(.+)$/m', $section[1] ?? '', $entries);
            $expected = array_map(static fn($entry) => esc_html(trim($entry)), $entries[1]);
            preg_match_all('/<li[^>]*>(.*?)<\/li>/s', $html, $rendered);
            $checks['current_changelog_rendered'] = $expected !== [] && array_diff($expected, $rendered[1]) === [];
            $checks['no_previous_version_entries'] = $rendered[1] === $expected;
            break;
        case 'manage':
            $op = $case['op'];
            wp_set_current_user($admin->ID);
            foreach (['division', 'family', 'genus', 'species'] as $i => $rank) { update_post_meta($post_id, 'am_tax_' . $rank . '_id', $terms[$i]); }
            wp_set_object_terms($post_id, [$vocab_id], AM_TAX_INFRASPEC);
            update_post_meta($post_id, 'am_infra_id', $vocab_id);
            $extra = [];
            if ($op === 'move_genus') { $extra['family2'] = (int) wp_insert_term('Second Family', AM_TAX_CLASSIFICAZIONE, ['parent' => $terms[0]])['term_id']; }
            if ($op === 'delete_unused') { $extra['unused'] = (int) wp_insert_term('unused_species', AM_TAX_CLASSIFICAZIONE, ['parent' => $terms[2]])['term_id']; }
            if ($op === 'merge') { $extra['vocab2'] = (int) wp_insert_term('var. merged', AM_TAX_INFRASPEC)['term_id']; }
            $GLOBALS['am_manage_extra'] = $extra;
            wp_set_current_user($user->ID);
            if ($op === 'render') {
                $GLOBALS['am_render'] = [do_shortcode('[am_tassonomia]'), do_shortcode('[am_vocabolari]')];
                break;
            }
            $requests = [
                'add' => ['op' => 'add', 'tax' => 'classificazione', 'parent' => $terms[1], 'name' => 'Nuovo Genere'],
                'rename_species' => ['op' => 'rename', 'tax' => 'classificazione', 'term_id' => $terms[3], 'name' => 'renamed_species'],
                'rename_genus' => ['op' => 'rename', 'tax' => 'classificazione', 'term_id' => $terms[2], 'name' => 'Other Genus'],
                'move_genus' => ['op' => 'move', 'tax' => 'classificazione', 'term_id' => $terms[2], 'parent' => $extra['family2'] ?? 0],
                'move_bad' => ['op' => 'move', 'tax' => 'classificazione', 'term_id' => $terms[3], 'parent' => $terms[1]],
                'delete_used' => ['op' => 'delete', 'tax' => 'classificazione', 'term_id' => $terms[3]],
                'delete_children' => ['op' => 'delete', 'tax' => 'classificazione', 'term_id' => $terms[2]],
                'delete_unused' => ['op' => 'delete', 'tax' => 'classificazione', 'term_id' => $extra['unused'] ?? 0],
                'merge' => ['op' => 'merge', 'tax' => 'infra', 'term_id' => $vocab_id, 'target_id' => $extra['vocab2'] ?? 0],
            ];
            $_POST = array_map('strval', $requests[$op] + ['action' => 'am_manage_term', '_wpnonce' => wp_create_nonce('am_manage_term')]);
            // The form sends its own page as a site-relative path; the referer points elsewhere on purpose.
            $_POST['am_return'] = '/tassonomia/?am_vocab=author';
            $_REQUEST = $_POST;
            $_SERVER['HTTP_REFERER'] = home_url('/altra-pagina/');
            ArchivioMicologico\TermManager::handle();
            break;
        case 'admin_pages':
            require_once ABSPATH . 'wp-admin/includes/admin.php';
            $twin = wp_insert_post(['post_type' => AM_CPT, 'post_status' => 'draft', 'post_author' => $user->ID, 'post_title' => 'Original  title', 'post_content' => 'Second copy.']);
            wp_set_object_terms($twin, [$terms[3]], AM_TAX_CLASSIFICAZIONE);
            $orphan = wp_insert_post(['post_type' => AM_CPT, 'post_status' => 'pending', 'post_author' => $user->ID, 'post_title' => 'Orphan scheda', 'post_content' => 'No classification.']);
            $quality = (new ReflectionMethod('ArchivioMicologico\\AdminPages', 'quality'))->invoke(null);
            $ids = static fn(array $items): array => array_map(static fn($i) => $i[0], $items);
            $checks['quality_no_author'] = in_array($post_id, $ids($quality['no_author']), true);
            $checks['quality_no_image'] = in_array($post_id, $ids($quality['no_image']), true);
            $checks['quality_no_class'] = in_array($orphan, $ids($quality['no_class']), true) && !in_array($post_id, $ids($quality['no_class']), true);
            $checks['quality_duplicates'] = array_diff([$post_id, $twin], $ids($quality['duplicates'])) === [] && !in_array($orphan, $ids($quality['duplicates']), true);
            $checks['quality_unused_vocab'] = $quality['unused_terms'] >= 1;
            $coverage = ArchivioMicologico\TermManager::coverage();
            $checks['coverage_species'] = $coverage['ranks']['species']['documented'] >= 1 && $coverage['ranks']['species']['terms'] >= $coverage['ranks']['species']['documented'];
            AM_Admin::register_settings();
            $render = static function (callable $fn): string { ob_start(); $fn(); return (string) ob_get_clean(); };
            $dash = $render([AM_Admin::class, 'render_dashboard_page']);
            $checks['dashboard_rendered'] = str_contains($dash, 'Copertura tassonomica') && str_contains($dash, 'Controllo qualità dei dati') && str_contains($dash, 'post_status=pending') && str_contains($dash, 'Orphan scheda');
            $settings = $render([AM_Admin::class, 'render_settings_page']);
            foreach (['am_require_login','am_default_status','am_max_upload_mb','am_edit_page_id','am_show_submit_result_admin','am_submit_result_ttl','am_enable_debug_panel','am_seo_mode','am_articles_order','am_recent_entries_count','am_single_show_title','am_random_widget_title','am_random_widget_count','am_random_widget_visible','am_random_widget_rows','am_random_widget_autoplay','am_random_widget_interval','am_book_nav','am_book_effect','am_book_speed','am_article_status','am_archive_articles','am_sitemap_images','am_page_archive','am_page_submit','am_page_article','am_page_taxonomy','am_page_vocab','am_page_search'] as $field) {
                $checks['settings_field_' . $field] = substr_count($settings, 'name="' . $field . '"') >= 1;
            }
            $checks['settings_single_form'] = substr_count($settings, 'value="am_save_settings"') === 1;
            $checks['instructions_rendered'] = str_contains($render([AM_Admin::class, 'render_instructions_page']), 'Regole di nomenclatura');
            $checks['taxonomy_page_rendered'] = str_contains($render([ArchivioMicologico\AdminPages::class, 'taxonomy']), 'am-mtree');
            $checks['vocab_page_rendered'] = str_contains($render([AM_Admin::class, 'render_vocabolari_page']), 'am-mtable');
            global $submenu;
            $submenu = [];
            AM_Admin::admin_menu();
            $slugs = array_map(static fn($item) => $item[2], $submenu['am_dashboard'] ?? []);
            $checks['menu_without_schede_links'] = !in_array('am_all_schede', $slugs, true) && !in_array('am_add_new', $slugs, true);
            $checks['menu_has_management'] = in_array('am_classificazione', $slugs, true) && in_array('am_vocabolari', $slugs, true) && in_array('am_settings', $slugs, true);
            break;
        case 'sitemap_images':
            $att = static fn(string $file, string $title) => (int) wp_insert_attachment(['post_title' => $title, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], $file, $post_id);
            $featured = $att('2026/01/macro.jpg', 'Macro');
            $micro = $att('2026/01/spore.jpg', 'Spore');
            update_post_meta($micro, '_wp_attachment_image_alt', 'Spore 1000x');
            update_post_meta($post_id, 'am_gallery_ids', [$featured, $micro, $micro, 999, $pdf]);
            $base = [['src' => wp_get_attachment_image_url($featured, 'full'), 'title' => 'Macro', 'alt' => '']];
            $out = apply_filters('wpseo_sitemap_urlimages', $base, $post_id);
            $srcs = array_column($out, 'src');
            $checks['gallery_added'] = in_array(wp_get_attachment_image_url($micro, 'full'), $srcs, true);
            $checks['no_duplicates'] = count($srcs) === 2;
            $checks['alt_kept'] = ($out[1]['alt'] ?? '') === 'Spore 1000x';
            $checks['pdf_skipped'] = !in_array(wp_get_attachment_url($pdf), $srcs, true);
            $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'P']);
            $checks['other_types_untouched'] = apply_filters('wpseo_sitemap_urlimages', $base, $page) === $base;
            break;
        case 'fallback':
            $method=new ReflectionMethod('AM_Admin','get_front_submit_page_url');
            $url=$method->invoke(null);
            $checks['fallback_without_recursion']=str_contains($url,'post-new.php?post_type=am_fungo');
            update_option('am_enable_debug_panel',1);
            AM_Elementor_Conditions::add_post_type_support();
            $checks['debug_callable']=true;
            break;
    }
} catch (Throwable $e) { $error=$e->getMessage(); }
