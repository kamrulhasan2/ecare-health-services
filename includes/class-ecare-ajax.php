<?php
defined('ABSPATH') || exit;

class ECare_Ajax {

    public static function init() {
        $actions = array(
            'filter_caregivers',
            'get_caregiver_details',
            'submit_caregiver_booking',
            'submit_caregiver_registration',
            'get_locations',
            'filter_lab_tests',
            'add_lab_test_to_cart',
            'submit_ambulance_request',
            'submit_ambulance_registration',
        );

        foreach ($actions as $action) {
            add_action("wp_ajax_ecare_{$action}", array(__CLASS__, $action));
            add_action("wp_ajax_nopriv_ecare_{$action}", array(__CLASS__, $action));
        }

        // Admin AJAX
        add_action('wp_ajax_ecare_update_booking_status', array(__CLASS__, 'update_booking_status'));
        add_action('wp_ajax_ecare_update_provider_status', array(__CLASS__, 'update_provider_status'));
    }

    public static function filter_caregivers() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $type    = sanitize_text_field($_POST['provider_type'] ?? '');
        $package = sanitize_text_field($_POST['package_type'] ?? '');

        $meta_query = array('relation' => 'AND');
        $meta_query[] = array('key' => '_provider_status', 'value' => 'approved');

        if (!empty($type)) {
            $meta_query[] = array('key' => '_provider_type', 'value' => $type);
        }

        $args = array(
            'post_type'      => 'ecare_caregiver',
            'posts_per_page' => -1,
            'meta_query'     => $meta_query,
        );

        $query = new WP_Query($args);
        $html = '';

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $id = get_the_ID();
                $provider_type = get_post_meta($id, '_provider_type', true);
                $price = 0;
                $price_label = '';

                if ($package === 'daily_12') {
                    $price = get_post_meta($id, '_daily_12_price', true);
                    $price_label = 'Daily 12H';
                } elseif ($package === 'daily_24') {
                    $price = get_post_meta($id, '_daily_24_price', true);
                    $price_label = 'Daily 24H';
                } elseif ($package === 'monthly_12') {
                    $price = get_post_meta($id, '_monthly_12_price', true);
                    $price_label = 'Monthly 12H';
                } elseif ($package === 'monthly_24') {
                    $price = get_post_meta($id, '_monthly_24_price', true);
                    $price_label = 'Monthly 24H';
                }

