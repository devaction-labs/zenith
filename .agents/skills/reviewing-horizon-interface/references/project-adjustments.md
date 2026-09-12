# Project-specific UI review criteria

This file contains project-specific UI review criteria used when reviewing Zenith UI changes. Apply each criterion without preserving one-off copy, record-specific dimensions, or obsolete pixel values.

## Fidelity before invention

- Open every supplied screen and asset before implementing. Compare the rendered page at matching dimensions instead of reconstructing the design from memory.
- Use the supplied icon language and current navigation icon map. Keep related empty states and navigation visually consistent.
- Do not add visual garnish merely because a component library offers it. Status pills and badges stay text-only unless the current design explicitly includes an icon.
- Use project shadcn/Base UI components first. Create a custom primitive only after confirming the installed set cannot express the design.

## Fix systems, then sweep consumers

- Repeated border, padding, radius, hover, header, tab, or table defects usually belong in a shared primitive. Identify that seam, then inspect every consumer.
- Do not stop after correcting the route where the user noticed the problem. Sweep equivalent pages and states.
- Preserve deliberate differences between cards, tables, detail panels, and destructive actions; shared does not mean visually identical.
- Keep form inputs and compact search/filter inputs as intentional variants instead of gradually collapsing their radius, height, and spacing into one accidental style.
- When a tab changes a panel, verify that sibling content outside the tab's ownership remains visible.

## Compose from the shared layout system

Use the existing primitive that owns each seam. Do not repeat its internal Tailwind classes in every page.

| Surface | Shared owner | Contract |
| --- | --- | --- |
| Page and panel frame | `HorizonLayout`, `Card`, `CardHeader`, `CardAction`, `ListPageHeader` | The shell owns outer gutters; cards own borders, radius, and header alignment. |
| Tabs and tabbed results | `ResponsiveTabsHeader`, `TabbedResultsCard` | Use a select below `sm`, line tabs from `sm`, counts in both modes, and a separated right-side action area. |
| Tables | `Table*`, `SortableTableHead`, `TableEmpty` | Shared cells own responsive gutters, vertical alignment, sticky headers, separators, and empty geometry. |
| Detail metadata | `DetailList`, `DetailListItem` | Keep labels and values top-aligned; use the shared scrollable-value behavior for opaque identifiers. |
| Statistics | `Statistic*` | Preserve the shared hierarchy, tabular numerals, separators, and responsive grid behavior. |
| Feedback and loading | `Alert`, `Empty*`, `TableEmpty`, `Skeleton` | Use semantic variants and stable geometry for initial, empty, warning, and error states. |
| Actions | `ActionMenuTrigger` | Keep ellipses and comparable toolbar actions the same color, hit area, disabled behavior, and focus treatment. |
| Structured payloads | `CodeBlock`, payload components | Preserve preformatted, non-wrapping data with local horizontal scrolling. |

### Density and edge alignment

- Use the compact page stack owned by `HorizonLayout`: `gap-[7px]`, widening to `gap-3.5` at the desktop shell seam.
- Use `px-4` for compact inner content and `sm:px-6` once space allows.
- Align right-edge header, search, filter, and row actions with `pr-2.5 sm:pr-6`.
- Let the primitive supply these values. Add page-level padding only when the content intentionally leaves the shared grid.
- Give text and search regions `min-w-0 flex-1` and buttons, filters, counts, and actions `shrink-0`. Avoid overlapping hit areas.

### Responsive behavior

