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
            'add_caregiver_type',
            'delete_caregiver',
            'get_caregiver_types',
            'delete_caregiver_type',
            'create_family_member',
            'refresh_nonce',
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

        if (!empty($package)) {
            $matching_terms = array();
            $terms = get_terms(array(
                'taxonomy'   => 'ecare_caregiver_type',
                'hide_empty' => false,
            ));
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    $pkgs = get_term_meta($term->term_id, 'ecare_packages', true);
                    if (is_array($pkgs)) {
                        foreach ($pkgs as $pkg) {
                            if ($pkg['label'] === $package && floatval($pkg['price']) > 0) {
                                $matching_terms[] = $term->name;
                                break;
                            }
                        }
                    }
                }
            }
            
            if (!empty($matching_terms)) {
                if (!empty($type)) {
                    if (in_array($type, $matching_terms)) {
                        $meta_query[] = array('key' => '_provider_type', 'value' => $type);
                    } else {
                        $meta_query[] = array('key' => '_provider_type', 'value' => 'non_existent_type');
                    }
                } else {
                    $meta_query[] = array(
                        'key'     => '_provider_type',
                        'value'   => $matching_terms,
                        'compare' => 'IN'
                    );
                }
            } else {
                $meta_query[] = array('key' => '_provider_type', 'value' => 'non_existent_type');
            }
        } else {
            if (!empty($type)) {
                $meta_query[] = array('key' => '_provider_type', 'value' => $type);
            }
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

        $provider_type = get_post_meta($id, '_provider_type', true);
        if ($provider_type === 'Physiotherapist') {
            $daily_12_fallback = get_option('ecare_default_physio_regular_price', 1500);
            $daily_24_fallback = get_option('ecare_default_physio_premium_price', 2000);
            $monthly_12_fallback = 0;
            $monthly_24_fallback = 0;
        } else {
            $daily_12_fallback = get_option('ecare_default_daily_12_price', 1700);
            $daily_24_fallback = get_option('ecare_default_daily_24_price', 2200);
            $monthly_12_fallback = get_option('ecare_default_monthly_12_price', 30000);
            $monthly_24_fallback = get_option('ecare_default_monthly_24_price', 50000);
        }

        $meta = array(
            'provider_type'    => $provider_type,
            'skills'           => get_post_meta($id, '_skills', true),
            'education'        => get_post_meta($id, '_education', true),
            'experience'       => get_post_meta($id, '_experience', true),
            'daily_12_price'   => get_post_meta($id, '_daily_12_price', true) ?: $daily_12_fallback,
            'daily_24_price'   => get_post_meta($id, '_daily_24_price', true) ?: $daily_24_fallback,
            'monthly_12_price' => get_post_meta($id, '_monthly_12_price', true) ?: $monthly_12_fallback,
            'monthly_24_price' => get_post_meta($id, '_monthly_24_price', true) ?: $monthly_24_fallback,
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
        
        $user_id = get_current_user_id();
        $members = self::get_user_family_members($user_id);
        
        if (empty($members)) {
            if ($user_id) {
                $current_user = wp_get_current_user();
                $user_phone = get_user_meta($user_id, 'billing_phone', true) ?: (get_user_meta($user_id, 'phone_number', true) ?: '');
                $default_active = array(
                    'name'      => $current_user ? ($current_user->display_name ?: $current_user->user_login) : '',
                    'relation'  => 'Self',
                    'phone'     => $user_phone,
                    'email'     => $current_user ? $current_user->user_email : '',
                    'gender'    => '',
                    'dob'       => '',
                    'weight'    => '',
                    'height_ft' => '',
                    'height_in' => ''
                );
            } else {
                $default_active = array(
                    'name'      => '',
                    'relation'  => 'Self',
                    'phone'     => '',
                    'email'     => '',
                    'gender'    => '',
                    'dob'       => '',
                    'weight'    => '',
                    'height_ft' => '',
                    'height_in' => ''
                );
            }
            $active_member = $default_active;
        } else {
            $active_member = $members[0] ?? array();
        }
        
        $age_str = '';
        if (!empty($active_member['dob'])) {
            $birthDate = new DateTime($active_member['dob']);
            $today = new DateTime('today');
            $age = $birthDate->diff($today)->y;
            $age_str = $age . ' Years';
        }
        
        $height_str = '';
        if (!empty($active_member['height_ft'])) {
            $height_str .= $active_member['height_ft'] . ' ft';
            if (!empty($active_member['height_in'])) {
                $height_str .= ' ' . $active_member['height_in'] . ' in';
            }
        } else {
            $height_str = '--';
        }
        
        $weight_str = !empty($active_member['weight']) ? $active_member['weight'] . ' kg' : '--';

        // Right Column Form
        $html .= '<div class="ecare-detail-main">';
        
        // Family Members Box
        $html .= '<div class="ecare-family-card">';
        $html .= '  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">';
        $html .= '    <h4 style="margin:0; font-size:15px; font-weight:700; color:#1E293B;">Family Members</h4>';
        $html .= '    <button type="button" id="ecare-open-create-family-btn" class="button" style="background:#22D3EE; color:#fff; border-color:#22D3EE; font-size:12px; padding:6px 12px; height:auto; line-height:1.2; font-weight:600; border-radius:6px; cursor:pointer;">Create Family Member</button>';
        $html .= '  </div>';
        
        // Active patient details
        $html .= '  <div class="ecare-family-details" data-active-index="0" style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:15px; margin-bottom:12px;">';
        $html .= '    <div class="ecare-family-header" style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">';
        $html .= '      <div class="ecare-family-name-wrap" style="display:flex; align-items:center; gap:8px;">';
        $html .= '        <span class="ecare-family-icon" style="font-size:18px;">👤</span>';
        $html .= '        <span class="ecare-family-name" style="font-weight:700; color:#1E293B; font-size:14px;">' . esc_html($active_member['name'] ?? '') . '</span>';
        $html .= '        <span class="ecare-family-badge" style="background:#E0F2FE; color:#0369A1; padding:2px 8px; border-radius:12px; font-size:11px; font-weight:600;">' . esc_html($active_member['relation'] ?? 'Self') . '</span>';
        $html .= '      </div>';
        $html .= '    </div>';
        
        $html .= '    <div class="ecare-family-meta" style="display:flex; flex-wrap:wrap; gap:15px; font-size:13px; color:#475569;">';
        $html .= '      <div class="ecare-family-meta-item">📞 <span class="ecare-family-phone-val">' . esc_html(($active_member['phone'] ?? '') ?: '--') . '</span></div>';
        $html .= '      <div class="ecare-family-meta-item">✉️ <span class="ecare-family-email-val">' . esc_html(($active_member['email'] ?? '') ?: '--') . '</span></div>';
        $html .= '      <div class="ecare-family-meta-item">📅 <span class="ecare-family-gender-age-val">' . esc_html(($active_member['gender'] ?? '') ?: '--') . ($age_str ? ' | ' . $age_str : '') . '</span></div>';
        $html .= '      <div class="ecare-family-meta-item">📏 Height: <span class="ecare-family-height-val">' . esc_html($height_str) . '</span></div>';
        $html .= '      <div class="ecare-family-meta-item">⚖️ Weight: <span class="ecare-family-weight-val">' . esc_html($weight_str) . '</span></div>';
        $html .= '    </div>';
        $html .= '  </div>';
        
        $html .= '  <div style="display:flex; gap:15px; align-items:center;">';
        $html .= '    <a href="#" class="ecare-change-family-link" style="font-weight:600; color:#22D3EE; font-size:13px; text-decoration:none;">Change Family Member</a>';
        $html .= '    <span style="color:#CBD5E1;">|</span>';
        $html .= '    <a href="#" class="ecare-edit-active-family-link" style="font-weight:600; color:#475569; font-size:13px; text-decoration:none;">Edit Active Details</a>';
        $html .= '  </div>';
        
        // Dynamic list wrapper
        $html .= '  <div class="ecare-family-select-list" style="display:none; margin-top:10px; border:1px solid #E2E8F0; border-radius:8px; background:#fff; overflow:hidden;">';
        
        $idx = 0;
        foreach ($members as $m) {
            $m_age_str = '';
            if (!empty($m['dob'])) {
                $m_birthDate = new DateTime($m['dob']);
                $m_today = new DateTime('today');
                $m_age = $m_birthDate->diff($m_today)->y;
                $m_age_str = $m_age . ' Years';
            }
            $m_height_str = '';
            if (!empty($m['height_ft'])) {
                $m_height_str .= $m['height_ft'] . ' ft';
                if (!empty($m['height_in'])) {
                    $m_height_str .= ' ' . $m['height_in'] . ' in';
                }
            } else {
                $m_height_str = '--';
            }
            $m_weight_str = !empty($m['weight']) ? $m['weight'] . ' kg' : '--';

            $html .= '    <div class="ecare-family-option-row" style="display:flex; align-items:center; justify-content:space-between; padding:10px 15px; border-bottom:1px solid #F1F5F9; cursor:pointer;" data-index="' . $idx . '" data-name="' . esc_attr($m['name'] ?? '') . '" data-relation="' . esc_attr($m['relation'] ?? '') . '" data-phone="' . esc_attr($m['phone'] ?? '') . '" data-email="' . esc_attr($m['email'] ?? '') . '" data-gender="' . esc_attr($m['gender'] ?? '') . '" data-dob="' . esc_attr($m['dob'] ?? '') . '" data-age="' . esc_attr($m_age_str) . '" data-weight="' . esc_attr($m_weight_str) . '" data-height-ft="' . esc_attr($m['height_ft'] ?? '') . '" data-height-in="' . esc_attr($m['height_in'] ?? '') . '" data-height="' . esc_attr($m_height_str) . '">';
            $html .= '      <span style="font-weight:600; font-size:13px; color:#1E293B;">👤 ' . esc_html($m['name'] ?? '') . ' (' . esc_html($m['relation'] ?? '') . ')</span>';
            $html .= '      <div style="display:flex; gap:12px; align-items:center;">';
            $html .= '        <span class="ecare-family-edit-btn" style="font-size:12px; color:#22D3EE; font-weight:600; cursor:pointer;">Edit</span>';
            $html .= '        <span class="ecare-family-select-action" style="font-size:12px; color:#0E9F6E; font-weight:600; cursor:pointer;">Select</span>';
            $html .= '      </div>';
            $html .= '    </div>';
            $idx++;
        }
        $html .= '  </div>'; // End list
        $html .= '</div>'; // End family card

        // Form fields
        $html .= '<form id="ecare-booking-form">';
        $html .= '  <input type="hidden" name="caregiver_id" value="' . esc_attr($id) . '" />';
        
        // Hidden inputs for patient details
        $html .= '  <input type="hidden" name="patient_name" id="ecare-booking-patient-name-val" value="' . esc_attr($active_member['name'] ?? '') . '" />';
        $html .= '  <input type="hidden" name="patient_relation" id="ecare-booking-patient-relation-val" value="' . esc_attr($active_member['relation'] ?? 'Self') . '" />';

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
        $html .= '      <input type="text" name="contact_phone" id="ecare-booking-patient-phone-val" placeholder="+8801XXXXXXXXX" value="' . esc_attr($active_member['phone'] ?? '') . '" required />';
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
        
        $html .= '  <input type="hidden" name="package_type" id="ecare-booking-package-val" value="" />';
        
        $html .= '  </div>'; // End info-grid
        
        // Documents upload box
        $html .= '  <div class="ecare-file-box" style="margin-top:16px;">';
        $html .= '    <label>Prescription / Medical NID</label>';
        $html .= '    <div class="ecare-doc-upload" onclick="document.getElementById(\'ecare-booking-file\').click()">';
        $html .= '      <span class="ecare-doc-upload-icon">📎</span>';
        $html .= '      <p>Click to upload prescription or diagnostic document</p>';
        $html .= '      <span class="file-hint">' . esc_html(sprintf(
            /* translators: 1: extension list, 2: size, e.g. "8 MB" */
            __('%1$s file, up to %2$s', 'ecare-health-services'),
            ECare_Secure_Files::allowed_extensions_label(ECare_Secure_Files::KIND_DOCUMENT),
            size_format(ECare_Secure_Files::max_bytes(ECare_Secure_Files::KIND_DOCUMENT))
        )) . '</span>';
        $html .= '      <input type="file" id="ecare-booking-file" name="booking_file" accept="' . esc_attr(implode(',', array_values(ECare_Secure_Files::allowed_mimes(ECare_Secure_Files::KIND_DOCUMENT)))) . '" style="display:none;" />';
        $html .= '    </div>';
        $html .= '  </div>';
        
        // Book button
        $html .= '  <div style="display:flex;justify-content:flex-end;border-top:1.5px solid var(--border-light);padding-top:18px;margin-top:24px;">';
        $html .= '    <button type="submit" class="ecare-submit-booking-btn">Book Caregiver</button>';
        $html .= '  </div>';
        
        $html .= '</form>';
        $html .= '</div>'; // End detail-main
        
        // Append Create Family Member Modal Container here!
        $html .= '<!-- Create Family Member Modal -->';
        $html .= '<div id="ecare-create-family-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:100000; justify-content:center; align-items:center;">';
        $html .= '  <div style="background:#fff; padding:30px; border-radius:12px; width:95%; max-width:600px; box-shadow:0 10px 25px rgba(0,0,0,0.15); position:relative; box-sizing:border-box;">';
        $html .= '    <h3 style="margin-top:0; font-size:18px; font-weight:700; color:#1E293B; margin-bottom:20px; border-bottom:1px solid #E2E8F0; padding-bottom:10px; text-align:left;">Create Family Member</h3>';
        $html .= '    <button type="button" id="ecare-close-create-family-modal" style="position:absolute; top:20px; right:20px; background:none; border:none; font-size:24px; cursor:pointer; color:#94A3B8; line-height:1;">&times;</button>';
        
        $html .= '    <form id="ecare-create-family-form" style="text-align:left;">';
        $html .= '      <input type="hidden" name="member_index" id="ecare-member-index-val" value="" />';
        $html .= '      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:20px;">';
        
        $html .= '        <div>';
        $html .= '          <label style="display:block; font-weight:600; font-size:12px; color:#475569; margin-bottom:6px; text-align:left;">Name <span style="color:#EF4444;">*</span></label>';
        $html .= '          <input type="text" name="member_name" placeholder="e.g., Jack" style="width:100%; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px; box-sizing:border-box;" required />';
        $html .= '        </div>';
        
        $html .= '        <div>';
        $html .= '          <label style="display:block; font-weight:600; font-size:12px; color:#475569; margin-bottom:6px; text-align:left;">Mobile</label>';
        $html .= '          <input type="text" name="member_phone" placeholder="e.g., +8801XXXXXXXXX" style="width:100%; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px; box-sizing:border-box;" />';
        $html .= '        </div>';
        
        $html .= '        <div>';
        $html .= '          <label style="display:block; font-weight:600; font-size:12px; color:#475569; margin-bottom:6px; text-align:left;">Email</label>';
        $html .= '          <input type="email" name="member_email" placeholder="e.g., abc@gmail.com" style="width:100%; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px; box-sizing:border-box;" />';
        $html .= '        </div>';
        
        $html .= '        <div>';
        $html .= '          <label style="display:block; font-weight:600; font-size:12px; color:#475569; margin-bottom:6px; text-align:left;">Gender <span style="color:#EF4444;">*</span></label>';
        $html .= '          <select name="member_gender" style="width:100%; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px; box-sizing:border-box;" required>';
        $html .= '            <option value="">Select Gender</option>';
        $html .= '            <option value="Male">Male</option>';
        $html .= '            <option value="Female">Female</option>';
        $html .= '            <option value="Other">Other</option>';
        $html .= '          </select>';
        $html .= '        </div>';
        
        $html .= '        <div>';
        $html .= '          <label style="display:block; font-weight:600; font-size:12px; color:#475569; margin-bottom:6px; text-align:left;">Date of Birth <span style="color:#EF4444;">*</span></label>';
        $html .= '          <div style="display:flex; gap:5px;">';
        $html .= '            <select name="member_dob_year" style="flex:1.2; padding:6px; border-radius:6px; border:1px solid #CBD5E1; font-size:12px; box-sizing:border-box;" required>';
        $html .= '              <option value="">Year</option>';
        for($y = intval(date('Y')); $y >= 1900; $y--) {
            $html .= '          <option value="' . $y . '">' . $y . '</option>';
        }
        $html .= '            </select>';
        $html .= '            <select name="member_dob_month" style="flex:1.2; padding:6px; border-radius:6px; border:1px solid #CBD5E1; font-size:12px; box-sizing:border-box;" required>';
        $html .= '              <option value="">Month</option>';
        for($m = 1; $m <= 12; $m++) {
            $html .= '          <option value="' . sprintf('%02d', $m) . '">' . date('M', mktime(0,0,0,$m,1)) . '</option>';
        }
        $html .= '            </select>';
        $html .= '            <select name="member_dob_day" style="flex:1; padding:6px; border-radius:6px; border:1px solid #CBD5E1; font-size:12px; box-sizing:border-box;" required>';
        $html .= '              <option value="">Day</option>';
        for($d = 1; $d <= 31; $d++) {
            $html .= '          <option value="' . sprintf('%02d', $d) . '">' . sprintf('%02d', $d) . '</option>';
        }
        $html .= '            </select>';
        $html .= '          </div>';
        $html .= '        </div>';
        
        $html .= '        <div>';
        $html .= '          <label style="display:block; font-weight:600; font-size:12px; color:#475569; margin-bottom:6px; text-align:left;">Relationship <span style="color:#EF4444;">*</span></label>';
        $html .= '          <select name="member_relation" style="width:100%; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px; box-sizing:border-box;" required>';
        $html .= '            <option value="">Select relationship</option>';
        $html .= '            <option value="Self">Self</option>';
        $html .= '            <option value="Parent">Parent</option>';
        $html .= '            <option value="Spouse">Spouse</option>';
        $html .= '            <option value="Child">Child</option>';
        $html .= '            <option value="Sibling">Sibling</option>';
        $html .= '            <option value="Other">Other</option>';
        $html .= '          </select>';
        $html .= '        </div>';
        
        $html .= '        <div>';
        $html .= '          <label style="display:block; font-weight:600; font-size:12px; color:#475569; margin-bottom:6px; text-align:left;">Weight (kg)</label>';
        $html .= '          <input type="number" name="member_weight" placeholder="e.g., 70" style="width:100%; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px; box-sizing:border-box;" />';
        $html .= '        </div>';
        
        $html .= '        <div>';
        $html .= '          <label style="display:block; font-weight:600; font-size:12px; color:#475569; margin-bottom:6px; text-align:left;">Height (Feet and Inches)</label>';
        $html .= '          <div style="display:flex; gap:10px;">';
        $html .= '            <input type="number" name="member_height_ft" placeholder="Feet" style="flex:1; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px; box-sizing:border-box;" />';
        $html .= '            <input type="number" name="member_height_in" placeholder="Inches" style="flex:1; padding:8px; border-radius:6px; border:1px solid #CBD5E1; font-size:13px; box-sizing:border-box;" />';
        $html .= '          </div>';
        $html .= '        </div>';
        
        $html .= '      </div>';
        
        $html .= '      <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #E2E8F0; padding-top:15px; margin-top:10px;">';
        $html .= '        <button type="submit" style="padding:10px 20px; font-size:14px; background:#22D3EE; border:1px solid #22D3EE; color:#fff; font-weight:600; border-radius:6px; cursor:pointer; line-height:1.2;">Add Member</button>';
        $html .= '      </div>';
        $html .= '    </form>';
        $html .= '  </div>';
        $html .= '</div>';
        
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

        if (!$caregiver_id || !$required_date || !$address || !$contact_phone || !$package_type) {
            wp_send_json_error(array('message' => 'Please fill in all required fields, including duration package.'));
        }

        $price = 0;
        $provider_type = get_post_meta($caregiver_id, '_provider_type', true);
        if ($provider_type) {
            $term = get_term_by('name', $provider_type, 'ecare_caregiver_type');
            if ($term) {
                $pkgs = get_term_meta($term->term_id, 'ecare_packages', true);
                if (is_array($pkgs)) {
                    foreach ($pkgs as $pkg) {
                        if ($pkg['label'] === $package_type) {
                            $price = floatval($pkg['price']);
                            break;
                        }
                    }
                }
            }
        }

        // Prescription / medical document. Stored privately, NOT in the Media
        // Library: attachments are listable through the public REST media
        // endpoint, which would expose every patient's documents.
        $file_urls = '';
        if (!empty($_FILES['booking_file']) && !empty($_FILES['booking_file']['name'])) {
            $stored = ECare_Secure_Files::upload('booking_file');
            if (is_wp_error($stored)) {
                wp_send_json_error(array('message' => 'File upload failed: ' . $stored->get_error_message()));
            }
            // A private reference relative to the uploads directory, never a URL.
            $file_urls = $stored;
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

        $password      = $_POST['password'] ?? '';
        $confirm_pass  = $_POST['confirm_password'] ?? '';

        if (!$full_name || !$email || !$phone || !$provider_type || !$nid_passport) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
        }

        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
        } else {
            if (!get_option('users_can_register')) {
                wp_send_json_error(array('message' => __('User registration is currently disabled on this site.', 'ecare-health-services')));
            }

            if (empty($password) || $password !== $confirm_pass) {
                wp_send_json_error(array('message' => __('Passwords do not match or are empty.', 'ecare-health-services')));
            }

            if (email_exists($email)) {
                wp_send_json_error(array('message' => __('This email address is already registered.', 'ecare-health-services')));
            }
        }

        // Validate every uploaded file BEFORE the user and the provider post are
        // created. A rejected file must not leave a half-finished registration
        // behind, and the visitor must be told why rather than being congratulated
        // on a submission that quietly dropped their document.
        foreach (array(
            'care_photo'      => ECare_Secure_Files::KIND_IMAGE,
            'credentials_doc' => ECare_Secure_Files::KIND_DOCUMENT,
        ) as $upload_field => $upload_kind) {
            if (empty($_FILES[$upload_field]) || empty($_FILES[$upload_field]['name'])) {
                continue;
            }
            $file_check = ECare_Secure_Files::validate_upload($upload_field, $upload_kind);
            if (is_wp_error($file_check)) {
                wp_send_json_error(array('message' => $file_check->get_error_message()));
            }
            $rate_check = ECare_Secure_Files::check_rate_limit();
            if (is_wp_error($rate_check)) {
                wp_send_json_error(array('message' => $rate_check->get_error_message()));
            }
        }

        if (!is_user_logged_in()) {
            // Create standard WordPress subscriber user
            $user_id = wp_insert_user(array(
                'user_login'   => $email,
                'user_email'   => $email,
                'user_pass'    => $password,
                'display_name' => $full_name,
                'first_name'   => $full_name,
                'role'         => 'subscriber'
            ));

            if (is_wp_error($user_id)) {
                wp_send_json_error(array('message' => 'Registration failed: ' . $user_id->get_error_message()));
            }
        }

        $post_id = wp_insert_post(array(
            'post_title'   => $full_name,
            'post_type'    => 'ecare_caregiver',
            'post_status'  => 'publish',
            'post_content' => $education,
            'post_author'  => $user_id,
        ));

        if (is_wp_error($post_id)) {
            wp_send_json_error(array('message' => 'Registration failed. Please try again.'));
        }

        update_post_meta($post_id, '_user_id', $user_id);

        // Handle profile photo using media_handle_upload. This one stays in the
        // Media Library on purpose: it is shown publicly on the caregiver cards.
        $doc_warning = '';
        $photo_id = 0;
        if (!empty($_FILES['care_photo']) && !empty($_FILES['care_photo']['name'])) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $photo_id = media_handle_upload('care_photo', $post_id, array(), array(
                'test_form' => false,
                'mimes'     => ECare_Secure_Files::allowed_mimes(ECare_Secure_Files::KIND_IMAGE),
            ));
            if (is_wp_error($photo_id)) {
                $photo_id = 0;
            }
        }

        if ($photo_id) {
            set_post_thumbnail($post_id, $photo_id);
            update_post_meta($post_id, '_photo_url', wp_get_attachment_url($photo_id));
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

        // Identity / verification document. Private storage, never the Media Library.
        if (!empty($_FILES['credentials_doc']) && !empty($_FILES['credentials_doc']['name'])) {
            $stored = ECare_Secure_Files::upload('credentials_doc');
            if (is_wp_error($stored)) {
                // The account and provider post already exist, so this cannot
                // abort. Say so instead of reporting a clean success.
                $doc_warning = $stored->get_error_message();
            } else {
                update_post_meta($post_id, '_verification_doc', $stored);
            }
        }

        // Synchronize ecare_caregiver_type taxonomy
        $term = get_term_by('name', $provider_type, 'ecare_caregiver_type');
        if ($term) {
            wp_set_post_terms($post_id, array($term->term_id), 'ecare_caregiver_type');
        }

        $message = 'Caregiver Registration submitted successfully! We will review your application and approve it.';
        if (!empty($doc_warning)) {
            $message .= ' However, your verification document was NOT saved (' . $doc_warning . '). Please contact us to submit it again.';
        }

        wp_send_json_success(array('message' => $message));
    }

    /**
     * Get locations for cascading dropdowns from comma-separated post meta values
     */
    public static function get_locations() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $type     = sanitize_text_field($_POST['location_type'] ?? 'division');
        $division = sanitize_text_field($_POST['division'] ?? '');
        $district = sanitize_text_field($_POST['district'] ?? '');
        $area     = sanitize_text_field($_POST['area'] ?? '');

        $args = array(
            'post_type'      => 'ecare_lab_test',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'   => '_test_status',
                    'value' => 'active'
                )
            )
        );

        if ($type === 'district' || $type === 'area' || $type === 'lab_provider') {
            if (!empty($division)) {
                $args['meta_query'][] = array(
                    'key'     => '_division',
                    'value'   => $division,
                    'compare' => 'LIKE'
                );
            }
        }
        if ($type === 'area' || $type === 'lab_provider') {
            if (!empty($district)) {
                $args['meta_query'][] = array(
                    'key'     => '_district',
                    'value'   => $district,
                    'compare' => 'LIKE'
                );
            }
        }
        if ($type === 'lab_provider') {
            if (!empty($area)) {
                $args['meta_query'][] = array(
                    'key'     => '_area',
                    'value'   => $area,
                    'compare' => 'LIKE'
                );
            }
        }

        $posts = get_posts($args);
        $unique_values = array();

        $meta_key = '_' . $type;
        foreach ($posts as $p) {
            $val = get_post_meta($p->ID, $meta_key, true);
            if (!empty($val)) {
                $parts = explode(',', $val);
                foreach ($parts as $part) {
                    $trimmed = trim($part);
                    if ($trimmed !== '') {
                        $unique_values[] = $trimmed;
                    }
                }
            }
        }

        $unique_values = array_unique($unique_values);
        sort($unique_values);

        wp_send_json_success(array('locations' => array_values($unique_values)));
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

        if (!empty($division)) $meta_query[] = array('key' => '_division', 'value' => $division, 'compare' => 'LIKE');
        if (!empty($district)) $meta_query[] = array('key' => '_district', 'value' => $district, 'compare' => 'LIKE');
        if (!empty($area))     $meta_query[] = array('key' => '_area', 'value' => $area, 'compare' => 'LIKE');
        if (!empty($provider)) $meta_query[] = array('key' => '_lab_provider', 'value' => $provider, 'compare' => 'LIKE');

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

        $division     = sanitize_text_field($_POST['division'] ?? '');
        $district     = sanitize_text_field($_POST['district'] ?? '');
        $area         = sanitize_text_field($_POST['area'] ?? '');
        $lab_provider = sanitize_text_field($_POST['lab_provider'] ?? '');

        if (!$division || !$district || !$area || !$lab_provider) {
            wp_send_json_error(array('message' => 'Please select your Division, District, Area, and Lab Provider first.'));
        }

        $price = floatval(get_post_meta($test_id, '_price', true));
        $title = get_the_title($test_id);

        if (!class_exists('WooCommerce')) {
            wp_send_json_error(array('message' => 'WooCommerce is required for checkout.'));
        }

        // Find or create WooCommerce product for this test
        $product_id = self::find_or_create_product($test_id, $title, $price, 'lab_test');

        $cart_item_data = array(
            'ecare_location_data' => array(
                'division'     => $division,
                'district'     => $district,
                'area'         => $area,
                'lab_provider' => $lab_provider,
            )
        );

        $cart_item_key = WC()->cart->add_to_cart($product_id, 1, 0, array(), $cart_item_data);
        if ($cart_item_key) {
            // Store reference to original test
            WC()->session->set('ecare_lab_test_ref_' . $cart_item_key, $test_id);
            wp_send_json_success(array(
                'message' => 'Test added to cart!',
                'checkout_url' => wc_get_checkout_url(),
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

        $driver_name    = sanitize_text_field($_POST['driver_name'] ?? '');
        $provider_name  = sanitize_text_field($_POST['provider_name'] ?? $driver_name);
        $email          = sanitize_email($_POST['email'] ?? '');
        $phone          = sanitize_text_field($_POST['phone'] ?? '');
        $license_plate  = sanitize_text_field($_POST['license_plate'] ?? '');
        $vehicle_model  = sanitize_text_field($_POST['vehicle_model'] ?? '');
        $driver_license = sanitize_text_field($_POST['driver_license'] ?? '');
        $driver_nid     = sanitize_text_field($_POST['driver_nid'] ?? '');
        $ambulance_type = sanitize_text_field($_POST['ambulance_type'] ?? '');
        $base_price     = floatval($_POST['base_price'] ?? 0);

        $password       = $_POST['password'] ?? '';
        $confirm_pass   = $_POST['confirm_password'] ?? '';

        if (!$provider_name || !$email || !$phone || !$license_plate || !$driver_name || !$driver_license) {
            wp_send_json_error(array('message' => 'Please fill in all required fields.'));
        }

        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
        } else {
            if (!get_option('users_can_register')) {
                wp_send_json_error(array('message' => __('User registration is currently disabled on this site.', 'ecare-health-services')));
            }

            if (empty($password) || $password !== $confirm_pass) {
                wp_send_json_error(array('message' => __('Passwords do not match or are empty.', 'ecare-health-services')));
            }

            if (email_exists($email)) {
                wp_send_json_error(array('message' => __('This email address is already registered.', 'ecare-health-services')));
            }
        }

        // Validate every uploaded file BEFORE the user and the provider post are
        // created. A rejected file must not leave a half-finished registration
        // behind, and the visitor must be told why rather than being congratulated
        // on a submission that quietly dropped their document.
        foreach (array('credentials_doc' => ECare_Secure_Files::KIND_DOCUMENT) as $upload_field => $upload_kind) {
            if (empty($_FILES[$upload_field]) || empty($_FILES[$upload_field]['name'])) {
                continue;
            }
            $file_check = ECare_Secure_Files::validate_upload($upload_field, $upload_kind);
            if (is_wp_error($file_check)) {
                wp_send_json_error(array('message' => $file_check->get_error_message()));
            }
            $rate_check = ECare_Secure_Files::check_rate_limit();
            if (is_wp_error($rate_check)) {
                wp_send_json_error(array('message' => $rate_check->get_error_message()));
            }
        }

        if (!is_user_logged_in()) {
            // Create standard WordPress subscriber user
            $user_id = wp_insert_user(array(
                'user_login'   => $email,
                'user_email'   => $email,
                'user_pass'    => $password,
                'display_name' => $provider_name,
                'first_name'   => $provider_name,
                'role'         => 'subscriber'
            ));

            if (is_wp_error($user_id)) {
                wp_send_json_error(array('message' => 'Registration failed: ' . $user_id->get_error_message()));
            }
        }

        $post_id = wp_insert_post(array(
            'post_title'   => $provider_name,
            'post_type'    => 'ecare_ambulance',
            'post_status'  => 'publish',
            'post_content' => '',
            'post_author'  => $user_id,
        ));

        if (is_wp_error($post_id)) {
            wp_send_json_error(array('message' => 'Registration failed.'));
        }

        update_post_meta($post_id, '_user_id', $user_id);

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

        // Identity / verification document. Private storage, never the Media Library.
        $doc_warning = '';
        if (!empty($_FILES['credentials_doc']) && !empty($_FILES['credentials_doc']['name'])) {
            $stored = ECare_Secure_Files::upload('credentials_doc');
            if (is_wp_error($stored)) {
                // The account and provider post already exist, so this cannot
                // abort. Say so instead of reporting a clean success.
                $doc_warning = $stored->get_error_message();
            } else {
                update_post_meta($post_id, '_verification_doc', $stored);
            }
        }

        $message = 'Ambulance provider registration submitted! We will review and approve.';
        if (!empty($doc_warning)) {
            $message .= ' However, your verification document was NOT saved (' . $doc_warning . '). Please contact us to submit it again.';
        }

        wp_send_json_success(array('message' => $message));
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

        $post_type = get_post_type($provider_id);
        if ($post_type === 'ecare_ambulance') {
            update_post_meta($provider_id, '_ambulance_status', $status);
        } else {
            update_post_meta($provider_id, '_provider_status', $status);
        }

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
            $product = wc_get_product($product_id);
            if ($product && (floatval($product->get_price()) !== floatval($price) || $product->get_name() !== $name)) {
                $product->set_name($name);
                $product->set_price($price);
                $product->set_regular_price($price);
                $product->save();
            }
            return $product;
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
            $product = wc_get_product($product_id);
            if ($product && (floatval($product->get_price()) !== floatval($price) || $product->get_name() !== $title)) {
                $product->set_name($title);
                $product->set_price($price);
                $product->set_regular_price($price);
                $product->save();
            }
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

    /**
     * Add or Update Caregiver Type (Term) via AJAX
     */
    public static function add_caregiver_type() {
        check_ajax_referer('ecare_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access.'));
        }

        $type_name = sanitize_text_field($_POST['type_name'] ?? '');
        $image_id = intval($_POST['image_id'] ?? 0);
        $term_id = intval($_POST['term_id'] ?? 0);

        if (empty($type_name)) {
            wp_send_json_error(array('message' => 'Please enter a name for the Caregiver Type.'));
        }

        if ($term_id) {
            $updated = wp_update_term($term_id, 'ecare_caregiver_type', array('name' => $type_name));
            if (is_wp_error($updated)) {
                wp_send_json_error(array('message' => 'Failed to update caregiver type: ' . $updated->get_error_message()));
            }
        } else {
            if (term_exists($type_name, 'ecare_caregiver_type')) {
                wp_send_json_error(array('message' => 'This Caregiver Type already exists.'));
            }

            $inserted = wp_insert_term($type_name, 'ecare_caregiver_type');

            if (is_wp_error($inserted)) {
                wp_send_json_error(array('message' => 'Failed to create caregiver type: ' . $inserted->get_error_message()));
            }

            $term_id = $inserted['term_id'];
        }

        if ($image_id) {
            update_term_meta($term_id, 'caregiver_type_image', $image_id);
        } else {
            if (isset($_POST['remove_image']) && $_POST['remove_image'] == '1') {
                delete_term_meta($term_id, 'caregiver_type_image');
            }
        }

        if (isset($_POST['term_package_labels']) && isset($_POST['term_package_prices'])) {
            $labels = $_POST['term_package_labels'];
            $prices = $_POST['term_package_prices'];
            $packages = array();

            for ($i = 0; $i < count($labels); $i++) {
                $label = sanitize_text_field($labels[$i]);
                $price = floatval($prices[$i]);
                if (!empty($label)) {
                    $packages[] = array(
                        'label' => $label,
                        'price' => $price
                    );
                }
            }
            update_term_meta($term_id, 'ecare_packages', $packages);
        } else {
            update_term_meta($term_id, 'ecare_packages', array());
        }

        $message = $_POST['term_id'] ? 'Caregiver Type updated successfully!' : 'Caregiver Type added successfully!';
        wp_send_json_success(array('message' => $message, 'term_id' => $term_id));
    }

    /**
     * Delete Caregiver via AJAX
     */
    public static function delete_caregiver() {
        check_ajax_referer('ecare_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access.'));
        }

        $id = intval($_POST['provider_id'] ?? 0);
        if (!$id) {
            wp_send_json_error(array('message' => 'Invalid provider ID.'));
        }

        $deleted = wp_delete_post($id, true);

        if ($deleted) {
            wp_send_json_success(array('message' => 'Provider deleted successfully!'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete provider.'));
        }
    }

    /**
     * Get all Caregiver Types via AJAX
     */
    public static function get_caregiver_types() {
        check_ajax_referer('ecare_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access.'));
        }

        $terms = get_terms(array(
            'taxonomy'   => 'ecare_caregiver_type',
            'hide_empty' => false,
        ));

        $types = array();
        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {
                $image_id = get_term_meta($term->term_id, 'caregiver_type_image', true);
                $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
                
                // Fallback to default bundled image if empty
                if (!$image_url) {
                    $slug = sanitize_title($term->name);
                    $default_file = ECARE_PLUGIN_DIR . 'assets/images/' . $slug . '.jpg';
                    if (file_exists($default_file)) {
                        $image_url = ECARE_PLUGIN_URL . 'assets/images/' . $slug . '.jpg';
                    }
                }

                $packages = get_term_meta($term->term_id, 'ecare_packages', true);
                if (empty($packages) || !is_array($packages)) {
                    if ($term->name === 'Physiotherapist') {
                        $packages = array(
                            array('label' => 'Daily Regular (1 Hour)', 'price' => get_option('ecare_default_physio_regular_price', 1500)),
                            array('label' => 'Daily Premium (1 Hour)', 'price' => get_option('ecare_default_physio_premium_price', 2000)),
                        );
                    } else {
                        $packages = array(
                            array('label' => 'Daily (12 Hours)', 'price' => get_option('ecare_default_daily_12_price', 1700)),
                            array('label' => 'Daily (24 Hours)', 'price' => get_option('ecare_default_daily_24_price', 2200)),
                            array('label' => 'Monthly (12 Hours)', 'price' => get_option('ecare_default_monthly_12_price', 30000)),
                            array('label' => 'Monthly (24 Hours)', 'price' => get_option('ecare_default_monthly_24_price', 50000)),
                        );
                    }
                    update_term_meta($term->term_id, 'ecare_packages', $packages);
                }

                $types[] = array(
                    'term_id'   => $term->term_id,
                    'name'      => $term->name,
                    'image_id'  => $image_id,
                    'image_url' => $image_url,
                    'packages'  => $packages,
                );
            }
        }

        wp_send_json_success(array('types' => $types));
    }

    /**
     * Delete Caregiver Type via AJAX
     */
    public static function delete_caregiver_type() {
        check_ajax_referer('ecare_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access.'));
        }

        $term_id = intval($_POST['term_id'] ?? 0);
        if (!$term_id) {
            wp_send_json_error(array('message' => 'Invalid term ID.'));
        }

        $deleted = wp_delete_term($term_id, 'ecare_caregiver_type');

        if (is_wp_error($deleted)) {
            wp_send_json_error(array('message' => 'Failed to delete caregiver type: ' . $deleted->get_error_message()));
        } else {
            wp_send_json_success(array('message' => 'Caregiver Type deleted successfully!'));
        }
    }

    /**
     * Get user's family members list
     */
    private static function get_user_family_members($user_id) {
        $members = array();
        if ($user_id) {
            $members = get_user_meta($user_id, 'ecare_family_members', true);
        } else {
            if (!session_id() && !headers_sent()) {
                session_start();
            }
            if (isset($_SESSION['ecare_family_members'])) {
                $members = $_SESSION['ecare_family_members'];
            }
        }

        return is_array($members) ? $members : array();
    }

    /**
     * Create/Edit family member via AJAX
     */
    public static function create_family_member() {
        check_ajax_referer('ecare_nonce', 'nonce');

        $user_id = get_current_user_id();
        $index     = isset($_POST['member_index']) && $_POST['member_index'] !== '' ? intval($_POST['member_index']) : -1;
        $name      = sanitize_text_field($_POST['member_name'] ?? '');
        $phone     = sanitize_text_field($_POST['member_phone'] ?? '');
        $email     = sanitize_email($_POST['member_email'] ?? '');
        $gender    = sanitize_text_field($_POST['member_gender'] ?? '');
        $relation  = sanitize_text_field($_POST['member_relation'] ?? '');
        $weight    = sanitize_text_field($_POST['member_weight'] ?? '');
        $height_ft = sanitize_text_field($_POST['member_height_ft'] ?? '');
        $height_in = sanitize_text_field($_POST['member_height_in'] ?? '');

        $dob_year  = sanitize_text_field($_POST['member_dob_year'] ?? '');
        $dob_month = sanitize_text_field($_POST['member_dob_month'] ?? '');
        $dob_day   = sanitize_text_field($_POST['member_dob_day'] ?? '');
        $dob       = '';
        if ($dob_year && $dob_month && $dob_day) {
            $dob = $dob_year . '-' . $dob_month . '-' . $dob_day;
        }

        if (empty($name) || empty($gender) || empty($relation) || empty($dob)) {
            wp_send_json_error(array('message' => 'Please fill in all required fields (Name, Gender, Relationship, Date of Birth).'));
        }

        $new_member = array(
            'name'      => $name,
            'relation'  => $relation,
            'phone'     => $phone,
            'email'     => $email,
            'gender'    => $gender,
            'dob'       => $dob,
            'weight'    => $weight,
            'height_ft' => $height_ft,
            'height_in' => $height_in
        );

        $members = self::get_user_family_members($user_id);
        
        $target_index = -1;
        if ($index >= 0 && isset($members[$index])) {
            $members[$index] = $new_member;
            $target_index = $index;
        } else {
            if ($relation === 'Self') {
                foreach ($members as $key => $m) {
                    if ($m['relation'] === 'Self') {
                        unset($members[$key]);
                    }
                }
                $members = array_values($members);
            }
            $members[] = $new_member;
            $target_index = count($members) - 1;
        }

        if ($user_id) {
            update_user_meta($user_id, 'ecare_family_members', $members);
        } else {
            if (!session_id() && !headers_sent()) {
                session_start();
            }
            $_SESSION['ecare_family_members'] = $members;
        }

        wp_send_json_success(array(
            'message' => 'Family member details updated successfully!',
            'member'  => $new_member,
            'index'   => $target_index
        ));
    }

    /**
     * Refresh ecare_nonce dynamically (useful for LiteSpeed / cached pages)
     */
    public static function refresh_nonce() {
        do_action('litespeed_nonce', 'ecare_nonce');
        wp_send_json_success(array(
            'nonce' => wp_create_nonce('ecare_nonce')
        ));
    }
}
