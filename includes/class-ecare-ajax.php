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

    /**
     * Filter Caregivers list by Type and Package
     */
    public static function filter_caregivers() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $type    = sanitize_text_field($_POST['provider_type'] ?? '');
        $package = sanitize_text_field($_POST['package_type'] ?? '');

        $meta_query = array('relation' => 'AND');
        $meta_query[] = array('key' => '_provider_status', 'value' => 'approved');

        if (!empty($type)) {
            $meta_query[] = array('key' => '_provider_type', 'value' => $type);
        }

        // Filter caregivers who have a price set for the requested package
        if (!empty($package)) {
            $price_key = '_' . $package . '_price';
            $meta_query[] = array(
                'key'     => $price_key,
                'value'   => 0,
                'compare' => '>',
                'type'    => 'NUMERIC'
            );
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
                $photo_url = get_post_meta($id, '_photo_url', true);
                $excerpt = get_the_excerpt() ?: 'Professional and dedicated caregiver with exceptional reviews.';

                $html .= '<div class="ecare-cg-card" data-id="' . esc_attr($id) . '">';
                $html .= '  <div class="ecare-cg-card-header">';

                // Image
                $html .= '    <div class="ecare-cg-card-image">';
                if (has_post_thumbnail($id)) {
                    $html .= get_the_post_thumbnail($id, 'thumbnail', array(
                        'style' => 'width:100%;height:100%;object-fit:cover;display:block;',
                    ));
                } elseif (!empty($photo_url)) {
                    $html .= '<img src="' . esc_url($photo_url) . '" alt="' . esc_attr(get_the_title()) . '" style="width:100%;height:100%;object-fit:cover;display:block;" />';
                } else {
                    $html .= '<span style="font-size:40px;line-height:1;">👤</span>';
                }
                $html .= '    </div>';

                // Info: name + bio
                $html .= '    <div class="ecare-cg-card-info">';
                $html .= '      <h3 title="' . esc_attr(get_the_title()) . '">' . esc_html(get_the_title()) . '</h3>';
                $html .= '      <p class="ecare-cg-card-bio">' . esc_html($excerpt) . '</p>';
                $html .= '    </div>';

                $html .= '  </div>';

                // Book Now button full width
                $html .= '  <a href="#" class="ecare-cg-card-btn ecare-view-details" data-id="' . esc_attr($id) . '">' . esc_html__('Book Now', 'ecare-health-services') . '</a>';
                $html .= '</div>';
            }
            wp_reset_postdata();
        } else {
            $html .= '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--text-muted);">';
            $html .= '  <p class="ecare-no-results">No caregivers found matching your criteria.</p>';
            $html .= '</div>';
        }

        wp_send_json_success(array('html' => $html, 'count' => $query->found_posts));
    }

    /**
     * Get caregiver details for two-column details & booking modal
     */
    public static function get_caregiver_details() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $id = intval($_POST['caregiver_id'] ?? 0);
        if (!$id) wp_send_json_error(array('message' => 'Invalid caregiver.'));

        $post = get_post($id);
        if (!$post || $post->post_type !== 'ecare_caregiver') {
            wp_send_json_error(array('message' => 'Caregiver not found.'));
        }

        $meta = array(
            'provider_type'    => get_post_meta($id, '_provider_type', true),
            'skills'           => get_post_meta($id, '_skills', true),
            'education'        => get_post_meta($id, '_education', true),
            'experience'       => get_post_meta($id, '_experience', true),
            'daily_12_price'   => get_post_meta($id, '_daily_12_price', true),
            'daily_24_price'   => get_post_meta($id, '_daily_24_price', true),
            'monthly_12_price' => get_post_meta($id, '_monthly_12_price', true),
            'monthly_24_price' => get_post_meta($id, '_monthly_24_price', true),
            'photo_url'        => get_post_meta($id, '_photo_url', true),
        );

        $html = '<div class="ecare-detail-grid">';

        // Left Sidebar Profile Card
        $html .= '<div class="ecare-detail-sidebar">';
        if (has_post_thumbnail($id)) {
            $html .= get_the_post_thumbnail($id, 'thumbnail', array('class' => 'ecare-profile-img'));
        } elseif (!empty($meta['photo_url'])) {
            $html .= '<img src="' . esc_url($meta['photo_url']) . '" class="ecare-profile-img" alt="' . esc_attr(get_the_title($id)) . '" />';
        } else {
            $html .= '<div class="ecare-profile-img" style="background:#E2E8F0;display:flex;align-items:center;justify-content:center;font-size:48px;">👤</div>';
        }
        $html .= '<h3>' . get_the_title($id) . '</h3>';
        $html .= '<span class="ecare-provider-badge">' . esc_html($meta['provider_type']) . '</span>';
        
        $html .= '<div class="ecare-sidebar-block">';
        $html .= '  <div class="ecare-sidebar-label">Education</div>';
        $html .= '  <p>' . nl2br(esc_html($meta['education'] ?: 'Completed professional caregiver qualifications.')) . '</p>';
        $html .= '</div>';
        
        $html .= '<div class="ecare-sidebar-block">';
        $html .= '  <div class="ecare-sidebar-label">Biography</div>';
        $bio = $post->post_content ?: 'Highly experienced care provider offering support, companionship, and clinical checkups for patients at home.';
        $html .= '  <p>' . nl2br(esc_html($bio)) . '</p>';
        $html .= '</div>';
        
        $html .= '<div class="ecare-sidebar-block">';
        $html .= '  <div class="ecare-sidebar-label">Special Skills</div>';
        $html .= '  <p>' . nl2br(esc_html($meta['skills'] ?: 'Patient care, medication management, basic clinical checks.')) . '</p>';
        $html .= '</div>';
        $html .= '</div>'; // End detail-sidebar

        // Right Column Form
        $html .= '<div class="ecare-detail-main">';
        
        // Family Members Box
        $html .= '<div class="ecare-family-card">';
        $html .= '  <h4>Family Members</h4>';
        $html .= '  <div class="ecare-family-details">';
        $html .= '    <div class="ecare-family-header">';
        $html .= '      <div class="ecare-family-name-wrap">';
        $html .= '        <span class="ecare-family-icon">👤</span>';
        $html .= '        <span class="ecare-family-name">KH01 ER</span>';
        $html .= '        <span class="ecare-family-badge">Self</span>';
        $html .= '      </div>';
        $html .= '    </div>';
        $html .= '    <div class="ecare-family-meta">';
        $html .= '      <div class="ecare-family-meta-item">📞 <span class="ecare-family-phone-val">+8801700000000</span></div>';
        $html .= '      <div class="ecare-family-meta-item">✉️ <span class="ecare-family-email-val">patient@example.com</span></div>';
        $html .= '      <div class="ecare-family-meta-item">📅 <span class="ecare-family-gender-age-val">Male | 32 Years</span></div>';
        $html .= '    </div>';
        $html .= '  </div>';
        $html .= '  <a href="#" class="ecare-change-family-link">Change Family Member</a>';
        
        // Dynamic picker list
        $html .= '  <div class="ecare-family-select-list" style="display:none;">';
        
        $members = array(
            array('name' => 'KH01 ER', 'relation' => 'Self', 'phone' => '+8801700000000', 'email' => 'patient@example.com', 'gender' => 'Male', 'age' => '32 Years'),
            array('name' => 'Mst. Rabeya Begum', 'relation' => 'Parent', 'phone' => '+8801811111111', 'email' => 'mother@example.com', 'gender' => 'Female', 'age' => '65 Years'),
            array('name' => 'Md. Abul Kashem', 'relation' => 'Parent', 'phone' => '+8801922222222', 'email' => 'father@example.com', 'gender' => 'Male', 'age' => '70 Years'),
            array('name' => 'Nusrat Jahan', 'relation' => 'Spouse', 'phone' => '+8801533333333', 'email' => 'spouse@example.com', 'gender' => 'Female', 'age' => '28 Years'),
        );
        
        foreach ($members as $m) {
            $html .= '    <div class="ecare-family-option-row" data-name="' . esc_attr($m['name']) . '" data-relation="' . esc_attr($m['relation']) . '" data-phone="' . esc_attr($m['phone']) . '" data-email="' . esc_attr($m['email']) . '" data-gender="' . esc_attr($m['gender']) . '" data-age="' . esc_attr($m['age']) . '">';
            $html .= '      <span>👥 ' . esc_html($m['name']) . ' (' . esc_html($m['relation']) . ')</span>';
            $html .= '      <span style="font-size:11px;color:var(--brand-teal);">Select</span>';
            $html .= '    </div>';
        }
        $html .= '  </div>'; // End list
        $html .= '</div>'; // End family card

        // Form fields
        $html .= '<form id="ecare-booking-form">';
        $html .= '  <input type="hidden" name="caregiver_id" value="' . esc_attr($id) . '" />';
        
        $html .= '  <div class="ecare-info-grid">';
        
        // Patient Type Dropdown
        $html .= '    <div class="ecare-form-field">';
        $html .= '      <label>Patient Type <span>*</span></label>';
        $html .= '      <select name="patient_type" required>';
        $html .= '        <option value="Adult">Adult</option>';
        $html .= '        <option value="Child">Child</option>';
        $html .= '        <option value="Elderly" selected>Elderly</option>';
        $html .= '        <option value="Infant">Infant</option>';
        $html .= '      </select>';
        $html .= '    </div>';
        
        // Required Date Picker
        $html .= '    <div class="ecare-form-field">';
        $html .= '      <label>Service Required Date <span>*</span></label>';
        $html .= '      <input type="date" name="required_date" value="' . date('Y-m-d', strtotime('+1 day')) . '" required />';
        $html .= '    </div>';
        
        // Diaper Change Needed Dropdown
        $html .= '    <div class="ecare-form-field">';
        $html .= '      <label>Diaper Change Required <span>*</span></label>';
        $html .= '      <select name="diaper_change" required>';
        $html .= '        <option value="0">No</option>';
        $html .= '        <option value="1">Yes</option>';
        $html .= '      </select>';
        $html .= '    </div>';
        
        // Contact Number
        $html .= '    <div class="ecare-form-field">';
        $html .= '      <label>Contact Number <span>*</span></label>';
        $html .= '      <input type="text" name="contact_phone" placeholder="+8801XXXXXXXXX" value="+8801700000000" required />';
        $html .= '    </div>';
        
        // Address Textarea
        $html .= '    <div class="ecare-form-field full-width">';
        $html .= '      <label>Full Address <span>*</span></label>';
        $html .= '      <textarea name="address" placeholder="Patient residential address" required>Dhaka, Bangladesh</textarea>';
        $html .= '    </div>';
        
        // Disease Textarea
        $html .= '    <div class="ecare-form-field full-width">';
        $html .= '      <label>Disease Description / Symptoms</label>';
        $html .= '      <textarea name="disease" placeholder="Describe symptoms or diseases if any..."></textarea>';
        $html .= '    </div>';
        
        // Package Duration Selector inside Modal
        $html .= '    <div class="ecare-form-field full-width" style="margin-top:10px;">';
        $html .= '      <label>Select Duration Package</label>';
        $html .= '      <div class="ecare-package-tabs">';
        
        $packages = array(
            'daily_12'   => array('label' => 'Daily (12 Hours)', 'price' => $meta['daily_12_price']),
            'daily_24'   => array('label' => 'Daily (24 Hours)', 'price' => $meta['daily_24_price']),
            'monthly_12' => array('label' => 'Monthly (12 Hours)', 'price' => $meta['monthly_12_price']),
            'monthly_24' => array('label' => 'Monthly (24 Hours)', 'price' => $meta['monthly_24_price']),
        );
        
        $first_key = '';
        $first_price = 0;
        foreach ($packages as $key => $pkg) {
            if ($pkg['price']) {
                $is_active = empty($first_key) ? 'active' : '';
                if ($is_active) {
                    $first_key = $key;
                    $first_price = $pkg['price'];
                }
                
                $html .= '      <div class="ecare-package-tab ' . $is_active . '" data-package="' . esc_attr($key) . '">';
                $html .= '        <input type="radio" name="package_type" value="' . esc_attr($key) . '" data-price="' . esc_attr($pkg['price']) . '" ' . checked($is_active, 'active', false) . ' style="display:none;" />';
                $html .= '        <span class="ecare-pkg-label">' . esc_html($pkg['label']) . '</span>';
                $html .= '        <span class="ecare-pkg-price">৳ ' . esc_html(number_format($pkg['price'])) . '</span>';
                $html .= '      </div>';
            }
        }
        $html .= '      </div>'; // End package row
        $html .= '    </div>'; // End field
        
        $html .= '  </div>'; // End info-grid
        
        // Documents upload box
        $html .= '  <div class="ecare-file-box" style="margin-top:16px;">';
        $html .= '    <label>Prescription / Medical NID</label>';
        $html .= '    <div class="ecare-doc-upload" onclick="document.getElementById(\'ecare-booking-file\').click()">';
        $html .= '      <span class="ecare-doc-upload-icon">📎</span>';
        $html .= '      <p>Click to upload prescription or diagnostic document</p>';
        $html .= '      <span class="file-hint">PDF or Image file (Max 2MB)</span>';
        $html .= '      <input type="file" id="ecare-booking-file" name="booking_file" style="display:none;" />';
        $html .= '    </div>';
        $html .= '  </div>';
        
        // Cost details and Book button
        $html .= '  <div style="display:flex;align-items:center;justify-content:space-between;border-top:1.5px solid var(--border-light);padding-top:18px;margin-top:24px;">';
        $html .= '    <div>';
        $html .= '      <span style="font-size:12px;color:var(--text-muted);font-weight:600;display:block;">Estimated Pricing</span>';
        $html .= '      <span style="font-size:20px;font-weight:800;color:var(--brand-teal);">৳ <span id="ecare-price-display">' . esc_html(number_format($first_price)) . '</span></span>';
        $html .= '    </div>';
        $html .= '    <button type="submit" class="ecare-submit-booking-btn">Book Caregiver</button>';
        $html .= '  </div>';
        
        $html .= '</form>';
        $html .= '</div>'; // End detail-main
        $html .= '</div>'; // End detail-grid

        wp_send_json_success(array('html' => $html));
    }

    /**
     * Submit Caregiver Booking
     */
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
        
        // Retrieve selected family member info
        $family_member = sanitize_text_field($_POST['family_member_name'] ?? '');
        $relation = sanitize_text_field($_POST['family_member_relation'] ?? '');
        
        $patient_booking_name = $patient_name ?: $family_member;

        if (!$caregiver_id || !$required_date || !$address || !$contact_phone) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
        }

        $price = 0;
        $price_map = array(
            'daily_12'   => '_daily_12_price',
            'daily_24'   => '_daily_24_price',
            'monthly_12' => '_monthly_12_price',
            'monthly_24' => '_monthly_24_price',
        );
        if (isset($price_map[$package_type])) {
            $price = floatval(get_post_meta($caregiver_id, $price_map[$package_type], true));
        }

        // File upload
        $file_urls = '';
        if (!empty($_FILES['booking_file']) && !empty($_FILES['booking_file']['name'])) {
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
            'patient_name'   => $patient_booking_name,
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

            // Generate WooCommerce order to process payment
            $patient_data = array(
                'name'    => $patient_booking_name,
                'phone'   => $contact_phone,
                'address' => $address
            );
            
            $order = self::create_woocommerce_order($user_id, $price, 'Caregiver Booking - ' . get_the_title($caregiver_id), $booking_id, $patient_data);
            if ($order) {
                $wpdb->update($table, array('order_id' => $order->get_id()), array('id' => $booking_id));
                wp_send_json_success(array(
                    'message' => 'Booking submitted successfully! Redirecting to checkout...',
                    'booking_id' => $booking_id,
                    'checkout_url' => $order->get_checkout_payment_url()
                ));
            }

            wp_send_json_success(array('message' => 'Booking submitted successfully! We will contact you shortly.', 'booking_id' => $booking_id));
        } else {
            wp_send_json_error(array('message' => 'Failed to submit booking. Please try again.'));
        }
    }

    /**
     * Caregiver provider registration form handler
     */
    public static function submit_caregiver_registration() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $full_name     = sanitize_text_field($_POST['full_name'] ?? '');
        $email         = sanitize_email($_POST['email'] ?? '');
        $phone         = sanitize_text_field($_POST['phone'] ?? '');
        $provider_type = sanitize_text_field($_POST['provider_type'] ?? '');
        $experience    = sanitize_text_field($_POST['experience'] ?? '');
        $category      = sanitize_text_field($_POST['category'] ?? ''); // Workplace
        $skills        = sanitize_textarea_field($_POST['skills'] ?? '');
        $education     = sanitize_textarea_field($_POST['education'] ?? '');
        $nid_passport  = sanitize_text_field($_POST['nid_passport'] ?? '');
        $bank_name     = sanitize_text_field($_POST['bank_name'] ?? '');
        $bank_acc_name = sanitize_text_field($_POST['bank_account_name'] ?? '');
        $bank_account  = sanitize_text_field($_POST['bank_account'] ?? '');
        
        $daily_12      = floatval($_POST['daily_12_price'] ?? 0);
        $daily_24      = floatval($_POST['daily_24_price'] ?? 0);
        $monthly_12    = floatval($_POST['monthly_12_price'] ?? 0);
        $monthly_24    = floatval($_POST['monthly_24_price'] ?? 0);

        if (!$full_name || !$email || !$phone || !$provider_type || !$nid_passport) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
        }

        // Handle profile photo
        $photo_id = 0;
        if (!empty($_FILES['care_photo']) && !empty($_FILES['care_photo']['name'])) {
            $photo_url = self::handle_file_upload($_FILES['care_photo']);
            if ($photo_url) {
                // Insert as attachment to set as featured image
                $photo_id = self::insert_attachment_from_url($photo_url);
            }
        }

        $post_id = wp_insert_post(array(
            'post_title'   => $full_name,
            'post_type'    => 'ecare_caregiver',
            'post_status'  => 'publish',
            'post_content' => $education,
        ));

        if (is_wp_error($post_id)) {
            wp_send_json_error(array('message' => 'Registration failed. Please try again.'));
        }

        if ($photo_id) {
            set_post_thumbnail($post_id, $photo_id);
        }

        update_post_meta($post_id, '_provider_type', $provider_type);
        update_post_meta($post_id, '_experience', $experience);
        update_post_meta($post_id, '_category', $category);
        update_post_meta($post_id, '_skills', $skills);
        update_post_meta($post_id, '_education', $education);
        update_post_meta($post_id, '_nid_passport', $nid_passport);
        update_post_meta($post_id, '_bank_name', $bank_name);
        update_post_meta($post_id, '_bank_account_name', $bank_acc_name);
        update_post_meta($post_id, '_bank_account', $bank_account);
        
        update_post_meta($post_id, '_daily_12_price', $daily_12);
        update_post_meta($post_id, '_daily_24_price', $daily_24);
        update_post_meta($post_id, '_monthly_12_price', $monthly_12);
        update_post_meta($post_id, '_monthly_24_price', $monthly_24);
        
        update_post_meta($post_id, '_provider_status', 'pending');
        update_post_meta($post_id, '_email', $email);
        update_post_meta($post_id, '_phone', $phone);

        // Upload verification document
        if (!empty($_FILES['credentials_doc']) && !empty($_FILES['credentials_doc']['name'])) {
            $doc_url = self::handle_file_upload($_FILES['credentials_doc']);
            if ($doc_url) {
                update_post_meta($post_id, '_verification_doc', esc_url($doc_url));
            }
        }

        wp_send_json_success(array('message' => 'Caregiver Registration submitted successfully! We will review your application and approve it.'));
    }

    /**
     * Get locations for cascading dropdowns
     */
    public static function get_locations() {
        check_ajax_referer('ecare_nonce', 'nonce');

        global $wpdb;
        $table = $wpdb->prefix . 'ecare_locations';

        $type     = sanitize_text_field($_POST['location_type'] ?? 'division');
        $parent   = intval($_POST['parent_id'] ?? 0);

        $where = $wpdb->prepare('location_type = %s', $type);
        if ($parent) {
            $where .= $wpdb->prepare(' AND parent_id = %d', $parent);
        } else {
            $where .= ' AND parent_id IS NULL';
        }

        $results = $wpdb->get_results("SELECT id, name FROM {$table} WHERE {$where} ORDER BY name ASC");

        wp_send_json_success(array('locations' => $results));
    }

    /**
     * Filter Lab Tests by hierarchical dropdown locations and search keyword
     */
    public static function filter_lab_tests() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $division  = sanitize_text_field($_POST['division'] ?? '');
        $district  = sanitize_text_field($_POST['district'] ?? '');
        $area      = sanitize_text_field($_POST['area'] ?? '');
        $provider  = sanitize_text_field($_POST['lab_provider'] ?? '');
        $search    = sanitize_text_field($_POST['search'] ?? '');

        $meta_query = array('relation' => 'AND');
        $meta_query[] = array('key' => '_test_status', 'value' => 'active');

        // Location hierarchy check
        if (!empty($division)) $meta_query[] = array('key' => '_division', 'value' => $division);
        if (!empty($district)) $meta_query[] = array('key' => '_district', 'value' => $district);
        if (!empty($area))     $meta_query[] = array('key' => '_area', 'value' => $area);
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
                $price = floatval(get_post_meta($id, '_price', true));
                $cat = get_post_meta($id, '_test_category', true);
                $sample = get_post_meta($id, '_sample_type', true);
                $turnaround = intval(get_post_meta($id, '_turnaround_days', true));

                $html .= '<div class="ecare-lab-test-card">';
                $html .= '  <div>';
                $html .= '    <h3 class="ecare-lab-test-title">' . get_the_title() . '</h3>';
                $html .= '    <div class="ecare-lab-test-meta">';
                $html .= '      <span class="ecare-pill ecare-pill-gray">Code: ' . esc_html(get_post_meta($id, '_test_code', true)) . '</span>';
                if ($cat) $html .= '      <span class="ecare-pill ecare-pill-yellow">' . esc_html($cat) . '</span>';
                if ($sample) $html .= '      <span class="ecare-pill ecare-pill-green">' . esc_html($sample) . '</span>';
                $html .= '    </div>';
                if ($turnaround) {
                    $html .= '    <span style="font-size:12px;color:var(--text-muted);display:block;margin-top:6px;">⏱ ' . esc_html($turnaround) . ' ' . _n('day', 'days', $turnaround, 'ecare-health-services') . '</span>';
                }
                $html .= '  </div>';
                $html .= '  <div class="ecare-lab-test-footer">';
                $html .= '    <div class="ecare-lab-test-price">';
                $html .= '      <span class="price-lbl">Starting from</span>';
                $html .= '      <span class="price-val">৳ ' . esc_html(number_format($price, 2)) . '</span>';
                $html .= '    </div>';
                $html .= '    <button class="ecare-add-to-cart-plus-btn" data-id="' . esc_attr($id) . '">+</button>';
                $html .= '  </div>';
                $html .= '</div>';
            }
            wp_reset_postdata();
        } else {
            $html .= '<div style="grid-column:1/-1;text-align:center;padding:40px;color:var(--text-muted);">';
            $html .= '  <p class="ecare-no-results">No lab tests found matching your criteria.</p>';
            $html .= '</div>';
        }

        wp_send_json_success(array('html' => $html, 'count' => $query->found_posts));
    }

    /**
     * Add Lab test product directly to WooCommerce Cart
     */
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
                'cart_count' => WC()->cart->get_cart_contents_count(),
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to add to cart.'));
        }
    }

    /**
     * Submit Ambulance Booking Dispatch Request
     */
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

            // Generate WooCommerce order
            $patient_data = array(
                'name'    => 'Ambulance Patient',
                'phone'   => $contact_phone,
                'address' => $pickup_address
            );
            
            if ($user_id) {
                $user_info = get_userdata($user_id);
                $patient_data['name'] = $user_info->display_name;
            }
            
            $order = self::create_woocommerce_order($user_id, $price, 'Ambulance Booking - ' . $ambulance_type, $booking_id, $patient_data);
            if ($order) {
                $wpdb->update($table, array('order_id' => $order->get_id()), array('id' => $booking_id));
                wp_send_json_success(array(
                    'message' => 'Ambulance dispatch request registered successfully! Redirecting to pay...',
                    'booking_id' => $booking_id,
                    'checkout_url' => $order->get_checkout_payment_url()
                ));
            }

            wp_send_json_success(array('message' => 'Ambulance request submitted! We will dispatch shortly.', 'booking_id' => $booking_id));
        } else {
            wp_send_json_error(array('message' => 'Failed to submit request.'));
        }
    }

    /**
     * Submit Ambulance Provider Registration
     */
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

        if (!$provider_name || !$email || !$phone || !$license_plate || !$driver_name || !$driver_license) {
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

        // File upload
        if (!empty($_FILES['credentials_doc']) && !empty($_FILES['credentials_doc']['name'])) {
            $doc_url = self::handle_file_upload($_FILES['credentials_doc']);
            if ($doc_url) {
                update_post_meta($post_id, '_verification_doc', esc_url($doc_url));
            }
        }

        wp_send_json_success(array('message' => 'Ambulance provider registration submitted! We will review and approve.'));
    }

    // ---- Admin Actions ----

    /**
     * Update Booking status from dashboard table via AJAX
     */
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

    /**
     * Update Provider approval status from dashboard table via AJAX
     */
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
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload = wp_handle_upload($file, array('test_form' => false));
        if (isset($upload['url'])) {
            return $upload['url'];
        }
        return false;
    }

    private static function insert_attachment_from_url($url) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment = array(
            'guid'           => $url,
            'post_mime_type' => wp_check_filetype($url)['type'],
            'post_title'     => sanitize_file_name(basename($url)),
            'post_content'   => '',
            'post_status'    => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $url);
        return $attach_id;
    }

    private static function create_woocommerce_order($user_id, $amount, $item_name, $booking_id, $patient_data = array()) {
        if (!class_exists('WooCommerce')) return null;

        $order = wc_create_order(array('customer_id' => $user_id));
        $order->add_product(self::get_or_create_booking_product($item_name, $amount), 1);
        $order->set_total($amount);
        $order->update_meta_data('_ecare_booking_id', $booking_id);
        
        // Setup billing info
        if (!empty($patient_data)) {
            $billing = array(
                'first_name' => $patient_data['name'] ?? '',
                'phone'      => $patient_data['phone'] ?? '',
                'address_1'  => $patient_data['address'] ?? '',
            );
            $order->set_address($billing, 'billing');
        }
        
        $order->set_status('pending');
        $order->save();

        return $order;
    }

    private static function get_or_create_booking_product($name, $price) {
        $sku = 'ecare-booking-' . sanitize_title($name);
        $product_id = wc_get_product_id_by_sku($sku);

        if ($product_id) {
            return wc_get_product($product_id);
        }

        $product = new WC_Product_Simple();
        $product->set_name($name);
        $product->set_sku($sku);
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
