<?php
/**
 * This file is part of WikiArticleFeeds
 *
 * Copyright © 2007 Jim R. Wilson
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights to
 * use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of
 * the Software, and to permit persons to whom the Software is furnished to do
 * so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
 * OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 */
class WikiArticleFeeds {
	const VERSION = '2.0.0 20261112';

	/** Cache lifetime (seconds) of generated feeds */
	const CACHE_TTL = 86400;

	public static function feedStart( $text, $params, Parser $parser ) {
		$parser->addTrackingCategory( 'wikiarticlefeeds-tracking-category' );
		return '<!-- FEED_START -->';
	}

	public static function feedEnd( $text, $params ) {
		return '<!-- FEED_END -->';
	}

	/**
	 * <feedBurner> is no longer supported (FeedBurner is defunct);
	 * the tag is still accepted so old pages don't show it as text.
	 */
	public static function burnFeed( $text, $params ) {
		return '';
	}

	public static function itemTagsTag( $text, $params ) {
		return ( $text ? '<!-- ITEM_TAGS ' . base64_encode( serialize( $text ) ) . ' -->' : '' );
	}

	public static function itemTagsFunction( Parser $parser, ...$tags ) {
		return ( !empty( $tags ) ? '<pre>@ITEMTAGS@' . base64_encode( serialize( implode( ',', $tags ) ) ) . '@ITEMTAGS@</pre>' : '' );
	}

	public static function onParserAfterTidy( Parser $parser, &$text ) {
		$text = preg_replace(
			'|<pre>@ITEMTAGS@([0-9a-zA-Z\\+\\/]+=*)@ITEMTAGS@</pre>|',
			'<!-- ITEM_TAGS $1 -->',
			$text
		);
		return true;
	}

