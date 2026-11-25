<?php
/**
 * Plugin Name: wp-playground-share
 * Description: Shows the template by default when editing pages, so template-built pages do not look empty.
 */

add_action(
	'init',
	static function () {
		$supports = get_all_post_type_supports( 'page' );
		if ( empty( $supports['editor'] ) || ! wp_is_block_theme() ) {
			return;
		}
		// add_post_type_support() replaces existing arguments, so core's own (block notes) are kept.
		$args = isset( $supports['editor'][0] ) && is_array( $supports['editor'][0] ) ? $supports['editor'][0] : array();
		add_post_type_support( 'page', 'editor', array_merge( $args, array( 'default-mode' => 'template-locked' ) ) );
	}
);
