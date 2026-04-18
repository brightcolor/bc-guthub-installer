<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BCGI_Update_Manager {
    /** @var BCGI_Registry_Manager */
    private $registry;

    /** @var BCGI_GitHub_Client */
    private $github;

    public function __construct( BCGI_Registry_Manager $registry, BCGI_GitHub_Client $github ) {
        $this->registry = $registry;
        $this->github   = $github;
    }

    public function register_hooks(): void {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_updates' ) );
        add_filter( 'plugins_api', array( $this, 'plugins_api' ), 20, 3 );
        add_action( 'upgrader_process_complete', array( $this, 'on_upgrader_complete' ), 10, 2 );
    }

    public function inject_updates( $transient ) {
        if ( ! is_object( $transient ) ) {
            $transient = new stdClass();
        }

        if ( ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
            return $transient;
        }

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = array();
        }

        if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
            $transient->no_update = array();
        }

        $registry = $this->registry->get_registry_plugins();

        foreach ( $registry as $plugin ) {
            if ( ! is_array( $plugin ) ) {
                continue;
            }

            $plugin_file = $this->registry->get_plugin_file( $plugin );
            if ( '' === $plugin_file ) {
                continue;
            }

            if ( ! isset( $transient->checked[ $plugin_file ] ) ) {
                continue;
            }

            $installed_version = (string) $transient->checked[ $plugin_file ];
            $latest_version    = isset( $plugin['latest_version'] ) ? (string) $plugin['latest_version'] : '';

            if ( '' === $latest_version || empty( $plugin['download_url'] ) ) {
                continue;
            }

            $payload = (object) array(
                'slug'        => $plugin['slug'],
                'plugin'      => $plugin_file,
                'new_version' => $latest_version,
                'package'     => $plugin['download_url'],
                'url'         => ! empty( $plugin['homepage'] ) ? $plugin['homepage'] : sprintf( 'https://github.com/%s/%s', $plugin['owner'], $plugin['repo'] ),
                'tested'      => '',
                'requires'    => '',
                'icons'       => array(),
                'banners'     => array(),
                'banners_rtl' => array(),
            );

            if ( version_compare( $latest_version, $installed_version, '>' ) ) {
                $transient->response[ $plugin_file ] = $payload;
                unset( $transient->no_update[ $plugin_file ] );
            } else {
                $transient->no_update[ $plugin_file ] = $payload;
                unset( $transient->response[ $plugin_file ] );
            }
        }

        return $transient;
    }

    public function plugins_api( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
            return $result;
        }

        $slug   = sanitize_title( (string) $args->slug );
        $plugin = $this->registry->get_plugin( $slug );

        if ( ! $plugin ) {
            return $result;
        }

        $url = ! empty( $plugin['homepage'] )
            ? $plugin['homepage']
            : sprintf( 'https://github.com/%s/%s', $plugin['owner'], $plugin['repo'] );

        return (object) array(
            'name'          => $plugin['name'],
            'slug'          => $plugin['slug'],
            'version'       => $plugin['latest_version'],
            'author'        => '<a href="' . esc_url( $url ) . '">GitHub</a>',
            'homepage'      => esc_url( $url ),
            'requires'      => '6.1',
            'tested'        => '',
            'download_link' => esc_url( $plugin['download_url'] ),
            'sections'      => array(
                'description' => wp_kses_post( $plugin['description'] ),
                'changelog'   => esc_html__( 'Version data comes from GitHub releases or tags.', 'bc-github-installer' ),
            ),
        );
    }

    public function on_upgrader_complete( $upgrader_object, $options ): void {
        if ( empty( $options['type'] ) || 'plugin' !== $options['type'] ) {
            return;
        }

        delete_site_transient( 'update_plugins' );
    }
}