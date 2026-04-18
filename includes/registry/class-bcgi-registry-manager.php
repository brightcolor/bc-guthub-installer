<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BCGI_Registry_Manager {
    /** @var BCGI_GitHub_Client */
    private $github;

    public function __construct( BCGI_GitHub_Client $github ) {
        $this->github = $github;
    }

    public function get_settings(): array {
        $settings = get_option( BCGI_OPTION_SETTINGS, array() );

        return wp_parse_args(
            is_array( $settings ) ? $settings : array(),
            array(
                'manifest_url'         => '',
                'github_token'         => '',
                'enable_org_discovery' => 0,
                'org_or_user'          => '',
            )
        );
    }

    public function save_settings( array $input ): void {
        $current                       = $this->get_settings();
        $current['manifest_url']       = isset( $input['manifest_url'] ) ? esc_url_raw( trim( (string) $input['manifest_url'] ) ) : '';
        $current['github_token']       = isset( $input['github_token'] ) ? sanitize_text_field( (string) $input['github_token'] ) : '';
        $current['enable_org_discovery'] = ! empty( $input['enable_org_discovery'] ) ? 1 : 0;
        $current['org_or_user']        = isset( $input['org_or_user'] ) ? sanitize_text_field( (string) $input['org_or_user'] ) : '';

        update_option( BCGI_OPTION_SETTINGS, $current );
    }

    public function clear_cache(): void {
        delete_transient( BCGI_TRANSIENT_MANIFEST );
    }

    public function get_registry_plugins(): array {
        $plugins = get_option( BCGI_OPTION_REGISTRY, array() );
        return is_array( $plugins ) ? $plugins : array();
    }

    public function get_plugin( string $slug ): ?array {
        $all = $this->get_registry_plugins();
        return isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? $all[ $slug ] : null;
    }

    public function get_status_for_plugin( array $plugin ): array {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugin_file = $this->get_plugin_file( $plugin );
        $installed   = get_plugins();

        if ( ! isset( $installed[ $plugin_file ] ) ) {
            return array(
                'state'            => 'not_installed',
                'installed_version'=> '',
                'latest_version'   => isset( $plugin['latest_version'] ) ? (string) $plugin['latest_version'] : '',
                'plugin_file'      => $plugin_file,
            );
        }

        $installed_version = isset( $installed[ $plugin_file ]['Version'] ) ? (string) $installed[ $plugin_file ]['Version'] : '';
        $latest_version    = isset( $plugin['latest_version'] ) ? (string) $plugin['latest_version'] : '';

        if ( '' !== $latest_version && version_compare( $latest_version, $installed_version, '>' ) ) {
            return array(
                'state'             => 'update_available',
                'installed_version' => $installed_version,
                'latest_version'    => $latest_version,
                'plugin_file'       => $plugin_file,
            );
        }

        return array(
            'state'             => 'up_to_date',
            'installed_version' => $installed_version,
            'latest_version'    => $latest_version,
            'plugin_file'       => $plugin_file,
        );
    }

    public function get_plugin_file( array $plugin ): string {
        $slug      = isset( $plugin['slug'] ) ? sanitize_title( (string) $plugin['slug'] ) : '';
        $main_file = isset( $plugin['main_file'] ) ? trim( (string) $plugin['main_file'] ) : '';

        if ( '' === $slug || '' === $main_file ) {
            return '';
        }

        return $slug . '/' . ltrim( $main_file, '/' );
    }

    public function sync_registry( bool $force = false ): array {
        $settings = $this->get_settings();
        $manifest = $this->fetch_manifest( $settings['manifest_url'], $force );

        if ( ! $manifest['success'] ) {
            return $manifest;
        }

        $entries = isset( $manifest['data']['plugins'] ) && is_array( $manifest['data']['plugins'] )
            ? $manifest['data']['plugins']
            : array();

        $processed = array();

        foreach ( $entries as $raw ) {
            $plugin = $this->normalize_manifest_entry( $raw );

            if ( empty( $plugin['slug'] ) || empty( $plugin['owner'] ) || empty( $plugin['repo'] ) || empty( $plugin['main_file'] ) ) {
                continue;
            }

            $plugin = $this->enrich_plugin_versions( $plugin, $force );
            $processed[ $plugin['slug'] ] = $plugin;
        }

        if ( ! empty( $settings['enable_org_discovery'] ) && ! empty( $settings['org_or_user'] ) ) {
            $discovered = $this->github->discover_repositories( $settings['org_or_user'], true );

            foreach ( $discovered as $repo ) {
                $slug = sanitize_title( $repo['repo'] );

                if ( isset( $processed[ $slug ] ) ) {
                    continue;
                }

                $candidate = array(
                    'slug'            => $slug,
                    'name'            => ucwords( str_replace( '-', ' ', $slug ) ),
                    'description'     => isset( $repo['description'] ) ? (string) $repo['description'] : '',
                    'owner'           => (string) $repo['owner'],
                    'repo'            => (string) $repo['repo'],
                    'homepage'        => isset( $repo['homepage'] ) ? (string) $repo['homepage'] : '',
                    'main_file'       => $slug . '.php',
                    'version_strategy'=> 'release',
                    'zip_source'      => 'release',
                    'release_asset'   => '',
                    'branch'          => 'main',
                );

                $candidate = $this->enrich_plugin_versions( $candidate, $force );

                if ( ! empty( $candidate['latest_version'] ) ) {
                    $processed[ $slug ] = $candidate;
                }
            }
        }

        update_option( BCGI_OPTION_REGISTRY, $processed );

        return array(
            'success' => true,
            'error'   => '',
            'data'    => $processed,
            'count'   => count( $processed ),
        );
    }

    public function refresh_plugin( string $slug ): array {
        $all = $this->get_registry_plugins();

        if ( empty( $all[ $slug ] ) || ! is_array( $all[ $slug ] ) ) {
            return array(
                'success' => false,
                'error'   => 'Plugin not found in registry.',
                'data'    => null,
            );
        }

        $all[ $slug ] = $this->enrich_plugin_versions( $all[ $slug ], true );
        update_option( BCGI_OPTION_REGISTRY, $all );

        return array(
            'success' => true,
            'error'   => '',
            'data'    => $all[ $slug ],
        );
    }

    private function fetch_manifest( string $manifest_url, bool $force = false ): array {
        if ( '' === $manifest_url ) {
            return array(
                'success' => false,
                'error'   => 'Manifest URL is empty. Please configure it in settings.',
                'data'    => null,
            );
        }

        if ( ! $force ) {
            $cached = get_transient( BCGI_TRANSIENT_MANIFEST );
            if ( false !== $cached && is_array( $cached ) ) {
                return array(
                    'success' => true,
                    'error'   => '',
                    'data'    => $cached,
                );
            }
        }

        $response = wp_remote_get(
            $manifest_url,
            array(
                'timeout' => 20,
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'error'   => $response->get_error_message(),
                'data'    => null,
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return array(
                'success' => false,
                'error'   => 'Manifest URL returned HTTP ' . $code,
                'data'    => null,
            );
        }

        $decoded = json_decode( $body, true );

        if ( null === $decoded || JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            return array(
                'success' => false,
                'error'   => 'Manifest is not valid JSON.',
                'data'    => null,
            );
        }

        if ( ! isset( $decoded['plugins'] ) || ! is_array( $decoded['plugins'] ) ) {
            return array(
                'success' => false,
                'error'   => 'Manifest must contain a top-level "plugins" array.',
                'data'    => null,
            );
        }

        set_transient( BCGI_TRANSIENT_MANIFEST, $decoded, 15 * MINUTE_IN_SECONDS );

        return array(
            'success' => true,
            'error'   => '',
            'data'    => $decoded,
        );
    }

    private function normalize_manifest_entry( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return array();
        }

        $slug = isset( $raw['slug'] ) && '' !== trim( (string) $raw['slug'] )
            ? sanitize_title( (string) $raw['slug'] )
            : ( isset( $raw['repo'] ) ? sanitize_title( (string) $raw['repo'] ) : '' );

        $main_file = isset( $raw['main_file'] ) ? sanitize_text_field( (string) $raw['main_file'] ) : $slug . '.php';

        return array(
            'slug'             => $slug,
            'name'             => isset( $raw['name'] ) ? sanitize_text_field( (string) $raw['name'] ) : ucwords( str_replace( '-', ' ', $slug ) ),
            'description'      => isset( $raw['description'] ) ? wp_kses_post( (string) $raw['description'] ) : '',
            'owner'            => isset( $raw['owner'] ) ? sanitize_text_field( (string) $raw['owner'] ) : '',
            'repo'             => isset( $raw['repo'] ) ? sanitize_text_field( (string) $raw['repo'] ) : '',
            'homepage'         => isset( $raw['homepage'] ) ? esc_url_raw( (string) $raw['homepage'] ) : '',
            'main_file'        => ltrim( $main_file, '/' ),
            'version_strategy' => isset( $raw['version_strategy'] ) ? sanitize_key( (string) $raw['version_strategy'] ) : 'release',
            'zip_source'       => isset( $raw['zip_source'] ) ? sanitize_key( (string) $raw['zip_source'] ) : 'release',
            'release_asset'    => isset( $raw['release_asset'] ) ? sanitize_text_field( (string) $raw['release_asset'] ) : '',
            'branch'           => isset( $raw['branch'] ) ? sanitize_text_field( (string) $raw['branch'] ) : 'main',
            'latest_version'   => isset( $raw['latest_version'] ) ? sanitize_text_field( (string) $raw['latest_version'] ) : '',
            'download_url'     => isset( $raw['download_url'] ) ? esc_url_raw( (string) $raw['download_url'] ) : '',
            'source_ref'       => isset( $raw['source_ref'] ) ? sanitize_text_field( (string) $raw['source_ref'] ) : '',
            'updated_at'       => current_time( 'mysql' ),
        );
    }

    private function enrich_plugin_versions( array $plugin, bool $force = false ): array {
        $owner = $plugin['owner'];
        $repo  = $plugin['repo'];

        if ( '' === $owner || '' === $repo ) {
            return $plugin;
        }

        $strategy = isset( $plugin['version_strategy'] ) ? $plugin['version_strategy'] : 'release';

        if ( 'tags' === $strategy || 'tag' === $strategy ) {
            $tag = $this->github->get_latest_tag_info( $owner, $repo );

            if ( $tag['success'] && is_array( $tag['data'] ) ) {
                $plugin['latest_version'] = (string) $tag['data']['version'];
                $plugin['source_ref']     = (string) $tag['data']['tag'];

                if ( 'repo' === $plugin['zip_source'] ) {
                    $plugin['download_url'] = $this->github->build_repo_zip_url( $owner, $repo, $plugin['branch'] );
                } else {
                    $plugin['download_url'] = $this->github->build_tag_zip_url( $owner, $repo, (string) $tag['data']['tag'] );
                }
            }

            return $plugin;
        }

        $release = $this->github->get_latest_release_info( $owner, $repo );

        if ( ! $release['success'] || ! is_array( $release['data'] ) ) {
            if ( empty( $plugin['download_url'] ) ) {
                $plugin['download_url'] = $this->github->build_repo_zip_url( $owner, $repo, $plugin['branch'] );
            }

            return $plugin;
        }

        $release_data              = $release['data'];
        $plugin['latest_version']  = ! empty( $release_data['version'] ) ? (string) $release_data['version'] : $plugin['latest_version'];
        $plugin['source_ref']      = ! empty( $release_data['tag'] ) ? (string) $release_data['tag'] : $plugin['source_ref'];

        if ( 'repo' === $plugin['zip_source'] ) {
            $plugin['download_url'] = $this->github->build_repo_zip_url( $owner, $repo, $plugin['branch'] );
            return $plugin;
        }

        if ( ! empty( $plugin['release_asset'] ) ) {
            $asset_url = $this->github->resolve_release_asset_url( $release_data, $plugin['release_asset'] );
            if ( '' !== $asset_url ) {
                $plugin['download_url'] = $asset_url;
                return $plugin;
            }
        }

        if ( ! empty( $release_data['zipball_url'] ) ) {
            $plugin['download_url'] = (string) $release_data['zipball_url'];
            return $plugin;
        }

        if ( ! empty( $plugin['source_ref'] ) ) {
            $plugin['download_url'] = $this->github->build_tag_zip_url( $owner, $repo, $plugin['source_ref'] );
        }

        return $plugin;
    }
}