	/**
	 * Decode an ITEM_TAGS payload.  The payload can be written by any
	 * editor, so never let unserialize() create objects.
	 *
	 * @param string $encoded base64 of a serialized string
	 * @return string[] trimmed, non-empty tags
	 */
	private static function decodeTags( $encoded ) {
		$t = @unserialize( base64_decode( $encoded ), [ 'allowed_classes' => false ] );
		if ( !is_string( $t ) ) {
			return [];
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $t ) ), 'strlen' ) );
	}

	# Sets up the WikiArticleFeeds Parser hooks
	public static function setup( Parser $parser ) {
		$parser->setHook( 'startFeed', [ self::class, 'feedStart' ] );
		$parser->setHook( 'endFeed', [ self::class, 'feedEnd' ] );
		$parser->setHook( 'feedBurner', [ self::class, 'burnFeed' ] );
		$parser->setHook( 'itemTags', [ self::class, 'itemTagsTag' ] );
		$parser->setFunctionHook( 'itemtags', [ self::class, 'itemTagsFunction' ] );

		return true;
	}

	/**
	 * Base URL of a page's feeds; append 'rss' or 'atom'.
	 *
	 * @param Title $title
	 * @return string
	 */
	private static function feedBaseUrl( Title $title ) {
		return $title->getFullURL( [ 'action' => 'feed', 'feed' => '' ] );
	}

	/**
	 * Cache key of a generated feed.
	 *
	 * @param BagOStuff $cache
	 * @param Title $title
	 * @param string $format 'rss' or 'atom'
	 * @param string $tags comma separated filter tags, or ''
	 * @return string
	 */
	public static function cacheKey( BagOStuff $cache, Title $title, $format, $tags = '' ) {
		return $cache->makeKey( 'wikiarticlefeeds', md5( $title->getPrefixedDBkey() ), $format, md5( $tags ) );
	}

	/**
	 * Adds the Wiki feed link headers.
	 *
	 * @param OutputPage $out Handle to an OutputPage object (presumably $wgOut).
	 * @param string $text Article/Output text.
	 */
	public static function addWikiFeedHeaders( $out, $text ) {
		# Short-circuit if this article contains no feeds
		if ( !preg_match( '/<!-- FEED_START -->/m', $text ) ) {
			return true;
		}

		$title = $out->getTitle();
		$baseUrl = self::feedBaseUrl( $title );
		$out->addLink( [
			'rel' => 'alternate',
			'type' => 'application/rss+xml',
			'title' => $title->getText() . ' - RSS 2.0',
			'href' => $baseUrl . 'rss',
		] );
		$out->addLink( [
			'rel' => 'alternate',
			'type' => 'application/atom+xml',
			'title' => $title->getText() . ' - Atom',
			'href' => $baseUrl . 'atom',
		] );

		# Remembered for onSidebarBeforeOutput()
		$out->setProperty( 'wikiarticlefeeds', true );

		# True to indicate that other action handlers should continue to process this page
		return true;
	}

	/**
	 * Add additional attributes to links to User- or User_talk pages
	 * It does this for all links on all pages
	 * (even when we need this only for pages which generate a feed)
	 *
	 * Attributes are used later in self::generateWikiFeed() to determine signatures with timestamps
	 * for attributing author and timestamp values to the feed item from the signatures.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LinkEnd
	 */
	public static function onHtmlPageLinkRendererEnd( $linkRenderer, $target, $isKnown, &$text, &$attribs, &$ret ) {
		if ( $target->getNamespace() == NS_USER ) {
			$attribs['data-userpage-link'] = 'true';
		} elseif ( $target->getNamespace() == NS_USER_TALK ) {
			$attribs['data-usertalkpage-link'] = 'true';
		}
		return true;
	}

	/**
	 * Adds RSS and Atom links to the sidebar toolbox of pages that contain feeds.
	 *
	 * @param Skin $skin
	 * @param array &$sidebar
	 * @return bool
	 */
	public static function onSidebarBeforeOutput( $skin, &$sidebar ) {
		# Short-circuit if there are no Feeds present
		if ( !$skin->getOutput()->getProperty( 'wikiarticlefeeds' ) ) {
			return true;
		}

		$title = $skin->getTitle();
		if ( !$title || $title->getNamespace() < NS_MAIN ) {
			return true;
		}

		$baseUrl = self::feedBaseUrl( $title );
		foreach ( [ 'rss' => 'RSS', 'atom' => 'Atom' ] as $feed => $name ) {
			$sidebar['TOOLBOX']['wikiarticlefeeds-' . $feed] = [
				'text' => $name,
				'href' => $baseUrl . $feed,
				'id' => 'feed-' . $feed,
				'class' => 'feedlink',
			];
		}

		return true;
	}

	/**
	 * Purges the unfiltered feeds when an Article is purged.  Tag-filtered
	 * feeds are refreshed once $wgFeedCacheTimeout has passed, because a
	 * purge also updates the page's touched timestamp.
	 *
	 * @param WikiPage $wikiPage The WikiPage which is being purged.
	 * @return bool Always true to permit additional hook processing.
	 */
	public static function onArticlePurge( WikiPage $wikiPage ) {
		$cache = ObjectCache::getLocalClusterInstance();
		$title = $wikiPage->getTitle();
		foreach ( [ 'rss', 'atom' ] as $format ) {
			$key = self::cacheKey( $cache, $title, $format );
			$cache->delete( $key );
			$cache->delete( $key . ':timestamp' );
		}
		return true;
	}

	/**
	 * Converts an MediaWiki article into a feed, echoing generated content directly.
	 *
	 * @param Article $article Article to be converted to RSS or Atom feed.
	 * @param string $feedFormat A format type - must be either 'rss' or 'atom'
	 * @param array|null $filterTags Tags to use in filtering out items.
	 */
	public static function generateWikiFeed( $article, $feedFormat = 'atom', $filterTags = null ) {
		global $wgServer, $wgFeedClasses;

		# Setup, handle redirects
		if ( $article->getPage()->isRedirect() ) {
			$rtitle = $article->getContentObject()->getRedirectTarget();
			if ( $rtitle ) {
				$article = new Article( $rtitle );
			}
		}
		$title = $article->getTitle();
		$feedUrl = $title->getFullURL();

		# Determine fallback author/date for items where no signature could be
		# detected in the content (e.g. no linked username + timestamp found).
		# Since transclusions are already flattened into plain HTML by the time
		# we see them, we can't attribute a specific paragraph to the exact
		# subpage it came from -- so we use whichever page (this article or any
		# page it transcludes) was edited most recently as a reasonable default.
		$fallbackWikiPage = $article->getPage();
		$fallbackDate = wfTimestamp( TS_MW, $fallbackWikiPage->getTimestamp() );
		$fallbackAuthor = $fallbackWikiPage->getUserText();
		foreach ( $title->getTemplateLinksFrom() as $tTitle ) {
			$tWikiPage = WikiPage::factory( $tTitle );
			$tDate = wfTimestamp( TS_MW, $tWikiPage->getTimestamp() );
			if ( $tDate > $fallbackDate ) {
				$fallbackDate = $tDate;
				$fallbackAuthor = $tWikiPage->getUserText();
			}
		}

		# Parse page into feed items.
		$content = $article->getContext()->getOutput()->parseAsContent( ContentHandler::getContentText( $article->getPage()->getContent() ) .
			"\n__NOEDITSECTION__ __NOTOC__" );
		preg_match_all(
			'/<!--\\s*FEED_START\\s*-->(.*?)<!--\\s*FEED_END\\s*-->/s',
			$content,
			$matches
		);
		$feedContentSections = $matches[1];

		# Parse and process all feeds, collecting feed items
		$items = [];
		$feedDescription = '';
		foreach ( $feedContentSections as $feedKey => $feedContent ) {
			# Determine Feed item depth (what header level defines a feed)
			preg_match_all( '/<h(\\d)>/m', $feedContent, $matches );
			if ( !isset( $matches[1] ) ) {
				continue;
			}
			$lvl = $matches[1][0];
			foreach ( $matches[1] as $match ) {
				if ( $match < $lvl ) {
					$lvl = $match;
				}
			}

			$sectionRegExp = '#<h' . $lvl . '>\s*<span.+?id="(.*?)">\s*(.*?)\s*</span>\s*</h' . $lvl . '>#m';

			# Determine the item titles and default item links
			preg_match_all(
				$sectionRegExp,
				$feedContent,
				$matches
			);
			$itemLinks = $matches[1];
			$itemTitles = $matches[2];

			# Split content into segments
			$segments = preg_split( $sectionRegExp, $feedContent );
			$segDesc = trim( strip_tags( array_shift( $segments ) ) );
			if ( $segDesc ) {
				if ( !$feedDescription ) {
					$feedDescription = $segDesc;
				} else {
					$feedDescription =
						$article->getContext()->msg( 'wikiarticlefeeds_combined_description' )->text();
				}
			}

			# Loop over parsed segments and add all items to item array
			foreach ( $segments as $key => $seg ) {
				# Filter by tag (if any are present)
				$skip = false;
				$tags = null;
				if ( is_array( $filterTags ) && !empty( $filterTags ) ) {
					if ( preg_match_all( '/<!-- ITEM_TAGS ([0-9a-zA-Z\\+\\/]+=*) -->/m', $seg, $matches ) ) {
						$tags = [];
						foreach ( $matches[1] as $encodedString ) {
							$tags = array_merge( $tags, self::decodeTags( $encodedString ) );
						}
						$tags = array_unique( $tags );
						if ( !count( array_intersect( $tags, $filterTags ) ) ) {
							$skip = true;
						}
						$seg = preg_replace( '/<!-- ITEM_TAGS ([0-9a-zA-Z\\+\\/]+=*) -->/m', '', $seg );
					} else {
						$skip = true;
					}
				}
				if ( $skip ) {
					continue;
				}

				# Try hard to determine the item author and date
				# Look for a regular signatures of the layout
				# data-userpage-link [optional user_talk page link] date (with a delimiting timezone code in parentheses)

				$author = null;
				$date = null;

				$signatureRegExp = '#<a href=".+?User:.+?" title="User:.+?">(.*?)</a> (\d\d):(\d\d), (\d+) ([a-z]+) (\d{4}) (\([A-Z]+\))#im';

				$signatureRegExp1 = '#<.*data-userpage-link.*>(.*?)</a>.*<.*data-usertalkpage-link.*>.*</a>\) (.*\([A-Z]+\))#im';
				$signatureRegExp2 = '#<.*data-userpage-link.*>(.*?)</a> (.*\([A-Z]+\))#im';
				$signatureRegExp3 = '#<.*data-usertalkpage-link.*>(.*?)</a> (.*\([A-Z]+\))#im';

				$isAttributable = ( preg_match( $signatureRegExp1, $seg, $matches )
					|| preg_match( $signatureRegExp2, $seg, $matches )
					|| preg_match( $signatureRegExp3, $seg, $matches ) );

				if ( $isAttributable ) {
					list( $author, $timestring ) = array_slice( $matches, 1 );

					$tempTimezone = date_default_timezone_get();
					date_default_timezone_set( 'UTC' );
					$date = date( 'YmdHis', strtotime( $timestring ) );
					date_default_timezone_set( $tempTimezone );
				} else {
					# No signature detected in this item; fall back so the feed
					# entry still has the author/updated fields Atom requires.
					$author = $fallbackAuthor;
					$date = $fallbackDate;
				}

				# Set default 'article section' feed-link
				$url = $feedUrl . '#' . $itemLinks[$key];

				# Look for an alternative to the default link (unless default 'section linking' has been forced)
				global $wgForceArticleFeedSectionLinks;
				if ( !$wgForceArticleFeedSectionLinks ) {
					$strippedSeg = preg_replace( $signatureRegExp, '', $seg );
					preg_match(
						'#<a [^>]*href=([\'"])(.*?)\\1[^>]*>(.*?)</a>#m',
						$strippedSeg,
						$matches
					);
					if ( isset( $matches[2] ) ) {
						$url = $matches[2];
						if ( preg_match( '#^/#', $url ) ) {
							$url = $wgServer . $url;
						}
					}
				}

				# Create 'absolutified' segment - where all URLs are fully qualified
				$seg = preg_replace( '/ (href|src)=([\'"])\\//', ' $1=$2' . $wgServer . '/', $seg );

				# Create item and push onto item list
				$items[$date][] = new FeedItem( strip_tags( $itemTitles[$key] ), $seg, $url, $date, $author );
			}
		}

		# Programmatically determine the feed title and ID.
		$feedTitle = $title->getPrefixedText() . ' - Feed';
		$feedId = $title->getFullURL();

		# Create feed
		$feed = new $wgFeedClasses[$feedFormat]( $feedTitle, $feedDescription, $feedId );

		# Push feed header
		$feed->outHeader();

		# Sort all items by date and push onto feed
		krsort( $items );
		foreach ( $items as $itemGroup ) {
			foreach ( $itemGroup as $item ) {
				$feed->outItem( $item );
			}
		}

		# Feed footer
		$feed->outFooter();
	}

} /* class WikiArticleFeeds */

