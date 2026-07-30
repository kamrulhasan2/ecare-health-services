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