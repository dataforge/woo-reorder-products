<?php
/*
Plugin Name: DF - Reorder Products for WooCommerce
Description: Adds a drag-and-drop interface to reorder WooCommerce products by date.
Version: 1.0.0
Author: Your Name
License: GPL2
*/

if (!defined('ABSPATH')) exit;

class DF_Reorder_Products_Plugin {

    public function __construct() {
        // Admin menu
        add_action('admin_menu', array($this, 'add_submenu'));
        // Enqueue scripts/styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        // AJAX handler
        add_action('wp_ajax_df_reorder_products_save_order', array($this, 'save_order'));
    }

    public function add_submenu() {
        add_submenu_page(
            'woocommerce',
            'DF - Reorder products',
            'DF - Reorder products',
            'manage_woocommerce',
            'df-reorder-products',
            array($this, 'page_callback')
        );
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'woocommerce_page_df-reorder-products') return;
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_style('df-reorder-products-style', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css');
        wp_add_inline_style('df-reorder-products-style', '
            #df-reorder-products-list { list-style: none; margin: 0; padding: 0; max-width: 700px; }
            #df-reorder-products-list li { display: flex; align-items: center; padding: 8px 12px; border: 1px solid #ddd; margin-bottom: 4px; background: #fff; cursor: grab; }
            .df-reorder-products-handle { cursor: grab; margin-right: 16px; color: #888; font-size: 1.2em; }
            .df-reorder-products-index { width: 32px; text-align: right; margin-right: 12px; color: #666; }
            .df-reorder-products-title { flex: 1; }
            .df-reorder-products-date { color: #888; font-size: 0.95em; margin-left: 16px; }
            #df-reorder-products-save { margin-top: 16px; }
            #df-reorder-products-message { margin-top: 16px; }
        ');
        wp_add_inline_script('jquery-ui-sortable', '
            jQuery(function($){
                $("#df-reorder-products-list").sortable({
                    handle: ".df-reorder-products-handle",
                    update: function() {
                        $("#df-reorder-products-list li").each(function(i){
                            $(this).find(".df-reorder-products-index").text(i+1);
                        });
                    }
                });
                $("#df-reorder-products-save").on("click", function(e){
                    e.preventDefault();
                    var order = [];
                    $("#df-reorder-products-list li").each(function(){
                        order.push($(this).data("product-id"));
                    });
                    $("#df-reorder-products-save").prop("disabled", true);
                    $("#df-reorder-products-message").text("Saving...");
                    $.post(ajaxurl, {
                        action: "df_reorder_products_save_order",
                        order: order,
                        _wpnonce: $("#df-reorder-products-nonce").val()
                    }, function(response){
                        $("#df-reorder-products-save").prop("disabled", false);
                        if(response.success){
                            $("#df-reorder-products-message").text("Order saved successfully.");
                        } else {
                            $("#df-reorder-products-message").text("Error: " + (response.data || "Unknown error"));
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
        echo '<div class="wrap"><h1>DF - Reorder products</h1>';
        echo '<p>Drag and drop products to reorder them. The top item will be the most recent. Click "Save" to apply the new order.</p>';
        echo '<ul id="df-reorder-products-list">';
        $i = 1;
        foreach ($product_ids as $pid) {
            $title = get_the_title($pid);
            $date = get_the_date('Y-m-d H:i', $pid);
            $thumb = get_the_post_thumbnail($pid, array(32,32), array('style'=>'width:32px;height:32px;margin-right:12px;'));
            echo '<li data-product-id="' . esc_attr($pid) . '">';
            echo '<span class="df-reorder-products-handle"><i class="fas fa-grip-vertical"></i></span>';
            echo '<span class="df-reorder-products-index">' . $i . '</span>';
            echo $thumb;
            echo '<span class="df-reorder-products-title">' . esc_html($title) . '</span>';
            echo '<span class="df-reorder-products-date">' . esc_html($date) . '</span>';
            echo '</li>';
            $i++;
        }
        echo '</ul>';
        echo '<input type="hidden" id="df-reorder-products-nonce" value="' . esc_attr(wp_create_nonce('df_reorder_products_save')) . '">';
        echo '<button class="button button-primary" id="df-reorder-products-save">Save</button>';
        echo '<div id="df-reorder-products-message"></div>';
        echo '</div>';
    }

    public function save_order() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        check_ajax_referer('df_reorder_products_save');
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
    new DF_Reorder_Products_Plugin();
}
