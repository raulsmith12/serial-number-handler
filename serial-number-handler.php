<?php
/**
 * Plugin Name: Serial Number Handler
 * Description: Assigns serial numbers to products after purchase.
 * Author: Raul Smith (Galactic Digital Studios)
 * Version: 1.1.0
 */

add_action('woocommerce_order_status_completed', 'handle_serial_and_page_creation');

// creates a page using the serial number attached to the item upon completed order

function handle_serial_and_page_creation($order_id) {
    $order = wc_get_order($order_id);
    
    foreach ($order->get_items() as $item) {
        $item_no = $item->get_product_id();

        // Retrieve the product-level custom field (if using one)
        $serial_pool_tag = get_post_meta($item_no, '_serial_pool_tag', true);

        global $wpdb;
        $serial_table = $wpdb->prefix . 'serial_numbers';

        // Query for an unused serial assigned to this product
        $serial = $wpdb->get_var($wpdb->prepare(
            "SELECT serial_number FROM {$serial_table}
             WHERE used = 0
             AND item_no = %d
             LIMIT 1",
            $item_no
        ));

        if ($serial) {
            
            // Mark the serial as used
            $wpdb->update($serial_table, ['used' => 1], ['serial_number' => $serial]);
            
            $display_name = $item->get_meta('Customer Name');
            $vital_information = $item->get_meta('Vital Information');
            
            $raw_phone_number = $item->get_meta('Phone Number');

            // Remove all non-numeric characters
            $sanitized_number = preg_replace('/\D/', '', $raw_phone_number);
            
            // Add country code if missing (assuming U.S. = +1)
            if (strlen($sanitized_number) === 10) {
                $sanitized_number = '1' . $sanitized_number;
            }
            
            $tel_href = 'tel:+' . $sanitized_number;

            $image_id = $item->get_meta('Customer Image');
            $image_url = wp_get_attachment_url($image_id);

            if ($image_url) {
                $image_html = '<img src="' . esc_url($image_url) . '" alt="Customer Uploaded Image" style="float: right; margin: 0 0 20px 20px; max-width: 300px; height: auto;">';

            } else {
                $image_html = '<p>No image uploaded.</p>';
            }

            $buttons_html = '
                <div style="margin-top: 30px; display: flex; flex-wrap: wrap; gap: 15px;">
                    <a href="' . esc_attr($tel_href) . '" 
                       style="padding: 24px; background-color: #dc2626; color: white; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: bold;">
                        📞 Contact
                    </a>
                    <a href="#" id="open-maps-btn" 
                       style="padding: 24px; background-color: #16a34a; color: white; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: bold;">
                        🚔 Find the Nearest Authorities
                    </a>
                </div>
            ';

            $page_content = "
                <h1>$display_name</h1>
                $image_html
                <p>$vital_information</p>
                <div style='clear: both;'>&nbsp;</div>
                $buttons_html
            ";

            // Create a public page
            $page_id = wp_insert_post([
                'post_title'   => $serial,
                'post_content' => $page_content,
                'post_status'  => "publish",
                'post_type'    => "page",
                'post_author'  => get_current_user_id(), // This links it to the buyer
            ]);
            
            update_post_meta($page_id, '_wp_page_template', 'qr-code-scan.php');
            
            update_post_meta($page_id, 'is_serial_page', true);
            
            delete_post_meta($page_id, '_kubio_data');

            delete_post_meta($page_id, '_edit_with_kubio');
            delete_post_meta($page_id, '_kubio_page_settings');

            update_post_meta($page_id, '_locked_from_kubio', true);
            
            update_post_meta($page_id, '_registered_user_id', get_current_user_id());
            
            update_post_meta($page_id, '_is_registered_item', true);
            
            update_post_meta($page_id, '_display_name', $display_name);
            update_post_meta($page_id, '_vital_information', $vital_information);
            update_post_meta($page_id, '_tel_href', $tel_href);
            update_post_meta($page_id, '_customer_image', $image_id);

            // Save created page ID to the order (optional)
            update_post_meta($order_id, '_activation_page_id', $page_id);
            
            // Mark the serial as used
            $wpdb->update($serial_table, ['used' => 1], ['serial_number' => $serial]);
        }
    }
}

