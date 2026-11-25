<?php
/** Runs inside Playground only: replaces the default content with the exported content. */

require_once '/wordpress/wp-load.php';

wp_set_current_user( 1 );
kses_remove_filters();
wp_defer_term_counting( true );

global $wpdb;
$data = json_decode( file_get_contents( '/tmp/wps/content.json' ), true );
$home = untrailingslashit( home_url() );
$find = array( 'https://wps-share.invalid', 'https:\/\/wps-share.invalid' );
$with = array( $home, str_replace( '/', '\/', $home ) );
$fix  = static function ( $value ) use ( &$fix, $find, $with ) {
	if ( is_string( $value ) ) {
		return str_replace( $find, $with, $value );
	}
	return is_array( $value ) ? array_map( $fix, $value ) : $value;
};
$data = $fix( $data );

// Every status and type must go, or auto-drafts take the original IDs.
foreach ( array( 'posts', 'postmeta', 'comments', 'commentmeta', 'term_relationships', 'term_taxonomy', 'terms' ) as $table ) {
	$wpdb->query( "DELETE FROM {$wpdb->$table}" ); // phpcs:ignore WordPress.DB
}

// Blocks store term IDs, and wp_insert_term() cannot set them.
foreach ( $data['terms'] as $t ) {
	$wpdb->insert( $wpdb->terms, array( 'term_id' => $t['id'], 'name' => $t['name'], 'slug' => $t['slug'], 'term_group' => 0 ) );
	$wpdb->insert( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => $t['ttid'], 'term_id' => $t['id'], 'taxonomy' => $t['taxonomy'], 'description' => $t['description'], 'parent' => $t['parent'], 'count' => 0 ) );
}
wp_cache_flush();
foreach ( array_unique( array_column( $data['terms'], 'taxonomy' ) ) as $taxonomy ) {
	delete_option( "{$taxonomy}_children" );
}
$categories = array_filter( $data['terms'], static fn( $t ) => 'category' === $t['taxonomy'] );
if ( $categories ) {
	update_option( 'default_category', reset( $categories )['id'] );
} else {
	$created = wp_insert_term( 'General', 'category' );
	if ( ! is_wp_error( $created ) ) {
		update_option( 'default_category', $created['term_id'] );
	}
}

foreach ( $data['attachments'] as $a ) {
	$id = wp_insert_attachment(
		wp_slash(
			array(
				'import_id'      => $a['id'],
				'post_title'     => $a['title'],
				'post_name'      => $a['slug'],
				'post_mime_type' => $a['mime'],
				'post_date'      => $a['date'],
				'post_parent'    => $a['parent'],
				'post_status'    => 'inherit',
				'post_author'    => 1,
			)
		)
	);
	update_post_meta( $id, '_wp_attached_file', $a['file'] );
	if ( $a['metadata'] ) {
		update_post_meta( $id, '_wp_attachment_metadata', wp_slash( $a['metadata'] ) );
	}
	if ( '' !== $a['alt'] ) {
		update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $a['alt'] ) );
	}
}

foreach ( $data['posts'] as $p ) {
	$id = wp_insert_post(
		wp_slash(
			array(
				'import_id'    => $p['id'],
				'post_type'    => $p['type'],
				'post_status'  => 'publish',
				'post_name'    => $p['slug'],
				'post_title'   => $p['title'],
				'post_content' => $p['content'],
				'post_excerpt' => $p['excerpt'],
				'post_date'    => $p['date'],
				'post_parent'  => $p['parent'],
				'menu_order'   => $p['menu_order'],
				'post_author'  => 1,
			)
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		error_log( 'wp-playground-share: ' . $p['slug'] . ': ' . $id->get_error_message() );
		continue;
	}
	foreach ( $p['terms'] as $taxonomy => $ids ) {
		if ( taxonomy_exists( $taxonomy ) ) {
			wp_set_object_terms( $id, $ids, $taxonomy );
		}
	}
	foreach ( $p['meta'] as $key => $value ) {
		if ( is_array( $value ) && isset( $value['__multi'] ) ) {
			foreach ( $value['__multi'] as $one ) {
				add_post_meta( $id, $key, wp_slash( $one ) );
			}
		} else {
			update_post_meta( $id, $key, wp_slash( $value ) );
		}
	}
}

$front = '' !== $data['site']['front_page_slug'] ? get_page_by_path( $data['site']['front_page_slug'] ) : null;
if ( $front ) {
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $front->ID );
}

wp_defer_term_counting( false );
flush_rewrite_rules();
