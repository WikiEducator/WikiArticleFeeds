<?php
/**
 * This file is part of WikiArticleFeeds
 *
 * Copyright © 2007 Jim R. Wilson
 *
 * @license MIT
 * @file
 */

namespace MediaWiki\Extension\WikiArticleFeeds;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use MediaWiki\Feed\ChannelFeed;
use MediaWiki\Feed\FeedItem;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\Sanitizer;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Utils\UrlUtils;
use MessageLocalizer;
use WikiPage;

/**
 * Turns the rendered HTML of a wiki page into an RSS or Atom feed.
 *
 * The parts of the page between <startFeed /> and <endFeed /> are feeds.
 * In a feed, each section of the highest heading level is an item.
 */
class FeedGenerator {

	/** Rendered <startFeed /> */
	public const FEED_START = '<!-- FEED_START -->';

	/** Rendered <endFeed /> */
	public const FEED_END = '<!-- FEED_END -->';

	private const FEED_REGEX = '/<!--\s*FEED_START\s*-->(.*?)<!--\s*FEED_END\s*-->/s';

	private const ITEM_TAGS_REGEX = '#<!-- ITEM_TAGS ([0-9a-zA-Z+/]*=*) -->#';

	/**
	 * A heading made from wikitext, in either of the forms MediaWiki renders
	 * (see $wgParserEnableLegacyHeadingDOM).  Headings written as HTML with
	 * attributes of their own don't start feed items.
	 */
	private const HEADING_LEVEL_REGEX = '#<h([1-6])(?: id="[^"]*")?>#';

	/**
	 * Signatures, which give an item its author and date: a link to a user
	 * page, perhaps a link to the user's talk page, and a time that ends
	 * with a timezone abbreviation in parentheses.
	 */
	private const SIGNATURE_REGEXES = [
		'#<.*data-userpage-link.*>(.*?)</a>.*<.*data-usertalkpage-link.*>.*</a>\) (.*\([A-Z]+\))#im',
		'#<.*data-userpage-link.*>(.*?)</a> (.*\([A-Z]+\))#im',
		'#<.*data-usertalkpage-link.*>(.*?)</a> (.*\([A-Z]+\))#im',
	];

	/** A signature in the default format, which is not used as the link of an item */
	private const DEFAULT_SIGNATURE_REGEX =
		'#<a href=".+?User:.+?" title="User:.+?">(.*?)</a> (\d\d):(\d\d), (\d+) ([a-z]+) (\d{4}) (\([A-Z]+\))#im';

	private const LINK_REGEX = '#<a [^>]*href=([\'"])(.*?)\1[^>]*>#';

	private const LOCAL_URL_REGEX = '#(?<= )(href|src)=([\'"])([/\#].*?)\2#';

	public function __construct(
		private readonly RevisionLookup $revisionLookup,
		private readonly UrlUtils $urlUtils,
		private readonly MessageLocalizer $localizer,
		private readonly bool $forceSectionLinks,
	) {
	}

	/**
	 * The HTML that marks a feed item with tags.
	 *
	 * @param string $tags comma separated tags
	 * @return string HTML
	 */
	public static function encodeItemTags( string $tags ): string {
		return $tags === '' ? '' : '<!-- ITEM_TAGS ' . base64_encode( $tags ) . ' -->';
	}

