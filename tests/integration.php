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
        $checks['submit_permission'] = ($new_id > 0) === !$denied;
        if (!$denied) { $checks['default_status'] = get_post_status($new_id) === $case['status']; }
    }
    // Expected permission/nonce rejections are ordinary test outcomes; undefined callbacks are never allowed.
    $checks['no_plugin_warnings'] = count($plugin_warnings) === 0;
    $checks['no_php_fatal'] = !($fatal && in_array($fatal['type'], [E_ERROR,E_PARSE,E_COMPILE_ERROR], true));
    if (!$denied && in_array($kind,['update','settings','submit'],true)) { $checks['completed_without_exception'] = $error === null; }
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
            AM_Frontend::handle_submit(); break;
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
