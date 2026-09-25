/**
 * reCAPTCHA v3 token injection for newsletter signup forms.
 *
 * Routes on the request path rather than form class, so a single listener
 * covers the inline, multi-newsletter, and dynamically injected regwall forms
 * without each block needing its own implementation.
 */

declare const courierRecaptchaSiteKey: string;

declare const grecaptcha: {
  ready: (callback: () => void) => void;
  execute: (siteKey: string, options: { action: string }) => Promise<string>;
};

const ENDPOINTS = [
  '/api/newsletter-signup-form',
  '/api/multi-newsletter-signup-form',
];

/**
 * Read the GA4 client ID from the _ga cookie.
 *
 * Format is GA1.1.<client_id>, where the client ID is <random>.<timestamp>.
 * Returns an empty string when analytics cookies aren't set — e.g. before
 * the user has accepted analytics consent — so coverage is partial by design.
 */
function getGaClientId(): string {
  const match = document.cookie.match(/(?:^|;\s*)_ga=GA\d+\.\d+\.(\d+\.\d+)/);

  return match ? match[1] : '';
}

let recaptchaScriptPromise: Promise<void> | null = null;

/**
 * Load the reCAPTCHA API script on demand instead of on every pageview.
 * Triggered on email field focus; also called lazily from getRecaptchaToken
 * as a fallback if submission happens without a prior focus event.
 */
function loadRecaptchaScript(): Promise<void> {
  if (recaptchaScriptPromise) {
    return recaptchaScriptPromise;
  }

  recaptchaScriptPromise = new Promise((resolve) => {
    const script = document.createElement('script');
    script.src = `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(courierRecaptchaSiteKey)}`;
    script.onload = () => resolve();
    script.onerror = () => resolve();
    document.head.appendChild(script);
  });

  return recaptchaScriptPromise;
}

document.body.addEventListener('focusin', (e) => {
  const target = e.target as HTMLElement;
  if (target?.matches('input[type="email"]') && typeof courierRecaptchaSiteKey !== 'undefined' && courierRecaptchaSiteKey) {
    loadRecaptchaScript();
  }
});

/**
 * Generate a fresh reCAPTCHA v3 token.
 */
function getRecaptchaToken(): Promise<string | null> {
  if (typeof courierRecaptchaSiteKey === 'undefined' || !courierRecaptchaSiteKey) {
    return Promise.resolve(null);
  }

  return loadRecaptchaScript().then(() => new Promise<string | null>((resolve) => {
    if (typeof grecaptcha === 'undefined') {
      resolve(null);
      return;
    }
    grecaptcha.ready(() => {
      grecaptcha
        .execute(courierRecaptchaSiteKey, { action: 'newsletter_signup' })
        .then(resolve, () => resolve(null));
    });
  }));
}

/**
 * Delay newsletter signup requests until a fresh token has been generated.
 *
 * htmx:confirm is used rather than htmx:configRequest because configRequest is
 * synchronous — it cannot await grecaptcha.execute(). preventDefault() holds the
 * request; detail.issueRequest() releases it once the token resolves.
 *
 * A null token still issues the request; the endpoint decides how to handle it.
 */
document.body.addEventListener('htmx:confirm', (e) => {
  const { detail } = e as CustomEvent;
  const path = String(detail.path ?? '');

  if (!ENDPOINTS.some((endpoint) => path.endsWith(endpoint))) {
    return;
  }

  e.preventDefault();

  getRecaptchaToken().then((token) => {
    if (token) {
      detail.elt.courierRecaptchaToken = token;
    }
    detail.issueRequest(true);
  });
});

/**
 * Attach the reCAPTCHA token and GA4 client ID to the outgoing request.
 *
 * htmx provides no direct channel from confirm to configRequest, so the token
 * is stashed on the triggering element and consumed here. The client ID is read
 * fresh from the cookie so Sailthru profiles can be joined back to GA4 sessions.
 */
document.body.addEventListener('htmx:configRequest', (e) => {
  const { detail } = e as CustomEvent;
  const path = String(detail.path ?? '');

  if (ENDPOINTS.some((endpoint) => path.endsWith(endpoint))) {
    detail.parameters.ga_client_id = getGaClientId();
  }

  const token = detail.elt?.courierRecaptchaToken;

  if (token) {
    detail.parameters.recaptcha_token = token;
    delete detail.elt.courierRecaptchaToken;
  }
});

/**
 * Push a GTM dataLayer event when a newsletter signup is blocked server-side.
 *
 * Fired for both honeypot and reCAPTCHA rejections. The endpoint returns 200
 * with an HX-Trigger header so the event reaches the browser, but skips the
 * Sailthru submission. No personally identifying form data is sent to GA4.
 *
 * @param reason Why the submission was blocked ('honeypot' or 'recaptcha').
 * @param elt    The form element that triggered the request, if available.
 */
function pushBlocked(reason: string, elt: HTMLElement | null): void {
  window.dataLayer = window.dataLayer || [];
  window.dataLayer.push({
    event: 'newsletter_signup_blocked',
    eventModel: {
      block_reason: reason,
      form_id: elt?.getAttribute('id') ?? '',
      signup_type: elt?.querySelector<HTMLInputElement>('input[name="signup_type"]')?.value ?? '',
      list_name: elt?.querySelector<HTMLInputElement>('input[name="list_name"]')?.value ?? '',
      page_path: window.location.pathname,
    },
  });
}

document.body.addEventListener('recaptcha_failed', (e) => {
  pushBlocked('recaptcha', (e as CustomEvent).detail?.elt ?? null);
});

document.body.addEventListener('honeypot_triggered', (e) => {
  pushBlocked('honeypot', (e as CustomEvent).detail?.elt ?? null);
});
