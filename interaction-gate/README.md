# Interaction Gate

Holds ad tech and third-party tracking scripts until the visitor actually
interacts with the page, then releases them.

**File:** `plugins/courier/src/features/class-interaction-gate.php`

Registered in `main.php` as a `Documented` feature, same as every other
feature in the plugin.

## The problem

Mobile PageSpeed across the network was in the low 40s. Total Blocking Time
was around 4.3s on throttled mobile in DebugBear. Almost all of it was the ad
stack: the Aditude wrapper loads prebid, prebid loads a dozen bidders, and the
bidders fire a cookie-sync storm. None of that is needed before someone starts
reading.

## Why it works the way it does

There are two delivery paths because the scripts arrive two different ways,
and each needs a different mechanism.

**Scripts we enqueue ourselves** (the Aditude wrapper, jQuery, the slider) get
neutralized server-side in `script_loader_tag`. The tag gets rewritten to
`type="text/courier-gate"` with the real URL parked in `data-courier-src`, so
the browser parses it but won't execute it. This path is race-free because it
happens before the HTML ever reaches the browser.

**Scripts injected at runtime** by prebid and GTM can't be filtered in PHP,
because they don't exist yet when PHP runs. Those get caught client-side by a
MutationObserver watching for matching `<script>`, `<link>`, and `<iframe>`
nodes and neutralizing them as they're inserted.

Killing the Aditude wrapper is what does most of the work. Prebid never
initializes before interaction, so most of the bidder and sync traffic never
gets requested at all. Without that, I'd have had to enumerate every bidder
host by hand and keep that list current forever.

Stylesheets are handled differently again: they get flipped to `media="print"`
so the browser still downloads them at low priority but they don't block
render, and the original media value is stashed in `data-courier-media` to
restore on activation.

The runtime is plain ES5 printed inline at `wp_head` priority 1. It has to
parse and run before anything else on the page, so a built entry file loading
later is no use — by then the scripts it's supposed to catch have already run.
It's inline for the same reason GTM's loader is inline, and it inherits the
CSP nonce the same way.

`is_feed()` gets called in `print_runtime()` and not in `boot()`, because
conditional query tags aren't available that early in the request. That one
cost me an afternoon.

## The limitation

Anything GTM injects can't be reliably gated, because GTM is the injector and
it loads before the observer can intervene. The Meta Pixel is the example: it
was printed as a raw `<script>` via `wp_head`, so neither the filter nor the
observer caught it. I ended up moving the pixel into the gate's activation
callback instead, which works but is a one-off rather than a general solution.

Gating GTM itself isn't an option because the regwall and GA4 depend on it.
I proved this empirically with DevTools request blocking rather than guessing:
blocking GTM's two GA4 property loads moved Lighthouse from 72 to 92, while
Parse.ly and fonts were worth about 4 points each.

## Result

TBT went from ~4.3s to ~774ms on throttled mobile. Mobile PageSpeed moved from
the low 40s into the 90s.

There's a 60 second fallback timer so anyone who never interacts still gets
ads and analytics eventually, and `window.__courierGate.activate` is exposed
so I can trigger it by hand when testing on develop.
