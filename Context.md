# Project Context: E-Care Health Services WordPress Plugin

## Project Overview
We are developing a comprehensive healthcare service booking and management WordPress Plugin named **"E-Care Health Services"**. 
The plugin connects patients with caregivers/nurses, allows lab test ordering with location filtering, and enables ambulance dispatch requests—integrated with WooCommerce for payments.

## Stack & Architecture Requirements
- **Language:** Pure PHP (WordPress Native Architecture, OOP Structure).
- **No Modern JS Frameworks:** Strict constraint—DO NOT use React, Vue, or Angular. Use native JavaScript / jQuery and WordPress Admin AJAX for interactivity.
- **Compatibility:** WordPress 6.x+, WooCommerce 8.x+, PHP 8.1+.
- **Data Persistence:** Custom Post Types (CPTs) combined with Custom DB Tables (`{$wpdb->prefix}ecare_bookings`, `{$wpdb->prefix}ecare_locations`) and Post Meta.

---

## Key Modules & Specifications

### 1. Caregiver & Nurse Module
- **Frontend Filter (`[ecare_caregiver_booking]`):**
  - **Provider Types:** Nurse, Senior Care, Nanny, Physiotherapist.
  - **Packages:** Daily (12 Hours), Daily (24 Hours), Monthly (12 Hours), Monthly (24 Hours) with dynamic price tags.
  - **AJAX Grid:** Filter caregivers instantly by Type and Package without page reload.
  - **Caregiver Details & Patient Booking Form:** Displays caregiver profile, bio, skills, education, family member selection, patient details (Patient Type, Required Date, Diaper Change Needed, Address, Contact Number, Disease), and file/document upload (Prescription/NID).
- **Caregiver Provider Registration (`[ecare_caregiver_registration]`):**
  - Form fields: Personal Information, Professional Credentials (NID/Passport, Experience, Category, Skill), Bank Information, Documents (Care photo, credentials).
- **Admin Management:**
  - "Care Providers" menu under WooCommerce/Plugin Dashboard to list, review, approve, and manage registered caregivers.
  - "Care Bookings" dashboard displaying metrics (Total Bookings, Pending Approvals, Completed Care, Cancelled) and booking data table.

### 2. Lab Test Booking Module
- **Frontend Diagnostic Catalog (`[ecare_lab_tests]`):**
  - Multi-level Location Hierarchy Filter: Division -> District -> Area -> Diagnostic Facility / Lab Provider.
  - Dynamic searchable catalog showing tests (e.g., CBC, ESR, Hb%, MP) with price starting from.
  - "Add to Cart" integration directly with WooCommerce Checkout.
- **Admin Lab Management:**
  - "Lab Catalog" dashboard: Add/Edit Lab Tests with fields (Test Name, Division, District, Area, Lab Provider, Test Code, Price, Category, Sample Type, Turnaround Days, Status).
  - "Lab Orders" dashboard to track diagnostic test bookings.

### 3. Ambulance Booking & Dispatch Module
- **Frontend Request Form (`[ecare_ambulance_request]`):**
  - Ambulance Type: Standard (Non-AC), ICU (AC), Freezer Type.
  - Fields: Pickup Address, Destination (Hospital/Target), Schedule Time, Contact Phone, Priority Level (Normal vs. Emergency), Additional Notes.
  - Summary card with live price calculation and "Confirm Request".
- **Ambulance Provider Registration (`[ecare_ambulance_registration]`):**
  - Vehicle Specifications: License Plate, Model, Driver Credentials, Driving License No, NID, Documents.
- **Admin Ambulance Dispatch Dashboard:**
  - View real-time dispatch requests with status tags (Pending Dispatch, Assigned, Completed, Emergency).

---

## Plugin File Structure
```text
ecare-health-services/
├── ecare-health-services.php      # Core Bootstrap File
├── includes/
│   ├── class-ecare-activator.php  # DB Table creation logic
│   ├── class-ecare-cpt.php        # Register Caregiver, Lab Test CPTs
│   ├── class-ecare-ajax.php       # All AJAX hooks for filtering & forms
│   ├── class-ecare-woocommerce.php# WC Product creation & Checkout integration
│   └── class-ecare-shortcodes.php # Frontend HTML outputs
├── admin/
│   ├── class-ecare-admin.php      # Admin Menu Pages & Dashboards
│   └── views/                     # Admin HTML Views
└── assets/
    ├── css/ecare-style.css
    └── js/ecare-script.js         # Pure jQuery AJAX logic