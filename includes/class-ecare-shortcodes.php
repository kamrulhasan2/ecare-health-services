<?php
defined('ABSPATH') || exit;

class ECare_Shortcodes {

    public static function init() {
        add_shortcode('ecare_caregiver_booking', array(__CLASS__, 'render_caregiver_booking'));
        add_shortcode('ecare_caregiver_registration', array(__CLASS__, 'render_caregiver_registration'));
        add_shortcode('ecare_lab_tests', array(__CLASS__, 'render_lab_tests'));
        add_shortcode('ecare_ambulance_request', array(__CLASS__, 'render_ambulance_request'));
        add_shortcode('ecare_ambulance_registration', array(__CLASS__, 'render_ambulance_registration'));
    }

    /**
     * Caregiver booking catalog and grid shortcode [ecare_caregiver_booking]
     */
    public static function render_caregiver_booking() {
        ob_start();
        ?>
        <div class="ecare-container ecare-caregiver-booking">
            <h2 class="ecare-section-title"><?php _e('Find Your Caregiver', 'ecare-health-services'); ?></h2>
            <p class="ecare-section-subtitle"><?php _e('Select type and package to filter caregivers instantly.', 'ecare-health-services'); ?></p>

            <!-- Caregiver Type Tabs -->
            <label class="ecare-sidebar-label" style="display:block;margin-bottom:10px;"><?php _e('Select Caregiver Type', 'ecare-health-services'); ?></label>
            <div class="ecare-type-tabs" id="ecare-filter-type">
                <?php
                $terms = get_terms(array(
                    'taxonomy'   => 'ecare_caregiver_type',
                    'hide_empty' => false,
                ));

                $default_icons = array(
                    'Nurse'            => '🩺',
                    'Senior Care'      => '👵',
                    'Nanny'            => '👶',
                    'Physiotherapist'  => '🏋️'
                );

                if (!is_wp_error($terms) && !empty($terms)):
                    $index = 0;
                    foreach ($terms as $term):
                        $image_id = get_term_meta($term->term_id, 'caregiver_type_image', true);
                        $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
                        
                        // Check for default photo in plugin assets if custom metadata is empty
                        if (!$image_url) {
                            $slug = sanitize_title($term->name);
                            $default_file = ECARE_PLUGIN_DIR . 'assets/images/' . $slug . '.jpg';
                            if (file_exists($default_file)) {
                                $image_url = ECARE_PLUGIN_URL . 'assets/images/' . $slug . '.jpg';
                            }
                        }
                        
                        $fallback_emoji = $default_icons[$term->name] ?? '👤';
                        $active_class = ($index === 0) ? ' active' : '';
                        ?>
                        <div class="ecare-type-tab<?php echo $active_class; ?>" data-type="<?php echo esc_attr($term->name); ?>">
                            <div class="ecare-tab-icon">
                                <?php if ($image_url): ?>
                                    <img src="<?php echo esc_url($image_url); ?>" alt="<?php echo esc_attr($term->name); ?>" class="ecare-type-icon-img" style="width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;" />
                                <?php else: ?>
                                    <?php echo esc_html($fallback_emoji); ?>
                                <?php endif; ?>
                            </div>
                            <span><?php echo esc_html($term->name); ?></span>
                        </div>
                        <?php
                        $index++;
                    endforeach;
                endif;
                ?>
            </div>

            <!-- Package Selection Cards -->
            <label class="ecare-sidebar-label" style="display:block;margin-bottom:10px;"><?php _e('Select Package', 'ecare-health-services'); ?></label>
            <div class="ecare-package-tabs" id="ecare-filter-package">
                <!-- Loaded dynamically by Javascript based on selected Caregiver Type -->
            </div>

            <!-- Active filter states line matching PDF Page 1 -->
            <div class="ecare-active-filters-info">
                <span class="ecare-results-count">13 caregivers available</span>
                <div class="ecare-filter-badge-row">
                    <!-- Badges injected by JS -->
                </div>
            </div>

            <!-- Caregivers grid -->
            <label class="ecare-sidebar-label" style="display:block;margin-bottom:12px;font-size:13px;font-weight:700;color:var(--text-dark);"><?php _e('Choose Caregiver', 'ecare-health-services'); ?></label>
            <div id="ecare-caregiver-grid" class="ecare-cg-grid">
                <div style="grid-column:1/-1;text-align:center;padding:40px;"><p>Loading caregivers...</p></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Caregiver provider sign up form [ecare_caregiver_registration]
     */
    public static function render_caregiver_registration() {
        ob_start();
        ?>
        <div class="ecare-container">
            <div class="ecare-registration-banner-card">
                <div class="ecare-banner-left">
                    <div class="ecare-banner-icon">💼</div>
                    <div>
                        <h4 class="ecare-banner-title"><?php _e('CARE AGENCIES', 'ecare-health-services'); ?></h4>
                        <p class="ecare-banner-desc"><?php _e('Nursing & Home Care – Certified nurses and caregivers for in-home medical support.', 'ecare-health-services'); ?></p>
                    </div>
                </div>
                <a href="#ecare-caregiver-registration-form" class="ecare-banner-enroll-link"><?php _e('ENROLL NOW →', 'ecare-health-services'); ?></a>
            </div>

            <form id="ecare-caregiver-registration-form" class="ecare-form ecare-form-registration" style="background:#fff;padding:30px;border-radius:12px;border:1px solid var(--border-light);box-shadow:var(--shadow-md);" enctype="multipart/form-data" novalidate>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                    <h2 class="ecare-section-title" style="margin:0;font-size:20px;"><?php _e('E-CARE Service Registration', 'ecare-health-services'); ?></h2>
                    <button type="button" class="ecare-admin-btn-outline" onclick="window.history.back()"><?php _e('← Back to Options', 'ecare-health-services'); ?></button>
                </div>
                <p class="ecare-section-subtitle" style="margin-bottom:30px;"><?php _e('Register as a professional caregiver to start receiving booking orders.', 'ecare-health-services'); ?></p>

                <!-- Section: Personal Information -->
                <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:2px solid var(--brand-teal-light);padding-bottom:6px;margin-bottom:20px;">
                    <h3 style="font-size:14px;font-weight:700;color:var(--admin-green);text-transform:uppercase;margin:0;"><?php _e('Personal Information', 'ecare-health-services'); ?></h3>
                    <!-- Profile Photo upload box -->
                    <label class="ecare-profile-upload">
                        <span class="ecare-profile-upload-copy">
                            <span class="ecare-profile-upload-title"><?php _e('Profile Photo', 'ecare-health-services'); ?></span>
                            <span class="ecare-profile-upload-hint"><?php _e('JPG, PNG up to 2MB', 'ecare-health-services'); ?></span>
                        </span>
                        <span class="ecare-profile-upload-btn">
                            <svg class="ecare-upload-icon" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M12 3v10m0-10 4 4m-4-4-4 4M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span><?php _e('Choose File', 'ecare-health-services'); ?></span>
                        </span>
                        <input type="file" name="care_photo" accept="<?php echo esc_attr(implode(',', array_values(ECare_Secure_Files::allowed_mimes(ECare_Secure_Files::KIND_IMAGE)))); ?>" required />
                    </label>
                </div>

                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('Full Name', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="full_name" placeholder="e.g. John Doe" required />
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Mail Address', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="email" name="email" placeholder="john@example.com" required />
                    </div>
                </div>

                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('Phone Number', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="phone" placeholder="+880" required />
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Date of Birth', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="date" name="dob" required />
                    </div>
                </div>

                <div class="ecare-form-row single">
                    <div class="ecare-form-field">
                        <label><?php _e('Full Address', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="address_line" placeholder="e.g. 123 Health St, Dhaka" required />
                    </div>
                </div>

                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('Password', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="password" name="password" placeholder="••••••••" required />
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Confirm Password', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="password" name="confirm_password" placeholder="••••••••" required />
                    </div>
                </div>

                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('Care Provider Type', 'ecare-health-services'); ?> <span>*</span></label>
                        <select name="provider_type" required>
                            <option value=""><?php _e('Select type', 'ecare-health-services'); ?></option>
                            <option value="Nurse"><?php _e('Nurse', 'ecare-health-services'); ?></option>
                            <option value="Senior Care"><?php _e('Senior Care', 'ecare-health-services'); ?></option>
                            <option value="Nanny"><?php _e('Nanny', 'ecare-health-services'); ?></option>
                            <option value="Physiotherapist"><?php _e('Physiotherapist', 'ecare-health-services'); ?></option>
                        </select>
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Gender Selection', 'ecare-health-services'); ?> <span>*</span></label>
                        <select name="gender" required>
                            <option value="Male"><?php _e('Male', 'ecare-health-services'); ?></option>
                            <option value="Female"><?php _e('Female', 'ecare-health-services'); ?></option>
                            <option value="Other"><?php _e('Other', 'ecare-health-services'); ?></option>
                        </select>
                    </div>
                </div>

                <!-- Section: Professional Credentials -->
                <h3 style="font-size:14px;font-weight:700;color:var(--admin-green);text-transform:uppercase;border-bottom:2px solid var(--brand-teal-light);padding-bottom:6px;margin:30px 0 20px;"><?php _e('Professional Credentials', 'ecare-health-services'); ?></h3>
                
                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('NID Number / Passport', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="nid_passport" placeholder="NID or Passport number" required />
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Years of Experience', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="number" name="experience" min="0" placeholder="e.g. 5" required />
                    </div>
                </div>

                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('Nationality', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="nationality" value="Bangladeshi" required />
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Organization / Clinic', 'ecare-health-services'); ?></label>
                        <input type="text" name="category" placeholder="Current or Previous workplace" />
                    </div>
                </div>

                <div class="ecare-form-row single">
                    <div class="ecare-form-field">
                        <label><?php _e('Skills & Competencies', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="skills" placeholder="e.g. ICU, Wound Care, BLS" required />
                    </div>
                </div>

                <!-- Section: Available Service Packages -->
                <div class="ecare-package-prices-section" style="display:none;">
                    <h3 style="font-size:14px;font-weight:700;color:var(--admin-green);text-transform:uppercase;border-bottom:2px solid var(--brand-teal-light);padding-bottom:6px;margin:30px 0 20px;"><?php _e('Available Service Packages', 'ecare-health-services'); ?></h3>
                    <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;"><?php _e('Set your price rates for service durations (Leave blank if not offered):', 'ecare-health-services'); ?></p>
                    <div class="ecare-form-row">
                        <div class="ecare-form-field">
                            <label><?php _e('Daily 12H Rate (৳)', 'ecare-health-services'); ?></label>
                            <input type="number" step="0.01" name="daily_12_price" placeholder="1700" />
                        </div>
                        <div class="ecare-form-field">
                            <label><?php _e('Daily 24H Rate (৳)', 'ecare-health-services'); ?></label>
                            <input type="number" step="0.01" name="daily_24_price" placeholder="2200" />
                        </div>
                    </div>
                    <div class="ecare-form-row">
                        <div class="ecare-form-field">
                            <label><?php _e('Monthly 12H Rate (৳)', 'ecare-health-services'); ?></label>
                            <input type="number" step="0.01" name="monthly_12_price" placeholder="30000" />
                        </div>
                        <div class="ecare-form-field">
                            <label><?php _e('Monthly 24H Rate (৳)', 'ecare-health-services'); ?></label>
                            <input type="number" step="0.01" name="monthly_24_price" placeholder="50000" />
                        </div>
                    </div>
                </div>

                <!-- Section: Bank Information -->
                <h3 style="font-size:14px;font-weight:700;color:var(--admin-green);text-transform:uppercase;border-bottom:2px solid var(--brand-teal-light);padding-bottom:6px;margin:30px 0 20px;"><?php _e('Bank Information', 'ecare-health-services'); ?></h3>
                
                <div style="display:flex;gap:10px;margin-bottom:20px;">
                    <button type="button" class="ecare-admin-btn-outline ecare-bank-type-btn active" data-target="bank"><?php _e('Bank Account', 'ecare-health-services'); ?></button>
                    <button type="button" class="ecare-admin-btn-outline ecare-bank-type-btn" data-target="mobile"><?php _e('Mobile Banking', 'ecare-health-services'); ?></button>
                </div>

                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label class="bank-field-lbl"><?php _e('Bank Name & Branch', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="bank_name" placeholder="Bank name" required />
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Account Name', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="bank_account_name" placeholder="Name on account" required />
                    </div>
                </div>
                <div class="ecare-form-row">
                    <div class="ecare-form-field" style="grid-column:1/-1;">
                        <label class="bank-acc-lbl"><?php _e('Account Number', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="bank_account" placeholder="Account Number" required />
                    </div>
                </div>

                <!-- Section: Documents & Credentials -->
                <h3 style="font-size:14px;font-weight:700;color:var(--admin-green);text-transform:uppercase;border-bottom:2px solid var(--brand-teal-light);padding-bottom:6px;margin:30px 0 20px;"><?php _e('Documents & Credentials', 'ecare-health-services'); ?></h3>
                
                <div class="ecare-file-box">
                    <div class="ecare-doc-upload" onclick="document.getElementById('reg_credentials').click()">
                        <span class="ecare-doc-upload-icon" aria-hidden="true">
                            <svg class="ecare-upload-icon" viewBox="0 0 24 24">
                                <path d="M8 3.5h6.2L19 8.3V20a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 7 20V5a1.5 1.5 0 0 1 1.5-1.5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
                                <path d="M14.2 3.5V8.3H19" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>
                                <path d="M12 10.5v6m0-6 2.5 2.5M12 10.5 9.5 13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <p><?php _e('Click to Upload Credentials / Certificates', 'ecare-health-services'); ?></p>
                        <span class="file-hint"><?php echo esc_html(sprintf(
                            /* translators: 1: extension list, 2: size, e.g. "8 MB" */
                            __('%1$s, up to %2$s per file', 'ecare-health-services'),
                            ECare_Secure_Files::allowed_extensions_label(ECare_Secure_Files::KIND_DOCUMENT),
                            size_format(ECare_Secure_Files::max_bytes(ECare_Secure_Files::KIND_DOCUMENT))
                        )); ?></span>
                        <input type="file" id="reg_credentials" name="credentials_doc" accept="<?php echo esc_attr(implode(',', array_values(ECare_Secure_Files::allowed_mimes(ECare_Secure_Files::KIND_DOCUMENT)))); ?>" style="display:none;" required />
                    </div>
                </div>

                <div class="ecare-form-row single">
                    <div class="ecare-form-field">
                        <label><?php _e('Professional Bio', 'ecare-health-services'); ?> <span>*</span></label>
                        <textarea name="education" placeholder="Describe your credentials, education, and caregiver philosophy..." required style="min-height:120px;"></textarea>
                    </div>
                </div>

                <div class="ecare-form-row single" style="margin-top:20px;">
                    <button type="submit" class="ecare-admin-btn-green" style="font-size:15px;padding:14px;justify-content:center;"><?php _e('Register Care Provider', 'ecare-health-services'); ?></button>
                </div>
                
                <div class="ecare-form-response"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Lab Tests catalog catalog shortcode [ecare_lab_tests]
     */
    public static function render_lab_tests() {
        ob_start();
        $cart_count = 0;
        if (class_exists('WooCommerce') && WC()->cart) {
            $cart_count = WC()->cart->get_cart_contents_count();
        }
        ?>
        <div class="ecare-container ecare-lab-tests">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;">
                <div>
                    <h2 class="ecare-section-title"><?php _e('Diagnostic Lab Tests', 'ecare-health-services'); ?></h2>
                    <p class="ecare-section-subtitle"><?php _e('Order medical tests from verified laboratories with location filtering.', 'ecare-health-services'); ?></p>
                </div>
                <div class="ecare-catalog-info">
                    <span class="ecare-test-count"><?php _e('Showing 0 tests', 'ecare-health-services'); ?></span>
                    <a href="<?php echo class_exists('WooCommerce') ? esc_url(wc_get_cart_url()) : '#'; ?>" class="ecare-cart-badge">
                        🛒 <?php printf(__('Cart (%d)', 'ecare-health-services'), $cart_count); ?>
                    </a>
                </div>
            </div>

            <!-- Location Sticky Bar -->
            <div class="ecare-location-sticky-bar">
                <div class="ecare-location-select-wrap">
                    <label><?php _e('Select Division', 'ecare-health-services'); ?></label>
                    <select id="ecare-lab-division" class="ecare-select">
                        <option value=""><?php _e('Select Division', 'ecare-health-services'); ?></option>
                    </select>
                </div>
                <div class="ecare-location-select-wrap">
                    <label><?php _e('Select District', 'ecare-health-services'); ?></label>
                    <select id="ecare-lab-district" class="ecare-select" disabled>
                        <option value=""><?php _e('Select District', 'ecare-health-services'); ?></option>
                    </select>
                </div>
                <div class="ecare-location-select-wrap">
                    <label><?php _e('Select Area', 'ecare-health-services'); ?></label>
                    <select id="ecare-lab-area" class="ecare-select" disabled>
                        <option value=""><?php _e('Select Area', 'ecare-health-services'); ?></option>
                    </select>
                </div>
                <div class="ecare-location-select-wrap">
                    <label><?php _e('Select Lab Provider', 'ecare-health-services'); ?></label>
                    <select id="ecare-lab-provider" class="ecare-select" disabled>
                        <option value=""><?php _e('Select Lab Provider', 'ecare-health-services'); ?></option>
                    </select>
                </div>
            </div>

            <!-- Search input bar -->
            <div class="ecare-test-search-wrap">
                <div class="ecare-test-search-input-box">
                    <input type="text" id="ecare-lab-search" placeholder="<?php esc_attr_e('Search for a test...', 'ecare-health-services'); ?>" />
                </div>
            </div>

            <!-- Tests Grid -->
            <div id="ecare-lab-grid" class="ecare-lab-test-grid">
                <div class="ecare-empty-lab-view" style="grid-column:1/-1;">
                    <span style="font-size:48px;display:block;margin-bottom:12px;">🏥</span>
                    <p><?php _e('Select a location and provider to view available tests.', 'ecare-health-services'); ?></p>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Ambulance Dispatch Request shortcode [ecare_ambulance_request]
     */
    public static function render_ambulance_request() {
        ob_start();
        ?>
        <div class="ecare-container ecare-ambulance-page">
            <h2 class="ecare-section-title"><?php _e('Ambulance Booking', 'ecare-health-services'); ?></h2>
            <p class="ecare-section-subtitle"><?php _e('Request instant or scheduled medical ambulance transportation dispatch.', 'ecare-health-services'); ?></p>

            <div class="ecare-ambulance-layout">
                <!-- Form Area -->
                <div class="ecare-amb-form-col">
                    <form id="ecare-ambulance-form" class="ecare-amb-form-card">
                        
                        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:8px;text-transform:uppercase;color:var(--text-muted);"><?php _e('Ambulance Type', 'ecare-health-services'); ?> <span style="color:#EF4444;">*</span></label>
                        <!-- Large selectable type cards -->
                        <div class="ecare-amb-type-grid" id="ecare-ambulance-type">
                            <div class="ecare-amb-type-card active" data-type="Standard" data-price="1500">
                                <span class="ecare-amb-type-icon">🚑</span>
                                <span class="ecare-amb-type-title"><?php _e('Standard (Non-AC)', 'ecare-health-services'); ?></span>
                                <span class="ecare-amb-type-desc"><?php _e('Regular Non-AC Transport', 'ecare-health-services'); ?></span>
                            </div>
                            <div class="ecare-amb-type-card" data-type="ICU" data-price="3000">
                                <span class="ecare-amb-type-icon">🏥</span>
                                <span class="ecare-amb-type-title"><?php _e('ICU (AC)', 'ecare-health-services'); ?></span>
                                <span class="ecare-amb-type-desc"><?php _e('Life Support with AC & Oxygen', 'ecare-health-services'); ?></span>
                            </div>
                            <div class="ecare-amb-type-card" data-type="Freezer" data-price="5000">
                                <span class="ecare-amb-type-icon">❄️</span>
                                <span class="ecare-amb-type-title"><?php _e('Freezer Van', 'ecare-health-services'); ?></span>
                                <span class="ecare-amb-type-desc"><?php _e('Mortuary / Cooler Van', 'ecare-health-services'); ?></span>
                            </div>
                        </div>

                        <!-- Hidden Field for selected type -->
                        <input type="hidden" name="ambulance_type" value="Standard" />

                        <!-- Fields without icons -->
                        <div class="ecare-form-row">
                            <div class="ecare-form-field">
                                <label><?php _e('Pickup Location', 'ecare-health-services'); ?> <span>*</span></label>
                                <input type="text" name="pickup_address" placeholder="<?php esc_attr_e('Your current address', 'ecare-health-services'); ?>" required />
                            </div>
                            <div class="ecare-form-field">
                                <label><?php _e('Destination', 'ecare-health-services'); ?> <span>*</span></label>
                                <input type="text" name="destination" placeholder="<?php esc_attr_e('Hospital / target address', 'ecare-health-services'); ?>" required />
                            </div>
                        </div>

                        <div class="ecare-form-row">
                            <div class="ecare-form-field">
                                <label><?php _e('Dispatch Time', 'ecare-health-services'); ?> <span>*</span></label>
                                <input type="datetime-local" name="schedule_time" required />
                            </div>
                            <div class="ecare-form-field">
                                <label><?php _e('Contact Phone', 'ecare-health-services'); ?> <span>*</span></label>
                                <input type="text" name="contact_phone" placeholder="<?php esc_attr_e('Your contact number', 'ecare-health-services'); ?>" required />
                            </div>
                        </div>

                        <div class="ecare-form-row single">
                            <div class="ecare-form-field">
                                <label><?php _e('Priority Level', 'ecare-health-services'); ?> <span>*</span></label>
                                <select name="priority_level" required>
                                    <option value="Normal"><?php _e('Normal – Scheduled Transport', 'ecare-health-services'); ?></option>
                                    <option value="Emergency"><?php _e('Emergency – Urgent / Life-Threatening', 'ecare-health-services'); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="ecare-form-row single">
                            <div class="ecare-form-field">
                                <label><?php _e('Additional Notes', 'ecare-health-services'); ?></label>
                                <textarea name="notes" rows="3" placeholder="<?php esc_attr_e('e.g. Patient condition, floor number, special requirements...', 'ecare-health-services'); ?>"></textarea>
                            </div>
                        </div>

                        <div class="ecare-form-response"></div>
                    </form>
                </div>

                <!-- Sticky Sidebar -->
                <div class="ecare-amb-sidebar">
                    <!-- Feature Icons -->
                    <div class="ecare-amb-features-box">
                        <div class="ecare-feat-item">
                            <span class="ecare-feat-icon" style="color:#0E9F6E;">⏰</span>
                            <span class="ecare-feat-text"><?php _e('24/7 Always Available', 'ecare-health-services'); ?></span>
                        </div>
                        <div class="ecare-feat-item" style="border-left:1px solid var(--border-light);border-right:1px solid var(--border-light);">
                            <span class="ecare-feat-icon" style="color:#3B82F6;">👨‍⚕️</span>
                            <span class="ecare-feat-text"><?php _e('100% Trained Crew', 'ecare-health-services'); ?></span>
                        </div>
                        <div class="ecare-feat-item">
                            <span class="ecare-feat-icon" style="color:#EF4444;">💨</span>
                            <span class="ecare-feat-text"><?php _e('Oxygen Equipped', 'ecare-health-services'); ?></span>
                        </div>
                    </div>

                    <!-- Summary Card -->
                    <div class="ecare-summary-sticky-card">
                        <h4><?php _e('Request Summary', 'ecare-health-services'); ?></h4>
                        
                        <div class="ecare-summary-row">
                            <span class="label"><?php _e('Ambulance Request Type', 'ecare-health-services'); ?></span>
                            <span class="value" id="ecare-summary-type"><?php _e('Standard (Non-AC)', 'ecare-health-services'); ?></span>
                        </div>
                        
                        <div class="ecare-summary-row">
                            <span class="label"><?php _e('Priority', 'ecare-health-services'); ?></span>
                            <span class="value" id="ecare-summary-priority"><?php _e('Normal', 'ecare-health-services'); ?></span>
                        </div>

                        <div class="ecare-summary-row">
                            <span class="label"><?php _e('Status', 'ecare-health-services'); ?></span>
                            <span class="value ecare-summary-status"><?php _e('Pending Dispatch', 'ecare-health-services'); ?></span>
                        </div>

                        <div class="ecare-summary-warning">
                            ⚠️ <?php _e('Payment will be collected post-service or on invoice.', 'ecare-health-services'); ?>
                        </div>

                        <div class="ecare-summary-terms">
                            <input type="checkbox" id="agree-terms" required />
                            <label for="agree-terms">
                                <?php _e('I AGREE TO THE E-CARE AMBULANCE TERMS AND PRIVACY POLICY.', 'ecare-health-services'); ?>
                            </label>
                        </div>

                        <div class="ecare-summary-total-row">
                            <span><?php _e('Estimated Cost', 'ecare-health-services'); ?></span>
                            <span>৳ <span id="ecare-summary-price">1,500</span></span>
                        </div>

                        <button type="submit" form="ecare-ambulance-form" class="ecare-confirm-amb-btn"><?php _e('CONFIRM REQUEST', 'ecare-health-services'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Ambulance Provider sign up form [ecare_ambulance_registration]
     */
    public static function render_ambulance_registration() {
        ob_start();
        ?>
        <div class="ecare-container">
            <form id="ecare-ambulance-registration-form" class="ecare-form ecare-form-registration" style="background:#fff;padding:30px;border-radius:12px;border:1px solid var(--border-light);box-shadow:var(--shadow-md);" enctype="multipart/form-data" novalidate>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
                    <h2 class="ecare-section-title" style="margin:0;font-size:20px;"><?php _e('E-CARE Service Registration', 'ecare-health-services'); ?></h2>
                    <button type="button" class="ecare-admin-btn-outline" onclick="window.history.back()"><?php _e('← Back to Options', 'ecare-health-services'); ?></button>
                </div>
                <p class="ecare-section-subtitle" style="margin-bottom:30px;"><?php _e('Register your vehicle and driver details to offer dispatch services.', 'ecare-health-services'); ?></p>

                <!-- Section: Vehicle Specifications -->
                <h3 style="font-size:14px;font-weight:700;color:var(--admin-green);text-transform:uppercase;border-bottom:2px solid var(--brand-teal-light);padding-bottom:6px;margin:0 0 20px 0;"><?php _e('Vehicle Specifications', 'ecare-health-services'); ?></h3>
                
                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('Ambulance Type', 'ecare-health-services'); ?> <span>*</span></label>
                        <select name="ambulance_type" required>
                            <option value="Standard"><?php _e('Standard (Non-AC)', 'ecare-health-services'); ?></option>
                            <option value="ICU"><?php _e('ICU (AC)', 'ecare-health-services'); ?></option>
                            <option value="Freezer"><?php _e('Freezer Type', 'ecare-health-services'); ?></option>
                        </select>
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Vehicle Plate Number', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="license_plate" placeholder="e.g. Dhaka-Metro-1234" required />
                    </div>
                </div>

                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('Vehicle Model', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="vehicle_model" placeholder="e.g. Toyota Hiace 2022" required />
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Base Dispatch Price (৳)', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="number" step="0.01" name="base_price" placeholder="1500" required />
                    </div>
                </div>

                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('Engine Number', 'ecare-health-services'); ?></label>
                        <input type="text" name="engine_number" placeholder="Engine serial" />
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Chassis Number', 'ecare-health-services'); ?></label>
                        <input type="text" name="chassis_number" placeholder="Chassis serial" />
                    </div>
                </div>

                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('Insurance Expiry Date', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="date" name="insurance_expiry" required />
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Fitness Certificate Expiry', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="date" name="fitness_expiry" required />
                    </div>
                </div>

                <!-- Section: Driver Credentials -->
                <h3 style="font-size:14px;font-weight:700;color:var(--admin-green);text-transform:uppercase;border-bottom:2px solid var(--brand-teal-light);padding-bottom:6px;margin:30px 0 20px;"><?php _e('Driver Credentials', 'ecare-health-services'); ?></h3>
                
                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('Driver Full Name', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="driver_name" placeholder="Driver name" required />
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Contact Phone', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="phone" placeholder="+880..." required />
                    </div>
                </div>

                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('Email Address', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="email" name="email" placeholder="driver@example.com" required />
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Driving License No', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="driver_license" placeholder="License Number" required />
                    </div>
                </div>

                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('NID Number', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="driver_nid" placeholder="NID Number" required />
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Years of Experience', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="number" name="experience" min="0" placeholder="e.g. 5" required />
                    </div>
                </div>

                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('Blood Group', 'ecare-health-services'); ?> <span>*</span></label>
                        <select name="blood_group" required>
                            <option value="A+">A+</option>
                            <option value="A-">A-</option>
                            <option value="B+">B+</option>
                            <option value="B-">B-</option>
                            <option value="O+">O+</option>
                            <option value="O-">O-</option>
                            <option value="AB+">AB+</option>
                            <option value="AB-">AB-</option>
                        </select>
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Present Address', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="text" name="address_line" placeholder="Street address, city" required />
                    </div>
                </div>

                <div class="ecare-form-row">
                    <div class="ecare-form-field">
                        <label><?php _e('Password', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="password" name="password" placeholder="••••••••" required />
                    </div>
                    <div class="ecare-form-field">
                        <label><?php _e('Confirm Password', 'ecare-health-services'); ?> <span>*</span></label>
                        <input type="password" name="confirm_password" placeholder="••••••••" required />
                    </div>
                </div>

                <!-- Section: Documents -->
                <h3 style="font-size:14px;font-weight:700;color:var(--admin-green);text-transform:uppercase;border-bottom:2px solid var(--brand-teal-light);padding-bottom:6px;margin:30px 0 20px;"><?php _e('Documents', 'ecare-health-services'); ?></h3>
                
                <div class="ecare-file-box">
                    <div class="ecare-doc-upload" onclick="document.getElementById('reg_amb_credentials').click()">
                        <span class="ecare-doc-upload-icon">❄️</span>
                        <p><?php _e('Upload Verification Documents', 'ecare-health-services'); ?></p>
                        <span class="file-hint"><?php echo esc_html(sprintf(
                            /* translators: 1: extension list, 2: size, e.g. "8 MB" */
                            __('%1$s, up to %2$s per file', 'ecare-health-services'),
                            ECare_Secure_Files::allowed_extensions_label(ECare_Secure_Files::KIND_DOCUMENT),
                            size_format(ECare_Secure_Files::max_bytes(ECare_Secure_Files::KIND_DOCUMENT))
                        )); ?></span>
                        <input type="file" id="reg_amb_credentials" name="credentials_doc" accept="<?php echo esc_attr(implode(',', array_values(ECare_Secure_Files::allowed_mimes(ECare_Secure_Files::KIND_DOCUMENT)))); ?>" style="display:none;" required />
                    </div>
                </div>

                <div class="ecare-form-row single" style="margin-top:20px;">
                    <button type="submit" class="ecare-admin-btn-green" style="font-size:15px;padding:14px;justify-content:center;"><?php _e('Register Ambulance', 'ecare-health-services'); ?></button>
                </div>
                
                <div class="ecare-form-response"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