                $html .= '<div class="ecare-caregiver-card" data-id="' . esc_attr($id) . '">';
                $html .= '<div class="ecare-card-image">' . get_the_post_thumbnail($id, 'medium') . '</div>';
                $html .= '<div class="ecare-card-body">';
                $html .= '<h3>' . get_the_title() . '</h3>';
                $html .= '<span class="ecare-badge ecare-badge-type">' . esc_html($provider_type) . '</span>';
                if ($price) {
                    $html .= '<p class="ecare-price">' . esc_html($price_label) . ': ৳' . esc_html(number_format($price)) . '</p>';
                }
                $html .= '<p class="ecare-excerpt">' . get_the_excerpt() . '</p>';
                $html .= '<a href="#" class="ecare-btn ecare-btn-primary ecare-view-details" data-id="' . esc_attr($id) . '">View Details & Book</a>';
                $html .= '</div></div>';
            }
            wp_reset_postdata();
        } else {
            $html .= '<p class="ecare-no-results">No caregivers found matching your criteria.</p>';
        }

        wp_send_json_success(array('html' => $html));
    }

    public static function get_caregiver_details() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $id = intval($_POST['caregiver_id'] ?? 0);
        if (!$id) wp_send_json_error(array('message' => 'Invalid caregiver.'));

        $post = get_post($id);
        if (!$post || $post->post_type !== 'ecare_caregiver') {
            wp_send_json_error(array('message' => 'Caregiver not found.'));
        }

        $meta = array(
            'provider_type' => get_post_meta($id, '_provider_type', true),
            'skills'        => get_post_meta($id, '_skills', true),
            'education'     => get_post_meta($id, '_education', true),
            'experience'    => get_post_meta($id, '_experience', true),
            'daily_12_price' => get_post_meta($id, '_daily_12_price', true),
            'daily_24_price' => get_post_meta($id, '_daily_24_price', true),
            'monthly_12_price' => get_post_meta($id, '_monthly_12_price', true),
            'monthly_24_price' => get_post_meta($id, '_monthly_24_price', true),
        );

        $html = '<div class="ecare-caregiver-detail">';
        $html .= '<div class="ecare-detail-header">';
        $html .= '<div class="ecare-detail-image">' . get_the_post_thumbnail($id, 'large') . '</div>';
        $html .= '<div class="ecare-detail-info">';
        $html .= '<h2>' . get_the_title($id) . '</h2>';
        $html .= '<span class="ecare-badge ecare-badge-type">' . esc_html($meta['provider_type']) . '</span>';
        $html .= '<p><strong>Experience:</strong> ' . esc_html($meta['experience']) . ' years</p>';
        $html .= '<p><strong>Education:</strong> ' . esc_html($meta['education']) . '</p>';
        $html .= '</div></div>';
        $html .= '<div class="ecare-detail-body">';
        $html .= '<h3>Skills</h3><p>' . nl2br(esc_html($meta['skills'])) . '</p>';
        $html .= '<h3>About</h3><p>' . nl2br(esc_html($post->post_content)) . '</p>';

        $html .= '<h3>Select Package</h3>';
        $html .= '<div class="ecare-package-grid">';
        $packages = array(
            'daily_12' => array('label' => 'Daily (12 Hours)', 'price' => $meta['daily_12_price']),
            'daily_24' => array('label' => 'Daily (24 Hours)', 'price' => $meta['daily_24_price']),
            'monthly_12' => array('label' => 'Monthly (12 Hours)', 'price' => $meta['monthly_12_price']),
            'monthly_24' => array('label' => 'Monthly (24 Hours)', 'price' => $meta['monthly_24_price']),
        );
        foreach ($packages as $key => $pkg) {
            if ($pkg['price']) {
                $html .= '<label class="ecare-package-option"><input type="radio" name="package_type" value="' . esc_attr($key) . '" data-price="' . esc_attr($pkg['price']) . '"> <span>' . esc_html($pkg['label']) . ' – ৳' . esc_html(number_format($pkg['price'])) . '</span></label>';
            }
        }
        $html .= '</div>';

        $html .= '<h3>Patient Booking Form</h3>';
        $html .= '<form id="ecare-booking-form" class="ecare-form">';
        $html .= '<input type="hidden" name="caregiver_id" value="' . esc_attr($id) . '" />';
        $html .= '<div class="ecare-form-row"><label>Patient Name <span>*</span></label><input type="text" name="patient_name" required /></div>';
        $html .= '<div class="ecare-form-row"><label>Patient Type <span>*</span></label><select name="patient_type" required><option value="">Select</option><option value="Adult">Adult</option><option value="Child">Child</option><option value="Elderly">Elderly</option><option value="Infant">Infant</option></select></div>';
        $html .= '<div class="ecare-form-row"><label>Required Date <span>*</span></label><input type="date" name="required_date" required /></div>';
        $html .= '<div class="ecare-form-row"><label><input type="checkbox" name="diaper_change" value="1" /> Diaper Change Needed</label></div>';
        $html .= '<div class="ecare-form-row"><label>Address <span>*</span></label><textarea name="address" required></textarea></div>';
        $html .= '<div class="ecare-form-row"><label>Contact Number <span>*</span></label><input type="text" name="contact_phone" required /></div>';
        $html .= '<div class="ecare-form-row"><label>Disease</label><textarea name="disease"></textarea></div>';
        $html .= '<div class="ecare-form-row"><label>Upload Prescription/NID</label><input type="file" name="booking_file" /></div>';
        $html .= '<div class="ecare-form-row">';
        $html .= '<p class="ecare-total-price">Total: ৳<span id="ecare-price-display">0</span></p>';
        $html .= '<button type="submit" class="ecare-btn ecare-btn-primary">Confirm Booking</button>';
        $html .= '</div>';
        $html .= '</form>';
        $html .= '</div></div>';

        wp_send_json_success(array('html' => $html));
    }

    public static function submit_caregiver_booking() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $user_id = get_current_user_id();
        $caregiver_id = intval($_POST['caregiver_id'] ?? 0);
        $package_type = sanitize_text_field($_POST['package_type'] ?? '');
        $patient_name = sanitize_text_field($_POST['patient_name'] ?? '');
        $patient_type = sanitize_text_field($_POST['patient_type'] ?? '');
        $required_date = sanitize_text_field($_POST['required_date'] ?? '');
        $diaper_change = intval($_POST['diaper_change'] ?? 0);
        $address = sanitize_textarea_field($_POST['address'] ?? '');
        $contact_phone = sanitize_text_field($_POST['contact_phone'] ?? '');
        $disease = sanitize_textarea_field($_POST['disease'] ?? '');

        if (!$caregiver_id || !$patient_name || !$required_date || !$address || !$contact_phone) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
        }

        $price = 0;
        $price_map = array(
            'daily_12' => '_daily_12_price',
            'daily_24' => '_daily_24_price',
            'monthly_12' => '_monthly_12_price',
            'monthly_24' => '_monthly_24_price',
        );
        if (isset($price_map[$package_type])) {
            $price = floatval(get_post_meta($caregiver_id, $price_map[$package_type], true));
        }

        // File upload
        $file_urls = '';
        if (!empty($_FILES['booking_file'])) {
            $uploaded = self::handle_file_upload($_FILES['booking_file']);
            if ($uploaded) {
                $file_urls = esc_url($uploaded);
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';

        $data = array(
            'booking_type'   => 'caregiver',
            'user_id'        => $user_id ?: 0,
            'provider_id'    => $caregiver_id,
            'package_type'   => $package_type,
            'patient_name'   => $patient_name,
            'patient_type'   => $patient_type,
            'required_date'  => $required_date,
            'diaper_change'  => $diaper_change,
            'address'        => $address,
            'contact_phone'  => $contact_phone,
            'disease'        => $disease,
            'file_urls'      => $file_urls,
            'total_amount'   => $price,
            'status'         => 'pending',
        );

        $inserted = $wpdb->insert($table, $data);

        if ($inserted) {
            $booking_id = $wpdb->insert_id;

            // Create WooCommerce order if user is logged in
            if ($user_id && $price > 0) {
                $order = self::create_woocommerce_order($user_id, $price, 'Caregiver Booking - ' . get_the_title($caregiver_id), $booking_id);
                if ($order) {
                    $wpdb->update($table, array('order_id' => $order->get_id()), array('id' => $booking_id));
                }
            }

            wp_send_json_success(array('message' => 'Booking submitted successfully! We will contact you shortly.', 'booking_id' => $booking_id));
        } else {
            wp_send_json_error(array('message' => 'Failed to submit booking. Please try again.'));
        }
    }

    public static function submit_caregiver_registration() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $full_name     = sanitize_text_field($_POST['full_name'] ?? '');
        $email         = sanitize_email($_POST['email'] ?? '');
        $phone         = sanitize_text_field($_POST['phone'] ?? '');
        $provider_type = sanitize_text_field($_POST['provider_type'] ?? '');
        $experience    = sanitize_text_field($_POST['experience'] ?? '');
        $category      = sanitize_text_field($_POST['category'] ?? '');
        $skills        = sanitize_textarea_field($_POST['skills'] ?? '');
        $education     = sanitize_textarea_field($_POST['education'] ?? '');
        $nid_passport  = sanitize_text_field($_POST['nid_passport'] ?? '');
        $bank_name     = sanitize_text_field($_POST['bank_name'] ?? '');
        $bank_account  = sanitize_text_field($_POST['bank_account'] ?? '');
        $daily_12      = floatval($_POST['daily_12_price'] ?? 0);
        $daily_24      = floatval($_POST['daily_24_price'] ?? 0);
        $monthly_12    = floatval($_POST['monthly_12_price'] ?? 0);
        $monthly_24    = floatval($_POST['monthly_24_price'] ?? 0);

        if (!$full_name || !$email || !$phone || !$provider_type) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
        }

        $post_id = wp_insert_post(array(
            'post_title'   => $full_name,
            'post_type'    => 'ecare_caregiver',
            'post_status'  => 'publish',
            'post_content' => '',
        ));

        if (is_wp_error($post_id)) {
            wp_send_json_error(array('message' => 'Registration failed. Please try again.'));
        }

        update_post_meta($post_id, '_provider_type', $provider_type);
        update_post_meta($post_id, '_experience', $experience);
        update_post_meta($post_id, '_category', $category);
        update_post_meta($post_id, '_skills', $skills);
        update_post_meta($post_id, '_education', $education);
        update_post_meta($post_id, '_nid_passport', $nid_passport);
        update_post_meta($post_id, '_bank_name', $bank_name);
        update_post_meta($post_id, '_bank_account', $bank_account);
        update_post_meta($post_id, '_daily_12_price', $daily_12);
        update_post_meta($post_id, '_daily_24_price', $daily_24);
        update_post_meta($post_id, '_monthly_12_price', $monthly_12);
        update_post_meta($post_id, '_monthly_24_price', $monthly_24);
        update_post_meta($post_id, '_provider_status', 'pending');

        // Store contact info as post meta
        update_post_meta($post_id, '_email', $email);
        update_post_meta($post_id, '_phone', $phone);

        wp_send_json_success(array('message' => 'Registration submitted successfully! We will review your application and notify you.'));
    }

    // ---- Lab Test AJAX ----

    public static function get_locations() {
        check_ajax_referer('ecare_nonce', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'ecare_locations';

        $type     = sanitize_text_field($_POST['location_type'] ?? 'division');
        $parent   = intval($_POST['parent_id'] ?? 0);

        $where = $wpdb->prepare('location_type = %s', $type);
        if ($parent) {
            $where .= $wpdb->prepare(' AND parent_id = %d', $parent);
        }

        $results = $wpdb->get_results("SELECT id, name FROM {$table} WHERE {$where} ORDER BY name ASC");

        wp_send_json_success(array('locations' => $results));
    }

    public static function filter_lab_tests() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $division  = sanitize_text_field($_POST['division'] ?? '');
        $district  = sanitize_text_field($_POST['district'] ?? '');
        $area      = sanitize_text_field($_POST['area'] ?? '');
        $provider  = sanitize_text_field($_POST['lab_provider'] ?? '');
        $search    = sanitize_text_field($_POST['search'] ?? '');

        $meta_query = array('relation' => 'AND');
        $meta_query[] = array('key' => '_test_status', 'value' => 'active');

        if (!empty($division)) $meta_query[] = array('key' => '_division', 'value' => $division);
        if (!empty($district)) $meta_query[] = array('key' => '_district', 'value' => $district);
        if (!empty($area)) $meta_query[] = array('key' => '_area', 'value' => $area);
        if (!empty($provider)) $meta_query[] = array('key' => '_lab_provider', 'value' => $provider);

        $args = array(
            'post_type'      => 'ecare_lab_test',
            'posts_per_page' => -1,
            'meta_query'     => $meta_query,
            's'              => $search,
        );

        $query = new WP_Query($args);
        $html = '';

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $id = get_the_ID();
                $price = get_post_meta($id, '_price', true);
                $cat = get_post_meta($id, '_test_category', true);
                $sample = get_post_meta($id, '_sample_type', true);
                $turnaround = get_post_meta($id, '_turnaround_days', true);

                $html .= '<div class="ecare-lab-test-card">';
                $html .= '<div class="ecare-lab-test-body">';
                $html .= '<h3>' . get_the_title() . '</h3>';
                $html .= '<p class="ecare-test-code">Code: ' . esc_html(get_post_meta($id, '_test_code', true)) . '</p>';
                if ($cat) $html .= '<span class="ecare-badge ecare-badge-cat">' . esc_html($cat) . '</span>';
                if ($sample) $html .= '<span class="ecare-badge ecare-badge-sample">' . esc_html($sample) . '</span>';
                if ($turnaround) $html .= '<p><small>Turnaround: ' . esc_html($turnaround) . ' days</small></p>';
                $html .= '<p class="ecare-lab-price">Starting from ৳' . esc_html(number_format($price)) . '</p>';
                $html .= '<a href="#" class="ecare-btn ecare-btn-primary ecare-add-lab-cart" data-id="' . esc_attr($id) . '">Add to Cart</a>';
                $html .= '</div></div>';
            }
            wp_reset_postdata();
        } else {
            $html .= '<p class="ecare-no-results">No lab tests found matching your criteria.</p>';
        }

        wp_send_json_success(array('html' => $html));
    }

    public static function add_lab_test_to_cart() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $test_id = intval($_POST['test_id'] ?? 0);
        if (!$test_id) wp_send_json_error(array('message' => 'Invalid test.'));

        $price = floatval(get_post_meta($test_id, '_price', true));
        $title = get_the_title($test_id);

        if (!class_exists('WooCommerce')) {
            wp_send_json_error(array('message' => 'WooCommerce is required for checkout.'));
        }

        // Find or create WooCommerce product for this test
        $product_id = self::find_or_create_product($test_id, $title, $price, 'lab_test');

        $cart_item_key = WC()->cart->add_to_cart($product_id, 1);
        if ($cart_item_key) {
            // Store reference to original test
            WC()->session->set('ecare_lab_test_ref_' . $cart_item_key, $test_id);
            wp_send_json_success(array(
                'message' => 'Test added to cart!',
                'cart_url' => wc_get_cart_url(),
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to add to cart.'));
        }
    }

    // ---- Ambulance AJAX ----

    public static function submit_ambulance_request() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $user_id = get_current_user_id();
        $ambulance_type  = sanitize_text_field($_POST['ambulance_type'] ?? '');
        $pickup_address  = sanitize_textarea_field($_POST['pickup_address'] ?? '');
        $destination     = sanitize_textarea_field($_POST['destination'] ?? '');
        $schedule_time   = sanitize_text_field($_POST['schedule_time'] ?? '');
        $contact_phone   = sanitize_text_field($_POST['contact_phone'] ?? '');
        $priority_level  = sanitize_text_field($_POST['priority_level'] ?? 'Normal');
        $notes           = sanitize_textarea_field($_POST['notes'] ?? '');

        if (!$ambulance_type || !$pickup_address || !$destination || !$contact_phone) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
        }

        // Calculate price based on type
        $price_map = array('Standard' => 1500, 'ICU' => 3000, 'Freezer' => 5000);
        $price = $price_map[$ambulance_type] ?? 1500;

        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';

        $data = array(
            'booking_type'   => 'ambulance',
            'user_id'        => $user_id ?: 0,
            'ambulance_type' => $ambulance_type,
            'pickup_address' => $pickup_address,
            'destination'    => $destination,
            'schedule_time'  => $schedule_time,
            'contact_phone'  => $contact_phone,
            'priority_level' => $priority_level,
            'notes'          => $notes,
            'total_amount'   => $price,
            'status'         => 'pending',
        );

        $inserted = $wpdb->insert($table, $data);

        if ($inserted) {
            $booking_id = $wpdb->insert_id;

            if ($user_id && $price > 0) {
                $order = self::create_woocommerce_order($user_id, $price, 'Ambulance Booking - ' . $ambulance_type, $booking_id);
                if ($order) {
                    $wpdb->update($table, array('order_id' => $order->get_id()), array('id' => $booking_id));
                }
            }

            wp_send_json_success(array('message' => 'Ambulance request submitted! We will dispatch shortly.', 'booking_id' => $booking_id));
        } else {
            wp_send_json_error(array('message' => 'Failed to submit request.'));
        }
    }

    public static function submit_ambulance_registration() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $provider_name  = sanitize_text_field($_POST['provider_name'] ?? '');
        $email          = sanitize_email($_POST['email'] ?? '');
        $phone          = sanitize_text_field($_POST['phone'] ?? '');
        $license_plate  = sanitize_text_field($_POST['license_plate'] ?? '');
        $vehicle_model  = sanitize_text_field($_POST['vehicle_model'] ?? '');
        $driver_name    = sanitize_text_field($_POST['driver_name'] ?? '');
        $driver_license = sanitize_text_field($_POST['driver_license'] ?? '');
        $driver_nid     = sanitize_text_field($_POST['driver_nid'] ?? '');
        $ambulance_type = sanitize_text_field($_POST['ambulance_type'] ?? '');
        $base_price     = floatval($_POST['base_price'] ?? 0);

        if (!$provider_name || !$email || !$phone || !$license_plate) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
        }

        $post_id = wp_insert_post(array(
            'post_title'   => $provider_name,
            'post_type'    => 'ecare_ambulance',
            'post_status'  => 'publish',
            'post_content' => '',
        ));

        if (is_wp_error($post_id)) {
            wp_send_json_error(array('message' => 'Registration failed.'));
        }

        update_post_meta($post_id, '_license_plate', $license_plate);
        update_post_meta($post_id, '_vehicle_model', $vehicle_model);
        update_post_meta($post_id, '_driver_name', $driver_name);
        update_post_meta($post_id, '_driver_license', $driver_license);
        update_post_meta($post_id, '_driver_nid', $driver_nid);
        update_post_meta($post_id, '_ambulance_type', $ambulance_type);
        update_post_meta($post_id, '_base_price', $base_price);
        update_post_meta($post_id, '_ambulance_status', 'pending');
        update_post_meta($post_id, '_email', $email);
        update_post_meta($post_id, '_phone', $phone);

        wp_send_json_success(array('message' => 'Ambulance provider registration submitted! We will review and approve.'));
    }

    // ---- Admin AJAX ----

    public static function update_booking_status() {
        check_ajax_referer('ecare_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized.'));
        }

        $booking_id = intval($_POST['booking_id'] ?? 0);
        $status     = sanitize_text_field($_POST['status'] ?? '');

        if (!$booking_id || !$status) {
            wp_send_json_error(array('message' => 'Invalid parameters.'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ecare_bookings';
        $wpdb->update($table, array('status' => $status), array('id' => $booking_id));

        wp_send_json_success(array('message' => 'Status updated successfully.'));
    }

    public static function update_provider_status() {
        check_ajax_referer('ecare_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized.'));
        }

        $provider_id = intval($_POST['provider_id'] ?? 0);
        $status      = sanitize_text_field($_POST['status'] ?? '');

        if (!$provider_id || !$status) {
            wp_send_json_error(array('message' => 'Invalid parameters.'));
        }

        update_post_meta($provider_id, '_provider_status', $status);

        wp_send_json_success(array('message' => 'Provider status updated.'));
    }

    // ---- Helpers ----

    private static function handle_file_upload($file) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $upload = wp_handle_upload($file, array('test_form' => false));
        if (isset($upload['url'])) {
            return $upload['url'];
        }
        return false;
    }

    private static function create_woocommerce_order($user_id, $amount, $item_name, $booking_id) {
        if (!class_exists('WooCommerce')) return null;

        $order = wc_create_order(array('customer_id' => $user_id));
        $order->add_product(self::get_or_create_booking_product($item_name, $amount), 1);
        $order->set_total($amount);
        $order->update_meta_data('_ecare_booking_id', $booking_id);
        $order->set_status('pending');
        $order->save();

        return $order;
    }

    private static function get_or_create_booking_product($name, $price) {
        $product_id = wc_get_product_id_by_sku('ecare-booking-' . sanitize_title($name));

        if ($product_id) {
            return wc_get_product($product_id);
        }

        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_sku('ecare-booking-' . sanitize_title($name));
        $product->set_price($price);
        $product->set_regular_price($price);
        $product->set_virtual(true);
        $product->set_catalog_visibility('hidden');
        $product->save();

        return $product;
    }

    private static function find_or_create_product($test_id, $title, $price, $type) {
        $sku = 'ecare-' . $type . '-' . $test_id;
        $product_id = wc_get_product_id_by_sku($sku);

        if ($product_id) {
            return $product_id;
        }

        $product = new WC_Product_Simple();
        $product->set_name($title);
        $product->set_sku($sku);
        $product->set_price($price);
        $product->set_regular_price($price);
        $product->set_virtual(true);
        $product->set_catalog_visibility('hidden');
        $product->save();

        return $product->get_id();
    }
}