/**
 * Handles action=feed, registered via $wgActions (extension.json "Actions").
 * Replaces the old onUnknownAction hook, which was removed from MediaWiki core.
 */
class WikiArticleFeedsFeedAction extends FormlessAction {

	public function getName() {
		return 'feed';
	}

	public function onView() {
		$article = $this->getArticle();
		$request = $this->getRequest();

		global $wgFeedClasses, $wgFeedCacheTimeout;

		$cache = ObjectCache::getLocalClusterInstance();

		# Get query parameters
		$feedFormat = $request->getVal( 'feed', 'atom' );
		$filterTags = $request->getVal( 'tags', null );

		# Process requested tags for use in keys
		if ( $filterTags ) {
			$filterTags = array_unique( array_filter( array_map( 'trim', explode( ',', $filterTags ) ), 'strlen' ) );
			sort( $filterTags );
		}

		if ( !isset( $wgFeedClasses[$feedFormat] ) ) {
			wfHttpError( 400, 'Bad Request', 'Unsupported feed type.' );
			return null;
		}

		# Setup cache-checking vars
		$title = $article->getTitle();
		$tags = ( $filterTags ? implode( ',', $filterTags ) : '' );
		$key = WikiArticleFeeds::cacheKey( $cache, $title, $feedFormat, $tags );
		$timekey = $key . ':timestamp';
		$cachedFeed = false;
		$feedLastmod = $cache->get( $timekey );

		# Determine last modification time for either the article itself
		# (edit or purge) or an included template
		$page = $article->getPage();
		$lastmod = max( $page->getTimestamp(), $page->getTouched() );
		foreach ( $title->getTemplateLinksFrom() as $tTitle ) {
			$lastmod = max( $lastmod, WikiPage::factory( $tTitle )->getTimestamp() );
		}

		# Check for availability of existing cached feed
		if ( ( $wgFeedCacheTimeout > 0 ) && $feedLastmod ) {
			$feedLastmodTS = wfTimestamp( TS_UNIX, $feedLastmod );
			if (
				time() - $feedLastmodTS < $wgFeedCacheTimeout ||
				$feedLastmodTS > wfTimestamp( TS_UNIX, $lastmod )
			) {
				wfDebugLog( 'WikiArticleFeeds', "Loading feed from cache ($key; $feedLastmod; $lastmod)...\n" );
				$cachedFeed = $cache->get( $key );
			} else {
				wfDebugLog( 'WikiArticleFeeds', "Cached feed timestamp check failed ($feedLastmod; $lastmod)\n" );
			}
		}

		# Display cachedFeed, or generate one from scratch
		global $wgWikiArticleFeedsSkipCache;
		if ( !$wgWikiArticleFeedsSkipCache && is_string( $cachedFeed ) ) {
			wfDebugLog( 'WikiArticleFeeds', 'Outputting cached feed' );
			$feed = new $wgFeedClasses[$feedFormat]( $title->getText(), '', $title->getFullURL() . ' - Feed' );
			$feed->httpHeaders();
			echo $cachedFeed;
		} else {
			wfDebugLog( 'WikiArticleFeeds', 'Rendering new feed and caching it' );
			ob_start();
			WikiArticleFeeds::generateWikiFeed( $article, $feedFormat, $filterTags );
			$cachedFeed = ob_get_contents();
			ob_end_flush();

			$cache->set( $key, $cachedFeed, WikiArticleFeeds::CACHE_TTL );
			$cache->set( $timekey, wfTimestamp( TS_MW ), WikiArticleFeeds::CACHE_TTL );
		}

		return null;
	}

}
