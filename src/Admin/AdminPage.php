<?php

/**
 * 
 * Admin Page
 * 
 * Register the plugin's admin page as a top-level menu item,
 * Enqueue styles and scripts used and render the page as a shell. 
 * All interactive behaviour is handled by the woam-admin.js file via the AJAX
 * endpoints registered in the AjaxHandler.
 * 
 * @package HW\WOAM\Admin
*/

namespace HW\WOAM\Admin;

defined ( 'ABSPATH' ) || exit;

/**
 * 
 * Class AdminPage
*/

class AdminPage {
    /**
     * The page slug used in the URL and add_menu_page()
    */

    private const PAGE_SLUG = "woam-dashboard";

    /**
     * 
     * Registers admin menu and asset hooks.
     * Called once from Plugin::boot().
     * 
     * @return void
     * 
    */

    public function register_hooks () :void{
        add_action( 'admin_menu' , [$this, 'register_menu'] );
        add_action( 'admin_enqueue_scripts', [$this, 'enqueue_assets'] );

    }

    /**
     * 
     * Register the menu in top-level, 
     * Position Just above the WooCommerce menu in the sidebar.
     * 
     * @return void
    */

    public function register_menu () :void{
        add_menu_page( 
            __( 'Order Archive Manager', 'woo-order-archive-manager' ),
            __( 'Order Archive', 'woo-order-archive-manager' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [$this, 'admin_render_page'],
            'dashicons-archive',
            56 //menu-position
        );
    }

}

