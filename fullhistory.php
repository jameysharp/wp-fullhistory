<?php
/*
Plugin Name:  Full-history (RFC5005) feeds
Description:  Allow RFC5005-capable feed readers to see all posts, not just the most recent.
Version:      0.2
Author:       Jamey Sharp
Author URI:   https://jamey.thesharps.us/
License:      BSD-2-Clause
License URI:  https://www.freebsd.org/copyright/freebsd-license.html
*/

/**
 * Test if two arrays have no values in common.
 *
 * @param array $a First array.
 * @param array $b Second array.
 * @return bool False if any element in $a is also in $b, true otherwise.
 */
function fullhistory_disjoint( $a, $b ) {
	foreach ( $a as $element ) {
		if ( in_array( $element, $b, true ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Invalidate feed reader caches by changing the URL we use to link to any
 * archived feed pages that might have been affected.
 *
 * @param array $query_params Query parameters to pass to WP_Query to limit
 *                            which posts might be part of a page that should
 *                            be re-fetched.
 */
function fullhistory_invalidate_client_caches( $query_params = array() ) {
	$public_stati = get_post_stati( array( 'public' => true ) );

	$change_count = get_option( 'fullhistory_change_count', 1 );
	++$change_count;
	update_option( 'fullhistory_change_count', $change_count );

	$query_params = array_merge(
		$query_params,
		array(
			'fields'        => 'ids',
			'no_found_rows' => true,
			'nopaging'      => true,
			'post_status'   => $public_stati,
		)
	);

	$query = new WP_Query( $query_params );
	foreach ( $query->posts as $id ) {
		update_post_meta( $id, '_fullhistory_version', $change_count );
	}
}

add_action( 'update_option_posts_per_rss', 'fullhistory_invalidate_client_caches', 10, 0 );

/**
 * When a post changes, invalidate feed reader client caches as needed.
 *
 * Posts that are not visible in a feed either before or after this change
 * don't need to invalidate any caches, so this function takes the list of the
 * old and new status of the changed post. If the post is being deleted, give
 * only the old status.
 *
 * @param WP_Post $post       The changed post.
 * @param array   $post_stati The post's status, both old and new.
 * @param array   $exclude    Post IDs to not invalidate.
 */
function fullhistory_post_change( $post, $post_stati, $exclude = array() ) {
	$public_stati = get_post_stati( array( 'public' => true ) );
	if ( fullhistory_disjoint( $post_stati, $public_stati ) ) {
		return;
	}

	fullhistory_invalidate_client_caches(
		array(
			'post__not_in' => $exclude,
			'date_query'   => array(
				'after'     => $post->post_date,
				'inclusive' => true,
			),
		)
	);
}

/**
 * Invalidate client caches on 'transition_post_status' action.
 *
 * Note that this action fires every time a post is saved, even if its status
 * hasn't actually changed.
 *
 * @param string  $new_status The post's new status.
 * @param string  $old_status The post's old status (possibly the same).
 * @param WP_Post $post       The post that is being saved.
 */
function fullhistory_post_status_change( $new_status, $old_status, $post ) {
	fullhistory_post_change( $post, array( $new_status, $old_status ) );
}

add_action( 'transition_post_status', 'fullhistory_post_status_change', 10, 3 );

/**
 * Invalidate client caches on 'delete_post' action.
 *
 * We have to exclude this post from the invalidation because we're in the
 * middle of deleting it so we need to avoid touching anything related to it in
 * the database.
 *
 * @param int $postid The ID of the post that's being deleted.
 */
function fullhistory_post_delete( $postid ) {
	$post = get_post( $postid );
	fullhistory_post_change( $post, array( $post->post_status ), array( $post->ID ) );
}

add_action( 'delete_post', 'fullhistory_post_delete', 10, 1 );

/**
 * Emit a `<link rel>` tag appropriate for the given feed format.
 *
 * @param string $feed_type One of 'rss2' or 'atom'.
 * @param string $rel Value for the 'rel' attribute; will be escaped.
 * @param string $url Value for the 'href' attribute; will be escaped.
 */
function fullhistory_atom_link( $feed_type, $rel, $url ) {
	if ( 'rss2' === $feed_type ) {
		echo '    <atom:link';
	} elseif ( 'atom' === $feed_type ) {
		echo '    <link';
	} else {
		// Don't know how to format for this feed type.
		return;
	}
	echo ' rel="', esc_attr( $rel ), '" href="', esc_url( $url ), '"/>', "\n";
}

/**
 * Emit RFC5005 metadata for a feed document.
 *
 * This function relies on the core WP_Query support for paging and sorting,
 * and generates metadata tags from either section 2 or section 4 of RFC5005,
 * based on the query and on the total number of posts.
 *
 * We depend on sorting archived posts in ascending order by publication date.
 * Page 1 must be at the opposite end of the history from the current
 * syndication document so that, under normal circumstances, adding a new post
 * doesn't change any page except the current one.
 *
 * @link https://tools.ietf.org/html/rfc5005
 */
function fullhistory_xml_head() {
	global $wp_query;
	$found_posts = $wp_query->found_posts;
	$per_page    = get_query_var( 'posts_per_page' );

	// If the total number of posts fits within a single page, use RFC5005
	// section 2 ("Complete Feeds").
	if ( $found_posts <= $per_page ) {
		echo "    <fh:complete xmlns:fh=\"http://purl.org/syndication/history/1.0\"/>\n";
		return;
	}

	// Otherwise, use the more complicated section 4 ("Archived Feeds").
	// Namespaces and link targets vary based on whether this is an RSS or
	// an Atom feed, so look that up now.
	$feed_type = get_query_var( 'feed' );
	if ( 'feed' === $feed_type ) {
		$feed_type = get_default_feed();
	}

	// Note: The 'paged' query variable is reported as 0 if unspecified.
	$current_page = get_query_var( 'paged' );
	$newest_page  = (int) ceil( $found_posts / $per_page );
	$current_feed = get_feed_link( $feed_type );

	// We only allow feed pages that are in ascending order to be archived,
	// so their contents stay relatively stable. Also, the newest page
	// can't be considered an archived page.
	if ( get_query_var( 'order' ) === 'ASC' && $current_page < $newest_page ) {
		// If this _is_ an archived page, then RFC5005 says we "SHOULD"
		// both mark it as an archive and also link to the current
		// syndication feed that this archive belongs to.
		echo "    <fh:archive xmlns:fh=\"http://purl.org/syndication/history/1.0\"/>\n";
		fullhistory_atom_link( $feed_type, 'current', $current_feed );
	} else {
		// Otherwise, set up a prev-archive link under the assumption
		// that the feed we're generating right now is the current
		// syndication document.
		//
		// Note: WordPress supports a lot of possible query parameters
		// that could change which entries we're returning right now,
		// so this assumption could lead to weird results. But
		// automatic tools won't usually be looking for RFC5005
		// metadata in feeds with odd query parameters set, so it
		// should be harmless.
		$current_page = $newest_page;
	}

	// Only page 2 and later can have a prev-archive link, since there is
	// no archive earlier than page 1.
	if ( $current_page > 1 ) {
		// According to the standard, archived feeds are effectively
		// treated as if they have a far-future Expires: header, which
		// means in order to invalidate caches we need to use the same
		// sorts of tricks that people do for CSS and other static
		// files. See
		// https://css-tricks.com/strategies-for-cache-busting-css/ for
		// example.
		$prev_query   = new WP_Query(
			array(
				'fields'        => 'ids',
				'no_found_rows' => true,
				'feed'          => 'feed',
				'order'         => 'ASC',
				'posts_per_rss' => 1,
				'offset'        => ( $current_page - 1 ) * $per_page - 1,
			)
		);
		$change_count = get_post_meta( $prev_query->posts[0], '_fullhistory_version', true );
		if ( empty( $change_count ) ) {
			$change_count = 1;
		}

		$prev_archive = add_query_arg(
			array(
				'order'       => 'ASC',
				'fullhistory' => $change_count,
			),
			$current_feed
		);

		// Linking to page 1 is special because if you pass 'paged=1',
		// WordPress redirects to a canonical URL with that page number
		// removed. Rather than triggering an extra HTTP request due to
		// the redirect, we skip adding the query parameter in that
		// case.
		if ( $current_page > 2 ) {
			$prev_archive = add_query_arg( 'paged', $current_page - 1, $prev_archive );
		}

		fullhistory_atom_link( $feed_type, 'prev-archive', $prev_archive );
	}
}

add_action( 'rss2_head', 'fullhistory_xml_head' );
add_action( 'atom_head', 'fullhistory_xml_head' );
