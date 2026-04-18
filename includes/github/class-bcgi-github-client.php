<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BCGI_GitHub_Client {
    private const API_BASE = 'https://api.github.com';

    public function get_json( string $url, int $ttl = 300 ): array {
        $cache_key = 'bcgi_req_' . md5( $url );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 20,
                'headers' => $this->build_headers(),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'error'   => $response->get_error_message(),
                'data'    => null,
                'code'    => 0,
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = (string) wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            return array(
                'success' => false,
                'error'   => 'GitHub API returned HTTP ' . $code,
                'data'    => null,
                'code'    => $code,
            );
        }

        $decoded = json_decode( $body, true );

        if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
            return array(
                'success' => false,
                'error'   => 'Invalid JSON response from GitHub.',
                'data'    => null,
                'code'    => $code,
            );
        }

        $result = array(
            'success' => true,
            'error'   => '',
            'data'    => $decoded,
            'code'    => $code,
        );

        set_transient( $cache_key, $result, $ttl );

        return $result;
    }

    public function get_latest_release_info( string $owner, string $repo ): array {
        $url    = sprintf( '%s/repos/%s/%s/releases/latest', self::API_BASE, rawurlencode( $owner ), rawurlencode( $repo ) );
        $result = $this->get_json( $url, 600 );

        if ( ! $result['success'] ) {
            return $result;
        }

        $release = is_array( $result['data'] ) ? $result['data'] : array();
        $assets  = array();

        if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
            foreach ( $release['assets'] as $asset ) {
                if ( empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
                    continue;
                }

                $assets[] = array(
                    'name' => (string) $asset['name'],
                    'url'  => (string) $asset['browser_download_url'],
                );
            }
        }

        return array(
            'success' => true,
            'error'   => '',
            'data'    => array(
                'version'      => isset( $release['tag_name'] ) ? ltrim( (string) $release['tag_name'], 'v' ) : '',
                'tag'          => isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '',
                'zipball_url'  => isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '',
                'html_url'     => isset( $release['html_url'] ) ? (string) $release['html_url'] : '',
                'published_at' => isset( $release['published_at'] ) ? (string) $release['published_at'] : '',
                'assets'       => $assets,
            ),
            'code'    => 200,
        );
    }

    public function get_latest_tag_info( string $owner, string $repo ): array {
        $url    = sprintf( '%s/repos/%s/%s/tags?per_page=1', self::API_BASE, rawurlencode( $owner ), rawurlencode( $repo ) );
        $result = $this->get_json( $url, 600 );

        if ( ! $result['success'] ) {
            return $result;
        }

        $tags = is_array( $result['data'] ) ? $result['data'] : array();
        $tag  = isset( $tags[0] ) && is_array( $tags[0] ) ? $tags[0] : array();

        if ( empty( $tag['name'] ) ) {
            return array(
                'success' => false,
                'error'   => 'No tags found for repository.',
                'data'    => null,
                'code'    => 404,
            );
        }

        return array(
            'success' => true,
            'error'   => '',
            'data'    => array(
                'version'     => ltrim( (string) $tag['name'], 'v' ),
                'tag'         => (string) $tag['name'],
                'zipball_url' => isset( $tag['zipball_url'] ) ? (string) $tag['zipball_url'] : '',
            ),
            'code'    => 200,
        );
    }

    public function build_repo_zip_url( string $owner, string $repo, string $branch = 'main' ): string {
        $branch = trim( $branch );
        if ( '' === $branch ) {
            $branch = 'main';
        }

        return sprintf(
            'https://github.com/%s/%s/archive/refs/heads/%s.zip',
            rawurlencode( $owner ),
            rawurlencode( $repo ),
            rawurlencode( $branch )
        );
    }

    public function build_tag_zip_url( string $owner, string $repo, string $tag ): string {
        return sprintf(
            'https://github.com/%s/%s/archive/refs/tags/%s.zip',
            rawurlencode( $owner ),
            rawurlencode( $repo ),
            rawurlencode( $tag )
        );
    }

    public function resolve_release_asset_url( array $release_info, string $asset_name ): string {
        if ( empty( $asset_name ) || empty( $release_info['assets'] ) || ! is_array( $release_info['assets'] ) ) {
            return '';
        }

        foreach ( $release_info['assets'] as $asset ) {
            if ( ! is_array( $asset ) || empty( $asset['name'] ) || empty( $asset['url'] ) ) {
                continue;
            }

            if ( $asset['name'] === $asset_name ) {
                return (string) $asset['url'];
            }
        }

        return '';
    }

    public function discover_repositories( string $org_or_user, bool $is_org = true ): array {
        $entity = sanitize_text_field( $org_or_user );

        if ( '' === $entity ) {
            return array();
        }

        if ( $is_org ) {
            $url = sprintf( '%s/orgs/%s/repos?per_page=100', self::API_BASE, rawurlencode( $entity ) );
        } else {
            $url = sprintf( '%s/users/%s/repos?per_page=100', self::API_BASE, rawurlencode( $entity ) );
        }

        $result = $this->get_json( $url, 600 );

        if ( ! $result['success'] || ! is_array( $result['data'] ) ) {
            return array();
        }

        $repos = array();

        foreach ( $result['data'] as $repo ) {
            if ( ! is_array( $repo ) || empty( $repo['name'] ) || empty( $repo['owner']['login'] ) ) {
                continue;
            }

            $repos[] = array(
                'owner'       => (string) $repo['owner']['login'],
                'repo'        => (string) $repo['name'],
                'description' => isset( $repo['description'] ) ? (string) $repo['description'] : '',
                'homepage'    => isset( $repo['html_url'] ) ? (string) $repo['html_url'] : '',
            );
        }

        return $repos;
    }

    private function build_headers(): array {
        $headers = array(
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'BC-GitHub-Installer/' . BCGI_VERSION,
        );

        $settings = get_option( BCGI_OPTION_SETTINGS, array() );
        $token    = isset( $settings['github_token'] ) ? trim( (string) $settings['github_token'] ) : '';

        if ( '' !== $token ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }
}