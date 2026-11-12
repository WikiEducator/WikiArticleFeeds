# WikiArticleFeeds

A MediaWiki extension that publishes RSS or Atom feeds generated from
ordinary wiki pages.

Requires MediaWiki 1.35 or later.

## Installation

```php
wfLoadExtension( 'WikiArticleFeeds' );
```

## Usage

The feed of a page is available through the `feed` action:

    index.php?title=Some_Article&action=feed&feed=atom

`feed` may be `rss` or `atom` (the default).  Pages with feeds also get
`<link rel="alternate">` tags for feed autodiscovery and RSS/Atom links
in the sidebar toolbox.

### Creating a feed

Mark the part of a page that contains feed items with `<startFeed />` and
`<endFeed />`.  Each section heading of the highest level inside that part
starts a new item.  An item's author and date are taken from a signature
in the item, or else from the most recent edit of the page or of a page it
transcludes.

### Tagging feed items

Put one of these anywhere in an item:

    <itemTags>dogs, cats</itemTags>
    {{#itemTags:dogs, cats}}
    {{#itemTags:dogs|cats}}

and request a filtered feed with `&tags=dogs` (or several tags,
comma separated).

### Configuration

* `$wgForceArticleFeedSectionLinks` — link items to their page section
  instead of the first link in the item.
* `$wgWikiArticleFeedsSkipCache` — always regenerate feeds (for debugging).
* `$wgFeedCacheTimeout` (core) — minimum age before a cached feed is
  checked against page edits.

## History

* 2.0.0  MediaWiki 1.35: sidebar links via SidebarBeforeOutput, safe
         decoding of item tags, tag trimming fixed, `<feedBurner>`
         ignored (FeedBurner is defunct), legacy loader removed.
* 1.0.0  extension.json, feed action, ResourceLoader.
* 0.74   Slight code cleanup.
* 0.73   LinkEnd hook attributes prefixed with `data-`.
* 0.71   Tracking category parameter removed.
* 0.703  Feed icon at the bottom of the MonoBook toolbox.
* 0.700  Rewritten as a class; autodiscovery links include the page title.
* 0.6    Item tags and tag filtering; `$wgWikiArticleFeedsSkipCache`.
* 0.5    FeedBurner support.
* 0.4    `$wgForceArticleFeedSectionLinks`; feeds follow redirects.
* 0.1    Initial release.

## Authors

Jim R. Wilson (2007), Thomas Gries (2012), Jim Tittsler (2026).
MIT License — see COPYING.
