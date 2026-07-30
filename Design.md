# Visual Design Blueprint for OpenCode (Extracted from E-Care WP Plugin PDF)

Please use this exact HTML DOM structure, CSS variables, and layout specs to rewrite the WordPress Plugin UI.

## 1. Global CSS Variables & Colors
```css
:root {
  --brand-teal: #18B8A3;          /* Main active border, tab text, price highlights */
  --brand-teal-light: #E6F7F5;    /* Active tab background tint, button background */
  --brand-purple: #8B2C7A;        /* "Book Caregiver" action button */
  --admin-green: #0E9F6E;         /* Admin dashboard "+ Add Booking" primary button */
  --bg-card: #FFFFFF;             /* White card container */
  --bg-page: #F8FAFC;             /* Light gray page background */
  --border-light: #E2E8F0;        /* Card & table borders */
  --text-dark: #1E293B;           /* Primary text */
  --text-muted: #64748B;          /* Subtitles & quotes */
}

2. Frontend HTML & Layout Specifications
A. Caregiver Booking Grid ([ecare_caregiver_booking])
Caregiver Type Tabs: Horizontal list of selectable pill buttons with icons.

Active State: background-color: var(--brand-teal-light); border: 2px solid var(--brand-teal); color: var(--brand-teal); font-weight: 600;

Package Selection Cards: Horizontal flex row (display: flex; gap: 15px;).

Shows Package Name (e.g., Daily (12 Hours)) on top-left and Price (e.g., Total ৳ 1200) in Teal bold font on top-right.

Caregiver Profile Grid: 4-column responsive CSS grid (grid-template-columns: repeat(4, 1fr); gap: 20px;).

Card Design: White card, border: 1px solid #E2E8F0, rounded corners (border-radius: 8px).

Card Image: Square-ish avatar photo aligned top/left.

Card Bio: Light muted text in quotes ("Ability to learn quickly...").

Card Button: Full-width light cyan button (background: var(--brand-teal-light); color: var(--brand-teal); border: none; font-weight: 600; padding: 10px; border-radius: 6px;).

B. Single Caregiver Details Page
Layout: 2-Column CSS Grid (grid-template-columns: 280px 1fr; gap: 24px;).

Left Column (Profile Card):

Light gray background container (#F8FAFC).

Centered circular avatar photo, Name, Education, Biography, and Special Skills listed in structured blocks.

Right Column (Form):

"Family Members" Box: White card with light cyan border (#18B8A3), showing selected family member details.

"Additional Information": 2-column grid input form.

"Documents Upload Box": Dotted border box (border: 2px dashed #CBD5E1; padding: 20px; text-align: center;).

Submit Button: Full-width pill button colored Purple (var(--brand-purple)), text "Book Caregiver" in bold white.

C. Diagnostic & Lab Test Catalog ([ecare_lab_tests])
Location Sticky Bar: Flexbox container containing 4 dropdowns (Select Division, Select District, Select Area, Select Lab Provider).

Test List Grid: Rows showing Test Title, Price ("Starting from ৳ XXX"), and a blue/teal plus button (+) to add to WooCommerce cart.

D. Ambulance Request Form ([ecare_ambulance_request])
Ambulance Type Cards: 3 large selectable cards (Standard Non-AC, ICU AC, Freezer Type). Active card has green border and faint green tint.

Input Fields: Each input box includes a left-aligned icon (e.g., location pin, clock, phone icon).

Summary Sidebar: Right-aligned sticky card with feature icons (24/7, 100%, Oxygen) and a confirmation submit button.

3. Custom Admin Dashboard (Meditaj / Shukhee Style)
Top Metric Cards (4 Grid Columns): White cards (padding: 20px; border: 1px solid #E2E8F0; border-radius: 8px;). Displays uppercase label (e.g., TOTAL BOOKINGS) and a big bold number.

Action Header: Flex layout with Search Bar on the left and a Solid Green Primary Button (#0E9F6E) on the right (+ Add Booking / + Register).

Data Tables: Borderless modern table rows with soft status pill badges (Active = Light Green BG, Pending = Light Yellow BG).

