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
            <div class="ecare-filters">
                <div class="ecare-filter-group">
                    <label>Provider Type</label>
                    <select id="ecare-filter-type" class="ecare-select">
                        <option value="">All Types</option>
                        <option value="Nurse">Nurse</option>
                        <option value="Senior Care">Senior Care</option>
                        <option value="Nanny">Nanny</option>
                        <option value="Physiotherapist">Physiotherapist</option>
                    </select>
                </div>
                <div class="ecare-filter-group">
                    <label>Package</label>
                    <select id="ecare-filter-package" class="ecare-select">
                        <option value="">All Packages</option>
                        <option value="daily_12">Daily (12 Hours)</option>
                        <option value="daily_24">Daily (24 Hours)</option>
                        <option value="monthly_12">Monthly (12 Hours)</option>
                        <option value="monthly_24">Monthly (24 Hours)</option>
                    </select>
                </div>
            </div>
            <div id="ecare-caregiver-grid" class="ecare-grid">
                <p>Loading caregivers...</p>
            </div>
            <div id="ecare-caregiver-detail-modal" class="ecare-modal" style="display:none;">
                <div class="ecare-modal-content">
                    <span class="ecare-modal-close">&times;</span>
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
                <h2>Care Provider Registration</h2>
                <p class="ecare-form-desc">Register as a Nurse, Senior Care, Nanny, or Physiotherapist.</p>

                <h3>Personal Information</h3>
                <div class="ecare-form-row">
                    <label>Full Name <span>*</span></label>
                    <input type="text" name="full_name" required />
                </div>
                <div class="ecare-form-row">
                    <label>Email <span>*</span></label>
                    <input type="email" name="email" required />
                </div>
                <div class="ecare-form-row">
                    <label>Phone <span>*</span></label>
                    <input type="text" name="phone" required />
                </div>

                <h3>Professional Credentials</h3>
                <div class="ecare-form-row">
                    <label>Provider Type <span>*</span></label>
                    <select name="provider_type" required>
                        <option value="">Select</option>
                        <option value="Nurse">Nurse</option>
                        <option value="Senior Care">Senior Care</option>
                        <option value="Nanny">Nanny</option>
                        <option value="Physiotherapist">Physiotherapist</option>
                    </select>
                </div>
                <div class="ecare-form-row">
                    <label>Experience (years)</label>
                    <input type="number" name="experience" min="0" />
                </div>
                <div class="ecare-form-row">
                    <label>Category</label>
                    <input type="text" name="category" placeholder="e.g., Pediatric, Geriatric" />
                </div>
                <div class="ecare-form-row">
                    <label>Skills</label>
                    <textarea name="skills" rows="3"></textarea>
                </div>
                <div class="ecare-form-row">
                    <label>Education</label>
                    <textarea name="education" rows="3"></textarea>
                </div>
                <div class="ecare-form-row">
                    <label>NID/Passport <span>*</span></label>
                    <input type="text" name="nid_passport" required />
                </div>

                <h3>Pricing</h3>
                <div class="ecare-form-row ecare-price-row">
                    <div><label>Daily 12H (৳)</label><input type="number" step="0.01" name="daily_12_price" /></div>
                    <div><label>Daily 24H (৳)</label><input type="number" step="0.01" name="daily_24_price" /></div>
                </div>
                <div class="ecare-form-row ecare-price-row">
                    <div><label>Monthly 12H (৳)</label><input type="number" step="0.01" name="monthly_12_price" /></div>
                    <div><label>Monthly 24H (৳)</label><input type="number" step="0.01" name="monthly_24_price" /></div>
                </div>

                <h3>Bank Information</h3>
                <div class="ecare-form-row">
                    <label>Bank Name</label>
                    <input type="text" name="bank_name" />
                </div>
                <div class="ecare-form-row">
                    <label>Bank Account</label>
                    <input type="text" name="bank_account" />
                </div>

                <h3>Documents</h3>
                <div class="ecare-form-row">
                    <label>Care Photo</label>
                    <input type="file" name="care_photo" accept="image/*" />
                </div>
                <div class="ecare-form-row">
                    <label>Credentials Document</label>
                    <input type="file" name="credentials_doc" />
                </div>

                <div class="ecare-form-row">
                    <button type="submit" class="ecare-btn ecare-btn-primary">Submit Registration</button>
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
            <div class="ecare-filters ecare-lab-filters">
                <div class="ecare-filter-group">
                    <label>Division</label>
                    <select id="ecare-lab-division" class="ecare-select">
                        <option value="">All Divisions</option>
                    </select>
                </div>
                <div class="ecare-filter-group">
                    <label>District</label>
                    <select id="ecare-lab-district" class="ecare-select" disabled>
                        <option value="">Select Division First</option>
                    </select>
                </div>
                <div class="ecare-filter-group">
                    <label>Area</label>
                    <select id="ecare-lab-area" class="ecare-select" disabled>
                        <option value="">Select District First</option>
                    </select>
                </div>
                <div class="ecare-filter-group">
                    <label>Lab Provider</label>
                    <select id="ecare-lab-provider" class="ecare-select" disabled>
                        <option value="">Select Area First</option>
                    </select>
                </div>
                <div class="ecare-filter-group">
                    <label>Search</label>
                    <input type="text" id="ecare-lab-search" class="ecare-input" placeholder="Search tests..." />
                </div>
            </div>
            <div id="ecare-lab-grid" class="ecare-grid ecare-lab-grid">
                <p>Select filters to browse lab tests.</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_ambulance_request() {
        ob_start();
        ?>
        <div class="ecare-container ecare-ambulance-request">
            <form id="ecare-ambulance-form" class="ecare-form">
                <h2>Ambulance Request</h2>

                <div class="ecare-form-row">
                    <label>Ambulance Type <span>*</span></label>
                    <select name="ambulance_type" id="ecare-ambulance-type" required>
                        <option value="">Select Type</option>
                        <option value="Standard">Standard (Non-AC) – ৳1,500</option>
                        <option value="ICU">ICU (AC) – ৳3,000</option>
                        <option value="Freezer">Freezer Type – ৳5,000</option>
                    </select>
                </div>
                <div class="ecare-form-row">
                    <label>Pickup Address <span>*</span></label>
                    <textarea name="pickup_address" required></textarea>
                </div>
                <div class="ecare-form-row">
                    <label>Destination (Hospital/Target) <span>*</span></label>
                    <textarea name="destination" required></textarea>
                </div>
                <div class="ecare-form-row">
                    <label>Schedule Time</label>
                    <input type="datetime-local" name="schedule_time" />
                </div>
                <div class="ecare-form-row">
                    <label>Contact Phone <span>*</span></label>
                    <input type="text" name="contact_phone" required />
                </div>
                <div class="ecare-form-row">
                    <label>Priority Level</label>
                    <select name="priority_level">
                        <option value="Normal">Normal</option>
                        <option value="Emergency">Emergency</option>
                    </select>
                </div>
                <div class="ecare-form-row">
                    <label>Additional Notes</label>
                    <textarea name="notes" rows="3"></textarea>
                </div>

                <div class="ecare-summary-card">
                    <h3>Price Summary</h3>
                    <p>Ambulance Type: <span id="ecare-summary-type">-</span></p>
                    <p class="ecare-total-price">Total: ৳<span id="ecare-summary-price">0</span></p>
                </div>

                <div class="ecare-form-row">
                    <button type="submit" class="ecare-btn ecare-btn-primary">Confirm Request</button>
                </div>
                <div class="ecare-form-response"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function render_ambulance_registration() {
        ob_start();
        ?>
        <div class="ecare-container">
            <form id="ecare-ambulance-registration-form" class="ecare-form ecare-form-registration" enctype="multipart/form-data">
                <h2>Ambulance Provider Registration</h2>
                <p class="ecare-form-desc">Register your ambulance service to receive dispatch requests.</p>

                <h3>Contact Information</h3>
                <div class="ecare-form-row">
                    <label>Provider Name <span>*</span></label>
                    <input type="text" name="provider_name" required />
                </div>
                <div class="ecare-form-row">
                    <label>Email <span>*</span></label>
                    <input type="email" name="email" required />
                </div>
                <div class="ecare-form-row">
                    <label>Phone <span>*</span></label>
                    <input type="text" name="phone" required />
                </div>

                <h3>Vehicle Specifications</h3>
                <div class="ecare-form-row">
                    <label>License Plate <span>*</span></label>
                    <input type="text" name="license_plate" required />
                </div>
                <div class="ecare-form-row">
                    <label>Vehicle Model</label>
                    <input type="text" name="vehicle_model" />
                </div>
                <div class="ecare-form-row">
                    <label>Ambulance Type <span>*</span></label>
                    <select name="ambulance_type" required>
                        <option value="">Select</option>
                        <option value="Standard">Standard (Non-AC)</option>
                        <option value="ICU">ICU (AC)</option>
                        <option value="Freezer">Freezer Type</option>
                    </select>
                </div>
                <div class="ecare-form-row">
                    <label>Base Price (৳)</label>
                    <input type="number" step="0.01" name="base_price" />
                </div>

                <h3>Driver Credentials</h3>
                <div class="ecare-form-row">
                    <label>Driver Name <span>*</span></label>
                    <input type="text" name="driver_name" required />
                </div>
                <div class="ecare-form-row">
                    <label>Driving License No <span>*</span></label>
                    <input type="text" name="driver_license" required />
                </div>
                <div class="ecare-form-row">
                    <label>Driver NID</label>
                    <input type="text" name="driver_nid" />
                </div>

                <h3>Documents</h3>
                <div class="ecare-form-row">
                    <label>Vehicle Registration</label>
                    <input type="file" name="vehicle_doc" />
                </div>
                <div class="ecare-form-row">
                    <label>Driver License Image</label>
                    <input type="file" name="license_doc" />
                </div>

                <div class="ecare-form-row">
                    <button type="submit" class="ecare-btn ecare-btn-primary">Submit Registration</button>
                </div>
                <div class="ecare-form-response"></div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