- Verify phone, intermediate/sidebar-constrained, just below the desktop shell seam, and desktop widths. The current desktop shell seam is `1140px`.
- Keep descriptive table columns flexible and truncated with a `title`; let compact numeric columns size to their heading or largest visible value and use right-aligned tabular numerals.
- Constrain table overflow locally on small and intermediate screens. Remove narrow-screen minimum widths at the desktop shell seam so desktop columns can use the available card width.
- Keep row actions narrow and right aligned. Do not make a short numeric column consume space that belongs to the descriptive column.
- Use `ResponsiveTabsHeader` for every panel-level tab set. URL-backed tabs remain real Inertia links or visits, preserve relevant query state, and scroll the active desktop tab into view.
- Use the sidebar primitives for child navigation. Child rows fill the available width, use deliberate indentation and vertical separation, and align counts to the same right edge as parent counts.
- On detail screens, use a one-third label and two-thirds value split on phones, then the shared fixed-label grid from `sm`. Keep both columns top aligned.
- Human prose may wrap. Opaque IDs use `DetailListItem`'s single-line local scroll, hidden scrollbar, keyboard access when needed, and directional edge fades only while more content exists.
- Keep JSON and other data blobs on one line per source line with horizontal overflow; never force structured payloads to soft-wrap.

### Async states and motion

- Use a geometry-matched skeleton only when content is unavailable on the initial deferred load. Give one container `role="status"` and hide repeated decorative skeleton pieces from assistive technology.
- Preserve already-rendered content during polling, filter-catalog refreshes, and background requests. Do not replace it with an initial-load skeleton or infinite-scroll fallback.
- Let Inertia infinite scroll own next-page loading. Do not layer custom sentinels or unrelated fallbacks on top.
- Prefetch or defer expensive derived controls when user intent is likely; do not block the first meaningful page render.
- Do not show transient “unavailable” alerts for expected deferred work. Keep a known count until a replacement is ready, then update it atomically.
- Show a “new results” affordance only when automatic refresh is off.
- Animate changed progress properties smoothly and honor reduced motion. Inertia updates do not require progress to jump.

### Shared presentation rules

- Use the shared count and duration formatters. Navigation and tab counts may abbreviate; data tables keep full values unless the established surface says otherwise.
- Use `bg-muted` for empty-state icon containers and semantic alert variants for messages. Do not reintroduce old white outlined banners.
- Keep filter icons, row ellipses, and panel-header ellipses on the same action color system.
- Decorative icons are `aria-hidden`; icon-only controls have an accessible name.
- Do not convert a current implementation detail into a universal rule. Exact table minimum widths, overflow-fade widths, record names, and task-specific copy remain local decisions.

## Validate meaningful states

- Exercise populated data, long queue names and UUIDs, empty results, realistic skeletons, refreshing/polling, errors, disabled actions, and destructive confirmations.
- Use the isolated demo workload when real queue behavior matters. Empty screens and mocked component renders do not prove a Horizon workflow.
- Verify desktop, phone, and the intermediate width where a fixed sidebar begins to constrain content. Check menu dismissal after navigation and horizontal overflow.
- Check light, dark, and system theme behavior when shared tokens or controls change.
- Use tabular numerals consistently for metrics and numeric columns. Keep ordinary identifiers in the surrounding typeface unless the design treats them as code.

## Respect product semantics

- Derive labels and states from Horizon/Laravel behavior and configured thresholds. Avoid broad terms such as "health" when the interface is specifically reporting a wait-threshold boundary.
- Keep actions where the established page hierarchy expects them. Avoid scattering related actions across rows, headers, and overflow menus without a product reason.
- Prefer quiet, information-dense presentation. Add icons, animation, copy affordances, or badges only when they clarify an action or state.
- Whole-row navigation must coexist with nested links, menus, and disabled controls. A disabled child action must never click through and activate the row.

## Prove the consumer

- The package owns source and compiled assets, while a consuming Laravel application serves the UI. Rebuild, run the package installer in the consumer, and reload the exact named URL.
- When no other consumer is named, prove `https://horizon-demo.nmbp/horizon/` after `php artisan zenith:install --force --no-interaction` in `/Users/nckrtl/apps/horizon-demo`.
- Confirm the browser loaded the new asset hashes. Inspect console and network failures and retest routes across at least one polling/cache interval.
- A green package build, Workbench page, or stale browser tab is not final UI evidence.
