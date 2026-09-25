<?php
/**
 * Block Name: Map.
 *
 * @package courier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the courier/courier-map block using the metadata
 * loaded from the block.json file.
 */
function courier_courier_map_block_init(): void {
	register_block_type( __DIR__ );
}
add_action( 'init', 'courier_courier_map_block_init' );

/**
 * Enqueue Google's dynamic importLibrary bootstrap, once per request.
 *
 * The editor needs it for address geocoding and autofill; the front end
 * needs it to draw the map. Both share one script handle so Google Maps
 * is never loaded twice.
 *
 * This immediately exposes google.maps.importLibrary(). Individual
 * libraries such as "geocoding" are then loaded by the block's
 * TypeScript when needed.
 *
 * @param bool $in_footer Whether to print the loader in the footer.
 */
function courier_courier_map_enqueue_maps_bootstrap( bool $in_footer ): void {
	if (
		wp_script_is( 'courier-google-maps-bootstrap', 'enqueued' )
		|| wp_script_is( 'courier-google-maps-bootstrap', 'done' )
	) {
		return;
	}

	$raw_api_key = vip_get_env_var( 'GOOGLE_MAPS_API_KEY', '' );
	$api_key     = is_string( $raw_api_key ) ? $raw_api_key : '';

	$bootstrap = sprintf(
		'(g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.${c}apis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({key:"%s",v:"weekly"});',
		esc_js( $api_key )
	);

	wp_register_script( 'courier-google-maps-bootstrap', '', [], '1.0.0', $in_footer );
	wp_enqueue_script( 'courier-google-maps-bootstrap' );
	wp_add_inline_script( 'courier-google-maps-bootstrap', $bootstrap, 'after' );
}

/**
 * Load the Google Maps JavaScript API in the block editor.
 *
 * enqueue_block_editor_assets fires early enough for a header script,
 * and importLibrary() must be available before the editor component
 * mounts — so this loads in the header, unlike the front end.
 */
function courier_courier_map_editor_assets(): void {
	$screen = get_current_screen();

	if ( ! $screen || ! $screen->is_block_editor() ) {
		return;
	}

	courier_courier_map_enqueue_maps_bootstrap( false );
}
add_action( 'enqueue_block_editor_assets', 'courier_courier_map_editor_assets' );
