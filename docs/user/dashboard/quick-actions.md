# Quick Actions

## What It Does

Shows contextual prompts on the dashboard to help you take the next useful step — a **Set up Plathix** block with onboarding cards, and a **migration banner** when a compatible plugin is detected.

## Set Up Plathix Block

Cards appear only while their underlying condition applies, and disappear on their own once it is resolved:

- **Enable infinite scroll** — shown while infinite loading for the Media Library grid is disabled. Links to **Plathix → Settings** (General).
- **Review SVG policy** — shown while SVG uploads are enabled with the sanitize policy. Links to **Plathix → Settings** (SVG).

PRO modules can add their own cards to this block (for example, **Enable audit log**).

### Dismissing a card

Each card has its own **×**. Dismissing a card hides only that card — it does not affect the others, and does not stop future cards (including PRO cards) from appearing. The dismissal is stored per card, per user, and is permanent — a dismissed card does not come back on its own.

The whole block disappears on its own once no cards are left to show (every card is either dismissed or its underlying condition is resolved).

## Migration Banner

**Import from [Plugin name]** — shown when a compatible plugin (FileBird, Real Media Library, etc.) is detected and active. Offers a one-click path to **Plathix → Tools → Import**.

The banner has its own **×**. Dismissing it hides the banner for the detected plugin permanently for your user; if a different compatible plugin is detected later, the banner appears again for that one.

## Notes

- Quick actions are suggestions, not required steps. The plugin works fully without acting on them.
- The dashboard checks for a migration plugin on each page load (lightweight check, no extra queries).

## Related

- [Health notices](health-notices.md)
- [Presets](../presets/index.md)
- [Import](../import/index.md)
- [PRO overview](../pro/index.md)
