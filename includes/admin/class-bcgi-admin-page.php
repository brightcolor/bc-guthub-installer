<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BCGI_Admin_Page {
    /** @var BCGI_Registry_Manager */
    private $registry;

    /** @var BCGI_Installer */
    private $installer;

    public function __construct( BCGI_Registry_Manager $registry, BCGI_Installer $installer ) {
        $this->registry  = $registry;
        $this->installer = $installer;
    }

    public function register_hooks(): void {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_post_bcgi_action', array( $this, 'handle_action' ) );
    }

    public function register_menu(): void {
        add_management_page(
            __( 'GitHub Plugin Installer', 'bc-github-installer' ),
            __( 'GitHub Plugin Installer', 'bc-github-installer' ),
            'manage_options',
            'bcgi-installer',
            array( $this, 'render_page' )
        );
    }

    public function handle_action(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'bc-github-installer' ) );
        }

        check_admin_referer( 'bcgi_action_nonce' );

        $subaction = isset( $_POST['subaction'] ) ? sanitize_key( (string) wp_unslash( $_POST['subaction'] ) ) : '';
        $slug      = isset( $_POST['slug'] ) ? sanitize_title( (string) wp_unslash( $_POST['slug'] ) ) : '';
        $notice    = 'unknown';
        $message   = '';

        if ( 'save_settings' === $subaction ) {
            $input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : array();
            $this->registry->save_settings( $input );
            $notice  = 'success';
            $message = __( 'Settings saved.', 'bc-github-installer' );
        }

        if ( 'sync' === $subaction ) {
            $result = $this->registry->sync_registry( true );
            if ( $result['success'] ) {
                $notice  = 'success';
                $message = sprintf( __( 'Registry synchronized. %d plugins found.', 'bc-github-installer' ), (int) $result['count'] );
            } else {
                $notice  = 'error';
                $message = $result['error'];
            }
        }

        if ( 'clear_cache' === $subaction ) {
            $this->registry->clear_cache();
            $notice  = 'success';
            $message = __( 'Manifest cache cleared.', 'bc-github-installer' );
        }

        if ( 'install' === $subaction && '' !== $slug ) {
            $plugin = $this->registry->get_plugin( $slug );

            if ( ! $plugin ) {
                $notice  = 'error';
                $message = __( 'Plugin not found in registry.', 'bc-github-installer' );
            } else {
                $result = $this->installer->install_plugin( $plugin );
                if ( $result['success'] ) {
                    $notice  = 'success';
                    $message = __( 'Plugin installed successfully.', 'bc-github-installer' );
                } else {
                    $notice  = 'error';
                    $message = $result['error'];
                }
            }
        }

        if ( 'check_update' === $subaction && '' !== $slug ) {
            $result = $this->registry->refresh_plugin( $slug );
            if ( $result['success'] ) {
                delete_site_transient( 'update_plugins' );
                wp_update_plugins();

                $notice  = 'success';
                $message = __( 'Update information refreshed.', 'bc-github-installer' );
            } else {
                $notice  = 'error';
                $message = $result['error'];
            }
        }

        if ( 'update_now' === $subaction && '' !== $slug ) {
            $plugin = $this->registry->get_plugin( $slug );
            if ( ! $plugin ) {
                $notice  = 'error';
                $message = __( 'Plugin not found in registry.', 'bc-github-installer' );
            } else {
                $this->registry->refresh_plugin( $slug );
                $plugin = $this->registry->get_plugin( $slug );
                $result = $this->installer->update_plugin( $plugin );

                if ( $result['success'] ) {
                    $notice  = 'success';
                    $message = __( 'Plugin updated successfully.', 'bc-github-installer' );
                } else {
                    $notice  = 'error';
                    $message = $result['error'];
                }
            }
        }

        $redirect = add_query_arg(
            array(
                'page'         => 'bcgi-installer',
                'bcgi_notice'  => rawurlencode( $notice ),
                'bcgi_message' => rawurlencode( $message ),
            ),
            admin_url( 'tools.php' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = $this->registry->get_settings();
        $plugins  = $this->registry->get_registry_plugins();

        $notice  = isset( $_GET['bcgi_notice'] ) ? sanitize_key( rawurldecode( (string) wp_unslash( $_GET['bcgi_notice'] ) ) ) : '';
        $message = isset( $_GET['bcgi_message'] ) ? sanitize_text_field( rawurldecode( (string) wp_unslash( $_GET['bcgi_message'] ) ) ) : '';

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'GitHub Plugin Installer', 'bc-github-installer' ); ?></h1>

            <?php if ( '' !== $notice && '' !== $message ) : ?>
                <div class="notice notice-<?php echo esc_attr( 'error' === $notice ? 'error' : 'success' ); ?> is-dismissible">
                    <p><?php echo esc_html( $message ); ?></p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Settings', 'bc-github-installer' ); ?></h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'bcgi_action_nonce' ); ?>
                <input type="hidden" name="action" value="bcgi_action" />
                <input type="hidden" name="subaction" value="save_settings" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="bcgi_manifest_url"><?php esc_html_e( 'Manifest URL', 'bc-github-installer' ); ?></label></th>
                        <td>
                            <input type="url" class="regular-text" id="bcgi_manifest_url" name="settings[manifest_url]" value="<?php echo esc_attr( $settings['manifest_url'] ); ?>" placeholder="https://raw.githubusercontent.com/owner/repo/main/registry.json" />
                            <p class="description"><?php esc_html_e( 'JSON with a top-level "plugins" array.', 'bc-github-installer' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bcgi_token"><?php esc_html_e( 'GitHub Token (optional)', 'bc-github-installer' ); ?></label></th>
                        <td>
                            <input type="password" class="regular-text" id="bcgi_token" name="settings[github_token]" value="<?php echo esc_attr( $settings['github_token'] ); ?>" autocomplete="off" />
                            <p class="description"><?php esc_html_e( 'Needed for private repos or higher API limits.', 'bc-github-installer' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="bcgi_org_user"><?php esc_html_e( 'Org/User Discovery (optional)', 'bc-github-installer' ); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="settings[enable_org_discovery]" value="1" <?php checked( (int) $settings['enable_org_discovery'], 1 ); ?> />
                                <?php esc_html_e( 'Enable organization discovery (experimental fallback).', 'bc-github-installer' ); ?>
                            </label>
                            <br />
                            <input type="text" class="regular-text" id="bcgi_org_user" name="settings[org_or_user]" value="<?php echo esc_attr( $settings['org_or_user'] ); ?>" placeholder="my-github-org" />
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save Settings', 'bc-github-installer' ) ); ?>
            </form>

            <hr />

            <h2><?php esc_html_e( 'Registry Actions', 'bc-github-installer' ); ?></h2>
            <p>
                <?php $this->render_action_button( 'sync', __( 'Synchronize', 'bc-github-installer' ), 'primary' ); ?>
                <?php $this->render_action_button( 'clear_cache', __( 'Clear Cache', 'bc-github-installer' ), 'secondary' ); ?>
            </p>

            <h2><?php esc_html_e( 'Detected Plugins', 'bc-github-installer' ); ?></h2>
            <?php if ( empty( $plugins ) ) : ?>
                <p><?php esc_html_e( 'No plugins discovered yet. Configure your manifest URL and click Synchronize.', 'bc-github-installer' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'bc-github-installer' ); ?></th>
                        <th><?php esc_html_e( 'Slug', 'bc-github-installer' ); ?></th>
                        <th><?php esc_html_e( 'Installed', 'bc-github-installer' ); ?></th>
                        <th><?php esc_html_e( 'Latest', 'bc-github-installer' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'bc-github-installer' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'bc-github-installer' ); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $plugins as $plugin ) : ?>
                        <?php $status = $this->registry->get_status_for_plugin( $plugin ); ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $plugin['name'] ); ?></strong><br />
                                <span class="description"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $plugin['description'] ), 18 ) ); ?></span>
                            </td>
                            <td><code><?php echo esc_html( $plugin['slug'] ); ?></code></td>
                            <td><?php echo esc_html( '' !== $status['installed_version'] ? $status['installed_version'] : '-' ); ?></td>
                            <td><?php echo esc_html( ! empty( $plugin['latest_version'] ) ? $plugin['latest_version'] : '-' ); ?></td>
                            <td><?php echo esc_html( $this->format_status_label( $status['state'] ) ); ?></td>
                            <td>
                                <?php if ( 'not_installed' === $status['state'] ) : ?>
                                    <?php $this->render_action_button( 'install', __( 'Install', 'bc-github-installer' ), 'secondary', $plugin['slug'] ); ?>
                                <?php endif; ?>

                                <?php if ( 'update_available' === $status['state'] ) : ?>
                                    <?php $this->render_action_button( 'update_now', __( 'Update Now', 'bc-github-installer' ), 'primary', $plugin['slug'] ); ?>
                                <?php endif; ?>

                                <?php $this->render_action_button( 'check_update', __( 'Check Update', 'bc-github-installer' ), 'secondary', $plugin['slug'] ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_action_button( string $subaction, string $label, string $class = 'secondary', string $slug = '' ): void {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-right:6px; margin-bottom:6px;">
            <?php wp_nonce_field( 'bcgi_action_nonce' ); ?>
            <input type="hidden" name="action" value="bcgi_action" />
            <input type="hidden" name="subaction" value="<?php echo esc_attr( $subaction ); ?>" />
            <?php if ( '' !== $slug ) : ?>
                <input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>" />
            <?php endif; ?>
            <button type="submit" class="button button-<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    private function format_status_label( string $state ): string {
        if ( 'not_installed' === $state ) {
            return __( 'Not installed', 'bc-github-installer' );
        }

        if ( 'update_available' === $state ) {
            return __( 'Update available', 'bc-github-installer' );
        }

        return __( 'Up to date', 'bc-github-installer' );
    }
}
