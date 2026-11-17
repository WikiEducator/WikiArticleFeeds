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

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\SidebarBeforeOutputHook;
use MediaWiki\Linker\Hook\HtmlPageLinkRendererEndHook;
use MediaWiki\Output\Hook\OutputPageParserOutputHook;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;

/**
 * Parser tags and functions that mark up feeds in wiki pages, and the
 * feed links of pages that contain a feed.
 */
class Hooks implements
	ParserFirstCallInitHook,
	OutputPageParserOutputHook,
	SidebarBeforeOutputHook,
	HtmlPageLinkRendererEndHook
{
	/** ParserOutput extension data key and OutputPage property: the page has a feed */
	private const HAS_FEED = 'wikiarticlefeeds';

	/** Feed formats offered as links, with their names and MIME types */
	private const FORMATS = [
		'rss' => [ 'RSS', 'RSS 2.0', 'application/rss+xml' ],
		'atom' => [ 'Atom', 'Atom', 'application/atom+xml' ],
	];

	/** @inheritDoc */
	public function onParserFirstCallInit( $parser ) {
		$parser->setHook( 'startFeed', [ $this, 'startFeed' ] );
		$parser->setHook( 'endFeed', [ $this, 'endFeed' ] );
		$parser->setHook( 'feedBurner', [ $this, 'feedBurner' ] );
		$parser->setHook( 'itemTags', [ $this, 'itemTagsTag' ] );
		$parser->setFunctionHook( 'itemtags', [ $this, 'itemTagsFunction' ] );
	}

	/**
	 * <startFeed />
	 *
	 * @param string|null $text
	 * @param string[] $params
	 * @param Parser $parser
	 * @return string HTML
	 */
	public function startFeed( ?string $text, array $params, Parser $parser ): string {
		$parser->addTrackingCategory( 'wikiarticlefeeds-tracking-category' );
		$parser->getOutput()->setExtensionData( self::HAS_FEED, true );
		return FeedGenerator::FEED_START;
	}

	/**
	 * <endFeed />
	 *
	 * @return string HTML
	 */
	public function endFeed(): string {
		return FeedGenerator::FEED_END;
	}

	/**
	 * <feedBurner> is no longer supported (FeedBurner is defunct);
	 * the tag is still accepted so old pages don't show it as text.
	 *
	 * @return string HTML
	 */
	public function feedBurner(): string {
		return '';
	}

	/**
	 * <itemTags>tag, tag</itemTags>
	 *
	 * @param string|null $text
	 * @return string HTML
	 */
	public function itemTagsTag( ?string $text ): string {
		return FeedGenerator::encodeItemTags( $text ?? '' );
	}

	/**
	 * {{#itemTags:tag, tag|tag}}
	 *
	 * @param Parser $parser
	 * @param string ...$tags
	 * @return array
	 */
	public function itemTagsFunction( Parser $parser, string ...$tags ): array {
		return [ FeedGenerator::encodeItemTags( implode( ',', $tags ) ), 'isHTML' => true ];
	}

	/**
	 * Adds the feed autodiscovery links to pages that contain a feed.
	 *
	 * @inheritDoc
	 */
	public function onOutputPageParserOutput( $outputPage, $parserOutput ): void {
		$title = $outputPage->getTitle();
		if ( !$parserOutput->getExtensionData( self::HAS_FEED ) || !$title || !$title->canExist() ) {
			return;
		}

		foreach ( self::FORMATS as $format => [ , $name, $mimeType ] ) {
			$outputPage->addLink( [
				'rel' => 'alternate',
				'type' => $mimeType,
				'title' => $title->getText() . ' - ' . $name,
				'href' => self::getFeedUrl( $title, $format ),
			] );
		}

		# Remembered for onSidebarBeforeOutput()
		$outputPage->setProperty( self::HAS_FEED, true );
	}

	/**
	 * Adds RSS and Atom links to the sidebar toolbox of pages that contain a feed.
	 *
	 * @inheritDoc
	 */
	public function onSidebarBeforeOutput( $skin, &$sidebar ): void {
		$title = $skin->getTitle();
		if ( !$title || !$skin->getOutput()->getProperty( self::HAS_FEED ) ) {
			return;
		}

		foreach ( self::FORMATS as $format => [ $name ] ) {
			$sidebar['TOOLBOX']['wikiarticlefeeds-' . $format] = [
				'text' => $name,
				'href' => self::getFeedUrl( $title, $format ),
				'id' => 'feed-' . $format,
				'class' => 'feedlink',
			];
		}
	}

	/**
	 * Marks links to user and user talk pages, on all pages.  FeedGenerator
	 * uses the marks to find signatures, which give feed items their author
	 * and date.
	 *
	 * @inheritDoc
	 */
	public function onHtmlPageLinkRendererEnd(
		$linkRenderer, $target, $isKnown, &$text, &$attribs, &$ret
	) {
		if ( $target->getNamespace() === NS_USER ) {
			$attribs['data-userpage-link'] = 'true';
		} elseif ( $target->getNamespace() === NS_USER_TALK ) {
			$attribs['data-usertalkpage-link'] = 'true';
		}
	}

	private static function getFeedUrl( Title $title, string $format ): string {
		return $title->getFullURL( [ 'action' => 'feed', 'feed' => $format ] );
	}
}
