<?php
/**
 * Plugin Name: Serial Number Handler
 * Description: Assigns serial numbers to products after purchase.
 * Author: Raul Smith (Galactic Digital Studios)
 * Version: 2.0.1
 */

add_action('init', function () {
    if (!session_id()) {
        session_start();
    }
}, 1);

add_action('woocommerce_order_details_after_order_table', 'gds_show_serial_form_in_account', 10, 1);

function gds_show_serial_form_in_account($order) {
    if (!is_user_logged_in() || get_current_user_id() !== $order->get_user_id()) {
        return;
    }

    foreach ($order->get_items() as $item_id => $item) {
        $product_id = $item->get_product_id();
        $page_created = $order->get_meta('_activation_page_id_' . $item_id);
        $display_name = $item->get_meta('Customer Name');
        $phone_number = $item->get_meta('Phone Number');
        $vital_info   = $item->get_meta('Vital Information');
        $image_id     = $item->get_meta('Customer Image');
        $image_url    = wp_get_attachment_url($image_id);
        $existing_serial = $item->get_meta('Serial Number');

        echo '<div style="border:1px solid #ccc; padding:20px; margin-top:30px;">';
        echo '<h3>Register Your Item for ' . get_the_title($product_id) . '</h3>';

        // Show preview
        echo '<form method="post" enctype="multipart/form-data">';
        echo '<input type="hidden" name="gds_item_id" value="' . esc_attr($item_id) . '">';
        echo '<input type="hidden" name="gds_order_id" value="' . esc_attr($order->get_id()) . '">';

        wp_nonce_field('gds_register_serial_' . $item_id, 'gds_nonce');

        echo '<p><label>Name:<br><input type="text" name="gds_name" value="' . esc_attr($display_name) . '" required></label></p>';
        echo '<p><label>Phone:<br><input type="text" name="gds_phone" value="' . esc_attr($phone_number) . '" required></label></p>';
        echo '<p><label>Vital Info:<br><textarea name="gds_vital_info" required>' . esc_textarea($vital_info) . '</textarea></label></p>';

        echo '<p><label>Upload Image (optional):<br><input type="file" name="gds_image"></label></p>';

        if ($image_url) {
            echo '<p>Current Image:<br><img src="' . esc_url($image_url) . '" style="max-width:200px;"></p>';
        }

        echo '<p><label>Enter Serial Number:<br><input type="text" name="gds_serial" value="' . esc_attr($existing_serial) . '" required></label></p>';

        echo '<p><button type="submit" name="gds_submit_registration">Submit Registration</button></p>';
        echo '</form>';

        echo '</div>';
    }
}

add_action('init', 'gds_handle_serial_form_submission');

