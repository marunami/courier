<?php
/**
 * Courier Features: Interaction Gate file
 *
 * Delays selected scripts and stylesheets until the
 * visitor first interacts with the page (scroll, pointer, key, wheel, touch, mousemove).
 *
 * @package courier
 */

namespace Courier_Plugin\Features;

use Alley\WP\Types\Feature;

/**
 * Delay resources until first user interaction.
 */
final readonly class Interaction_Gate implements Feature {

	/**
	 * Enqueued SCRIPT handles to neutralise server-side.
	 *
	 * @var string[]
	 */
	private const array DELAY_SCRIPT_HANDLES = [
		'courier-aditude-wrapper',
		'jquery-core',
		'jquery-migrate',
		'courier-post-template-slider-js',
		'wp-emoji-release',
	];

	/**
	 * Enqueued STYLE handles to neutralise server-side.
	 *
	 * @var string[]
	 */
	private const array DELAY_STYLE_HANDLES = [
		'courier-post-template-slider-css',
		'social-links',
	];

	/**
	 * URL substrings to neutralise client-side (runtime-injected nodes).
	 *
	 * @var string[]
	 */
	private const array DELAY_URLS = [
		// Aditude.
		'edge.aditude.io',
		'raven-static.aditude.io',
		'raven-edge.aditude.io',
		'dn0qt3r0xannq.cloudfront.net', // Aditude prebid wrapper distro.
		'htlbid.com',
		'prebid.cloud',
		'gpt.js',
		'prebid-wrapper.js',

		// Google ad serving.
		'securepubads.g.doubleclick.net',
		'pagead2.googlesyndication.com',
		'tpc.googlesyndication.com',
 
		// Amazon header bidding.
		'amazon-adsystem.com',

		// Prebid bidders / sync.
		'rubiconproject.com',
		'pubmatic.com',
		'id5-sync.com',
		'liadm.com',
		'script.4dex.io',
		'amxrtb.com',
		'crwdcntrl.net',
		'minutemedia-prebid.com',
		'smilewanted.com',
		'cootlogix.com',
		'yellowblue.io',
		'measureadv.com',

		// Meta pixel.
		'connect.facebook.net',

		'courier-aditude-wrapper',
		'jquery-core',
		'jquery-migrate',
		'courier-post-template-slider-js',
		'wp-emoji-release',

		'htlbid.com',
	];

	/**
	 * Fallback in milliseconds. If the visitor never interacts, gated
	 * resources are released after this delay.
	 */
	private const int FALLBACK_MS = 60000;

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		// Front-end only. Conditional query tags (is_feed) are NOT available
		// this early, so don't call them here.
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		add_action( 'wp_head', $this->print_runtime( ... ), 1 );
		add_filter( 'script_loader_tag', $this->maybe_defer_script( ... ), 10, 3 );
		add_filter( 'style_loader_tag', $this->maybe_defer_style( ... ), 10, 4 );
	}
	/**
	 * Convert a matching enqueued script tag into an inert placeholder the
	 * runtime can revive on interaction.
	 *
	 * @param string $tag    The full <script> tag.
	 * @param string $handle The script handle.
	 * @param string $src    The script source URL.
	 * @return string
	 */
	public function maybe_defer_script( string $tag, string $handle, string $src ): string {
		if ( ! in_array( $handle, self::DELAY_SCRIPT_HANDLES, true ) ) {
			return $tag;
		}
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript 
		return sprintf(
			'<script type="text/courier-gate" data-courier-src="%s"></script>' . "\n",
			esc_url( $src )
		);// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedScript
	}

	/**
	 * Convert a matching enqueued stylesheet to a non-blocking print sheet.
	 *
	 * @param string $tag    The full <link> tag.
	 * @param string $handle The style handle.
	 * @param string $href   The stylesheet URL.
	 * @param string $media  The media attribute.
	 * @return string
	 */
	public function maybe_defer_style( string $tag, string $handle, string $href, string $media ): string {
		if ( ! in_array( $handle, self::DELAY_STYLE_HANDLES, true ) ) {
			return $tag;
		}

		$tag = str_replace( "media='" . $media . "'", "media='print'", $tag );
		return str_replace( '<link ', '<link data-courier-media="' . esc_attr( $media ) . '" ', $tag );
	}

	/**
	 * Print the inline gate runtime + config.
	 */
	public function print_runtime(): void {
		if ( is_feed() ) {
			return;
		}
		$config = [
			'urls'       => self::DELAY_URLS,
			'fallbackMs' => self::FALLBACK_MS,
		];

		$script = 'window.__courierGate=' . wp_json_encode( $config ) . ';' . $this->runtime_js();

		wp_print_inline_script_tag( $script ); // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
	}

	/**
	 * The gate runtime. Plain ES5 so it parses before anything else.
	 *
	 * @return string
	 */
	private function runtime_js(): string {
		return <<<'JS'
(function () {
	var cfg = window.__courierGate || {};
	var urls = cfg.urls || [];
	var fallbackMs = typeof cfg.fallbackMs === 'number' ? cfg.fallbackMs : 0;
	var EVENTS = ['pointerdown', 'mousedown', 'touchstart', 'touchmove', 'keydown', 'wheel', 'scroll', 'mousemove'];
	var activated = false;
	var observer = null;
	var queuedScripts = []; 
	var queuedIframes = []; 

	function matches(url) {
		if (!url) {
			return false;
		}
		for (var i = 0; i < urls.length; i++) {
			if (url.indexOf(urls[i]) !== -1) {
				return true;
			}
		}
		return false;
	}

	// Neutralise a runtime-injected node before it can run.
	function neutralize(node) {
		if (!node || !node.tagName) {
			return;
		}
		var tag = node.tagName;

		if (tag === 'SCRIPT') {
			if (!matches(node.src)) {
				return;
			}
			queuedScripts.push({
				src: node.src,
				async: node.async,
				defer: node.defer,
				type: node.getAttribute('type') || '',
				crossOrigin: node.getAttribute('crossorigin')
			});
			if (node.parentNode) {
				node.parentNode.removeChild(node);
			}
		} else if (tag === 'LINK') {
			if (node.rel !== 'stylesheet' || !matches(node.href)) {
				return;
			}
			node.setAttribute('data-courier-media', node.media || 'all');
			node.media = 'print';
		} else if (tag === 'IFRAME') {
			if (!matches(node.src)) {
				return;
			}
			queuedIframes.push({ node: node, src: node.src });
			node.setAttribute('src', 'about:blank');
		}
	}

	function startObserver() {
		if (!('MutationObserver' in window)) {
			return;
		}
		observer = new MutationObserver(function (mutations) {
			for (var i = 0; i < mutations.length; i++) {
				var added = mutations[i].addedNodes;
				for (var j = 0; j < added.length; j++) {
					neutralize(added[j]);
				}
			}
		});
		observer.observe(document.documentElement, { childList: true, subtree: true });
	}

	function activate() {
		if (activated) {
			return;
		}
		activated = true;

		if (observer) {
			observer.disconnect();
		}
		EVENTS.forEach(function (ev) {
			window.removeEventListener(ev, activate, true);
		});

		// 1. Revive server-side neutralised scripts (type="text/courier-gate").
		var placeholders = document.querySelectorAll('script[type="text/courier-gate"]');
		for (var i = 0; i < placeholders.length; i++) {
			var ph = placeholders[i];
			var s = document.createElement('script');
			s.src = ph.getAttribute('data-courier-src');
			s.async = false; // preserve original execution order for the wrapper.
			ph.parentNode.replaceChild(s, ph);
		}

		// 2. Flip deferred stylesheets back to their original media.
		var links = document.querySelectorAll('link[data-courier-media]');
		for (var k = 0; k < links.length; k++) {
			links[k].media = links[k].getAttribute('data-courier-media') || 'all';
		}

		// 3. Re-inject runtime-caught scripts.
		for (var m = 0; m < queuedScripts.length; m++) {
			var q = queuedScripts[m];
			var rs = document.createElement('script');
			rs.src = q.src;
			rs.async = q.async;
			rs.defer = q.defer;
			if (q.type) {
				rs.type = q.type;
			}
			if (q.crossOrigin !== null) {
				rs.setAttribute('crossorigin', q.crossOrigin);
			}
			(document.head || document.documentElement).appendChild(rs);
		}

		// 4. Restore runtime-caught iframes.
		for (var n = 0; n < queuedIframes.length; n++) {
			queuedIframes[n].node.setAttribute('src', queuedIframes[n].src);
		}
	}

	// Expose for manual triggering / debugging on develop.
	window.__courierGate.activate = activate;

	startObserver();

	EVENTS.forEach(function (ev) {
		window.addEventListener(ev, activate, { once: true, passive: true, capture: true });
	});

	if (fallbackMs > 0) {
		setTimeout(activate, fallbackMs);
	}
})();
JS;
	}
}
