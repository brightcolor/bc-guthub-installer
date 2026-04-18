<?php
/**
 * Plugin Name: BC GitHub Plugin Installer
 * Plugin URI: https://github.com/brightcolor
 * Description: Installiert und aktualisiert eigene GitHub-Plugins über eine zentrale Registry im WordPress-Backend.
 * Version: 1.0.0
 * Author: Bright Color
 * Author URI: https://github.com/brightcolor
 * Text Domain: bc-github-installer
 * Requires at least: 6.1
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'BCGI_VERSION', '1.0.0' );
define( 'BCGI_PLUGIN_FILE', __FILE__ );
define( 'BCGI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCGI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BCGI_OPTION_SETTINGS', 'bcgi_settings' );
define( 'BCGI_OPTION_REGISTRY', 'bcgi_registry_plugins' );
define( 'BCGI_TRANSIENT_MANIFEST', 'bcgi_manifest_cache' );

require_once BCGI_PLUGIN_DIR . 'includes/class-bcgi-plugin.php';
require_once BCGI_PLUGIN_DIR . 'includes/github/class-bcgi-github-client.php';
require_once BCGI_PLUGIN_DIR . 'includes/registry/class-bcgi-registry-manager.php';
require_once BCGI_PLUGIN_DIR . 'includes/installer/class-bcgi-installer.php';
require_once BCGI_PLUGIN_DIR . 'includes/updates/class-bcgi-update-manager.php';
require_once BCGI_PLUGIN_DIR . 'includes/admin/class-bcgi-admin-page.php';

add_action(
    'plugins_loaded',
    static function () {
        BCGI_Plugin::instance()->boot();
    }
);

register_activation_hook(
    __FILE__,
    static function () {
        $defaults = array(
            'manifest_url'          => '',
            'github_token'          => '',
            'enable_org_discovery'  => 0,
            'org_or_user'           => '',
        );

        $existing = get_option( BCGI_OPTION_SETTINGS, array() );
        $merged   = wp_parse_args( $existing, $defaults );

        update_option( BCGI_OPTION_SETTINGS, $merged );

        if ( false === get_option( BCGI_OPTION_REGISTRY, false ) ) {
            add_option( BCGI_OPTION_REGISTRY, array() );
        }
    }
);