function gds_handle_serial_form_submission() {
    if (!isset($_POST['gds_submit_registration'])) return;

    $order_id = absint($_POST['gds_order_id'] ?? 0);
    $item_id  = absint($_POST['gds_item_id'] ?? 0);
    $is_manual = ($order_id === 999999); // Flag to detect manual entry

    // Sanitize input
    $serial_input = sanitize_text_field($_POST['gds_serial']);
    $name         = sanitize_text_field($_POST['gds_name']);
    $phone        = sanitize_text_field($_POST['gds_phone']);
    $vital_info   = sanitize_textarea_field($_POST['gds_vital_info']);

    $product_id = 35;

    if (!$is_manual) {
        if (!isset($_POST['gds_nonce']) || !wp_verify_nonce($_POST['gds_nonce'], 'gds_register_serial_' . $item_id)) return;

        $order = wc_get_order($order_id);
        if (!$order || get_current_user_id() !== $order->get_user_id()) return;

        $item = $order->get_item($item_id);
        if (!$item) return;

        $product_id = $item->get_product_id();
    }

    // SERIAL NUMBER TABLE HANDLING
    global $wpdb;
    $serial_table = $wpdb->prefix . 'serial_numbers';

    $existing_serial = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $serial_table WHERE serial_number = %s",
        $serial_input
    ));

    if ($existing_serial && $existing_serial->used == 1) {
        wc_add_notice('This serial number has already been registered.', 'error');
        return;
    }

    if (!$existing_serial) {
        $wpdb->insert($serial_table, [
            'serial_number' => $serial_input,
            'item_no'       => $product_id,
            'used'          => 1,
        ]);
    } else {
        $wpdb->update($serial_table, ['used' => 1], ['serial_number' => $serial_input]);
    }

    // HANDLE IMAGE UPLOAD
    $image_id = 0;

    if (!empty($_FILES['gds_image']['name'])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = $_FILES['gds_image']['type'];

        if (!in_array($file_type, $allowed_types)) {
            wc_add_notice('Only JPG, PNG, or GIF images are allowed.', 'error');
            return;
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $upload = wp_handle_upload($_FILES['gds_image'], ['test_form' => false]);

        if (!isset($upload['error']) && isset($upload['file'])) {
            $filetype = wp_check_filetype($upload['file']);
            $attachment = [
                'post_mime_type' => $filetype['type'],
                'post_title'     => sanitize_file_name($upload['file']),
                'post_content'   => '',
                'post_status'    => 'inherit'
            ];
            $image_id = wp_insert_attachment($attachment, $upload['file']);
            $attach_data = wp_generate_attachment_metadata($image_id, $upload['file']);
            wp_update_attachment_metadata($image_id, $attach_data);
        } else {
            wc_add_notice('Image upload failed. Please try again.', 'error');
            return;
        }
    }

    // CREATE HTML CONTENT
    $sanitized_number = preg_replace('/\D/', '', $phone);
    if (strlen($sanitized_number) === 10) $sanitized_number = '1' . $sanitized_number;
    $tel_href = 'tel:+' . $sanitized_number;
    $image_url = $image_id ? wp_get_attachment_url($image_id) : '';

    $image_html = $image_url
        ? '<img src="' . esc_url($image_url) . '" style="float:right; margin:0 0 20px 20px; max-width:300px;">'
        : '<p>No image uploaded.</p>';

    $buttons_html = '
        <div style="margin-top: 30px; padding-top: 30px; display: flex; flex-wrap: wrap; gap: 15px;">
            <a href="' . esc_attr($tel_href) . '" 
               style="padding: 24px; background-color: #dc2626; color: white; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: bold;">
                📞 Contact
            </a>
        </div>
        <div style="margin-top: 30px; padding-top: 30px; display: flex; flex-wrap: wrap; gap: 15px;">
            <a href="#" id="open-maps-btn" 
               style="padding: 24px; background-color: #16a34a; color: white; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: bold;">
                🚓 Find the Nearest Authorities
            </a>
        </div>';

    $page_content = "
        <h1>$name</h1>
        $image_html
        <p>$vital_info</p>
        <div style='clear: both;'>&nbsp;</div>
        $buttons_html
    ";

    // CREATE PUBLIC PAGE
    $page_id = wp_insert_post([
        'post_title'   => $serial_input,
        'post_content' => $page_content,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_author'  => get_current_user_id(),
    ]);

    update_post_meta($page_id, '_wp_page_template', 'qr-code-scan.php');
    update_post_meta($page_id, '_registered_user_id', get_current_user_id());
    update_post_meta($page_id, '_is_registered_item', true);

    // SAVE DATA TO WC ITEM IF APPLICABLE
    if (!$is_manual) {
        $item->update_meta_data('Customer Name', $name);
        $item->update_meta_data('Phone Number', $phone);
        $item->update_meta_data('Vital Information', $vital_info);
        $item->update_meta_data('Customer Image', $image_id);
        $item->update_meta_data('Serial Number', $serial_input);
        $item->save();

        $order->update_meta_data('_activation_page_id_' . $item_id, $page_id);
        $order->save();
    }

    // Redirect immediately if manual
    if ($is_manual) {
        $page_url = get_permalink($page_id);

        if ($page_url) {
            wp_safe_redirect($page_url);
            exit;
        } else {
            wc_add_notice('Something went wrong. We could not generate your page.', 'error');
            return;
        }
    }

    $_SESSION['gds_registration_notice'] = [
        'message' => 'Your registration page has been created successfully!',
        'type'    => 'success'
    ];
    
    $_SESSION['gds_registration_notice'] = [
        'message' => 'This serial number has already been registered.',
        'type'    => 'error'
    ];

    wp_redirect($_SERVER['REQUEST_URI']);
    exit;

}
