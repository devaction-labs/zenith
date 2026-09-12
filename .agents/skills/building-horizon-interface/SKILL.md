---
name: building-horizon-interface
description: Use when creating or structurally changing Zenith screens, panels, tables, detail views, tabs, search or filter toolbars, loading states, or responsive layouts. Applies the package's shared interface system and consumer-browser proof requirements.
---

# Building the Horizon Interface

## Overview

Build new UI from Zenith's shared layout system instead of reconstructing spacing, responsive behavior, and interaction states per screen. Keep implementation and review guidance aligned through one project reference.

## Required guidance

Read [project-adjustments.md](../reviewing-horizon-interface/references/project-adjustments.md) before editing. Treat its primitive map, density rules, responsive contracts, and state behavior as the project interface specification.

Also use:

- `tailwindcss-development` when changing JSX layout or Tailwind classes.
- `inertia-react-development` for page props, visits, tabs, filters, polling, deferred props, prefetching, or infinite scroll.
- `shadcn` when selecting, installing, or changing UI primitives.
- `reviewing-horizon-interface` before handoff.

## Build workflow

1. Establish authority in this order: explicit user direction and selected browser evidence, supplied designs, shared project primitives and nearby screens, then general interface guidance.
2. Find the closest existing screen and the shared primitive responsible for each seam. Fix the primitive and sweep its consumers when the rule is repeated; keep a local exception only when the product behavior is genuinely different.
3. Compose the screen from the primitive map in the shared reference. Do not copy a utility-class bundle from another page or bake a screenshot's one-off width, copy, or data into a reusable rule.
4. Preserve Inertia ownership. Keep URL-backed state in real links or visits, retain useful content during polling, and let deferred props and infinite scroll own their loading behavior.
5. Exercise every meaningful state: populated, empty, initial deferred load, background refresh, error, long content, disabled and destructive actions, and reduced motion.
6. Validate at phone, intermediate/sidebar-constrained, and desktop widths. Rebuild and publish assets into the named consumer, then prove the exact consumer URL through at least one refresh interval.

## Hard gates

- Use semantic theme tokens and installed shadcn/Base UI primitives. Do not add raw status colors or manual dark-mode overrides.
- Reuse shared formatters, action triggers, empty states, detail rows, table parts, and responsive tab headers.
- Do not add or modify automated tests for a pure UI change unless the user explicitly requests them. Browser validation is required.
- A source render, component test, build, or stale browser tab is not consumer proof.
