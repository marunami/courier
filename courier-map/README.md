# Map Block

A Gutenberg block for building maps of locations, with address autofill, a
list and detail panel, and category filtering.

**Files:** `plugins/courier/blocks/courier-map/`

`block.json`, `index.php`, `index.ts`, `edit.tsx`, `render.php`, `view.ts`,
`style.scss`, `index.scss`

## What it does

An editor adds locations by typing an address and clicking Autofill. That
pulls the title, coordinates, phone, website, rating, price level, description,
and hours of operation from the Places API, and the editor can then correct
anything or fill in fields by hand. Locations can be tagged into categories
with their own labels and colors, which drive filter tabs on the front end.

The front end renders a map with colored markers, a side panel that goes from
a list to a detail view, action buttons for Call, Directions, Website and
Share, and an hours table with today's row bolded.

## Why it works the way it does

**It's a block, not a post type and shortcode.** That was the first attempt:
a Map CPT with an admin location builder and a `[courier_map]` shortcode. I
built it and then abandoned it. The admin UI kept hitting REST nonce and
`apiFetch` failures inside meta boxes, and I was writing a lot of code to
rebuild things the block editor already does. Moving to a block meant the
locations live in block attributes, the editor UI is just React components, and
there's no separate admin screen to maintain. Less code, fewer moving parts.

**Autofill went through three approaches.** `PlaceAutocompleteElement` first,
which wouldn't load reliably in the editor context. Then
`google.maps.Geocoder`, which returns coordinates but nothing else, so an
editor would still be typing in the phone number and hours by hand. Settled on
`Place.searchByText()` from the Places API (New), which returns the whole
business record from a plain address string. That needs both the Places API
(New) and the Geocoding API enabled on the Cloud project, which isn't obvious
from the error you get when they aren't.

Price level comes back as an enum like `PRICE_LEVEL_MODERATE`, so
`PRICE_LEVEL_MAP` strips the prefix and maps it to dollar signs. Hours come
back as `weekdayDescriptions` strings like `Monday: 9 AM – 5 PM`, split on
`': '` into a day-keyed record.

**One shared Google Maps script handle.** The editor needs Maps for geocoding
and the front end needs it to draw the map, and for a while they were fighting
over it and double-loading. Both paths now call
`courier_courier_map_enqueue_maps_bootstrap()`, which guards on `wp_script_is`
and bails if the handle is already queued or printed. The editor loads it in
the header because `importLibrary()` has to exist before the editor component
mounts; the front end loads it in the footer.

It uses Google's dynamic `importLibrary` bootstrap rather than a plain script
URL, so only the libraries actually needed get fetched the block's TypeScript
pulls in geocoding when the Autofill button is pressed, not on page load.

**The API key comes from `vip_get_env_var( 'GOOGLE_MAPS_API_KEY' )`.** Maps
keys are necessarily public since they're used in browser-side calls, so the
protection is HTTP referrer restrictions on the Cloud project rather than
hiding the key.

## Two bugs worth mentioning

`get_block_wrapper_attributes()` plus a separate `class="courier-map-block"`
attribute produced two `class` attributes on the same element. The browser
takes the first one, so `view.ts`'s `querySelector` never matched and the map
silently didn't render. Fixed by passing the class into
`get_block_wrapper_attributes()` instead.

Google's default POI icons were doubling up with the custom colored markers,
so `clickableIcons: false` plus a `styles` array targeting `poi` feature types
suppresses them. There's also a `wrapper.dataset.courierMapInitialized` guard
because `view.ts` can run more than once depending on DOM readiness.
