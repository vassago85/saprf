# Cursor prompt: SAPRF public SA Team page, Media page and homepage feature

Copy the mockup folder into the repo at `docs/mockups/saprf-sa-team/` first, then paste everything below the line into Cursor.

---

You are working in the SAPRF platform (saprf.co.za). Stack: PHP 8.3, Laravel 13, Livewire 4 + Volt, Flux UI, Tailwind 4, MariaDB, Spatie Permission (roles only), Pest. Follow the existing conventions in the codebase (folder layout, Volt vs class components, Flux components, policies, test style). Use the latest stable versions of anything you add; do not pin older majors.

The design reference is in `docs/mockups/saprf-sa-team/`: `sa-team.html`, `media.html`, `home-feature-and-admin.html` and `saprf-mockup.css`. Match the layout, hierarchy and spacing. Translate the CSS into Tailwind utilities and the existing theme tokens; do not ship the mockup CSS file. The mockup JavaScript is only there to fake data; replace it with Livewire/Blade.

## Step 0: investigate before changing anything

1. Find the route, component and models behind `/sa-team` and the IPRF team data (teams, members, divisions, colours, team manager/captain). Report what tables and relationships already exist.
2. Find the homepage "Team South Africa" block and where its image, heading and text currently come from.
3. Find the public layout used by the homepage and the authenticated app layout (sidebar, "View as" toggle).
4. Give me a short plan listing which existing tables you will extend and which you will add. Do not create tables that duplicate existing ones. Wait for my OK before writing migrations.

## Problem to fix

`/sa-team` is linked from the public nav but renders inside the authenticated app shell (sidebar, "View as Admin/Shooter", the logged-in user's name). It must render in the public layout for guests and logged-in users alike, with no auth middleware. It also shows all members in one flat list and no team photo.

## 1. Public SA Team page (`/sa-team`, see `sa-team.html`)

- Public layout, same header and footer as the homepage.
- Hero: full-width team photo for the selected year (object-cover, dark bottom scrim), "Team South Africa" pill, heading "Team South Africa", one-line intro, and three stat tiles computed from data: distinct athletes, total appearances, colours awarded (all years).
- Year switcher: one button per team year, newest first, selected year in the URL (`/sa-team/{year}` or `?year=`), default latest. Use `wire:navigate` or a Livewire property bound to the URL; deep links must work.
- Selected year header: year, event name, venue/city/country, dates, plus tiles for team captain, team manager and squad size (shooters only, excluding manager/officials).
- Roster grouped by division in cards (Open, Factory, Limited, Ladies, Juniors, Seniors; use the division order already defined in the system if there is one). Each row: initials avatar (or member photo if one exists), name, province, "Captain" badge where applicable, "Colours" badge where awarded. Officials (manager etc.) appear in the header tiles, not in division cards.
- "Past teams" grid: one card per year other than the selected one, with cover photo, year, event, venue and squad size, linking to that year.
- Honour roll: every person who has represented SA, A to Z, with the years they were selected. Client-side or Livewire search by name. Officials shown with their role.
- Empty states: a year with no photo shows a neutral placeholder; a year with no members shows "Squad to be announced".
- SEO: page title and meta description per year, Open Graph image = that year's team photo.

## 2. Media (`/media` and `/media/{album:slug}`, see `media.html`)

Data model (adapt names to the codebase):

- `media_albums`: title, slug, category (enum: team_sa, sa_champs, nationals, provincial, other), optional link to a match/event and/or an IPRF team year, event date, cover media item, published flag, sort order.
- `media_items`: album id, image path (store on the configured disk, generate responsive sizes and WebP; keep the original), caption, photo credit, alt text (required), taken at, sort order, plus homepage feature fields: `is_home_feature` (boolean), `home_label`, `home_headline`, `home_summary`, `home_link_type` (sa_team, album, results, custom), `home_link_url`.
- The IPRF team record gets a nullable `cover_media_item_id` so the SA Team hero uses a media item rather than a separate upload.

Public pages:

- `/media`: page heading and intro, a large "Featured" card for the current homepage feature, category filter chips (All, Team SA, SA Champs, Nationals, Provincial) bound to the URL, and an album grid (cover, category eyebrow, title, venue/date, photo count). Only published albums.
- `/media/{slug}`: album header, responsive photo grid, accessible lightbox (keyboard, Esc to close, focus trap, alt text, caption and credit shown), and links back to the related match or team year where set.
- Add "Media" to the public nav between "SA Team" and "Documents".

## 3. Homepage feature band (see top of `home-feature-and-admin.html`)

- Replace the hard-coded Team South Africa block with a component that reads the single media item where `is_home_feature = true`.
- Shows label pill, headline, summary, primary button (from `home_link_type`/`home_link_url`, default "Meet the team" to `/sa-team`), secondary "View gallery" button to that item's album, and the image with its caption.
- If no item is featured, fall back to the latest team year's cover photo; if that is also missing, hide the band.
- Cache the featured item and bust the cache when media items change.

## 4. Admin (see bottom of `home-feature-and-admin.html`)

- Admin screens for albums and media items using Flux UI, in the existing admin area, behind a new permission/role check consistent with how the codebase does it (Exco and admins can manage; match directors can add photos to albums linked to their own matches if that fits the existing permission model, otherwise leave it out and tell me).
- Bulk upload into an album (multiple files, progress, drag to reorder), edit caption/credit/alt per item, set album cover.
- "Feature on homepage" toggle on a media item with label, headline, summary and link fields. Setting it on one item clears it on all others inside a DB transaction, so exactly one item is featured. The previously featured item stays in its album.
- On the IPRF team admin screen, a picker to choose the team's cover photo from media items.

## 5. Tests (Pest)

- `/sa-team` returns 200 for guests, uses the public layout, and does not render the app sidebar or user menu.
- Year switching and deep links work; stat tiles count correctly (distinct people vs appearances vs colours).
- Officials are excluded from division cards and squad size.
- Only one media item can be featured; featuring a new one unfeatures the old.
- Homepage fallback order works (featured item, then latest team cover, then hidden).
- Unpublished albums 404 publicly; guests cannot reach admin routes; alt text is required on upload.

## Constraints

- Do not invent data. Seed nothing except what already exists; earlier team years will be captured through the admin.
- Accessible markup: real buttons and links, labels on inputs, 44px touch targets, text contrast at least 4.5:1, images with alt text.
- Mobile first: stats and tiles wrap, division cards stack, filter chips wrap, no horizontal scroll at 360px.
- Keep queries efficient: eager load members, divisions and media; no N+1 on the honour roll.
- When done, list the files changed, migrations added, and anything you could not match from the mockups.
