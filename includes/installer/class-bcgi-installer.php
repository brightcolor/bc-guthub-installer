<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BCGI_Installer {
    /** @var BCGI_Registry_Manager */
    private $registry;

    /** @var string */
    private $expected_slug = '';

    public function __construct( BCGI_Registry_Manager $registry ) {
        $this->registry = $registry;
    }

    public function install_plugin( array $plugin ): array {
        if ( empty( $plugin['slug'] ) || empty( $plugin['download_url'] ) ) {
            return array(
                'success' => false,
                'error'   => 'Plugin slug or download URL missing.',
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        WP_Filesystem();

        $slug             = sanitize_title( (string) $plugin['slug'] );
        $this->expected_slug = $slug;

        add_filter( 'upgrader_source_selection', array( $this, 'filter_source_selection' ), 10, 4 );

        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $result   = $upgrader->install( esc_url_raw( (string) $plugin['download_url'] ) );

        remove_filter( 'upgrader_source_selection', array( $this, 'filter_source_selection' ), 10 );
        $this->expected_slug = '';

        if ( is_wp_error( $result ) ) {
            return array(
                'success' => false,
                'error'   => $result->get_error_message(),
            );
        }

        if ( false === $result ) {
            return array(
                'success' => false,
                'error'   => 'Installation failed. Check package structure and permissions.',
            );
        }

        $check = $this->verify_main_file( $plugin );

        if ( ! $check['success'] ) {
            return $check;
        }

        return array(
            'success' => true,
            'error'   => '',
        );
    }

    public function update_plugin( array $plugin ): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugin_file = $this->registry->get_plugin_file( $plugin );
        if ( '' === $plugin_file ) {
            return array(
                'success' => false,
                'error'   => 'Invalid plugin file mapping.',
            );
        }

        if ( ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
            return array(
                'success' => false,
                'error'   => 'Plugin is not installed.',
            );
        }

        $this->prime_update_transient( $plugin, $plugin_file );

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        WP_Filesystem();

        $this->expected_slug = sanitize_title( (string) $plugin['slug'] );
        add_filter( 'upgrader_source_selection', array( $this, 'filter_source_selection' ), 10, 4 );

        $skin     = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $result   = $upgrader->upgrade( $plugin_file );

        remove_filter( 'upgrader_source_selection', array( $this, 'filter_source_selection' ), 10 );
        $this->expected_slug = '';

        if ( is_wp_error( $result ) ) {
            return array(
                'success' => false,
                'error'   => $result->get_error_message(),
            );
        }

        if ( false === $result ) {
            return array(
                'success' => false,
                'error'   => 'Update failed or no update available.',
            );
        }

        return $this->verify_main_file( $plugin );
    }

    public function filter_source_selection( $source, $remote_source, $upgrader, $hook_extra ) {
        if ( '' === $this->expected_slug ) {
            return $source;
        }

        global $wp_filesystem;

        $desired = trailingslashit( $remote_source ) . $this->expected_slug;

        if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
            return $source;
        }

        if ( ! $wp_filesystem ) {
            return $source;
        }

        if ( $wp_filesystem->exists( $desired ) ) {
            $wp_filesystem->delete( $desired, true );
        }

        if ( ! $wp_filesystem->move( $source, $desired, true ) ) {
            return new WP_Error( 'bcgi_move_failed', __( 'Could not normalize plugin folder slug during install/update.', 'bc-github-installer' ) );
        }

        return $desired;
    }

    private function verify_main_file( array $plugin ): array {
        $slug      = isset( $plugin['slug'] ) ? sanitize_title( (string) $plugin['slug'] ) : '';
        $main_file = isset( $plugin['main_file'] ) ? ltrim( (string) $plugin['main_file'], '/' ) : '';

        if ( '' === $slug || '' === $main_file ) {
            return array(
                'success' => false,
                'error'   => 'Missing slug or main file definition.',
            );
        }

        $path = WP_PLUGIN_DIR . '/' . $slug . '/' . $main_file;

        if ( ! file_exists( $path ) ) {
            return array(
                'success' => false,
                'error'   => sprintf( 'Main file missing after installation: %s', $main_file ),
            );
        }

        return array(
            'success' => true,
            'error'   => '',
        );
    }

    private function prime_update_transient( array $plugin, string $plugin_file ): void {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $all = get_plugins();

        if ( empty( $all[ $plugin_file ]['Version'] ) ) {
            return;
        }

        $transient = get_site_transient( 'update_plugins' );
        if ( ! is_object( $transient ) ) {
            $transient = new stdClass();
        }

        if ( ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
            $transient->checked = array();
        }

        $transient->checked[ $plugin_file ] = (string) $all[ $plugin_file ]['Version'];

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = array();
        }

        $transient->response[ $plugin_file ] = (object) array(
            'slug'        => $plugin['slug'],
            'plugin'      => $plugin_file,
            'new_version' => $plugin['latest_version'],
            'package'     => $plugin['download_url'],
            'url'         => ! empty( $plugin['homepage'] ) ? $plugin['homepage'] : sprintf( 'https://github.com/%s/%s', $plugin['owner'], $plugin['repo'] ),
            'tested'      => '',
            'requires'    => '',
            'icons'       => array(),
            'banners'     => array(),
            'banners_rtl' => array(),
        );

        set_site_transient( 'update_plugins', $transient );
    }
}