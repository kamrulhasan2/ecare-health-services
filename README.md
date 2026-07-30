# E-Care Health Services WordPress Plugin

A comprehensive, OOP-structured healthcare service booking and dispatch management WordPress plugin built using Pure PHP, HTML5, CSS3, and jQuery AJAX. E-Care connects patients with verified caregivers/nurses, facilitates diagnostic lab test ordering with cascading location filters, and manages ambulance dispatch requests—fully integrated with WooCommerce for seamless checkout payment flows.

---

## 🌟 Key Features

### 🩺 1. Caregiver & Nurse Module
* **Dynamic Grid Filter (`[ecare_caregiver_booking]`)**: Instantly filter approved caregivers by Provider Type (Nurse, Senior Care, Nanny, Physiotherapist) and Package Rate durations without reloading the page.
* **Single Profile & Booking Form (Modal View)**: Beautiful two-column details layout with biography, educational history, special skills, interactive family member selector, file upload box (NID/prescriptions), and immediate package price updates.
* **Provider Registration (`[ecare_caregiver_registration]`)**: Comprehensive signup form with personal information, professional credentials, package pricing rates, bank account information, and certificates upload.

### 🔬 2. Lab Test Booking Module
* **Diagnostic Test Catalog (`[ecare_lab_tests]`)**: Cascading location sticky bar (Division → District → Area → Lab Provider) with instant live test searches.
* **Add to Cart (+)**: Adds tests directly to WooCommerce cart with dynamic product generation, providing checkout redirect links.

### 🚑 3. Ambulance Booking & Dispatch Module
* **Dispatch Request Form (`[ecare_ambulance_request]`)**: Interactive selectable ambulance type cards (Standard Non-AC, ICU AC, Freezer Van), inline-icon input fields, terms validation, and a sticky real-time cost calculation summary card.
* **Vehicle Provider Registration (`[ecare_ambulance_registration]`)**: Vehicle details specification inputs and driver credentials verification signup page.

### 🖥️ 4. Custom Admin Dashboards (Meditaj / Shukhee style)
* **SaaS KPIs Row**: 4-column metrics displaying Total Bookings, Active Providers, Pending Approvals, and Completed Missions.
* **Actions Header**: Quick search input box, export to CSV triggers, and green primary booking actions buttons (`#0E9F6E`).
* **Borderless Modern Data Tables**: Soft status pill badges indicating dispatch state, dynamic payment order links, and instant status changing dropdowns via AJAX.

---

## 🎨 Styling Guidelines & Colors

The user interface adheres to a curated visual design blueprint:
* **Teal (`#18B8A3` / `--brand-teal`)**: Primary active states, active tab borders, pricing details, and lab cart additions.
* **Light Teal (`#E6F7F5` / `--brand-teal-light`)**: Background tints for active tabs and selection lists.
* **Purple (`#8B2C7A` / `--brand-purple`)**: Main Caregiver action booking button.
* **Green (`#0E9F6E` / `--admin-green`)**: Admin primary actions, success alerts, and active status badges.
* **Light Grey (`#F8FAFC` / `--bg-page` / `--bg-gray`)**: Main layout page backgrounds and profile sidebars.
* **Typography**: Enqueues Google Font **Inter** for premium, modern typography.

---

## 🛠️ Technology Stack & Architecture

* **Language**: PHP 8.1+ (WordPress Object-Oriented Native Architecture).
* **Dependencies**: WordPress 6.x+, WooCommerce 8.x+, jQuery (WordPress core package).
* **No Frameworks**: 100% pure native CSS3 and vanilla jQuery AJAX (no React, Vue, or Webpack bundlers).
* **Data Layer**: Custom Database Tables combined with Custom Post Types and post meta fields:
  * `{$wpdb->prefix}ecare_bookings` (Main dispatch and care service request records).
  * `{$wpdb->prefix}ecare_locations` (Location hierarchy seed tables).
  * `ecare_caregiver` CPT (Registered care providers).
  * `ecare_lab_test` CPT (Diagnostic test catalog items).
  * `ecare_ambulance` CPT (Ambulance vehicle units).

---

## 📂 Directory Structure

```text
ecare-health-services/
├── ecare-health-services.php      # Main Plugin Bootstrap File
├── README.md                      # Documentation
├── includes/
│   ├── class-ecare-activator.php  # Database schema setup & location seeders
│   ├── class-ecare-cpt.php        # Custom Post Type definitions & metaboxes
│   ├── class-class-ecare-ajax.php # Frontend AJAX handlers & WooCommerce integrations
│   ├── class-ecare-woocommerce.php# Checkout order completion updates
│   └── class-ecare-shortcodes.php # Frontend HTML shortcode markup
├── admin/
│   └── class-ecare-admin.php      # Meditaj/Shukhee-style Admin Menu Pages
└── assets/
    ├── css/
    │   └── ecare-style.css        # Premium style overrides & grid layouts
    └── js/
        └── ecare-script.js        # Pure jQuery AJAX interactions
```

---

## 🔌 Shortcodes

Place these shortcodes on WordPress pages to render frontend modules:

1. **Caregiver Grid Filter & Booking**:
   ```text
   [ecare_caregiver_booking]
   ```
2. **Caregiver Provider Signup Form**:
   ```text
   [ecare_caregiver_registration]
   ```
3. **Diagnostic Lab Test Catalog**:
   ```text
   [ecare_lab_tests]
   ```
4. **Ambulance Dispatch Form**:
   ```text
   [ecare_ambulance_request]
   ```
5. **Ambulance Provider Signup Form**:
   ```text
   [ecare_ambulance_registration]
   ```

---

## 🚀 Installation & Setup

1. Compress the `ecare-health-services` directory into a `.zip` file.
2. Log in to your WordPress Admin dashboard and navigate to **Plugins → Add New → Upload Plugin**.
3. Upload the `.zip` file and click **Install Now**.
4. Click **Activate Plugin**.
5. Upon activation, the plugin automatically creates the required custom tables and seeds default locations (Dhaka division → Dhaka district → Ashkona area → Praava Health provider) so the lab test filter path works out-of-the-box.
6. Install and activate **WooCommerce** to enable dynamic payment order generation and cart redirects.

---

## 📋 License

This plugin is licensed under the GPL v2 or later.
