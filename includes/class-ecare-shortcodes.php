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

    public static function render_caregiver_booking() {
        ob_start();
        ?>
        <div class="ecare-container ecare-caregiver-booking">
            <h2 class="ecare-section-title"><?php _e('Find Your Caregiver', 'ecare-health-services'); ?></h2>
            <p class="ecare-section-subtitle"><?php _e('Select type and package to filter caregivers instantly.', 'ecare-health-services'); ?></p>

            <div class="ecare-type-tabs" id="ecare-filter-type">
                <div class="ecare-type-tab active" data-type="">
                    <span class="ecare-tab-icon">&#9776;</span>
                    <span><?php _e('All Types', 'ecare-health-services'); ?></span>
                </div>
                <div class="ecare-type-tab" data-type="Nurse">
                    <span class="ecare-tab-icon">&#9737;</span>
                    <span><?php _e('Nurse', 'ecare-health-services'); ?></span>
                </div>
                <div class="ecare-type-tab" data-type="Senior Care">
                    <span class="ecare-tab-icon">&#9733;</span>
                    <span><?php _e('Senior Care', 'ecare-health-services'); ?></span>
                </div>
                <div class="ecare-type-tab" data-type="Nanny">
                    <span class="ecare-tab-icon">&#9787;</span>
                    <span><?php _e('Nanny', 'ecare-health-services'); ?></span>
                </div>
                <div class="ecare-type-tab" data-type="Physiotherapist">
                    <span class="ecare-tab-icon">&#9878;</span>
                    <span><?php _e('Physiotherapist', 'ecare-health-services'); ?></span>
                </div>
            </div>

            <div class="ecare-package-tabs" id="ecare-filter-package">
                <div class="ecare-package-tab active" data-package="">
                    <span class="ecare-pkg-label"><?php _e('All Packages', 'ecare-health-services'); ?></span>
                </div>
                <div class="ecare-package-tab" data-package="daily_12">
                    <span class="ecare-pkg-label"><?php _e('Daily (12 Hours)', 'ecare-health-services'); ?></span>
                    <span class="ecare-pkg-price"><?php _e('Total', 'ecare-health-services'); ?> &#2547; --</span>
                </div>
                <div class="ecare-package-tab" data-package="daily_24">
                    <span class="ecare-pkg-label"><?php _e('Daily (24 Hours)', 'ecare-health-services'); ?></span>
                    <span class="ecare-pkg-price"><?php _e('Total', 'ecare-health-services'); ?> &#2547; --</span>
                </div>
                <div class="ecare-package-tab" data-package="monthly_12">
                    <span class="ecare-pkg-label"><?php _e('Monthly (12 Hours)', 'ecare-health-services'); ?></span>
                    <span class="ecare-pkg-price"><?php _e('Total', 'ecare-health-services'); ?> &#2547; --</span>
                </div>
                <div class="ecare-package-tab" data-package="monthly_24">
                    <span class="ecare-pkg-label"><?php _e('Monthly (24 Hours)', 'ecare-health-services'); ?></span>
                    <span class="ecare-pkg-price"><?php _e('Total', 'ecare-health-services'); ?> &#2547; --</span>
                </div>
            </div>

            <div id="ecare-caregiver-grid" class="ecare-cg-grid">
                <p><?php _e('Loading caregivers...', 'ecare-health-services'); ?></p>
            </div>

            <div id="ecare-caregiver-detail-modal" class="ecare-modal" style="display:none;">
                <div class="ecare-modal-content">
                    <button class="ecare-modal-close">&times;</button>
                    <div id="ecare-caregiver-detail-content"></div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_caregiver_registration() {
        ob_start();
        ?>
        <div class="ecare-container">
            <form id="ecare-caregiver-registration-form" class="ecare-form ecare-form-registration" enctype="multipart/form-data">
                <h2 class="ecare-section-title"><?php _e('Care Provider Registration', 'ecare-health-services'); ?></h2>
                <p class="ecare-section-subtitle"><?php _e('Register as a Nurse, Senior Care, Nanny, or Physiotherapist.', 'ecare-health-services'); ?></p>

                <h3><?php _e('Personal Information', 'ecare-health-services'); ?></h3>
                <div class="ecare-form-row">
                    <label><?php _e('Full Name', 'ecare-health-services'); ?> <span>*</span></label>
                    <input type="text" name="full_name" required />
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Email', 'ecare-health-services'); ?> <span>*</span></label>
                    <input type="email" name="email" required />
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Phone', 'ecare-health-services'); ?> <span>*</span></label>
                    <input type="text" name="phone" required />
                </div>

                <h3><?php _e('Professional Credentials', 'ecare-health-services'); ?></h3>
                <div class="ecare-form-row">
                    <label><?php _e('Provider Type', 'ecare-health-services'); ?> <span>*</span></label>
                    <select name="provider_type" required>
                        <option value=""><?php _e('Select', 'ecare-health-services'); ?></option>
                        <option value="Nurse"><?php _e('Nurse', 'ecare-health-services'); ?></option>
                        <option value="Senior Care"><?php _e('Senior Care', 'ecare-health-services'); ?></option>
                        <option value="Nanny"><?php _e('Nanny', 'ecare-health-services'); ?></option>
                        <option value="Physiotherapist"><?php _e('Physiotherapist', 'ecare-health-services'); ?></option>
                    </select>
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Experience (years)', 'ecare-health-services'); ?></label>
                    <input type="number" name="experience" min="0" />
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Category', 'ecare-health-services'); ?></label>
                    <input type="text" name="category" placeholder="<?php esc_attr_e('e.g., Pediatric, Geriatric', 'ecare-health-services'); ?>" />
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Skills', 'ecare-health-services'); ?></label>
                    <textarea name="skills" rows="3"></textarea>
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Education', 'ecare-health-services'); ?></label>
                    <textarea name="education" rows="3"></textarea>
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('NID/Passport', 'ecare-health-services'); ?> <span>*</span></label>
                    <input type="text" name="nid_passport" required />
                </div>

                <h3><?php _e('Pricing', 'ecare-health-services'); ?></h3>
                <div class="ecare-form-row" style="display:flex;gap:16px;">
                    <div style="flex:1;"><label><?php _e('Daily 12H', 'ecare-health-services'); ?> (&#2547;)</label><input type="number" step="0.01" name="daily_12_price" /></div>
                    <div style="flex:1;"><label><?php _e('Daily 24H', 'ecare-health-services'); ?> (&#2547;)</label><input type="number" step="0.01" name="daily_24_price" /></div>
                </div>
                <div class="ecare-form-row" style="display:flex;gap:16px;">
                    <div style="flex:1;"><label><?php _e('Monthly 12H', 'ecare-health-services'); ?> (&#2547;)</label><input type="number" step="0.01" name="monthly_12_price" /></div>
                    <div style="flex:1;"><label><?php _e('Monthly 24H', 'ecare-health-services'); ?> (&#2547;)</label><input type="number" step="0.01" name="monthly_24_price" /></div>
                </div>

                <h3><?php _e('Bank Information', 'ecare-health-services'); ?></h3>
                <div class="ecare-form-row">
                    <label><?php _e('Bank Name', 'ecare-health-services'); ?></label>
                    <input type="text" name="bank_name" />
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Bank Account', 'ecare-health-services'); ?></label>
                    <input type="text" name="bank_account" />
                </div>

                <h3><?php _e('Documents', 'ecare-health-services'); ?></h3>
                <div class="ecare-form-row">
                    <label><?php _e('Care Photo', 'ecare-health-services'); ?></label>
                    <input type="file" name="care_photo" accept="image/*" />
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Credentials Document', 'ecare-health-services'); ?></label>
                    <input type="file" name="credentials_doc" />
                </div>

                <div class="ecare-form-row">
                    <button type="submit" class="ecare-book-btn" style="background:var(--brand-teal);"><?php _e('Submit Registration', 'ecare-health-services'); ?></button>
                </div>
                <div class="ecare-form-response"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_lab_tests() {
        ob_start();
        ?>
        <div class="ecare-container ecare-lab-tests">
            <h2 class="ecare-section-title"><?php _e('Diagnostic Lab Tests', 'ecare-health-services'); ?></h2>
            <p class="ecare-section-subtitle"><?php _e('Search and filter lab tests by location and provider.', 'ecare-health-services'); ?></p>

            <div class="ecare-filters" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;padding:16px;background:var(--bg-gray);border-radius:10px;">
                <div style="flex:1;min-width:150px;">
                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;display:block;margin-bottom:4px;"><?php _e('Division', 'ecare-health-services'); ?></label>
                    <select id="ecare-lab-division" class="ecare-select" style="width:100%;padding:10px;border:1px solid var(--border-color);border-radius:6px;">
                        <option value=""><?php _e('All Divisions', 'ecare-health-services'); ?></option>
                    </select>
                </div>
                <div style="flex:1;min-width:150px;">
                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;display:block;margin-bottom:4px;"><?php _e('District', 'ecare-health-services'); ?></label>
                    <select id="ecare-lab-district" class="ecare-select" disabled style="width:100%;padding:10px;border:1px solid var(--border-color);border-radius:6px;">
                        <option value=""><?php _e('Select Division First', 'ecare-health-services'); ?></option>
                    </select>
                </div>
                <div style="flex:1;min-width:150px;">
                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;display:block;margin-bottom:4px;"><?php _e('Area', 'ecare-health-services'); ?></label>
                    <select id="ecare-lab-area" class="ecare-select" disabled style="width:100%;padding:10px;border:1px solid var(--border-color);border-radius:6px;">
                        <option value=""><?php _e('Select District First', 'ecare-health-services'); ?></option>
                    </select>
                </div>
                <div style="flex:1;min-width:150px;">
                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;display:block;margin-bottom:4px;"><?php _e('Lab Provider', 'ecare-health-services'); ?></label>
                    <select id="ecare-lab-provider" class="ecare-select" disabled style="width:100%;padding:10px;border:1px solid var(--border-color);border-radius:6px;">
                        <option value=""><?php _e('Select Area First', 'ecare-health-services'); ?></option>
                    </select>
                </div>
                <div style="flex:1;min-width:150px;">
                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);text-transform:uppercase;display:block;margin-bottom:4px;"><?php _e('Search', 'ecare-health-services'); ?></label>
                    <input type="text" id="ecare-lab-search" placeholder="<?php esc_attr_e('Search tests...', 'ecare-health-services'); ?>" style="width:100%;padding:10px;border:1px solid var(--border-color);border-radius:6px;" />
                </div>
            </div>

            <div id="ecare-lab-grid" class="ecare-lab-grid">
                <p><?php _e('Select filters to browse lab tests.', 'ecare-health-services'); ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_ambulance_request() {
        ob_start();
        ?>
        <div class="ecare-container">
            <h2 class="ecare-section-title"><?php _e('Ambulance Request', 'ecare-health-services'); ?></h2>
            <p class="ecare-section-subtitle"><?php _e('Fill in the details below to request an ambulance.', 'ecare-health-services'); ?></p>

            <div class="ecare-amb-layout">
                <div class="ecare-amb-form-col">
                    <form id="ecare-ambulance-form">
                        <label style="font-size:13px;font-weight:600;display:block;margin-bottom:8px;"><?php _e('Ambulance Type', 'ecare-health-services'); ?> <span style="color:#EF4444;">*</span></label>
                        <div class="ecare-amb-type-row" id="ecare-ambulance-type">
                            <div class="ecare-amb-type-card active" data-type="Standard" data-price="1500">
                                <span class="ecare-amb-icon">&#128657;</span>
                                <span class="ecare-amb-label"><?php _e('Standard (Non-AC)', 'ecare-health-services'); ?></span>
                                <span class="ecare-amb-price">&#2547; 1,500</span>
                            </div>
                            <div class="ecare-amb-type-card" data-type="ICU" data-price="3000">
                                <span class="ecare-amb-icon">&#9768;</span>
                                <span class="ecare-amb-label"><?php _e('ICU (AC)', 'ecare-health-services'); ?></span>
                                <span class="ecare-amb-price">&#2547; 3,000</span>
                            </div>
                            <div class="ecare-amb-type-card" data-type="Freezer" data-price="5000">
                                <span class="ecare-amb-icon">&#10052;</span>
                                <span class="ecare-amb-label"><?php _e('Freezer Type', 'ecare-health-services'); ?></span>
                                <span class="ecare-amb-price">&#2547; 5,000</span>
                            </div>
                        </div>

                        <input type="hidden" name="ambulance_type" value="Standard" />

                        <div class="ecare-input-icon-wrap">
                            <span class="ecare-input-icon">&#128205;</span>
                            <input type="text" name="pickup_address" placeholder="<?php esc_attr_e('Pickup Location', 'ecare-health-services'); ?>" required />
                        </div>

                        <div class="ecare-input-icon-wrap">
                            <span class="ecare-input-icon">&#127963;</span>
                            <input type="text" name="destination" placeholder="<?php esc_attr_e('Destination (Hospital / Target)', 'ecare-health-services'); ?>" required />
                        </div>

                        <div style="display:flex;gap:12px;">
                            <div class="ecare-input-icon-wrap" style="flex:1;">
                                <span class="ecare-input-icon">&#128339;</span>
                                <input type="datetime-local" name="schedule_time" />
                            </div>
                            <div class="ecare-input-icon-wrap" style="flex:1;">
                                <span class="ecare-input-icon">&#128222;</span>
                                <input type="text" name="contact_phone" placeholder="<?php esc_attr_e('Contact Phone', 'ecare-health-services'); ?>" required />
                            </div>
                        </div>

                        <div class="ecare-input-icon-wrap">
                            <span class="ecare-input-icon">&#9888;</span>
                            <select name="priority_level">
                                <option value="Normal"><?php _e('Normal', 'ecare-health-services'); ?></option>
                                <option value="Emergency"><?php _e('Emergency', 'ecare-health-services'); ?></option>
                            </select>
                        </div>

                        <div class="ecare-input-icon-wrap">
                            <span class="ecare-input-icon">&#128221;</span>
                            <textarea name="notes" rows="3" placeholder="<?php esc_attr_e('Additional Notes', 'ecare-health-services'); ?>"></textarea>
                        </div>

                        <div class="ecare-form-response"></div>
                    </form>
                </div>

                <div class="ecare-amb-sidebar">
                    <div class="ecare-feature-icons">
                        <div class="ecare-feat-item">
                            <span class="ecare-feat-icon">&#128338;</span>
                            <span><?php _e('24/7', 'ecare-health-services'); ?></span>
                        </div>
                        <div class="ecare-feat-item">
                            <span class="ecare-feat-icon">&#9989;</span>
                            <span><?php _e('100%', 'ecare-health-services'); ?></span>
                        </div>
                        <div class="ecare-feat-item">
                            <span class="ecare-feat-icon">&#128168;</span>
                            <span><?php _e('Oxygen', 'ecare-health-services'); ?></span>
                        </div>
                    </div>

                    <div class="ecare-summary-card">
                        <h4><?php _e('Request Summary', 'ecare-health-services'); ?></h4>
                        <div class="ecare-summary-row">
                            <span><?php _e('Ambulance Type', 'ecare-health-services'); ?></span>
                            <span id="ecare-summary-type"><?php _e('Standard', 'ecare-health-services'); ?></span>
                        </div>
                        <div class="ecare-summary-row">
                            <span><?php _e('Priority', 'ecare-health-services'); ?></span>
                            <span id="ecare-summary-priority"><?php _e('Normal', 'ecare-health-services'); ?></span>
                        </div>
                        <div class="ecare-summary-total">
                            <?php _e('Total', 'ecare-health-services'); ?>: &#2547;<span id="ecare-summary-price">1,500</span>
                        </div>
                        <button type="submit" form="ecare-ambulance-form" class="ecare-confirm-btn"><?php _e('Confirm Request', 'ecare-health-services'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_ambulance_registration() {
        ob_start();
        ?>
        <div class="ecare-container">
            <form id="ecare-ambulance-registration-form" class="ecare-form ecare-form-registration" enctype="multipart/form-data">
                <h2 class="ecare-section-title"><?php _e('Ambulance Provider Registration', 'ecare-health-services'); ?></h2>
                <p class="ecare-section-subtitle"><?php _e('Register your ambulance service to receive dispatch requests.', 'ecare-health-services'); ?></p>

                <h3><?php _e('Contact Information', 'ecare-health-services'); ?></h3>
                <div class="ecare-form-row">
                    <label><?php _e('Provider Name', 'ecare-health-services'); ?> <span>*</span></label>
                    <input type="text" name="provider_name" required />
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Email', 'ecare-health-services'); ?> <span>*</span></label>
                    <input type="email" name="email" required />
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Phone', 'ecare-health-services'); ?> <span>*</span></label>
                    <input type="text" name="phone" required />
                </div>

                <h3><?php _e('Vehicle Specifications', 'ecare-health-services'); ?></h3>
                <div class="ecare-form-row">
                    <label><?php _e('License Plate', 'ecare-health-services'); ?> <span>*</span></label>
                    <input type="text" name="license_plate" required />
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Vehicle Model', 'ecare-health-services'); ?></label>
                    <input type="text" name="vehicle_model" />
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Ambulance Type', 'ecare-health-services'); ?> <span>*</span></label>
                    <select name="ambulance_type" required>
                        <option value=""><?php _e('Select', 'ecare-health-services'); ?></option>
                        <option value="Standard"><?php _e('Standard (Non-AC)', 'ecare-health-services'); ?></option>
                        <option value="ICU"><?php _e('ICU (AC)', 'ecare-health-services'); ?></option>
                        <option value="Freezer"><?php _e('Freezer Type', 'ecare-health-services'); ?></option>
                    </select>
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Base Price', 'ecare-health-services'); ?> (&#2547;)</label>
                    <input type="number" step="0.01" name="base_price" />
                </div>

                <h3><?php _e('Driver Credentials', 'ecare-health-services'); ?></h3>
                <div class="ecare-form-row">
                    <label><?php _e('Driver Name', 'ecare-health-services'); ?> <span>*</span></label>
                    <input type="text" name="driver_name" required />
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Driving License No', 'ecare-health-services'); ?> <span>*</span></label>
                    <input type="text" name="driver_license" required />
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Driver NID', 'ecare-health-services'); ?></label>
                    <input type="text" name="driver_nid" />
                </div>

                <h3><?php _e('Documents', 'ecare-health-services'); ?></h3>
                <div class="ecare-form-row">
                    <label><?php _e('Vehicle Registration', 'ecare-health-services'); ?></label>
                    <input type="file" name="vehicle_doc" />
                </div>
                <div class="ecare-form-row">
                    <label><?php _e('Driver License Image', 'ecare-health-services'); ?></label>
                    <input type="file" name="license_doc" />
                </div>

                <div class="ecare-form-row">
                    <button type="submit" class="ecare-confirm-btn"><?php _e('Submit Registration', 'ecare-health-services'); ?></button>
                </div>
                <div class="ecare-form-response"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