	/**
	 * @param string $tags comma separated tags
	 * @return string[] trimmed, non-empty, unique tags
	 */
	public static function splitTags( string $tags ): array {
		return array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $tags ) ), 'strlen' ) ) );
	}

	/**
	 * The most recent of the latest edits of a page and the pages it transcludes.
	 *
	 * Transclusions are already expanded in the HTML feeds are made from,
	 * so an item can't be attributed to the page its text comes from.  This
	 * edit is the fallback for items without a signature.
	 *
	 * @param WikiPage $page
	 * @return RevisionRecord|null null if the page has no revisions
	 */
	public function getLatestEdit( WikiPage $page ): ?RevisionRecord {
		$latest = $page->getRevisionRecord();
		if ( !$latest ) {
			return null;
		}
		foreach ( $page->getTitle()->getTemplateLinksFrom() as $template ) {
			$revision = $this->revisionLookup->getKnownCurrentRevision( $template );
			if ( $revision && $revision->getTimestamp() > $latest->getTimestamp() ) {
				$latest = $revision;
			}
		}
		return $latest;
	}

	/**
	 * @param WikiPage $page
	 * @param class-string<ChannelFeed> $feedClass
	 * @param string[] $filterTags if not empty, only items with one of these tags are included
	 * @param RevisionRecord $latestEdit see getLatestEdit()
	 * @return string XML
	 */
	public function generate(
		WikiPage $page, string $feedClass, array $filterTags, RevisionRecord $latestEdit
	): string {
		$title = $page->getTitle();
		$pageUrl = $title->getFullURL();
		$fallbackDate = $latestEdit->getTimestamp();
		$fallbackAuthor = $latestEdit->getUser()?->getName() ?? '';

		$description = '';
		$items = [];
		preg_match_all( self::FEED_REGEX, $this->getHtml( $page ), $feeds );
		foreach ( $feeds[1] as $feedHtml ) {
			# Sections of the highest heading level in the feed are its items
			if ( !preg_match_all( self::HEADING_LEVEL_REGEX, $feedHtml, $levels ) ) {
				continue;
			}
			$headingRegex = self::getHeadingRegex( min( $levels[1] ) );
			preg_match_all( $headingRegex, $feedHtml, $headings, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL );
			$segments = preg_split( $headingRegex, $feedHtml );

			# What comes before the first item describes the feed
			$intro = trim( Sanitizer::stripAllTags( array_shift( $segments ) ) );
			if ( $intro !== '' ) {
				$description = $description === '' ? $intro :
					$this->localizer->msg( 'wikiarticlefeeds_combined_description' )->inContentLanguage()->text();
			}

			foreach ( $segments as $i => $segment ) {
				$item = $this->makeItem(
					$headings[$i]['legacyTitle'] ?? $headings[$i]['title'],
					Sanitizer::decodeCharReferences( $headings[$i]['legacyId'] ?? $headings[$i]['id'] ),
					$segment,
					$pageUrl,
					$filterTags,
					$fallbackDate,
					$fallbackAuthor
				);
				if ( $item ) {
					$items[] = $item;
				}
			}
		}

		# Newest first; usort() is stable, so items of the same date stay in page order
		usort( $items, static fn ( FeedItem $a, FeedItem $b ) => $b->getDate() <=> $a->getDate() );

		/** @var ChannelFeed $feed */
		$feed = new $feedClass( $title->getPrefixedText() . ' - Feed', $description, $pageUrl );

		# Feeds print their output
		ob_start();
		try {
			$feed->outHeader();
			foreach ( $items as $item ) {
				$feed->outItem( $item );
			}
			$feed->outFooter();
			return ob_get_contents();
		} finally {
			ob_end_clean();
		}
	}

	/**
	 * The HTML of the page, without table of contents and section edit links.
	 */
	private function getHtml( WikiPage $page ): string {
		$parserOptions = ParserOptions::newFromAnon();
		$parserOutput = $page->getParserOutput( $parserOptions );
		if ( !$parserOutput ) {
			return '';
		}
		return $parserOutput->runOutputPipeline( $parserOptions, [
			'allowTOC' => false,
			'enableSectionEditLinks' => false,
			'unwrap' => true,
		] )->getContentHolderText();
	}

	/**
	 * A regular expression that matches headings of one level.  Depending
	 * on the form of the heading, it captures the fragment and HTML of the
	 * heading as "legacyId" and "legacyTitle", or as "id" and "title".
	 */
	private static function getHeadingRegex( string $level ): string {
		$fallbackAnchor = '(?:<span id="[^"]*"></span>)?';
		return "#<h$level>$fallbackAnchor" .
				'<span class="mw-headline" id="(?<legacyId>[^"]*)">(?<legacyTitle>.*?)</span>\s*' .
				"</h$level>" .
			'|' .
			"(?<wrapper><div class=\"mw-heading mw-heading$level\">)?" .
				"<h$level id=\"(?<id>[^\"]*)\">$fallbackAnchor(?<title>.*?)</h$level>" .
				'(?(wrapper)</div>)' .
			'#s';
	}

	/**
	 * @param string $titleHtml HTML of the heading of the item
	 * @param string $anchor id of the heading of the item
	 * @param string $html HTML of the item
	 * @param string $pageUrl URL of the page
	 * @param string[] $filterTags
	 * @param string $fallbackDate
	 * @param string $fallbackAuthor
	 * @return FeedItem|null null if the item doesn't have one of the tags to filter by
	 */
	private function makeItem(
		string $titleHtml,
		string $anchor,
		string $html,
		string $pageUrl,
		array $filterTags,
		string $fallbackDate,
		string $fallbackAuthor
	): ?FeedItem {
		if ( $filterTags ) {
			preg_match_all( self::ITEM_TAGS_REGEX, $html, $encodedTags );
			$tags = self::splitTags( implode( ',', array_map( 'base64_decode', $encodedTags[1] ) ) );
			if ( !array_intersect( $tags, $filterTags ) ) {
				return null;
			}
		}
		$html = preg_replace( self::ITEM_TAGS_REGEX, '', $html );

		# The first signature gives the author and date
		$author = $fallbackAuthor;
		$date = $fallbackDate;
		foreach ( self::SIGNATURE_REGEXES as $signatureRegex ) {
			if ( preg_match( $signatureRegex, $html, $signature ) ) {
				$signatureDate = self::parseSignatureDate( $signature[2] );
				if ( $signatureDate ) {
					$author = Sanitizer::stripAllTags( $signature[1] );
					$date = $signatureDate;
				}
				break;
			}
		}

		# The first link that isn't in a signature is the link of the item
		$url = $pageUrl . '#' . Sanitizer::escapeIdForLink( $anchor );
		if ( !$this->forceSectionLinks &&
			preg_match( self::LINK_REGEX, preg_replace( self::DEFAULT_SIGNATURE_REGEX, '', $html ), $link )
		) {
			$url = $this->expandUrl( Sanitizer::decodeCharReferences( $link[2] ), $pageUrl );
		}

		# Feed readers need complete URLs
		$html = preg_replace_callback(
			self::LOCAL_URL_REGEX,
			fn ( array $m ) => $m[1] . '=' . $m[2] .
				htmlspecialchars( $this->expandUrl( Sanitizer::decodeCharReferences( $m[3] ), $pageUrl ), ENT_QUOTES ) .
				$m[2],
			$html
		);

		return new FeedItem( Sanitizer::stripAllTags( $titleHtml ), $html, $url, $date, $author );
	}

	/**
	 * @param string $date the time of a signature, such as "01:23, 4 May 2006 (UTC)"
	 * @return string|null TS_MW timestamp, or null if the time is not understood
	 */
	private static function parseSignatureDate( string $date ): ?string {
		$utc = new DateTimeZone( 'UTC' );
		try {
			return ( new DateTimeImmutable( $date, $utc ) )->setTimezone( $utc )->format( 'YmdHis' );
		} catch ( Exception ) {
			return null;
		}
	}

	/**
	 * @param string $url
	 * @param string $pageUrl URL of the page, for links to its sections
	 * @return string complete URL
	 */
	private function expandUrl( string $url, string $pageUrl ): string {
		return match ( $url[0] ?? '' ) {
			'/' => $this->urlUtils->expand( $url, PROTO_CANONICAL ) ?? $url,
			'#' => $pageUrl . $url,
			default => $url,
		};
	}
}
