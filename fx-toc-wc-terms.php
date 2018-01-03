<?php
/**
 * Plugin Name: f(x) TOC WC Terms
 * Plugin URI: http://genbumedia.com/plugins/fx-email-log/
 * Description: TOC Support for WC Terms, use [toc-cat] to display.
 * Version: 1.0.2
 * Author: David Chandra Purnama
 * Author URI: http://shellcreeper.com/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: fx-toc-wc-terms
 * Domain Path: /languages/
 *
 * @author David Chandra Purnama <david@genbumedia.com>
 * @copyright Copyright (c) 2017, Genbu Media
**/

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Load on Init Hook
 *
 * @since 1.0.0
 */
add_action( 'init', function() {

	// Bail early if TOC or WC not active.
	if ( ! function_exists( 'fx_toc_plugins_loaded' ) || ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	/**
	 * Enable HTML in Term Description
	 *
	 * @since 1.0.0
	 * @link https://docs.woocommerce.com/document/allow-html-in-term-category-tag-descriptions/
	 */
	remove_filter( 'pre_term_description', 'wp_filter_kses' );
	remove_filter( 'term_description', 'wp_kses_data' );

	// Add Shortcode.
	add_shortcode( 'toc-cat', 'fx_toc_wc_terms_toc_cat_shortcode' );

	// Replace archive description.
	remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
	add_action( 'woocommerce_archive_description', 'fx_toc_wc_terms_taxonomy_archive_description', 10 );
} );


/**
 * TOC Shortcode
 *
 * @since 1.0.0
 *
 * @param array $atts Shortcode Atts.
 * @return string
 */
function fx_toc_wc_terms_toc_cat_shortcode( $atts ) {
	// Only in product taxonomy and on 1st page.
	if ( ! function_exists( 'is_product_taxonomy' ) || ! is_product_taxonomy() || is_admin() || 0 !== absint( get_query_var( 'paged' ) ) ) {
		return '';
	}

	// Default shortcode args.
	$default_args = apply_filters( 'fx_toc_wc_terms_default_args', array(
		'depth'          => 6,
		'list'           => 'ul',
		'title'          => __( 'Table of contents', 'fx-toc' ),
		'title_tag'      => 'p',
	) );
	$attr = shortcode_atts( $default_args, $atts );

	// Get term object.
	$term = get_queried_object();

	$toc = fx_toc_wc_terms_build_toc( $term->description, $attr, $term );

	return apply_filters( 'fx_toc_output', $toc );
}

/**
 * Build TOC List.
 * This is the same as fx_toc_build_toc() with minor modification for URL linked.
 *
 * @since 1.0.0
 * @see fx_toc_build_toc()
 *
 * @param string $content Content to parse.
 * @param array  $args    Shortcode Atts.
 * @param object $term    Term object to get permalink.
 * @return string
 */
function fx_toc_wc_terms_build_toc( $content, $args, $term ) {

	/* Get globals */
	global $post, $wp_rewrite, $fx_toc_used_names;
	fx_toc_sc_unique_names_reset();

	/* Shortcode attr */
	$default_args = apply_filters( 'fx_toc_default_args', array(
		'depth'          => 6,
		'list'           => 'ul',
		'title'          => __( 'Table of contents', 'fx-toc' ),
		'title_tag'      => 'h2',
	) );
	$attr = wp_parse_args( $args, $default_args );
	extract( $attr );

	/* Sanitize */
	$list = ( 'ul' == $list ) ? 'ul' : 'ol';
	$title_tag = strip_tags( $title_tag );
	$depth = absint( $depth );

	/* Set lowest heading number, default <h1>. <h1> is lower than <h3> */
	$lowest_heading = 1;

	/* Get the lowest value heading (ie <hN> where N is a number) in the post */
	for( $i = 1; $i <= 6; $i++ ){

		/* Find the <h{x}> tag start from 1 to 6 and. if found, use it.  */
		if( preg_match( "#<h" . $i . "#i", $content ) ) {
			$lowest_heading = $i;
			break;
		}
	}

	/* Set maximum heading tag in content e.g 2+6-1 = 7, so it will use <h2> to <h7> */
	$max_heading = $lowest_heading + $depth - 1;

	/* Find page separation points, so it will work on multi page post */
	$next_pages = array();
	preg_match_all( "#<\!--nextpage-->#i", $content, $next_pages, PREG_OFFSET_CAPTURE );
	$next_pages = $next_pages[0];

	/* Get all headings in post content */ 
	$headings = array();
	preg_match_all( "#<h([1-6]).*?>(.*?)</h[1-6]>#i", $content, $headings, PREG_OFFSET_CAPTURE );

	/* Set lowest heading found */
	$cur_level = $lowest_heading;

	/* Default value, start empty */
	$open = '';
	$heading_out = '';
	$close = '';
	$out = ''; //output

	/* If the Table Of Content title is set, display */
	if ( $title ){
		$open .= '<' . $title_tag . ' class="fx-toc-title">' . $title . '</' . $title_tag . '>';
	}

	/* Get opening level tags, open the list */
	$cur = $lowest_heading - 1;
	for( $i = $cur; $i < $lowest_heading; $i++ ) {
		$level = $i - $lowest_heading + 2;
		$open .= "<{$list} class='fx-toc-list level-{$level}'>\n";
	}

	$first = true;
	$tabs = 1;

	/* the headings */
	foreach( $headings[2] as $i => $heading ) {
		$level = $headings[1][$i][0]; // <hN>

		if( $level > $max_heading ){ // heading too deep
			continue;
		} 

		if( $level > $cur_level ) { // this needs to be nested
			$heading_out .= str_repeat( "\t", $tabs+1 ) . fx_toc_sc_open_level( $level, $cur_level, $lowest_heading, $list );
			$first = true;
			$tabs += 2;
		}

		if( !$first ){
			$heading_out .= str_repeat( "\t", $tabs ) . "</li>\n";
		}
		$first = false;

		if( $level < $cur_level ) { // jump back up from nest
			$heading_out .= str_repeat( "\t", $tabs-1 ) . fx_toc_sc_close_level( $level, $cur_level, $lowest_heading, $list );
			$tabs -= 2;
		}

		$name = fx_toc_sc_get_unique_name( $heading[0] );

		$page_num = 1;
		$pos = $heading[1];

		/* find the current page */
		foreach( $next_pages as $p ) {
			if( $p[1] < $pos ){
				$page_num++;
			}
		}

		/* fix error if heading link overlap / not hieraricaly correct */
		if ( $tabs+1 > 0 ){
			$tabs = $tabs;
		}
		else{
			$tabs = 0;
		}

		/* For disabled shortcode in heading (for docs for example) */
		$heading[0] = str_replace( "[[", "[", $heading[0] );
		$heading[0] = str_replace( "]]", "]", $heading[0] );

		/**
		 * The modification to linked to term permalink instead of post permalink.
		 */
		$heading_out .= str_repeat( "\t", $tabs ) . "<li>\n" . str_repeat( "\t", $tabs + 1 ) . "<a href=\"" .get_term_link( $term ). "#" . sanitize_title( $name ). "\">" . strip_tags( $heading[0] ) . "</a>\n";

		$cur_level = $level; // set the current level we are at

	} // end heading

	if( !$first ){
		$close = str_repeat( "\t", $tabs ) . "</li>\n";
	}

	/* Get closing level tags, close the list */
	$close .= fx_toc_sc_close_level( 0, $cur_level, $lowest_heading, $list );

	/* Check if heading exist. */
	if ( $heading_out ) {
		$out = $open . $heading_out . $close;
	}

	/* display */
	return $out;
}

/**
 * Replace Term Description.
 * This is a clone of woocommerce_taxonomy_archive_description().
 * To make this work, we need to remove the action and re-add our custom one because WC did not add filters in the description content.
 *
 * @since 1.0.0
 * @see woocommerce_taxonomy_archive_description()
 */
function fx_toc_wc_terms_taxonomy_archive_description() {
	if ( is_product_taxonomy() && 0 === absint( get_query_var( 'paged' ) ) ) {
		$term = get_queried_object();

		if ( $term && ! empty( $term->description ) ) {

			$content = $term->description;

			if ( ! is_admin() && has_shortcode( $content, 'toc-cat' ) ) {
				$content = fx_toc_add_span_to_headings( $content );
			}

			echo '<div class="term-description">' . wc_format_content( $content ) . '</div>'; // WPCS: XSS ok.
		}
	}
}
