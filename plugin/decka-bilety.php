<?php
/**
 * Plugin Name: Decka Bilety
 * Description: Numerowane miejsca, Stripe, bilety PDF i kontrola wejścia Decki Pelplin.
 * Version: 0.3.4
 * Update URI: https://github.com/kaulpl/decka-ticket
 * Requires at least: 6.6
 * Requires PHP: 8.2
 * License: GPL-3.0-or-later
 */
defined('ABSPATH') || exit;
define('DECKA_DIR', plugin_dir_path(__FILE__));
define('DECKA_URL', plugin_dir_url(__FILE__));
define('DECKA_VERSION', '0.3.4');
define('DECKA_FILE', __FILE__);
foreach (['domain','db','stripe','service','tickets','league','api','identity','wallet','updater','admin-api','admin'] as $part) require_once DECKA_DIR."includes/$part.php";
Decka_Updater::init();
register_activation_hook(__FILE__, ['Decka_DB','install']);
register_deactivation_hook(__FILE__, function(){ wp_clear_scheduled_hook('decka_maintenance'); });
add_action('rest_api_init', ['Decka_API','register']);
add_action('admin_menu', ['Decka_Admin','menu']);
add_action('rest_api_init', ['Decka_Admin_API','register']);
add_action('admin_post_decka_stats', ['Decka_Admin','export']);
add_action('decka_maintenance', ['Decka_Service','maintenance']);
add_filter('cron_schedules',function($s){$s['decka_minute']=['interval'=>60,'display'=>'Decka co minutę'];return $s;});
add_shortcode('decka_bilety',function(){ return Decka_API::embed('shop'); });
add_shortcode('decka_bileter',function(){ return Decka_API::embed('gate'); });
add_action('admin_post_decka_app',['Decka_API','app']);
add_action('admin_post_nopriv_decka_app',['Decka_API','app']);
add_action('admin_post_decka_pdf',['Decka_Tickets','download']);

add_action('init',[Decka_DB::class,'maybe_upgrade']);
add_action('template_redirect',function(){if(untrailingslashit(wp_parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH))===untrailingslashit(wp_parse_url(home_url('/bileter'),PHP_URL_PATH))){$_GET['view']='gate';Decka_API::app();}},0);
Decka_Identity::init();
add_action('admin_post_decka_wallet',['Decka_Wallet','download']);
