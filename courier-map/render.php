<?php
/**
 * The render callback for the courier/courier-map block.
 *
 * @var array<string, mixed> $attributes The array of attributes for this block.
 * @var string               $content    Rendered block output.
 * @var WP_Block             $block      The instance of the WP_Block class.
 *
 * @package courier
 */

defined( 'ABSPATH' ) || exit;

$locations = is_array( $attributes['locations'] ?? null ) ? $attributes['locations'] : [];

if ( empty( $locations ) ) {
	return;
}

$categories    = is_array( $attributes['categories'] ?? null ) ? $attributes['categories'] : [];
$map_id        = is_string( $attributes['mapId'] ?? null ) ? $attributes['mapId'] : 'DEMO_MAP_ID';
$accent_color  = is_string( $attributes['accentColor'] ?? null ) ? $attributes['accentColor'] : '#1c86c4';
$height        = is_numeric( $attributes['height'] ?? null ) ? (int) $attributes['height'] : 400;
$zoom          = is_numeric( $attributes['zoom'] ?? null ) ? (int) $attributes['zoom'] : 12;
$panel_title   = is_string( $attributes['panelTitle'] ?? null ) ? $attributes['panelTitle'] : 'Locations';
$panel_tagline = is_string( $attributes['panelTagline'] ?? null ) ? $attributes['panelTagline'] : '';

$locations_json  = wp_json_encode( $locations );
$locations_json  = false !== $locations_json ? $locations_json : '[]';
$categories_json = wp_json_encode( $categories );
$categories_json = false !== $categories_json ? $categories_json : '[]';

// Front end loads the Maps bootstrap in the footer; the editor loads the same
// handle in the header. Whichever runs first wins, so it is never loaded twice.
courier_courier_map_enqueue_maps_bootstrap( true );

$wrapper_attributes = get_block_wrapper_attributes(
	[
		'class' => 'courier-map-block',
		'style' => sprintf( 'height: %dpx;', $height ),
	]
);
?>
<div
	<?php echo wp_kses_data( $wrapper_attributes ); ?>
	data-map-id="<?php echo esc_attr( $map_id ); ?>"
	data-accent-color="<?php echo esc_attr( $accent_color ); ?>"
	data-zoom="<?php echo esc_attr( (string) $zoom ); ?>"
	data-locations="<?php echo esc_attr( $locations_json ); ?>"
	data-categories="<?php echo esc_attr( $categories_json ); ?>"
	data-panel-title="<?php echo esc_attr( $panel_title ); ?>"
	data-panel-tagline="<?php echo esc_attr( $panel_tagline ); ?>"
>
	<div class="courier-map-block__canvas"></div>
</div>
