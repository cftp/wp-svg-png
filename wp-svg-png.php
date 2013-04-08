<?php
/*
Plugin Name:  SVG to PNG
Plugin URI:   https://github.com/cftp/wp-svg-png
Description:  Automatically converts uploaded SVGs to PNGs to allow seamless fallback support for browsers that don't support SVGs
Version:      1.0
Author:       Code for the People
Author URI:   http://codeforthepeople.com/
Text Domain:  wp-svg-png
Domain Path:  /languages/
License:      GPL v2 or later

Copyright © 2013 Code for the People ltd

                _____________
               /      ____   \
         _____/       \   \   \
        /\    \        \___\   \
       /  \    \                \
      /   /    /          _______\
     /   /    /          \       /
    /   /    /            \     /
    \   \    \ _____    ___\   /
     \   \    /\    \  /       \
      \   \  /  \____\/    _____\
       \   \/        /    /    / \
        \           /____/    /___\
         \                        /
          \______________________/


This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

*/

defined( 'ABSPATH' ) or die();

require_once dirname( __FILE__ ) . '/class-plugin.php';

class CFTP_WP_SVG_PNG extends CFTP_WP_SVG_PNG_Plugin {

	/**
	 * Class constructor. Set up some filters and actions.
	 *
	 * @return null
	 * @author John Blackbourn
	 */
	function __construct() {

		# Actions:
		add_action( 'init',                            array( $this, 'action_init' ) );
		add_action( 'wp_enqueue_scripts',              array( $this, 'action_wp_enqueue_scripts' ) );

		# Filters:
		add_filter( 'upload_mimes',                    array( $this, 'filter_upload_mimes' ) );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'filter_attachment_metadata' ), 10, 2 );

		parent::__construct( __FILE__ );

	}

	function get_available_sizes() {

		global $_wp_additional_image_sizes;

		$sizes = array();

		foreach ( get_intermediate_image_sizes() as $s ) {
			$sizes[$s] = array( 'width' => '', 'height' => '', 'crop' => false );
			if ( isset( $_wp_additional_image_sizes[$s]['width'] ) )
				$sizes[$s]['width'] = intval( $_wp_additional_image_sizes[$s]['width'] ); // For theme-added sizes
			else
				$sizes[$s]['width'] = get_option( "{$s}_size_w" ); // For default sizes set in options
			if ( isset( $_wp_additional_image_sizes[$s]['height'] ) )
				$sizes[$s]['height'] = intval( $_wp_additional_image_sizes[$s]['height'] ); // For theme-added sizes
			else
				$sizes[$s]['height'] = get_option( "{$s}_size_h" ); // For default sizes set in options
			if ( isset( $_wp_additional_image_sizes[$s]['crop'] ) )
				$sizes[$s]['crop'] = intval( $_wp_additional_image_sizes[$s]['crop'] ); // For theme-added sizes
			else
				$sizes[$s]['crop'] = get_option( "{$s}_crop" ); // For default sizes set in options
		}

		return apply_filters( 'intermediate_image_sizes_advanced', $sizes );

	}

	function is_svg( $attachment_id ) {

		$mime_type = get_post_mime_type( $attachment_id );

		if ( empty( $mime_type ) )
			return false;

		return ( false !== strpos( $mime_type, '/svg' ) );

	}

	function generate_pngs( $file ) {

		# @TODO move this into its own class (eg. CFTP_SVG_PNG)

		if ( !class_exists( 'Imagick' ) )
			return new WP_Error( 'no_imagick', __( 'The required ImageMagick library is not installed', 'wp-svg-png' ) );

		$sizes = self::get_available_sizes();
		$pngs  = array();

		if ( empty( $sizes ) )
			return new WP_Error( 'no_sizes', __( 'No image sizes found', 'wp-svg-png' ) );

		$png = self::generate_png( $file ); # fullsize
		$pngs['sizes']['fullsize'] = $png['sizes'];

		foreach ( $sizes as $id => $size ) {
            $png = self::generate_png( $file, $size, $pngs['sizes']['fullsize'], $id );
			$pngs['sizes'][$id] = $png['sizes'];
            if ( $png['dims'] ) {
                $dims = array(
                    'width'  => $png['dims'][2],
                    'height' => $png['dims'][3],
                );
			    $pngs['dims'][$id] = $dims;
            }
        }

		return $pngs;

	}

	function generate_png( $file, $size = null, $fullsize = null, $id = null ) {

		# @TODO move this into its own class (eg. CFTP_SVG_PNG)

		if ( !class_exists( 'Imagick' ) )
			return new WP_Error( 'no_imagick', __( 'The required ImageMagick library is not installed', 'wp-svg-png' ) );

		$im = new Imagick();

		if ( $size ) {

			$dims = self::dimensions( $size, $fullsize );

			$svg = file_get_contents( $file );
			$svg = self::svg_scale( $svg, $dims[6] );

			$im->readImageBlob( $svg );

			$filename = "{$file}-{$id}.png"; # file.svg-thumbnail.png

		} else {

			$im->readImage( $file );
			$filename = "{$file}.png"; # file.svg.png
            $dims = null;

		}

		$im->setImageFormat( 'png24' );
		$im->writeImage( $filename );

		$geo = $im->getImageGeometry();

		$im->clear();
		$im->destroy();

		$mime_types = wp_get_mime_types();

		return array(
            'sizes' => array(
                'path'      => $filename,
                'file'      => wp_basename( apply_filters( 'image_make_intermediate_size', $filename ) ),
                'width'     => intval( $geo['width'] ),
                'height'    => intval( $geo['height'] ),
                'mime-type' => $mime_types['png'],
            ),
            'dims' => $dims
		);

	}

	function svg_scale( $svg, $ratio ) {

	    $reW = '/(.*<svg[^>]* width=")([\d.]+)(.*)/si';
	    $reH = '/(.*<svg[^>]* height=")([\d.]+)(.*)/si';

	    preg_match( $reW, $svg, $mw );
	    preg_match( $reH, $svg, $mh );

	    $width  = round( floatval( $mw[2] ), 3 );
	    $height = round( floatval( $mh[2] ), 3 );

	    if ( !$width or !$height )
	    	return false;

	    $width  *= $ratio;
	    $height *= $ratio;

	    $svg = preg_replace( $reW, "\${1}{$width}\${3}", $svg );
	    $svg = preg_replace( $reH, "\${1}{$height}\${3}", $svg );

	    return $svg;

	}

	function dimensions( $size, $fullsize ) {

		# This works similarly to image_resize_dimensions() but because our source image is of
		# infinite width and infinite height, we need to calculate the resized image dimenions
		# based on the ratio, not the actual size.

		# Size is usually a string, eg. 'medium'. Convert to assoc array of dimensions:
		if ( !is_array( $size ) ) {
			$sizes = self::get_available_sizes();
			$size  = $sizes[$size];
		}

		# Size could also be a numerically indexed array. Convert to assoc array of dimensions:
		if ( !isset( $size['width'] ) )
			$size['width'] = $size[0];
		if ( !isset( $size['height'] ) )
			$size['height'] = $size[1];

		# Calculate size ratios:
		$ratio_w = $size['width'] / $fullsize['width'];
		$ratio_h = $size['height'] / $fullsize['height'];
        $use_ratio = min( $ratio_w, $ratio_h );

		# Set "original" width and height:
		$orig_w = $fullsize['width'] * $use_ratio;
		$orig_h = $fullsize['height'] * $use_ratio;

		# Calculate new width and height:
		list( $new_w, $new_h ) = wp_constrain_dimensions( $orig_w, $orig_h, $size['width'], $size['height'] );

		# source x, source y, new width, new height, source width, source height, ratio
		return array(
			0,
			0,
			intval( $new_w ),
			intval( $new_h ),
			intval( $orig_w ),
			intval( $orig_h ),
			$use_ratio,
		);

	}

	function filter_attachment_metadata( $data, $attachment_id ) {

		if ( !self::is_svg( $attachment_id ) )
			return $data;
		if ( !$file = get_attached_file( $attachment_id ) )
			return $data;

		$pngs = self::generate_pngs( $file );

		if ( is_wp_error( $pngs ) ) {
			error_log( sprintf( 'WP-SVG-PNG: %s', $pngs->get_error_message() ) );
			return $data;
		}

		$sizes = array();

		$relative_file = _wp_relative_upload_path( $file );

		foreach ( $pngs['sizes'] as $id => $size ) {

			if ( isset( $pngs['dims'][$id] ) ) {
				$sizes[$id] = $pngs['dims'][$id];
				$sizes[$id]['file'] = wp_basename( $relative_file );
				$sizes[$id]['mime-type'] = 'image/svg+xml';
			}

		}

		$meta_sizes = array(
			'width'  => $pngs['sizes']['fullsize']['width'],
			'height' => $pngs['sizes']['fullsize']['height'],
			'file'   => $relative_file,
			'sizes'  => $sizes,
		);

		return $meta_sizes;

	}

	function action_wp_enqueue_scripts() {

		wp_enqueue_script(
			'cftp-wp-svg-png',
			$this->plugin_url( 'wp-svg-png.js' ),
			array( 'jquery' ),
			$this->plugin_ver( 'wp-svg-png.js' ),
			true
		);

		wp_localize_script(
			'cftp-wp-svg-png',
			'cftp_wp_svg_png',
			array(
				'sizes' => self::get_available_sizes()
			)
		);

	}

	function filter_upload_mimes( array $mime_types ) {

		$mime_types['svg'] = 'image/svg+xml';
		return $mime_types;

	}

	/**
	 * Load localisation files.
	 *
	 * @action init
	 *
	 * @return null
	 * @author John Blackbourn
	 */
	function action_init() {

		load_plugin_textdomain( 'wp-svg-png', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	}

	/**
	 * Singleton stuff.
	 * 
	 * @access @static
	 * 
	 * @return CFTP_WP_SVG_PNG
	 */
	static public function init() {
		static $instance = false;

		if ( ! $instance )
			$instance = new CFTP_WP_SVG_PNG;

		return $instance;

	}

}

CFTP_WP_SVG_PNG::init();
