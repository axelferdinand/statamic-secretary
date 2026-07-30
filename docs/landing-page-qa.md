# Landing page QA

Last reviewed: 2026-07-29

This document records the design, UX, copy, accessibility, and conversion review of the Statamic Secretary landing page. The landing page work is intentionally postponed while the product UX is improved.

## Overall assessment

The foundation is good, but the page currently feels more like a polished SaaS concept than a fully convincing Statamic product.

| Area | Assessment |
| --- | ---: |
| Product idea and positioning | 8/10 |
| Design direction | 7/10 |
| Copy | 6.5/10 |
| UX and conversion | 6/10 |
| Accessibility | 6/10 |
| Launch readiness | 6/10 |

## Keep

- The main idea, “Your Statamic site just hired a secretary”, is memorable.
- Email and Control Panel chat are communicated quickly.
- Draft-first behavior, blueprint validation, and human control are the right trust signals.
- The navy, mint, coral, and paper palette has a recognizable character.
- An interactive demo is the right device: the product should be demonstrated, not merely explained.
- Pricing is visible without sales tricks.
- English and Norwegian versions, canonical links, Open Graph, schema, reduced-motion support, and consent logic provide a solid technical foundation.
- The page does not use fabricated testimonials.

## Highest-priority improvements

### 1. Send every CTA to the actual product

All purchase buttons currently point to the generic Statamic Marketplace front page. Before the listing exists, use a launch-list or notification CTA. After publication, every purchase CTA must point directly to the Secretary listing.

### 2. Make the demo internally truthful

Users can choose three examples and type freely, but the result is always the same Friday-hours change. The Control Panel tab mostly hides the email fields instead of demonstrating the actual embedded CP chat.

Create three coherent demo flows:

1. Change opening hours: show field changes and the resulting draft.
2. Create a child page: show blueprint, parent, slug, and draft status.
3. Refine copy: show a real before-and-after text comparison.

The CP tab should resemble the real floating Secretary panel. Each result should end with actions such as “Open draft in Statamic” and “Continue refining”.

### 3. Use the site-specific hosted address

The page still uses `secretary@statamic.no` and describes the hosted service as one shared address. Show a clear site-specific example such as `acme.no@statamic.no`, and explain that approved Statamic users are paired with the correct installation.

Update the hero demo, channel illustration, pricing, and FAQ together.

### 4. Add verifiable product proof

The page needs to answer:

- Who built this?
- What does a real Statamic draft look like?
- Can I try an actual Statamic installation?
- Which Statamic and PHP versions are supported?
- What happens when revisions are not enabled?
- How has the addon been tested?

Add a compact “Built for real Statamic sites” section with:

- a link to the live demo;
- a real CP screenshot or short recording;
- supported versions;
- a concise capability boundary;
- the developer’s name and face;
- verifiable test or implementation facts where useful.

## Design

The visual system has character, but too many devices compete at the same time:

- grids;
- orbits and circles;
- rotated cards;
- a moving ticker;
- heavy 3D buttons;
- floating code labels;
- stars;
- oversized headings;
- many decorative arrows.

Remove roughly 40 percent of the decorative layer. Keep the colors, dark hero, and product window. Consider removing the ticker, demo arrow, most circles, and most decorative arrows. Let real Statamic UI provide more of the visual texture.

### Typography

The display font currently relies on `Arial Rounded MT Bold` and system fallbacks, so the composition will change across operating systems. Use one self-hosted, licensed type family with the required weights to control line breaks and optical alignment.

### Product icon

The current pen, document, and shield combination is too detailed at small sizes and communicates document security more strongly than a Statamic content assistant. A simpler mark based on conversation, structure, and draft status would be more recognizable and easier to own, especially given the existing secretary.no brand.

### Open Graph image

The current image has good dimensions and hierarchy, but it contains two prices that can become stale. Prefer one promise, one product visual, and the product/domain name unless pricing is generated from one source of truth.

## Copy

The English copy is generally stronger than the Norwegian copy. Several Norwegian phrases read like direct translations:

| Current | Suggested |
| --- | --- |
| Snakk menneske | Skriv som til en kollega |
| Behold Statamic | Blueprintet setter grensene |
| Se før du sender | Utkast før publisering |
| Ingen bitte liten utvikleroppgave | Små innholdsendringer skal ikke bli utvikleroppgaver |
| Behold kontrollen. Mist køen. | Behold kontrollen. Slipp innholdskøen. |

“Small things”, “tiny change”, and equivalent formulations are repeated often enough to reduce perceived product value. Secretary can also create pages, conduct multi-turn refinement, follow structure, and publish on explicit request.

Suggested English hero:

> **Your Statamic site just hired a secretary.**
>
> Editors ask by email or in the Control Panel. Secretary finds the right content, follows its blueprint, and prepares a reviewable draft. Nothing publishes until a human says so.

Suggested Norwegian hero:

> **Statamic-siden din har fått en innholdsassistent.**
>
> Redaktøren ber om endringen på e-post eller i kontrollpanelet. Secretary finner riktig innhold, følger blueprintet og lager et utkast til gjennomgang. Ingenting publiseres før et menneske ber om det.

## Mobile and accessibility

Confirmed code-level findings:

- The purchase button is hidden below 760 px without a replacement mobile navigation or purchase CTA.
- Coral text on paper has a contrast ratio of approximately 2.56:1.
- White text on coral buttons has a contrast ratio of approximately 2.86:1.
- The language link and several demo chips are smaller than recommended touch targets.
- The demo tab list lacks complete keyboard navigation and associated tab panels.
- The ticker repeats content for screen readers.
- The Norwegian page includes hardcoded English labels such as “Draft ready”, “inside the line”, “Primary”, and “Product principles”.
- Large mobile headings and fixed minimum card heights make the page longer than necessary.
- The consent panel occupies a large part of the initial mobile viewport and competes with the hero CTA.

## Recommended page structure

1. Hero with product window and a clear primary CTA.
2. Four concrete product promises.
3. One coherent demo with email/CP and three matching outcomes.
4. “Easy for editors. Safe for developers.”
5. Real product proof and live demo.
6. Pricing: own Postmark setup versus hosted address.
7. Short FAQ.
8. Final CTA.

The current problem cards, ticker, and channel section repeat information that the demo can communicate more effectively. A page that is 25–35 percent shorter should be easier to understand and convert better.

## Remaining visual verification

Browser automation was blocked for both the production and local QA URLs during this review. The findings above are based on the complete markup, CSS, interaction logic, and image assets. Actual optical alignment, line wrapping, scroll rhythm, and component behavior still require a manual desktop and mobile visual QA pass before the landing page is approved.
