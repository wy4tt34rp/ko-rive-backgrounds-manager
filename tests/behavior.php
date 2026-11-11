<?php

declare(strict_types=1);

$GLOBALS['ko_test_options'] = array();
$GLOBALS['ko_test_actions'] = array();
$GLOBALS['ko_test_shortcodes'] = array();
$GLOBALS['ko_test_upload_dir'] = sys_get_temp_dir() . '/ko-rive-manager-test-' . getmypid();
$GLOBALS['ko_test_queried_id'] = 0;
$GLOBALS['ko_test_post_types'] = array();
$GLOBALS['ko_test_menu_page'] = array();
$GLOBALS['ko_test_current_user_caps'] = array('edit_pages' => true);
$GLOBALS['ko_test_checked_capability'] = '';
$GLOBALS['ko_test_nonce_checked'] = array();

function add_action($hook, $callback, $priority = 10) { $GLOBALS['ko_test_actions'][$hook][] = $callback; }
function add_filter($hook, $callback, $priority = 10, $args = 1) {}
function add_menu_page($page_title, $menu_title, $capability, $menu_slug, $callback, $icon_url = '', $position = null) { $GLOBALS['ko_test_menu_page'] = compact('page_title', 'menu_title', 'capability', 'menu_slug', 'callback', 'icon_url', 'position'); return 'toplevel_page_' . $menu_slug; }
function add_shortcode($tag, $callback) { $GLOBALS['ko_test_shortcodes'][$tag] = $callback; }
function shortcode_exists($tag) { return isset($GLOBALS['ko_test_shortcodes'][$tag]); }
function register_activation_hook($file, $callback) {}
function plugin_basename($file) { return basename($file); }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/ko-rive-backgrounds-manager/'; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['ko_test_options']) ? $GLOBALS['ko_test_options'][$key] : $default; }
function get_site_option($key, $default = false) { return $default; }
function is_multisite() { return false; }
function add_option($key, $value) { if (array_key_exists($key, $GLOBALS['ko_test_options'])) { return false; } $GLOBALS['ko_test_options'][$key] = $value; return true; }
function update_option($key, $value) { $GLOBALS['ko_test_options'][$key] = $value; return true; }
function current_time($type) { return '2026-07-21 14:00:00'; }
function __($text, $domain = '') { return $text; }
function esc_html__($text, $domain = '') { return htmlspecialchars((string) $text, ENT_QUOTES); }
function sanitize_title($value) { $value = strtolower(trim((string) $value)); $value = preg_replace('/[^a-z0-9-]+/', '-', $value); return trim($value, '-'); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_html_class($value) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $value); }
function sanitize_file_name($value) { return preg_replace('/[^A-Za-z0-9._-]/', '-', basename((string) $value)); }
function sanitize_hex_color($value) { return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $value) ? strtolower((string) $value) : null; }
function wp_unslash($value) { return $value; }
function esc_url_raw($value) { return (string) $value; }
function esc_url($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function absint($value) { return abs((int) $value); }
function wp_parse_url($url) { return parse_url((string) $url); }
function wp_upload_dir() { return array('basedir' => $GLOBALS['ko_test_upload_dir'], 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => false); }
function wp_get_attachment_url($id) { return $id ? 'https://example.test/wp-content/uploads/loader.riv' : false; }
function trailingslashit($value) { return rtrim((string) $value, '/\\') . '/'; }
function add_query_arg($key, $value = null, $url = null) {
    if (is_array($key)) { return ($value ?: '') . '?' . http_build_query($key); }
    return (string) $url . (strpos((string) $url, '?') === false ? '?' : '&') . rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
}
function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim((string) $path, '/'); }
function home_url($path = '/') { return 'https://example.test' . $path; }
function get_permalink($id) { return $id ? 'https://example.test/page-' . absint($id) . '/' : false; }
function get_category_link($id) { return $id ? 'https://example.test/category-' . absint($id) . '/' : false; }
function get_queried_object_id() { return $GLOBALS['ko_test_queried_id']; }
function get_post_type($id = null) { $id = $id ?: $GLOBALS['ko_test_queried_id']; return $GLOBALS['ko_test_post_types'][$id] ?? false; }
function is_front_page() { return false; }
function is_home() { return false; }
function is_search() { return false; }
function is_404() { return false; }
function is_category($ids = array()) { return false; }
function has_category($ids = array(), $post = null) { return false; }
function shortcode_atts($pairs, $atts) { return array_merge($pairs, is_array($atts) ? $atts : array()); }
function do_shortcode($content) { return (string) $content; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function wp_add_inline_script() {}
function wp_json_encode($value) { return json_encode($value); }
function current_user_can($capability) { $GLOBALS['ko_test_checked_capability'] = $capability; return !empty($GLOBALS['ko_test_current_user_caps'][$capability]); }
function check_admin_referer($action, $name) { $GLOBALS['ko_test_nonce_checked'] = array($action, $name); return true; }
function selected($a, $b, $echo = true) { $out = $a == $b ? 'selected="selected"' : ''; if ($echo) { echo $out; } return $out; }
function checked($a, $b = true, $echo = true) { $out = $a == $b ? 'checked="checked"' : ''; if ($echo) { echo $out; } return $out; }

final class WP_Error {
    private $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }

function ko_assert($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/ko-rive-backgrounds-manager.php';

mkdir($GLOBALS['ko_test_upload_dir'] . '/ko-rive-backgrounds-manager', 0777, true);
mkdir($GLOBALS['ko_test_upload_dir'] . '/ko-rive-hero', 0777, true);
file_put_contents($GLOBALS['ko_test_upload_dir'] . '/ko-rive-backgrounds-manager/yellow.riv', 'RIVEtest-yellow');
file_put_contents($GLOBALS['ko_test_upload_dir'] . '/ko-rive-hero/hero-deadbeef0000.riv', 'RIVEtest-hero');

$legacy_backgrounds = array(
    'yellow' => array(
        'name' => 'Yellow Navigation',
        'link' => 'https://public.rive.app/hosted/example/yellow.riv',
        'file' => 'ko-rive-backgrounds-manager/yellow.riv',
        'hash' => 'abcdef1234567890',
        'title' => 'Working Project',
        'artboard' => 'Nav',
        'animation' => '',
        'state_machine' => 'State Machine 1',
    ),
);
$legacy_hero = array(
    'link' => 'https://public.rive.app/hosted/example/hero.riv',
    'file' => 'ko-rive-hero/hero-deadbeef0000.riv',
    'hash' => 'deadbeef00001234',
    'title' => 'Homepage Hero',
    'artboard' => 'landingLayout',
    'animation' => '',
    'state_machine' => 'stateMachine',
);
$legacy_loader = array(
    'enabled' => 1,
    'rive_url' => 'https://example.test/wp-content/uploads/logo-loader.riv',
    'artboard' => 'logoLoaderResponsive',
    'state_machine' => 'stateMachine',
    'completion_event' => 'logoLoaderDone',
    'display_mode' => 'include_selected',
    'front_page' => 1,
    'frequency' => 'session',
    'minimum_duration' => 500,
    'maximum_duration' => 5000,
    'fade_duration' => 350,
    'fit' => 'cover',
    'alignment' => 'center',
    'background_color' => '#ffffff',
    'lock_scroll' => 1,
    'respect_reduced_motion' => 1,
    'aria_label' => 'Loading page',
);

$GLOBALS['ko_test_options'] = array(
    'ko_rbm_backgrounds' => $legacy_backgrounds,
    'ko_rh_hero' => $legacy_hero,
    'ko_rpl_settings' => $legacy_loader,
);
$before = serialize($GLOBALS['ko_test_options']);
KO_Rive_Backgrounds_Manager::maybe_migrate();

ko_assert(serialize(array_intersect_key($GLOBALS['ko_test_options'], array_flip(array('ko_rbm_backgrounds', 'ko_rh_hero', 'ko_rpl_settings')))) === $before, 'Migration must not modify any legacy option.');
$library = $GLOBALS['ko_test_options']['ko_rive_library_v3'];
ko_assert(count($library) === 3, 'Migration should copy one background, one hero, and one loader.');
ko_assert(isset($library['rive-bg-yellow']), 'The existing background class must be preserved exactly.');
ko_assert($library['rive-bg-yellow']['role'] === 'background', 'The background role should migrate.');
ko_assert(in_array('yellow', $library['rive-bg-yellow']['aliases'], true), 'The legacy shortcode key should remain an alias.');
ko_assert($library['rive-hero']['role'] === 'hero', 'The Hero should migrate into the Library.');
ko_assert($library['rive-page-loader']['role'] === 'loader', 'The Page Loader should migrate into the Library.');
ko_assert($library['rive-page-loader']['loader_enabled'] === 1, 'The Page Loader enabled state should migrate.');
ko_assert(get_option('ko_rive_manager_migration_v3')['sources']['backgrounds'] === $legacy_backgrounds, 'A complete migration snapshot should be retained.');

$library['rive-hero']['artboard'] = '';
$library['rive-hero']['animation'] = '';
$library['rive-hero']['state_machine'] = '';
$library['rive-hero']['source_owner'] = 'unified';
$GLOBALS['ko_test_options']['ko_rive_library_v3'] = $library;
KO_Rive_Backgrounds_Manager::maybe_repair_v301();
$library = get_option('ko_rive_library_v3');
ko_assert($library['rive-hero']['artboard'] === 'landingLayout' && $library['rive-hero']['state_machine'] === 'stateMachine', 'The 3.0.1 repair should restore matching legacy Hero metadata without replacing the item.');
ko_assert($library['rive-hero']['source_owner'] === 'unified', 'The metadata repair must preserve unified item ownership.');

$manager = new KO_Rive_Backgrounds_Manager();
$manager->admin_menu();
ko_assert(KO_Rive_Backgrounds_Manager::MANAGE_CAPABILITY === 'edit_pages', 'Rive Library management should use an Editor-compatible capability.');
ko_assert($GLOBALS['ko_test_menu_page']['capability'] === 'edit_pages', 'The Rive Manager admin menu must be visible to Editors.');
ko_assert($GLOBALS['ko_test_menu_page']['menu_slug'] === 'ko-rive-backgrounds-manager', 'The Editor-visible menu must open the existing Rive Manager screen.');
$action_links = $manager->plugin_action_links(array());
ko_assert(strpos($action_links[0], '/wp-admin/admin.php?page=ko-rive-backgrounds-manager') !== false, 'The plugin action link must use the Editor-accessible top-level admin URL.');
$require_admin = new ReflectionMethod(KO_Rive_Backgrounds_Manager::class, 'require_admin_request');
if (PHP_VERSION_ID < 80100) { $require_admin->setAccessible(true); }
$require_admin->invoke($manager);
ko_assert($GLOBALS['ko_test_checked_capability'] === 'edit_pages', 'Every Library-changing request must require the same Editor-compatible capability.');
ko_assert($GLOBALS['ko_test_nonce_checked'] === array('ko_rive_manage', 'ko_rive_nonce'), 'Editor Library changes must retain nonce verification.');
$manager->register_shortcodes();
$new_markup = call_user_func($GLOBALS['ko_test_shortcodes']['ko_rive'], array('id' => 'rive-bg-yellow'));
$legacy_markup = call_user_func($GLOBALS['ko_test_shortcodes']['ko_rive_background'], array('id' => 'yellow'));
ko_assert(strpos($new_markup, 'data-ko-rive-id="rive-bg-yellow"') !== false, 'The new shortcode ID must equal the assigned class.');
ko_assert(strpos($new_markup, 'class="ko-rive-item rive-bg-yellow') !== false, 'The assigned class must appear in shortcode markup.');
ko_assert(strpos($legacy_markup, 'data-ko-rive-id="rive-bg-yellow"') !== false, 'The v1 background shortcode must still resolve its old key.');

$about_loader = array_merge($library['rive-page-loader'], array('loader_display_mode' => 'include_selected', 'loader_page_ids' => array(9), 'loader_front_page' => 0));
$GLOBALS['ko_test_queried_id'] = 9;
$GLOBALS['ko_test_post_types'][9] = 'page';
$_SERVER['REQUEST_URI'] = '/about/';
$loader_match = new ReflectionMethod(KO_Rive_Backgrounds_Manager::class, 'loader_matches_location');
if (PHP_VERSION_ID < 80100) { $loader_match->setAccessible(true); }
ko_assert($loader_match->invoke($manager, $about_loader) === true, 'A selected destination page must match by its queried page ID.');
$loader_urls = new ReflectionMethod(KO_Rive_Backgrounds_Manager::class, 'loader_target_urls');
if (PHP_VERSION_ID < 80100) { $loader_urls->setAccessible(true); }
ko_assert(in_array('https://example.test/page-9/', $loader_urls->invoke(null, $about_loader), true), 'A selected destination page URL must be available to the navigation-start loader.');
$original_loader_for_bootstrap = $library['rive-page-loader'];
$library['rive-page-loader'] = $about_loader;
$GLOBALS['ko_test_options']['ko_rive_library_v3'] = $library;
ob_start();
$manager->render_loader_bootstrap();
$loader_bootstrap = ob_get_clean();
ko_assert(strpos($loader_bootstrap, 'ko-rive-loader-pending') !== false, 'A matching loader must receive an early head shell before destination content paints.');
ko_assert(strpos($loader_bootstrap, 'rive-page-loader') !== false && strpos($loader_bootstrap, 'https:\/\/example.test\/page-9\/') !== false, 'The early shell must include the matching loader ID and selected destination URL.');
ko_assert(strpos($loader_bootstrap, 'scrollbar-gutter:stable') !== false, 'The early loader shell must reserve the scrollbar gutter while scrolling is locked.');
ko_assert(strpos($loader_bootstrap, 'koRiveLoaderNavigationHandoff') !== false, 'The destination head bootstrap must consume the same-tab navigation handoff.');
ko_assert(strpos($loader_bootstrap, 'handoff.revealedAt') !== false && strpos($loader_bootstrap, 'handoff.revealAfter') !== false, 'The destination must preserve the source loader reveal state and original lead timing.');
ko_assert(strpos($loader_bootstrap, '--ko-rive-loader-bootstrap-frame') !== false && strpos($loader_bootstrap, 'handoff.frameDataUrl') !== false, 'The destination head must paint a validated outgoing loader frame before body content replaces it.');
ko_assert(strpos($loader_bootstrap, 'value.length<=350000') !== false && strpos($loader_bootstrap, 'value.destination!==comparable(window.location.href)') !== false, 'The early frame handoff must remain size-bounded and bound to the exact destination.');
$library['rive-page-loader'] = $original_loader_for_bootstrap;
$GLOBALS['ko_test_options']['ko_rive_library_v3'] = $library;
$url_loader = array_merge($about_loader, array('loader_page_ids' => array(), 'loader_front_page' => 0));
$url_loader['loader_page_ids'] = array(9);
$GLOBALS['ko_test_queried_id'] = 0;
$GLOBALS['ko_test_post_types'] = array();
$_SERVER['REQUEST_URI'] = '/page-9/?cache-bypass=1';
ko_assert($loader_match->invoke($manager, $url_loader) === true, 'A selected destination must match its request path even when theme timing does not expose the queried object.');
$standalone_active = new ReflectionMethod(KO_Rive_Backgrounds_Manager::class, 'standalone_loader_plugin_active');
if (PHP_VERSION_ID < 80100) { $standalone_active->setAccessible(true); }
$GLOBALS['ko_test_options']['active_plugins'] = array();
ko_assert($standalone_active->invoke(null) === false, 'An inactive standalone Page Loader must not suppress the unified loader.');
$GLOBALS['ko_test_options']['active_plugins'] = array('ko-rive-page-loader/ko-rive-page-loader.php');
ko_assert($standalone_active->invoke(null) === true, 'An active standalone Page Loader should retain transition precedence.');
$GLOBALS['ko_test_options']['active_plugins'] = array();

$save = new ReflectionMethod(KO_Rive_Backgrounds_Manager::class, 'save_item');
if (PHP_VERSION_ID < 80100) { $save->setAccessible(true); }
$raw_hero = array(
    'item_role' => 'hero', 'source_mode' => 'external', 'load_policy' => 'lazy',
    'artboard' => 'Secondary', 'state_machine' => 'State Machine', 'fit' => 'layout', 'alignment' => 'center',
    'fallback_color' => '#222222', 'fallback_enabled' => 1, 'height' => '80svh', 'min_height' => '500px',
);
$result = $save->invoke(null, $library, '', 'secondary-hero', 'Secondary Hero', 'https://cdn.example.test/secondary.riv', $raw_hero);
ko_assert($result === true, 'A second Hero should be addable to the unified Library.');
$failed_upload = array('name' => 'too-large.riv', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE);
$result = $save->invoke(null, $library, '', 'failed-upload', 'Failed Upload', '', $raw_hero, $failed_upload);
ko_assert(is_wp_error($result) && strpos($result->get_error_message(), 'upload failed') !== false, 'A PHP upload error must be reported instead of silently keeping or creating a Library item.');
$wrong_extension = array('name' => 'not-rive.txt', 'tmp_name' => '/tmp/not-rive', 'size' => 100, 'error' => UPLOAD_ERR_OK);
$result = $save->invoke(null, $library, '', 'wrong-extension', 'Wrong Extension', '', $raw_hero, $wrong_extension);
ko_assert(is_wp_error($result) && strpos($result->get_error_message(), '.riv extension') !== false, 'An uploaded file without a .riv extension must be rejected.');
$too_small = array('name' => 'too-small.riv', 'tmp_name' => '/tmp/too-small', 'size' => 4, 'error' => UPLOAD_ERR_OK);
$result = $save->invoke(null, $library, '', 'too-small', 'Too Small', '', $raw_hero, $too_small);
ko_assert(is_wp_error($result) && strpos($result->get_error_message(), 'between 8 bytes and 10 MB') !== false, 'An implausibly small uploaded .riv file must be rejected.');
$untrusted_upload = array('name' => 'untrusted.riv', 'tmp_name' => '/tmp/not-an-http-upload', 'size' => 100, 'error' => UPLOAD_ERR_OK);
$result = $save->invoke(null, $library, '', 'untrusted-upload', 'Untrusted Upload', '', $raw_hero, $untrusted_upload);
ko_assert(is_wp_error($result) && strpos($result->get_error_message(), 'verify the uploaded') !== false, 'A file that did not originate from PHP HTTP upload handling must be rejected.');
$library = get_option('ko_rive_library_v3');
$raw_loader = array(
    'item_role' => 'loader', 'source_mode' => 'external', 'load_policy' => 'preload',
    'artboard' => 'Loader', 'state_machine' => 'Machine', 'fit' => 'cover', 'alignment' => 'center',
    'fallback_color' => '#ffffff', 'loader_enabled' => 1, 'loader_priority' => 20,
	'loader_display_mode' => 'everywhere', 'loader_frequency' => 'every_load',
	'loader_grace' => 350, 'loader_minimum_visible' => 250, 'loader_minimum' => 0,
	'loader_maximum' => 4000, 'loader_hold' => 180, 'loader_fade' => 250,
    'loader_lock_scroll' => 1, 'loader_reduced_motion' => 1, 'loader_aria_label' => 'Loading alternate view',
);
$result = $save->invoke(null, $library, '', 'alternate-loader', 'Alternate Loader', 'https://cdn.example.test/alternate-loader.riv', $raw_loader);
ko_assert($result === true, 'A second Page Loader should be addable to the unified Library.');
$library = get_option('ko_rive_library_v3');
ko_assert($library['secondary-hero']['role'] === 'hero' && $library['secondary-hero']['load_policy'] === 'lazy', 'The Hero role and Lazy Load selection should persist.');
ko_assert($library['alternate-loader']['role'] === 'loader' && $library['alternate-loader']['loader_priority'] === 20, 'The Page Loader role and priority should persist.');
ko_assert($library['alternate-loader']['loader_grace'] === 350 && $library['alternate-loader']['loader_minimum_visible'] === 250, 'The adaptive Page Loader grace and minimum visible timing should persist.');
ko_assert($library['alternate-loader']['loader_hold'] === 180 && $library['alternate-loader']['loader_fade'] === 250, 'The Page Loader exit pause and fade duration should persist.');
ko_assert(get_option('ko_rbm_backgrounds') === $legacy_backgrounds && get_option('ko_rh_hero') === $legacy_hero && get_option('ko_rpl_settings') === $legacy_loader, 'Adding unified items must not mutate any legacy option.');
$invalid_color = $raw_hero;
$invalid_color['fallback_color'] = 'beige';
$result = $save->invoke(null, $library, '', 'invalid-color-hero', 'Invalid Color Hero', 'https://cdn.example.test/invalid.riv', $invalid_color);
ko_assert(is_wp_error($result), 'An invalid fallback color must be rejected instead of silently replaced.');
$short_color = $raw_hero;
$short_color['fallback_color'] = '#abc';
$result = $save->invoke(null, $library, '', 'short-color-hero', 'Short Color Hero', 'https://cdn.example.test/short.riv', $short_color);
ko_assert($result === true && get_option('ko_rive_library_v3')['short-color-hero']['fallback_color'] === '#abc', 'A valid 3-digit fallback hex color should be accepted.');

$config_method = new ReflectionMethod(KO_Rive_Backgrounds_Manager::class, 'frontend_config');
if (PHP_VERSION_ID < 80100) { $config_method->setAccessible(true); }
$frontend_config = $config_method->invoke($manager);
ko_assert(isset($frontend_config['classes']['rive-bg-yellow']), 'The assigned class should be registered for class placement.');
ko_assert(!isset($frontend_config['classes']['yellow']), 'Legacy shortcode aliases must not be registered as CSS classes.');
ko_assert(substr_count($frontend_config['runtimeUrl'], 'rive.js') === 1, 'The frontend config should expose one shared runtime.');
ko_assert($frontend_config['items']['rive-page-loader']['loader']['enabled'] === true, 'The browser config should identify an enabled automatic Page Loader.');
ko_assert(in_array('https://example.test/', $frontend_config['items']['rive-page-loader']['loader']['targetUrls'], true), 'A selected front page should be exposed to the navigation-start loader matcher.');
ko_assert($frontend_config['items']['alternate-loader']['loader']['graceDuration'] === 350 && $frontend_config['items']['alternate-loader']['loader']['minimumVisibleDuration'] === 250, 'The browser config should receive adaptive viewport timing controls.');
ko_assert($frontend_config['items']['alternate-loader']['loader']['exitHoldDuration'] === 180 && $frontend_config['items']['alternate-loader']['loader']['fadeDuration'] === 250, 'The browser config should receive the Page Loader exit timing controls.');
ko_assert($frontend_config['navigationHandoffKey'] === 'koRiveLoaderNavigationHandoff', 'The source and destination scripts must share one scoped navigation handoff key.');

$frontend_script = file_get_contents(dirname(__DIR__) . '/assets/js/backgrounds.js');
ko_assert(strpos($frontend_script, 'createAdaptiveLoaderGate') !== false, 'The Page Loader must use the adaptive grace and maximum-wait controller.');
ko_assert(strpos($frontend_script, "document.querySelectorAll('img,video,iframe,.ko-rive-item[data-ko-rive-id]:not(.ko-rive-loader)')") !== false, 'The Page Loader must evaluate media and managed Rive items in the initial visible viewport.');
ko_assert(strpos($frontend_script, 'elementIsInInitialViewport(element)') !== false, 'Offscreen media must not delay the adaptive Page Loader exit.');
ko_assert(strpos($frontend_script, "['loadeddata', 'error']") !== false && strpos($frontend_script, "['load', 'error']") !== false, 'Loaded and failed visible resources must both settle viewport readiness.');
ko_assert(strpos($frontend_script, "['ko-rive:ready', 'ko-rive:error']") !== false, 'Visible managed Rive items must settle on either readiness or failure.');
ko_assert(strpos($frontend_script, "window.addEventListener('load', loadHandler") === false, 'Adaptive readiness must not wait for every resource through window.load.');
ko_assert(strpos($frontend_script, 'completionEvent') === false, 'Decorative Rive completion must not gate the adaptive Page Loader exit.');
ko_assert(strpos($frontend_script, "window.addEventListener('click', handleNavigationClick, true)") !== false, 'Eligible internal clicks must arm the loader before the destination document arrives.');
ko_assert(strpos($frontend_script, 'navigationLeadMaximum = 120') !== false && strpos($frontend_script, 'createNavigationHandoff') !== false, 'The outgoing loader must use a short bounded lead and pass its timing state forward.');
ko_assert(strpos($frontend_script, 'deferredMenuNavigation') !== false && strpos($frontend_script, '[data-canvas="Fullwidth Nav"]') !== false, 'The loader must support the fullscreen menu controller that defers location.assign until its close sequence finishes.');
ko_assert(strpos($frontend_script, "anchor.hasAttribute('download')") !== false && strpos($frontend_script, "target !== '_self'") !== false, 'Downloads and new-tab targets must not be intercepted by the navigation loader.');
ko_assert(strpos($frontend_script, 'event.metaKey') !== false && strpos($frontend_script, 'event.ctrlKey') !== false, 'Modified clicks must remain native and loader-free.');
ko_assert(strpos($frontend_script, "toDataURL('image/webp', 0.72)") !== false && strpos($frontend_script, 'canvasHasUsefulPaint') !== false, 'The outgoing bridge must capture only a useful painted frame in a bounded WebP form.');
ko_assert(strpos($frontend_script, 'waitForUsefulNavigationFrame') !== false, 'The outgoing bridge must sample painted frames until the real loader produces a useful branded frame.');
ko_assert(strpos($frontend_script, 'if (navigationBridge) { captureNavigationFrame(navigationBridge); }') !== false, 'The outgoing page must refresh the useful frame immediately before document replacement.');
ko_assert(strpos($frontend_script, 'delete fallback.frameDataUrl') !== false, 'Session-storage quota failure must retry the handoff without the optional frame rather than trapping navigation.');
ko_assert(strpos($frontend_script, "handoffFrame.className = 'ko-rive-handoff-frame'") !== false && strpos($frontend_script, 'releaseBootstrapWhenCovered') !== false, 'The destination must keep the captured frame painted until its replacement layer is decoded and covered.');
ko_assert(strpos($frontend_script, 'waitForUsefulDestinationPaint') !== false && strpos($frontend_script, 'if (canvasHasUsefulPaint(canvas))') !== false, 'The carried frame must remain visible until the restarted destination Rive has useful branded paint, not merely an initialized white canvas.');
$frontend_css = file_get_contents(dirname(__DIR__) . '/assets/css/backgrounds.css');
ko_assert(strpos($frontend_css, '.ko-rive-loader.is-active') !== false && strpos($frontend_css, 'visibility: hidden') !== false, 'The loader element must remain hidden until the adaptive grace expires.');
ko_assert(strpos($frontend_css, '.ko-rive-handoff-frame') !== false && strpos($frontend_css, '.ko-rive-loader.is-handoff-ready > .ko-rive-handoff-frame') !== false, 'The carried frame must crossfade only after the destination Rive canvas is ready.');
ko_assert(strpos($frontend_css, ".ko-rive-loader.is-exiting {\n\topacity: 0;\n\tvisibility: visible;") !== false, 'The loader must stay visible while its configured opacity fade is running.');

ob_start();
$manager->render_preload_links();
$preloads = ob_get_clean();
ko_assert(strpos($preloads, 'alternate-loader.riv') !== false, 'Preload items should receive a Rive file hint.');
ko_assert(strpos($preloads, 'secondary.riv') === false, 'Lazy Load items should not receive a Rive file hint.');

unset($GLOBALS['ko_test_options']['ko_rive_library_v3'], $GLOBALS['ko_test_options']['ko_rive_manager_migration_v3']);
$GLOBALS['ko_test_options']['ko_rh_animation_code'] = json_encode(array(
    'src' => 'https://public.rive.app/hosted/example/code-only.riv',
    'artboard' => 'Legacy Code Artboard',
    'stateMachine' => 'Legacy Code Machine',
));
$GLOBALS['ko_test_options']['ko_rbm_backgrounds'] = false;
$GLOBALS['ko_test_options']['ko_rh_hero'] = false;
$GLOBALS['ko_test_options']['ko_rpl_settings'] = false;
KO_Rive_Backgrounds_Manager::maybe_migrate();
$code_library = $GLOBALS['ko_test_options']['ko_rive_library_v3'];
ko_assert(isset($code_library['rive-hero']) && $code_library['rive-hero']['artboard'] === 'Legacy Code Artboard' && $code_library['rive-hero']['state_machine'] === 'Legacy Code Machine', 'A code-only legacy Hero should migrate with its artboard and state machine.');
ko_assert($code_library['rive-hero']['source_mode'] === 'external' && $code_library['rive-hero']['external_url'] === 'https://public.rive.app/hosted/example/code-only.riv', 'A code-only legacy Hero should remain usable through its direct URL.');

unset($GLOBALS['ko_test_options']['ko_rive_library_v3'], $GLOBALS['ko_test_options']['ko_rive_manager_migration_v3']);
$GLOBALS['ko_test_options']['ko_rbm_backgrounds'] = false;
$GLOBALS['ko_test_options']['ko_rh_hero'] = false;
$GLOBALS['ko_test_options']['ko_rh_animation_code'] = '';
$GLOBALS['ko_test_options']['ko_rpl_settings'] = false;
KO_Rive_Backgrounds_Manager::maybe_migrate();
ko_assert($GLOBALS['ko_test_options']['ko_rive_library_v3'] === array(), 'A clean public install must begin with an empty Library.');

unlink($GLOBALS['ko_test_upload_dir'] . '/ko-rive-backgrounds-manager/yellow.riv');
unlink($GLOBALS['ko_test_upload_dir'] . '/ko-rive-hero/hero-deadbeef0000.riv');
rmdir($GLOBALS['ko_test_upload_dir'] . '/ko-rive-backgrounds-manager');
rmdir($GLOBALS['ko_test_upload_dir'] . '/ko-rive-hero');
rmdir($GLOBALS['ko_test_upload_dir']);

echo "PASS: migration, rollback safety, Editor access, unique shortcode IDs, and clean-install behavior\n";
