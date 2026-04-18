<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BCGI_Plugin {
    private static $instance;

    /** @var BCGI_GitHub_Client */
    private $github;

    /** @var BCGI_Registry_Manager */
    private $registry;

    /** @var BCGI_Installer */
    private $installer;

    /** @var BCGI_Update_Manager */
    private $updates;

    /** @var BCGI_Admin_Page */
    private $admin;

    public static function instance(): BCGI_Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void {
        $this->github    = new BCGI_GitHub_Client();
        $this->registry  = new BCGI_Registry_Manager( $this->github );
        $this->installer = new BCGI_Installer( $this->registry );
        $this->updates   = new BCGI_Update_Manager( $this->registry, $this->github );
        $this->admin     = new BCGI_Admin_Page( $this->registry, $this->installer );

        $this->updates->register_hooks();
        $this->admin->register_hooks();

        add_action( 'init', array( $this, 'load_textdomain' ) );
    }

    public function load_textdomain(): void {
        load_plugin_textdomain( 'bc-github-installer', false, dirname( plugin_basename( BCGI_PLUGIN_FILE ) ) . '/languages' );
    }
}