add_action('admin_menu', 'register_serial_admin_page');

// adds the serial number input to the admin menu

function register_serial_admin_page() {
    add_menu_page(
        'Manage Serial Numbers',
        'Serial Numbers',
        'manage_options',
        'serial-number-manager',
        'render_serial_admin_page',
        'dashicons-editor-code',
        56
    );
}

// creates admin page allowing WP admins to add serial numbers to certain products

function render_serial_admin_page() {
    ?>
    <div class="wrap">
        <h1>Add Serial Numbers</h1>

        <?php if (isset($_GET['success'])): ?>
            <div class="updated notice"><p>Serial number added successfully.</p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('add_serial_nonce', 'serial_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="serial_number">Serial Number</label></th>
                    <td><input type="text" name="serial_number" id="serial_number" required class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="item_no">Select Product</label></th>
                    <td>
                        <select name="item_no" id="item_no" required>
                            <option value="">Select a Product</option>
                            <?php
                            $products = wc_get_products([
                                'status' => 'publish',
                                'limit' => -1,
                                'orderby' => 'title',
                                'order' => 'ASC',
                            ]);
                
                            foreach ($products as $product) {
                                echo '<option value="' . esc_attr($product->get_id()) . '">' . esc_html($product->get_name()) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
            </table>

            <p><input type="submit" name="submit_serial" class="button-primary" value="Add Serial Number"></p>
        </form>
    </div>
    <?php
}

add_action('admin_init', 'handle_serial_form_submission');

// serial number add form handler

function handle_serial_form_submission() {
    if (isset($_POST['submit_serial']) && check_admin_referer('add_serial_nonce', 'serial_nonce')) {
        global $wpdb;
        $table = $wpdb->prefix . 'serial_numbers';

        $serial = sanitize_text_field($_POST['serial_number']);
        $item_no = intval($_POST['item_no']);

        $wpdb->insert($table, [
            'serial_number' => $serial,
            'item_no'       => $item_no,
            'used'          => 0,
        ]);

        // Redirect to avoid form resubmission
        wp_redirect(admin_url('admin.php?page=serial-number-manager&success=1'));
        exit;
    }
}

add_action('woocommerce_product_options_general_product_data', 'add_next_serial_display_field');
function add_next_serial_display_field() {
    global $post;

    $product_id = $post->ID;

    // Get next available serial
    global $wpdb;
    $table = $wpdb->prefix . 'serial_numbers';
    $next_serial = $wpdb->get_var($wpdb->prepare(
        "SELECT serial_number FROM $table WHERE used = 0 AND item_no = %d ORDER BY id ASC LIMIT 1",
        $product_id
    ));

    // If no serial found, show placeholder
    $display = $next_serial ? esc_html($next_serial) : 'No available serial numbers.';

    // Add a read-only field
    echo '<div class="options_group">';
    woocommerce_wp_text_input([
        'id'          => 'next_serial_preview',
        'label'       => 'Next Available Serial',
        'desc_tip'    => true,
        'description' => 'This is the next unused serial assigned to this product.',
        'value'       => $display,
        'custom_attributes' => ['readonly' => 'readonly'],
    ]);
    echo '</div>';
}

add_filter('woocommerce_checkout_create_order', 'bypass_payment_method_if_empty', 10, 2);

// this function mostly exists for testing purposes

function bypass_payment_method_if_empty($order, $data) {
    if (empty($data['payment_method'])) {
        // Force a dummy payment method (like 'cod' = Cash on Delivery)
        $order->set_payment_method('cod');
        $order->set_payment_method_title('Bypassed Payment Method');
    }

    return $order;
}

