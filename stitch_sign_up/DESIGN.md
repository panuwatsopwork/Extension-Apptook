# Design System Document: The Lucid Core

## 1. Overview & Creative North Star
**Creative North Star: "The Architectural Refresh"**
This design system moves away from the "standard app" aesthetic by embracing the clarity of high-end editorial layouts and the depth of modern architecture. It is designed to feel like a breath of fresh air—clean, professional, and sophisticated. 

We break the "template" look through **Intentional Asymmetry** and **Tonal Depth**. Instead of boxing content into rigid grids, we use the primary Teal (#01b8bb) as a beacon of focus against a landscape of layered, neutral surfaces. The goal is a "Lucid" experience: one where the interface feels invisible, and the content feels curated.

---

## 2. Colors & Surface Philosophy
The palette is rooted in the primary Teal, supported by a sophisticated range of "cool neutrals" that prevent the UI from feeling flat or clinical.

### The "No-Line" Rule
**Strict Mandate:** Prohibit the use of 1px solid borders for sectioning. Traditional dividers create visual noise. Instead, boundaries must be defined solely through:
1.  **Background Color Shifts:** (e.g., a `surface-container-low` section sitting on a `surface` background).
2.  **Subtle Tonal Transitions:** Using the Spacing Scale to let white space act as the structural "wall."

### Surface Hierarchy & Nesting
Treat the UI as a series of physical layers. We use "Tonal Nesting" to create depth:
*   **Base:** `surface` (#f6fafa) — The canvas.
*   **Lowest Tier:** `surface-container-low` (#f0f5f4) — For large background sections.
*   **Highest Tier:** `surface-container-highest` (#dfe3e3) — For small, high-priority interactive elements.

### The "Glass & Gradient" Rule
To elevate CTAs, move beyond flat fills. Use **Signature Textures**:
*   **CTA Gradient:** Linear 135° transition from `primary` (#00696b) to `primary-container` (#01b8bb). This adds "soul" and a tactile, premium feel.
*   **Floating Navigation:** Apply `surface-container-lowest` with a 80% opacity and a `20px` backdrop-blur to create a "frosted glass" effect for headers or floating bars.

---

## 3. Typography: Editorial Authority
We utilize a dual-typeface system to balance character with readability.

*   **Display & Headlines (Manrope):** Chosen for its geometric modernism. High-contrast sizing (e.g., `display-lg` at 3.5rem) should be used to create clear entry points in the layout. Use **Asymmetric Alignment**: Don't be afraid to pull a headline into the left margin while body text remains centered.
*   **Body & Labels (Inter):** The workhorse. Inter provides maximum legibility at small scales. 
*   **Hierarchy Note:** Always maintain a minimum 2-step jump in the typography scale between a title and its supporting body text to ensure a clear "reading path."

---

## 4. Elevation & Depth: Tonal Layering
Traditional shadows are a fallback; tonal layering is the standard.

*   **The Layering Principle:** Place a `surface-container-lowest` card on a `surface-container-low` section. This creates a "soft lift" that feels integrated into the environment rather than hovering over it.
*   **Ambient Shadows:** When a float is required (e.g., a Modal), use an extra-diffused shadow: `box-shadow: 0 20px 40px rgba(23, 29, 29, 0.06)`. Note the use of the `on-surface` color (#171d1d) for the shadow tint—never use pure black.
*   **Ghost Borders:** If accessibility requires a border, use `outline-variant` (#bcc9c9) at 20% opacity. It should be felt, not seen.

---

## 5. Components: Refined Interaction

### Buttons
*   **Primary:** Features the signature Teal-to-Teal-Container gradient. Radius: `md` (0.75rem).
*   **Secondary:** `surface-container-high` background with `on-surface` text. No border.
*   **Tertiary:** Ghost style. No background, `primary` text. Use for low-priority actions.

### Input Fields
*   **Design:** Avoid the "box" look. Use `surface-container-low` as the fill. 
*   **States:** On focus, transition the background to `surface-container-lowest` and add a 2px bottom-weighted "accent bar" in `primary` (#00696b).
*   **Error:** Use `error` (#ba1a1a) for helper text and a subtle `error-container` (#ffdad6) background tint for the field.

### Cards & Lists
*   **The Divider Ban:** Never use lines to separate list items. Use `spacing-6` (1.5rem) of vertical white space or a subtle hover state shift to `surface-container-high`.
*   **Cards:** Radius: `lg` (1rem). Use Tonal Layering (e.g., a white card on a light teal background) to define the container.

### Modern Navigation (The Floating Dock)
Instead of a pinned bottom nav, use a floating "Dock" component. 
*   **Style:** `surface-container-lowest` at 90% opacity + backdrop blur. 
*   **Corner Radius:** `full` (9999px). 
*   **Active State:** Use a `primary-fixed` (#88f4f5) pill behind the active icon.

---

## 6. Do’s and Don’ts

### Do:
*   **Embrace the Void:** Use the Spacing Scale generously. If you think it needs more space, use the next size up.
*   **Stack Surfaces:** Use `surface-container` tiers to create hierarchy.
*   **Tint Your Shadows:** Always use a fraction of the `on-surface` color in shadows to maintain color harmony.

### Don’t:
*   **No 1px Lines:** Do not use borders to separate content. Use color shifts or space.
*   **No Pure Black:** Use `on-surface` (#171d1d) for text. It’s softer and more premium.
*   **No Symmetrical Overload:** Avoid perfectly centered, boxy layouts. Shift a headline or an image slightly off-axis to create an editorial, "designed" feel.