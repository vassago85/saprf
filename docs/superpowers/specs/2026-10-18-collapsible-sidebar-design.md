# Collapsible sidebar (desktop icon rail)

**Date:** 2026-10-18  
**Status:** Approved — implement

## Decision

Desktop (lg+) sidebar collapses to an icon rail. Preference persists in `localStorage` (`saprf.sidebarCollapsed`). Mobile drawer unchanged.

## Behaviour

- Default: expanded
- Toggle: chevron control in sidebar header (lg+ only)
- Collapsed: ~`w-16`, icons + `title` tooltips, hide section headings / sponsors / verbose chrome
- View-mode toggle: icon-only when collapsed, or hidden if too cramped
- Profile + Sign out: icon-only when collapsed

## Approach

Alpine state on `x-layouts.app` + `data-sidebar-collapsed` + minimal CSS overrides for Flux navlist. No Flux Pro, no nav catalog rewrite.
