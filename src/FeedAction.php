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

use Article;
use FormlessAction;
use HttpError;
use MediaWiki\Context\IContextSource;
use MediaWiki\Feed\ChannelFeed;
use MediaWiki\MainConfigNames;
use MediaWiki\Page\RedirectLookup;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Utils\UrlUtils;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * action=feed: the RSS or Atom feed of a wiki page.
 *
 * Query parameters:
 *  - feed: 'rss' or 'atom' (default)
 *  - tags: comma separated item tags; only items with one of them are included
 */
class FeedAction extends FormlessAction {

	public function __construct(
		Article $article,
		IContextSource $context,
		private readonly WANObjectCache $cache,
		private readonly RedirectLookup $redirectLookup,
		private readonly RevisionLookup $revisionLookup,
		private readonly UrlUtils $urlUtils,
		private readonly WikiPageFactory $wikiPageFactory,
	) {
		parent::__construct( $article, $context );
	}

	/** @inheritDoc */
	public function getName() {
		return 'feed';
	}

	/** @inheritDoc */
	public function onView() {
		$config = $this->getContext()->getConfig();
		$request = $this->getRequest();

		$format = $request->getRawVal( 'feed' ) ?? 'atom';
		$feedClass = $config->get( MainConfigNames::FeedClasses )[$format] ?? null;
		if ( !$feedClass ) {
			throw new HttpError( 400, 'Unsupported feed type.' );
		}

		$filterTags = FeedGenerator::splitTags( $request->getText( 'tags' ) );
		sort( $filterTags );

		# Feeds follow redirects
		$page = $this->getWikiPage();
		$redirectTarget = $this->redirectLookup->getRedirectTarget( $page );
		if ( $redirectTarget && !$redirectTarget->isExternal() ) {
			$page = $this->wikiPageFactory->newFromLinkTarget( $redirectTarget );
		}

		$generator = new FeedGenerator(
			$this->revisionLookup,
			$this->urlUtils,
			$this->getContext(),
			(bool)$config->get( 'ForceArticleFeedSectionLinks' )
		);

		# A feed changes when its page is edited or purged, or when a page
		# it transcludes is edited.  The time of the last change is part of
		# the cache key, so stale feeds are never served and simply expire.
		$latestEdit = $generator->getLatestEdit( $page );
		if ( !$latestEdit ) {
			throw new HttpError( 404, 'There is no such page.' );
		}
		$lastModified = max( $page->getTouched(), $latestEdit->getTimestamp() );
		$generate = static fn () => $generator->generate( $page, $feedClass, $filterTags, $latestEdit );

		if ( $config->get( 'WikiArticleFeedsSkipCache' ) ) {
			$xml = $generate();
		} else {
			$xml = $this->cache->getWithSetCallback(
				$this->cache->makeKey(
					'wikiarticlefeeds',
					$page->getId(),
					$format,
					md5( implode( ',', $filterTags ) ),
					$lastModified
				),
				WANObjectCache::TTL_DAY,
				$generate
			);
		}

		# Takes over from OutputPage, like the feeds of MediaWiki itself
		/** @var ChannelFeed $feed */
		$feed = new $feedClass( '', '', '' );
		$feed->httpHeaders();
		print $xml;

		return null;
	}
}
