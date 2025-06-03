<?php
/*
Plugin Name:       Woo Reorder Products
Description:       Adds a drag-and-drop interface to reorder WooCommerce products by date.
Version:           1.10
Author:            Dataforge
License:           GPL2
Text Domain:       woo-reorder-products
GitHub Plugin URI: https://github.com/dataforge/woo-reorder-products
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooCommerce_Reorder_Products_Plugin {

    public function __construct() {
        // Admin menu
        add_action('admin_menu', array($this, 'add_submenu'));
        // Enqueue scripts/styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        // AJAX handler
        add_action('wp_ajax_woo_reorder_products_save_order', array($this, 'save_order'));
    }

    public function add_submenu() {
        add_submenu_page(
            'woocommerce',
            'WooCommerce Reorder Products',
            'Woo Reorder Products',
            'manage_woocommerce',
            'woo-reorder-products',
            array($this, 'page_callback')
        );
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'woocommerce_page_woo-reorder-products') return;
        wp_enqueue_script('jquery-ui-sortable');
        wp_add_inline_style('wp-admin', '
            #woo-reorder-products-list { list-style: none; margin: 0; padding: 0; max-width: 700px; }
            #woo-reorder-products-list li { display: flex; align-items: center; padding: 8px 12px; border: 1px solid #ddd; margin-bottom: 4px; background: #fff; cursor: grab; }
            .woo-reorder-products-handle { cursor: grab; margin-right: 16px; color: #888; font-size: 1.2em; }
            .woo-reorder-products-grip { font-size: 1.2em; color: #888; display: inline-block; line-height: 1; }
            .woo-reorder-products-index { width: 32px; text-align: right; margin-right: 12px; color: #666; }
            .woo-reorder-products-title { flex: 1; }
            .woo-reorder-products-date { color: #888; font-size: 0.95em; margin-left: 16px; }
            #woo-reorder-products-save { margin-top: 16px; }
            #woo-reorder-products-message { margin-top: 16px; }
        ');
        wp_add_inline_script('jquery-ui-sortable', '
            jQuery(function($){
                $("#woo-reorder-products-list").sortable({
                    handle: ".woo-reorder-products-handle",
                    update: function() {
                        $("#woo-reorder-products-list li").each(function(i){
                            $(this).find(".woo-reorder-products-index").text(i+1);
                        });
                    }
                });
                $("#woo-reorder-products-save").on("click", function(e){
                    e.preventDefault();
                    var order = [];
                    $("#woo-reorder-products-list li").each(function(){
                        order.push($(this).data("product-id"));
                    });
                    $("#woo-reorder-products-save").prop("disabled", true);
                    $("#woo-reorder-products-message").text("Saving...");
                    $.post(ajaxurl, {
                        action: "woo_reorder_products_save_order",
                        order: order,
                        _wpnonce: $("#woo-reorder-products-nonce").val()
                    }, function(response){
                        $("#woo-reorder-products-save").prop("disabled", false);
                        if(response.success){
                            $("#woo-reorder-products-message").text("Order saved successfully.");
                        } else {
                            $("#woo-reorder-products-message").text("Error: " + (response.data || "Unknown error"));
                        }
                    });
                });
            });
        ');
    }

    public function page_callback() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You do not have permission to access this page.');
        }

        // --- Begin Plugin Update Check & Settings Section ---
        // Handle "Check for Plugin Updates" button
        if (isset($_POST['woo_inv_to_rs_check_update']) && check_admin_referer('woo_inv_to_rs_settings_nonce', 'woo_inv_to_rs_settings_nonce')) {
            // Simulate the cron event for plugin update check
            do_action('wp_update_plugins');
            if (function_exists('wp_clean_plugins_cache')) {
                wp_clean_plugins_cache(true);
            }
            // Remove the update_plugins transient to force a check
            delete_site_transient('update_plugins');
            // Call the update check directly as well
            if (function_exists('wp_update_plugins')) {
                wp_update_plugins();
            }
            // Get update info
            $plugin_file = plugin_basename(__FILE__);
            $update_plugins = get_site_transient('update_plugins');
            $update_msg = '';
            if (isset($update_plugins->response) && isset($update_plugins->response[$plugin_file])) {
                $new_version = $update_plugins->response[$plugin_file]->new_version;
                $update_msg = '<div class="updated"><p>Update available: version ' . esc_html($new_version) . '.</p></div>';
            } else {
                $update_msg = '<div class="updated"><p>No update available for this plugin.</p></div>';
            }
            echo $update_msg;
        }
        // For demonstration, $masked_key is empty (no API key logic yet)
        $masked_key = '';
        ?>
        <div class="wrap">
            <h2>RepairShopr API Settings</h2>
            <form method="post" action="">
                <?php wp_nonce_field('woo_inv_to_rs_settings_nonce', 'woo_inv_to_rs_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="woo_inv_to_rs_api_key">API Key</label></th>
                        <td>
                            <input type="text" id="woo_inv_to_rs_api_key" name="woo_inv_to_rs_api_key" value="<?php echo esc_attr($masked_key); ?>" class="regular-text" autocomplete="off">
                            <p class="description">For security, only the last 4 characters are shown. Enter a new key to update.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <form method="post" action="" style="margin-top:2em;">
                <?php wp_nonce_field('woo_inv_to_rs_settings_nonce', 'woo_inv_to_rs_settings_nonce'); ?>
                <input type="hidden" name="woo_inv_to_rs_check_update" value="1">
                <?php submit_button('Check for Plugin Updates', 'secondary'); ?>
            </form>
        </div>
        <?php
        // --- End Plugin Update Check & Settings Section ---

        // Get parent products (exclude variations)
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_parent'    => 0,
            'fields'         => 'ids',
        );
        $product_ids = get_posts($args);
        echo '<div class="wrap"><h1>WooCommerce Reorder Products</h1>';
        echo '<p>Drag and drop products to reorder them. The top item will be the most recent. Click "Save" to apply the new order.</p>';
        echo '<ul id="woo-reorder-products-list">';
        $i = 1;
        foreach ($product_ids as $pid) {
            $title = get_the_title($pid);
            $date = get_the_date('Y-m-d H:i', $pid);
            $thumb = get_the_post_thumbnail($pid, array(32,32), array('style'=>'width:32px;height:32px;margin-right:12px;'));
            echo '<li data-product-id="' . esc_attr($pid) . '">';
            echo '<span class="woo-reorder-products-handle"><span class="woo-reorder-products-grip">⋮⋮</span></span>';
            echo '<span class="woo-reorder-products-index">' . $i . '</span>';
            echo $thumb;
            echo '<span class="woo-reorder-products-title">' . esc_html($title) . '</span>';
            echo '<span class="woo-reorder-products-date">' . esc_html($date) . '</span>';
            echo '</li>';
            $i++;
        }
        echo '</ul>';
        echo '<input type="hidden" id="woo-reorder-products-nonce" value="' . esc_attr(wp_create_nonce('woo_reorder_products_save')) . '">';
        echo '<button class="button button-primary" id="woo-reorder-products-save">Save</button>';
        echo '<div id="woo-reorder-products-message"></div>';
        echo '</div>';
    }

    public function save_order() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        check_ajax_referer('woo_reorder_products_save');
        if (empty($_POST['order']) || !is_array($_POST['order'])) {
            wp_send_json_error('Invalid order data');
        }
        $order = array_map('intval', $_POST['order']);
        // Get current product IDs in DB order (descending date)
        $args = array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_parent'    => 0,
            'fields'         => 'ids',
        );
        $current_ids = get_posts($args);
        // Find the first index where the order changed
        $changed_index = null;
        foreach ($order as $i => $pid) {
            if (!isset($current_ids[$i]) || $current_ids[$i] != $pid) {
                $changed_index = $i;
                break;
            }
        }
        if ($changed_index === null) {
            wp_send_json_success('No changes detected');
        }
        // Only update products from $changed_index up
        $now = current_time('mysql');
        $now_ts = strtotime($now);
        $interval = 60; // 1 minute between products
        $updates = 0;
        foreach ($order as $i => $pid) {
            if ($i < $changed_index) continue;
            // Assign date: most recent for top, older for next, etc.
            $new_ts = $now_ts - ($i - $changed_index) * $interval;
            // Don't go into the future
            if ($new_ts > $now_ts) $new_ts = $now_ts;
            $new_date = date('Y-m-d H:i:s', $new_ts);
            // Only update if different
            $post = get_post($pid);
            if ($post && $post->post_date != $new_date) {
                wp_update_post(array(
                    'ID' => $pid,
                    'post_date' => $new_date,
                    'post_date_gmt' => get_gmt_from_date($new_date),
                ));
                $updates++;
            }
        }
        wp_send_json_success("Updated $updates products.");
    }
}

if (is_admin()) {
    new WooCommerce_Reorder_Products_Plugin();
}
