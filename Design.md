# Pixel-Perfect UI Redesign Guide for E-Care / Shukhee Platform

The previous design was too generic. We need to exactly replicate the provided screenshots. The UI is a modern, light-themed medical dashboard and frontend with specific shadow, border, and color treatments.

## 1. Global CSS Variables (MUST USE)
The platform uses a mix of Teal/Cyan (for the main frontend) and deep Purple/Green for specific buttons. Include these in the `:root`:

```css
:root {
  --brand-teal: #18B8A3;      /* Main active color, light borders */
  --brand-teal-light: #E6F7F5; /* Active tab backgrounds */
  --brand-purple: #8B2C7A;     /* Used for 'Book Caregiver' action button */
  --admin-green: #0E9F6E;      /* Admin dashboard primary buttons */
  --bg-main: #FFFFFF;
  --bg-gray: #F8FAFC;
  --border-color: #E2E8F0;
  --text-dark: #1E293B;
  --text-muted: #64748B;
  --shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
  --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1);
}

2. Frontend Layout Requirements (Strict HTML Structure)
A. Caregiver Filter Tabs & Grid ([ecare_caregiver_booking])
Structure Requirement:
Do not use standard generic buttons. Use Card-style radio buttons.

Select Caregiver Type: Horizontal flex container.

Inactive: White box, light gray border, image icon + text side-by-side.

Active: border-color: var(--brand-teal); background-color: var(--brand-teal-light); border-width: 2px;

Select Package: Horizontal flex container.

Content: Top left "Daily (24 Hours)", bottom right "Total ৳ 2200" (Text color teal).

Caregiver Cards (The Grid):

Grid: grid-template-columns: repeat(4, 1fr); gap: 20px;

Card CSS: White background, small shadow, rounded corners.

Card Content:

Image: Float left or top-aligned square-ish rounded image.

Name: Bold, 15px.

Bio: 12px, muted text, italicized quotes ("Ability to learn quickly...").

Button at bottom: Full-width, very light blue/teal background, text color teal, NO background fill (just light tint), with border radius.

B. Single Caregiver Details Page
Structure Requirement:
Two-column CSS Grid layout (grid-template-columns: 300px 1fr; gap: 30px;).

Left Sidebar (Profile Box):

Light gray background (#F8FAFC), padded.

Centered round profile image.

Details: Education, Biography, Special Skills listed underneath.

Right Column (Form & Details):

"Family Members" box: White card, light blue border, shows selected family member.

"Additional Information": 2-column grid form. Standard input fields with minimal borders.

"Documents" upload area: Wide dashed border box with upload icon.

Action Button: The "Book Caregiver" button MUST be colored Purple (var(--brand-purple)), rounded-full pill shape, placed at the bottom left of the form.

C. Admin Dashboard (Meditaj / Shukhee Style)
The admin panel must not look like the default WordPress backend. It must look like a modern SaaS app (React/Vue style).

Top KPI Cards (4 in a row):

CSS: display: flex; gap: 15px; margin-bottom: 20px;

Card style: White background, border: 1px solid var(--border-color); border-radius: 8px; padding: 20px; box-shadow: var(--shadow-sm);

Inside Card: Left side holds an icon, right side holds Title (e.g., "TOTAL BOOKINGS") in uppercase small font, and the Number in large bold font.

Action Bar (Above Data Table):

Flexbox, space-between. Left side: Title + total count badge + Search Input. Right side: Filter Button, Export Button, and a Green (var(--admin-green)) primary "+ Add Booking / + Register" button.

Data Table:

Modern borderless table. Only bottom-borders on rows (border-bottom: 1px solid #E2E8F0;).

Table Header: Uppercase, very small font (font-size: 11px; color: #94A3B8;).

Status Badges: Soft pills (e.g., Active = light green bg, green text).

D. Ambulance Request Form ([ecare_ambulance_request])
Ambulance Type (Top):

3 large selectable cards. Active state must have a faint green background and a dark green border.

Form Fields:

Each input field must have an icon inside the input on the left (e.g., Location pin icon for "Pickup Location", Clock icon for "Schedule Time").

Right Sidebar (Request Summary):

3 feature icons at the top (24/7, 100%, Oxygen).

A sticky summary card showing selected type and price, with a full-width confirmation button.


"The previous design did not match the required UI. I need a pixel-perfect replication of the 'Shukhee / Meditaj' UI shown in my reference documents. Read the new @Design.md carefully. You MUST use the exact HTML structures, Flexbox/Grid layouts, and CSS variables provided in the document. Do not invent your own UI patterns. Give me the updated ecare-style.css and the updated PHP shortcode HTML outputs."