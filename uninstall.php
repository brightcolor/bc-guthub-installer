<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'bcgi_settings' );
delete_option( 'bcgi_registry_plugins' );
delete_transient( 'bcgi_manifest_cache' );