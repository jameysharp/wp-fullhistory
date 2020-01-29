<?php
/*
Plugin Name:  Full-history (RFC5005) feeds
Description:  Allow RFC5005-capable feed readers to see all posts, not just the most recent.
Version:      0.3
Author:       Jamey Sharp
Author URI:   https://jamey.thesharps.us/
License:      BSD-2-Clause
License URI:  https://www.freebsd.org/copyright/freebsd-license.html
*/

/**
 * Emit a `<link rel>` tag appropriate for the given feed format.
 *
 * @param string $feed_type One of 'rss2' or 'atom'.
 * @param string $rel Value for the 'rel' attribute; will be escaped.
 * @param string $url Value for the 'href' attribute; will be escaped.
 */
function fullhistory_atom_link( $feed_type, $rel, $url ) {
	if ( 'rss2' === $feed_type ) {
		echo "\t<atom:link";
	} elseif ( 'atom' === $feed_type ) {
		echo "\t<link";
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
 * The simple case is when all posts in the query fit in a single feed document.
 * In that case, just use section 2 to mark the feed as "complete".
 *
 * Section 4 is more complicated because it requires that the URL of an archived
 * feed page must change if the contents of that page change in any meaningful
 * way. According to the standard, archived feeds are effectively treated as if
 * they have a far-future Expires: header, which means in order to invalidate
 * caches we need to use the same sorts of tricks that people do for CSS and
 * other static files. See
 * https://css-tricks.com/strategies-for-cache-busting-css/ for example.
 *
 * To do that, there needs to be some property of a given page of results that
 * is efficient to compute but that will change if that page or any older page
 * has changed, so we can incorporate that property into each page's URL.
 *
 * This implementation sorts archived posts in ascending order by
 * modification timestamp, so that:
 *
 * - If a post is newly added, then it will have a newer modification timestamp
 *   than all other posts, and so be added to the highest-numbered archive page.
 *
 * - If a post is deleted, that changes the position in the paginated list of
 *   all posts which were modified after the last time the deleted post was
 *   modified. As a result, all pagination boundaries after that post move one
 *   post later.
 *
 * - If a post is edited, it moves to the end of the list. That's equivalent,
 *   from the perspective of modification timestamps, to deleting it and then
 *   adding it again.
 *
 * - If the number of posts per RSS feed changes, then that moves all the
 *   pagination boundaries.
 *
 * Then we need some property of each page that changes when its pagination
 * boundaries move. One approach which almost works is to use the newest
 * modification timestamp of any post on that page. However, that fails in the
 * uncommon case where two posts are modified in the same second.
 *
 * Instead, this implementation incorporates the post-ID of the newest post on a
 * page into that page's URL. If the pagination boundary shifts, then the newest
 * post will be one with a different ID, satisfying the requirement that its URL
 * changes.
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
		echo "\t<fh:complete xmlns:fh=\"http://purl.org/syndication/history/1.0\"/>\n";
		return;
	}

	// Otherwise, use the more complicated section 4 ("Archived Feeds").
	// Namespaces and link targets vary based on whether this is an RSS or
	// an Atom feed, so look that up now.
	$feed_type = get_query_var( 'feed' );
	if ( 'feed' === $feed_type ) {
		$feed_type = get_default_feed();
	}

	$host         = wp_parse_url( home_url() );
	$current_feed = remove_query_arg(
		array( 'order', 'orderby', 'paged', 'modified' ),
		set_url_scheme( 'http://' . $host['host'] . wp_unslash( $_SERVER['REQUEST_URI'] ) ) // Input var okay.
	);

	// Only allow feed pages to be considered archived if they are in
	// ascending order by modification date, so their contents stay
	// relatively stable.
	if ( get_query_var( 'order' ) === 'ASC' && get_query_var( 'orderby' ) === 'modified' ) {
		// If this _is_ an archived page, then RFC5005 says we "SHOULD"
		// both mark it as an archive and also link to the current
		// syndication feed that this archive belongs to.
		echo "\t<fh:archive xmlns:fh=\"http://purl.org/syndication/history/1.0\"/>\n";
		fullhistory_atom_link( $feed_type, 'current', $current_feed );

		// Note: The 'paged' query variable is reported as 0 if unspecified.
		$prev_page = get_query_var( 'paged' ) - 1;
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
		//
		// Point to the newest page, which may not be complete yet, but
		// that's okay because as it changes the link computed below
		// will change.
		$prev_page = (int) ceil( $found_posts / $per_page );
	}

	// Only page 2 and later can have a prev-archive link, since there is
	// no archive earlier than page 1.
	if ( $prev_page >= 1 ) {
		// Repeat the current query, but one page earlier. If this
		// wasn't already a query for an archive page, then the
		// order/orderby parameters may need to be overridden.
		$prev_query = new WP_Query(
			array_merge(
				$wp_query->query_vars,
				array(
					'no_found_rows' => true,
					'fields'        => 'ids',
					'order'         => 'ASC',
					'orderby'       => 'modified',
					'paged'         => $prev_page,
				)
			)
		);

		// Get the most recent post ID from that previous page. Since
		// the posts are in ascending order by modification time, we
		// just need to look at the last post.
		$prev_ids = $prev_query->posts;
		$prev_id  = $prev_ids[ count( $prev_ids ) - 1 ];

		// Earlier, $current_feed was constructed by stripping off all
		// the order, orderby, modified, and paged query parameters.
		// Construct a new link by adding them back now with the right
		// values.
		$prev_archive = add_query_arg(
			array(
				'order'    => 'ASC',
				'orderby'  => 'modified',
				'modified' => $prev_id,
			),
			$current_feed
		);

		// Linking to page 1 is special because if you pass 'paged=1',
		// WordPress redirects to a canonical URL with that page number
		// removed. Rather than triggering an extra HTTP request due to
		// the redirect, skip adding the query parameter in that case.
		if ( $prev_page > 1 ) {
			$prev_archive = add_query_arg( 'paged', $prev_page, $prev_archive );
		}

		fullhistory_atom_link( $feed_type, 'prev-archive', $prev_archive );
	}
}

add_action( 'rss2_head', 'fullhistory_xml_head' );
add_action( 'atom_head', 'fullhistory_xml_head' );
