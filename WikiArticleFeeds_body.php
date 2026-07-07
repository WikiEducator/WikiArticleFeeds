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
	const VERSION = '1.0.0 20260705';

	public static function getGlobalInstance() {
		global $wgWikiArticleFeeds;
		if ( !$wgWikiArticleFeeds ) {
			$wgWikiArticleFeeds = new self();
		}
		return $wgWikiArticleFeeds;
	}

	function feedStart( $text, $params = [], Parser $parser ) {
		$parser->addTrackingCategory( 'wikiarticlefeeds-tracking-category' );
		return '<!-- FEED_START -->';
	}

	function feedEnd( $text, $params = [] ) {
		return '<!-- FEED_END -->';
	}

	function burnFeed( $text, $params = [] ) {
		return ( $params['name'] ? '<!-- BURN_FEED ' . base64_encode( serialize( $params['name'] ) ) . ' -->' : '' );
	}

	function itemTagsTag( $text, $params = [] ) {
		return ( $text ? '<!-- ITEM_TAGS ' . base64_encode( serialize( $text ) ) . ' -->' : '' );
	}

	function itemTagsFunction( Parser $parser ) {
		$tags = func_get_args();
		array_shift( $tags );
		return ( !empty( $tags ) ? '<pre>@ITEMTAGS@' . base64_encode( serialize( implode( ',', $tags ) ) ) . '@ITEMTAGS@</pre>' : '' );
	}

	function itemTagsPlaceholderCorrections( Parser $parser, &$text ) {
		$text = preg_replace(
			'|<pre>@ITEMTAGS@([0-9a-zA-Z\\+\\/]+=*)@ITEMTAGS@</pre>|',
			'<!-- ITEM_TAGS $1 -->',
			$text
		);
		return true;
	}

	public static function onParserAfterTidy( Parser $parser, &$text ) {
		return self::getGlobalInstance()->itemTagsPlaceholderCorrections( $parser, $text );
	}

	# Sets up the WikiArticleFeeds Parser hooks
	public static function setup( Parser $parser ) {
		$instance = self::getGlobalInstance();

		$parser->setHook( 'startFeed', [ $instance, 'feedStart' ] );
		$parser->setHook( 'endFeed', [ $instance, 'feedEnd' ] );
		$parser->setHook( 'feedBurner', [ $instance, 'burnFeed' ] );
		$parser->setHook( 'itemTags', [ $instance, 'itemTagsTag' ] );
		$parser->setFunctionHook( 'itemtags', [ $instance, 'itemTagsFunction' ] );

		return true;
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

		$rssArr = [
			'rel' => 'alternate',
			'type' => 'application/rss+xml',
			'title' => $out->getTitle()->getText() . ' - RSS 2.0',
		];
		$atomArr = [
			'rel' => 'alternate',
			'type' => 'application/atom+xml',
			'title' => $out->getTitle()->getText() . ' - Atom 0.3',
		];

		# Test for feedBurner presence
		if ( preg_match( '/<!-- BURN_FEED ([0-9a-zA-Z\\+\\/]+=*) -->/m', $text, $matches ) ) {
			$name = @unserialize( @base64_decode( $matches[1] ) );
			if ( $name ) {
				$rssArr['href'] = 'http://feeds.feedburner.com/' . urlencode( $name ) . '?format=xml';
				$atomArr['href'] = 'http://feeds.feedburner.com/' . urlencode( $name ) . '?format=xml';
			}
		}

		# If it's not being fed by feedBurner, do it ourselves!
		if ( !array_key_exists( 'href', $rssArr ) || !$rssArr['href'] ) {
			global $wgServer, $wgScript;

			$baseUrl = $wgServer . $wgScript . '?title=' . $out->getTitle()->getDBkey() . '&action=feed&feed=';
			$rssArr['href'] = $baseUrl . 'rss';
			$atomArr['href'] = $baseUrl . 'atom';
		}

		$out->addLink( $rssArr );
		$out->addLink( $atomArr );

		global $wgWikiFeedPresent;
		$wgWikiFeedPresent = true;

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
	 * Adds the Wiki feed links to the bottom of the toolbox in MonoBook or like-minded skins.
	 *
	 * @param BaseTemplate $template Instance of MonoBookTemplate or other BaseTemplate
	 */
	public static function addToolboxLinks( $template ) {
		global $wgServer, $wgScript, $wgWikiFeedPresent;

		# Short-circuit if there are no Feeds present
		if ( !$wgWikiFeedPresent ) {
			return true;
		}

		$title = $template->getSkin()->getTitle();

		if ( $title->getNamespace() < NS_MAIN ) {
			return true;
		}

		$result = '<li id="feedlinks">';

		# Test for feedBurner presence
		$burned = false;
		ob_start();
		$template->html( 'bodytext' );
		$text = ob_get_contents();
		ob_end_clean();
		if ( preg_match( '/<!-- BURN_FEED ([0-9a-zA-Z\\+\\/]+=*) -->/m', $text, $matches ) ) {
			$feedBurnerName = @unserialize( @base64_decode( $matches[1] ) );
			if ( $feedBurnerName ) {
				$feeds = [
					'rss' => 'RSS',
					'atom' => 'Atom'
				];
				foreach ( $feeds as $feed => $name ) {
					$result .=
					'<span id="feed-' . htmlspecialchars( $feed ) . '">' .
					'<a class="feedlink" href="http://feeds.feedburner.com/' . urlencode( $feedBurnerName ) . '?format=xml">' .
					htmlspecialchars( $name ) . '</a>&#160;</span>';
				}
				$burned = true;
			}
		}

		# Generate regular RSS and Atom feeds if not fed by feedBurner
		if ( !$burned ) {
			$dbKey = $title->getPrefixedDBkey();
			$baseUrl = $wgServer . $wgScript . '?title=' . $dbKey . '&action=feed&feed=';
			$feeds = [
				'rss' => 'RSS',
				'atom' => 'Atom'
			];
			foreach ( $feeds as $feed => $name ) {
				$result .=
				'<span id="feed-' . htmlspecialchars( $feed ) . '">' .
				'<a class="feedlink" href="' . htmlspecialchars( $baseUrl . $feed ) . '">' .
				htmlspecialchars( $name ) . '</a>&#160;</span>';
			}
		}

		echo $result . '</li>';

		# True to indicate that other action handlers should continue to process this page
		return true;
	}

	/**
	 * Purges all associated feeds when an Article is purged.
	 *
	 * @param WikiPage $wikiPage The WikiPage which is being purged.
	 * @return bool Always true to permit additional hook processing.
	 */
	public static function onArticlePurge( WikiPage $wikiPage ) {
		global $messageMemc, $wgDBname;
		if ( !$messageMemc ) {
			$messageMemc = ObjectCache::getLocalClusterInstance();
		}
		$titleDBKey = $wikiPage->mTitle->getPrefixedDBkey();
		$keyPrefix = "{$wgDBname}:wikiarticlefeedsextension:{$titleDBKey}";
		$messageMemc->delete( "{$keyPrefix}:atom:timestamp" );
		$messageMemc->delete( "{$keyPrefix}:atom" );
		$messageMemc->delete( "{$keyPrefix}:rss" );
		$messageMemc->delete( "{$keyPrefix}:rss:timestamp" );
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
		global $wgOut, $wgServer, $wgFeedClasses, $wgVersion;

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
		$content = $wgOut->parseAsContent( ContentHandler::getContentText( $article->getPage()->getContent() ) .
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
							$t = @unserialize( @base64_decode( $encodedString ) );
							if ( $t ) {
								$t = explode( ',', $t );
								array_walk( $t, 'trim' );
								sort( $t );
								$tags = array_merge( $tags, $t );
							}
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
		$tempWgVersion = $wgVersion;
		$wgVersion .= ' via WikiArticleFeeds ' . self::VERSION;
		$feed->outHeader();
		$wgVersion = $tempWgVersion;

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

		global $wgFeedClasses, $wgFeedCacheTimeout, $wgDBname, $messageMemc;

		if ( !$messageMemc ) {
			$messageMemc = ObjectCache::getLocalClusterInstance();
		}

		# Get query parameters
		$feedFormat = $request->getVal( 'feed', 'atom' );
		$filterTags = $request->getVal( 'tags', null );

		# Process requested tags for use in keys
		if ( $filterTags ) {
			$filterTags = explode( ',', $filterTags );
			array_walk( $filterTags, 'trim' );
			sort( $filterTags );
		}

		if ( !isset( $wgFeedClasses[$feedFormat] ) ) {
			wfHttpError( 500, 'Internal Server Error', 'Unsupported feed type.' );
			return null;
		}

		# Setup cache-checking vars
		$title = $article->getTitle();
		$titleDBkey = $title->getPrefixedDBkey();
		$tags = ( is_array( $filterTags ) ? ':' . implode( ',', $filterTags ) : '' );
		$key = "{$wgDBname}:wikiarticlefeedsextension:{$titleDBkey}:{$feedFormat}{$tags}";
		$timekey = $key . ':timestamp';
		$cachedFeed = false;
		$feedLastmod = $messageMemc->get( $timekey );

		# Determine last modification time for either the article itself or an included template
		$lastmod = $article->getPage()->getTimestamp();
		$templates = $title->getTemplateLinksFrom();
		foreach ( $templates as $tTitle ) {
			$tArticle = new Article( $tTitle );
			$tmod = $tArticle->getPage()->getTimestamp();
			$lastmod = ( $lastmod > $tmod ? $lastmod : $tmod );
		}

		# Check for availability of existing cached feed
		if ( ( $wgFeedCacheTimeout > 0 ) && $feedLastmod ) {
			$feedLastmodTS = wfTimestamp( TS_UNIX, $feedLastmod );
			if (
				time() - $feedLastmodTS < $wgFeedCacheTimeout ||
				$feedLastmodTS > wfTimestamp( TS_UNIX, $lastmod )
			) {
				wfDebugLog( 'WikiArticleFeeds', "Loading feed from cache ($key; $feedLastmod; $lastmod)...\n" );
				$cachedFeed = $messageMemc->get( $key );
			} else {
				wfDebugLog( 'WikiArticleFeeds', "Cached feed timestamp check failed ($feedLastmod; $lastmod)\n" );
			}
		}

		# Display cachedFeed, or generate one from scratch
		global $wgWikiArticleFeedsSkipCache;
		if ( !$wgWikiArticleFeedsSkipCache && is_string( $cachedFeed ) ) {
			wfDebugLog( 'WikiArticleFeeds', 'Outputting cached feed' );
			$feed = new $wgFeedClasses[$feedFormat]( $title->getText(), '', $title->getFullURL() . ' - Feed' );
			ob_start();
			$feed->httpHeaders();
			echo $cachedFeed;
		} else {
			wfDebugLog( 'WikiArticleFeeds', 'Rendering new feed and caching it' );
			ob_start();
			WikiArticleFeeds::generateWikiFeed( $article, $feedFormat, $filterTags );
			$cachedFeed = ob_get_contents();
			ob_end_flush();

			$expire = 3600; # One hour
			$messageMemc->set( $key, $cachedFeed );
			$messageMemc->set( $timekey, wfTimestamp( TS_MW ), $expire );
		}

		return null;
	}

}
