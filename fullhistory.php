<?php
/*
Plugin Name:  Full-history (RFC5005) feeds
Description:  Allow RFC5005-capable feed readers to see all posts, not just the most recent.
Version:      0.1
Author:       Jamey Sharp
Author URI:   https://jamey.thesharps.us/
License:      BSD-2-Clause
License URI:  https://www.freebsd.org/copyright/freebsd-license.html
*/

/**
 * Add the standard RFC5005 namespace to a feed root element.
 */
function fullhistory_xml_ns() {
	echo "xmlns:fh=\"http://purl.org/syndication/history/1.0\"\n";
}

add_action( 'rss2_ns', 'fullhistory_xml_ns' );
add_action( 'atom_ns', 'fullhistory_xml_ns' );

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
		echo "    <fh:complete/>\n";
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
		echo "    <fh:archive/>\n";
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
		// FIXME: Add a "cache-busting" query string parameter such
		// that, when a post is saved or deleted, the pages for that
		// post and everything newer all get new URLs. Until that's
		// implemented, RFC5005-compliant feed readers may not notice
		// when old posts are edited, deleted, or inserted.
		//
		// According to the standard, archived feeds are effectively
		// treated as if they have a far-future Expires: header, which
		// means in order to invalidate caches we need to use the same
		// sorts of tricks that people do for CSS and other static
		// files. See
		// https://css-tricks.com/strategies-for-cache-busting-css/ for
		// example.
		$prev_archive = add_query_arg( 'order', 'ASC', $current_feed );

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
