# reCAPTCHA v3 for HTMX Newsletter Forms

One global listener that gates every newsletter signup request on the network,
regardless of which block rendered the form.

**Files:**

| File | Original path |
|---|---|
| `index.ts` | `plugins/courier/entries/recaptcha/index.ts` |
| `index.php` | `plugins/courier/entries/recaptcha/index.php` |
| `class-recaptcha.php` | `plugins/courier/src/features/class-recaptcha.php` |
| `endpoint-verification.php` | excerpt from `plugins/courier/src/features/class-newsletter-signup-form-endpoint.php` |

The endpoint excerpt is included because it's half the feature. The client
generates the token, but the server is what interrupts the submission, calls
Google, checks the score, and decides whether the signup goes to Sailthru.
Without that side you're only looking at half the flow.

## The problem

Newsletter signups were getting hit with bot traffic. The forms are HTMX, and
there are three kinds: an inline block, a multi-newsletter block, and a regwall
form that gets injected into the DOM after page load.

The first version put reCAPTCHA in each block separately. That meant per-form
state, listeners that had to be re-bound after every HTMX swap, and double
handlers when two forms were on one page. It broke constantly and I spent
days chasing 403s that turned out to be timing rather than tokens.

## Why it works the way it does

I tore it out and rebuilt it around the endpoint being submitted to instead of
the form doing the submitting. One listener on `document.body`, routing on
`detail.path`. The regwall doesn't know reCAPTCHA exists. Neither does the
inline block or the multi block. Nothing needs re-binding after a swap, and a
dynamically injected form is covered automatically because the listener is on
the body, not the form.

Routing on the request path rather than a CSS class is deliberate. The path is
the one thing that can't drift when someone edits markup.

**`htmx:confirm`, not `htmx:configRequest`.** This is the part that took the
longest to get right. `configRequest` is synchronous — htmx has already
gathered parameters by the time it fires, so there's no way to await
`grecaptcha.execute()` inside it. My first attempt used `preventDefault()`
plus a flag plus a synthetic re-dispatched submit, which is fragile exactly
where it bit me. `htmx:confirm` exists for async gating: `preventDefault()`
holds the request and `detail.issueRequest()` releases it once the promise
resolves. Passing `true` skips htmx's built-in `window.confirm`.

There's no clean channel from `confirm` to `configRequest`, so the token gets
stashed on `detail.elt` and consumed on the way out. Slightly hacky, but
stable, and it's what htmx's own async-auth example does.

**The script loads on email field focus**, not on every pageview. It was
enqueued unconditionally on every page in the network, which meant every
article was paying for a script that only matters if someone signs up.
`getRecaptchaToken()` calls the loader itself as a fallback in case a
submission somehow happens without a prior focus event.

**GA4 client ID rides along.** Since `configRequest` was already being
intercepted for these endpoints, the client ID gets read out of the `_ga`
cookie and attached to the same request, so Sailthru profiles can be joined
back to GA4 sessions. It's read from the cookie rather than `gtag('get', ...)`
because the state sites don't load `gtag.js` directly. Coverage is partial by
design — no cookie before analytics consent means no client ID, which is
correct behavior rather than a gap to work around.

## Decisions worth explaining

**Missing secret key fails closed in production, open locally.**
`verify_recaptcha()` returns `wp_get_environment_type() === 'local'` when the
secret isn't set, so a misconfigured production environment rejects
submissions rather than silently accepting everything, but local development
still works without credentials.

**Network errors fail open.** If Google can't be reached, the submission goes
through. A Google outage blocking every newsletter signup on 21 sites is worse
than letting some spam through for a few minutes.

**Empty token fails closed.** This was originally the other way around during
debugging, and it's worth knowing why: with fail-closed on empty, any gap in
the client-side JavaScript turns into a hard 403 with no signal about what
broke. That's how I found out the architecture was wrong in the first place.

**Blocked submissions return HTTP 200 with an `HX-Trigger` header,** not 403.
HTMX needs a successful response to swap in error UI, and the trigger fires a
`newsletter_signup_blocked` dataLayer event so blocks show up in GA4 with a
`block_reason` of either `honeypot` or `recaptcha`. No form data goes to GA4.

## A VIP-specific gotcha

VIP environment variables are not PHP constants. `defined()` returns false for
them, always. The site key is a constant in `vip-config` while the secret is an
env var, and that mismatch is where a lot of the early debugging time went.
`vip_get_env_var()` is the only thing that reads them.

Also worth stating since it caused real failures: every domain in the network
has to be registered in the reCAPTCHA console. Missing domains was the root
cause of several failures that looked like code problems.
