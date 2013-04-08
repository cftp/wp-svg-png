
function cftp_wp_svg_png_supports_svg() {
    return !!document.createElementNS && !!document.createElementNS('http://www.w3.org/2000/svg', "svg").createSVGRect;
}

jQuery( function($) {

	if ( cftp_wp_svg_png_supports_svg() )
		return;

	var imgs = $('img');

	if ( !imgs.length )
		return;

	$.each( cftp_wp_svg_png.sizes, function( size, value ) {

		imgs.filter( '.size-' + size ).each( function() {
			$( this ).attr( 'src', $( this ).attr( 'src' ) + '-' + size + '.png' );
		} );

	} );

} );

