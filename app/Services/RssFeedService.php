<?php

namespace App\Services;

use App\Models\RssFeedModel;
use App\Models\RssArticleModel;

/**
 * Fetches and stores articles from a single RSS/Atom feed.
 *
 * Supports RSS 2.0 and Atom 1.0, including YouTube channel feeds.
 *
 * Image extraction order:
 *   1. <enclosure type="image/...">
 *   2. <media:content> / <media:thumbnail> (prefix or URI)
 *   3. First <img src="..."> in content:encoded or description HTML
 *
 * Video extraction:
 *   1. <yt:videoId> (YouTube channel feeds)
 *   2. <enclosure type="video/...">
 *   3. <media:content type="video/..." medium="video"> or YouTube URL
 *   4. YouTube iframe/link found in HTML content
 *
 * Full content: uses <content:encoded> when present; otherwise scrapes the
 * article URL to extract the main body text.
 */
class RssFeedService
{
    private const DESC_MAX_LEN    = 500;
    private const CONTENT_MAX_LEN = 100000;

    // ── Public API ────────────────────────────────────────────────────────────

    public function fetchAndStore(int $feedId): array
    {
        $feedModel    = new RssFeedModel();
        $articleModel = new RssArticleModel();

        $feed = $feedModel->find($feedId);
        if (! $feed) {
            return ['inserted' => 0, 'skipped' => 0, 'error' => "Feed #{$feedId} not found"];
        }

        $xml = $this->fetchUrl((string) $feed['url']);
        if ($xml === null) {
            return ['inserted' => 0, 'skipped' => 0, 'error' => "Failed to fetch URL: {$feed['url']}"];
        }

        try {
            $parsed = $this->parseXml($xml);
        } catch (\Throwable $e) {
            log_message('error', "[RssFeedService] XML parse error for feed #{$feedId}: " . $e->getMessage());
            return ['inserted' => 0, 'skipped' => 0, 'error' => 'XML parse error: ' . $e->getMessage()];
        }

        $inserted = 0;
        $skipped  = 0;
        $now      = date('Y-m-d H:i:s');

        foreach ($parsed as $item) {
            $guid = $item['guid'] ?: $item['url'];
            if (empty($guid)) { $skipped++; continue; }

            if ($articleModel->existsForFeed($feedId, $guid)) { $skipped++; continue; }

            $content  = $item['content'];
            $imageUrl = $item['image_url'];

            // Scrape article page when content is short OR image is missing (single fetch for both)
            if (
                ! empty($item['url']) && (
                    $imageUrl === null ||
                    $content === null  ||
                    mb_strlen($content) < 350
                )
            ) {
                $scraped = $this->scrapeArticle($item['url']);
                if ($scraped['content'] !== null && mb_strlen($scraped['content']) > mb_strlen($content ?? '')) {
                    $content = $scraped['content'];
                }
                if ($imageUrl === null) {
                    $imageUrl = $scraped['image'];
                }
            }

            $articleModel->insert([
                'feed_id'      => $feedId,
                'guid'         => $guid,
                'title'        => $item['title'],
                'description'  => $item['description'],
                'content'      => $content,
                'url'          => $item['url'],
                'image_url'    => $imageUrl,
                'video_url'    => $item['video_url'],
                'published_at' => $item['published_at'],
                'created_at'   => $now,
            ]);

            $inserted++;
        }

        $feedModel->update($feedId, ['last_fetched_at' => $now]);

        return ['inserted' => $inserted, 'skipped' => $skipped, 'error' => null];
    }

    // ── Private: HTTP ─────────────────────────────────────────────────────────

    private function fetchUrl(string $url, int $timeout = 20, string $userAgent = ''): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => $userAgent ?: 'Dimensions-RSSBot/1.0 (+https://dimensions.app)',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'],
        ]);

        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) {
            log_message('error', "[RssFeedService] cURL error for {$url}: {$err}");
            return null;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            log_message('error', "[RssFeedService] HTTP {$httpCode} for {$url}");
            return null;
        }

        return $body ?: null;
    }

    // ── Private: XML parsing ──────────────────────────────────────────────────

    private function parseXml(string $xml): array
    {
        libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($sx === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $msg = ! empty($errors) ? $errors[0]->message : 'Unknown XML error';
            throw new \RuntimeException(trim($msg));
        }
        libxml_clear_errors();

        $rootName = strtolower($sx->getName());
        if ($rootName === 'feed') return $this->parseAtom($sx);
        if ($rootName === 'rss' || $rootName === 'rdf') return $this->parseRss($sx);
        if (isset($sx->channel)) return $this->parseRss($sx);

        throw new \RuntimeException("Unrecognised feed format: <{$rootName}>");
    }

    private function parseRss(\SimpleXMLElement $sx): array
    {
        $items   = [];
        $channel = $sx->channel ?? $sx;

        foreach ($channel->item as $item) {
            $media = $item->children('media', true);
            if (! isset($media->content) && ! isset($media->thumbnail)) {
                $media = $item->children('http://search.yahoo.com/mrss/');
            }
            $content  = $item->children('content', true);
            $imageUrl = null;

            // Image: 1. enclosure  2. media:content/thumbnail  3. <img> in HTML
            if (isset($item->enclosure)) {
                $type = (string) $item->enclosure['type'];
                if (str_starts_with($type, 'image/')) {
                    $imageUrl = (string) $item->enclosure['url'];
                }
            }
            if ($imageUrl === null && isset($media->content)) {
                $candidate = (string) $media->content['url'];
                $mType     = (string) $media->content['type'];
                $medium    = (string) $media->content['medium'];
                if (! str_starts_with($mType, 'video/') && $medium !== 'video') {
                    $imageUrl = $candidate;
                }
            }
            if ($imageUrl === null && isset($media->thumbnail)) {
                $imageUrl = (string) $media->thumbnail['url'];
            }

            $rawContent  = (string) ($content->encoded ?? '');
            $description = (string) ($item->description ?? '');

            if ($imageUrl === null) {
                $imageUrl = $this->extractFirstImage($rawContent ?: $description);
            }

            $title     = trim((string) ($item->title ?? ''));
            $link      = trim((string) ($item->link ?? ''));
            $guid      = trim((string) ($item->guid ?? ''));
            $pubDate   = $this->parseDate((string) ($item->pubDate ?? ''));
            $videoUrl  = $this->extractVideoUrl($media, $item->enclosure ?? null, $rawContent ?: $description);

            $items[] = [
                'title'        => $title,
                'description'  => $this->cleanDescription($description),
                'content'      => $this->cleanContent($rawContent ?: $description),
                'url'          => $link,
                'guid'         => $guid ?: $link,
                'image_url'    => $imageUrl ? $this->sanitiseImageUrl($imageUrl) : null,
                'video_url'    => $videoUrl,
                'published_at' => $pubDate,
            ];
        }

        return $items;
    }

    private function parseAtom(\SimpleXMLElement $sx): array
    {
        $items = [];

        foreach ($sx->entry as $entry) {
            $media = $entry->children('media', true);
            if (! isset($media->thumbnail) && ! isset($media->content)) {
                $media = $entry->children('http://search.yahoo.com/mrss/');
            }
            // YouTube namespace
            $yt = $entry->children('http://www.youtube.com/xml/schemas/2015');

            $imageUrl = null;
            if (isset($media->thumbnail)) {
                $imageUrl = (string) $media->thumbnail['url'];
            } elseif (isset($media->content)) {
                $mType  = (string) $media->content['type'];
                $medium = (string) $media->content['medium'];
                if (! str_starts_with($mType, 'video/') && $medium !== 'video') {
                    $imageUrl = (string) $media->content['url'];
                }
            }

            $title      = trim((string) ($entry->title ?? ''));
            $rawSummary = (string) ($entry->summary ?? '');
            $rawContent = (string) ($entry->content ?? $entry->summary ?? '');
            $guid       = trim((string) ($entry->id ?? ''));
            $published  = $this->parseDate((string) ($entry->published ?? $entry->updated ?? ''));
            $link       = '';

            foreach ($entry->link as $linkEl) {
                $rel = strtolower((string) ($linkEl['rel'] ?? 'alternate'));
                if ($rel === 'alternate' || $rel === '') { $link = (string) $linkEl['href']; break; }
                if ($link === '') $link = (string) $linkEl['href'];
            }

            if ($imageUrl === null) {
                $imageUrl = $this->extractFirstImage($rawContent ?: $rawSummary);
            }

            $videoUrl = $this->extractVideoUrl($media, null, $rawContent ?: $rawSummary, $yt);

            // For YouTube feeds, use the YouTube thumbnail as image when no other image found
            if ($imageUrl === null && $videoUrl !== null) {
                $ytId = $this->youtubeIdFromUrl($videoUrl);
                if ($ytId !== null) {
                    $imageUrl = "https://img.youtube.com/vi/{$ytId}/hqdefault.jpg";
                }
            }

            $items[] = [
                'title'        => $title,
                'description'  => $this->cleanDescription($rawSummary ?: $rawContent),
                'content'      => $this->cleanContent($rawContent),
                'url'          => $link,
                'guid'         => $guid ?: $link,
                'image_url'    => $imageUrl ? $this->sanitiseImageUrl($imageUrl) : null,
                'video_url'    => $videoUrl,
                'published_at' => $published,
            ];
        }

        return $items;
    }

    // ── Private: video extraction ─────────────────────────────────────────────

    /**
     * Extracts a canonical video URL from the item's available sources.
     */
    private function extractVideoUrl(
        \SimpleXMLElement $media,
        mixed $enclosure,
        string $htmlContent,
        ?\SimpleXMLElement $yt = null
    ): ?string {
        // 1. YouTube namespace <yt:videoId> (YouTube channel RSS feeds)
        if ($yt !== null && isset($yt->videoId)) {
            $id = trim((string) $yt->videoId);
            if ($id !== '') return "https://www.youtube.com/watch?v={$id}";
        }

        // 2. <enclosure type="video/...">
        if ($enclosure !== null && isset($enclosure['type'])) {
            $type = (string) $enclosure['type'];
            $url  = trim((string) ($enclosure['url'] ?? ''));
            if (str_starts_with($type, 'video/') && $url !== '') {
                return $url;
            }
        }

        // 3. <media:content type="video/..." medium="video"> or YouTube URL
        if (isset($media->content)) {
            $mUrl    = trim((string) $media->content['url']);
            $mType   = (string) $media->content['type'];
            $mMedium = (string) $media->content['medium'];
            if (
                $mUrl !== '' && (
                    str_starts_with($mType, 'video/') ||
                    $mMedium === 'video' ||
                    str_contains($mUrl, 'youtube.com') ||
                    str_contains($mUrl, 'youtu.be')
                )
            ) {
                return $this->normaliseVideoUrl($mUrl);
            }
        }

        // 4. YouTube embed iframe in HTML: <iframe src="...youtube.com/embed/ID...">
        if (preg_match('/youtube(?:-nocookie)?\.com\/embed\/([a-zA-Z0-9_-]{11})/i', $htmlContent, $m)) {
            return "https://www.youtube.com/watch?v={$m[1]}";
        }

        // 5. YouTube watch URL in HTML
        if (preg_match('/youtube\.com\/watch\?[^"\'<\s]*v=([a-zA-Z0-9_-]{11})/i', $htmlContent, $m)) {
            return "https://www.youtube.com/watch?v={$m[1]}";
        }

        // 6. youtu.be short link in HTML
        if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]{11})/i', $htmlContent, $m)) {
            return "https://www.youtube.com/watch?v={$m[1]}";
        }

        return null;
    }

    /** Converts youtube.com/v/ID or /embed/ID to the standard watch URL. */
    private function normaliseVideoUrl(string $url): string
    {
        if (preg_match('/youtube\.com\/(?:v|embed)\/([a-zA-Z0-9_-]{11})/i', $url, $m)) {
            return "https://www.youtube.com/watch?v={$m[1]}";
        }
        return $url;
    }

    /** Extracts the YouTube video ID from a watch URL, or null if not a YouTube URL. */
    private function youtubeIdFromUrl(string $url): ?string
    {
        if (preg_match('/youtube\.com\/watch\?.*v=([a-zA-Z0-9_-]{11})/i', $url, $m)) return $m[1];
        if (preg_match('/youtu\.be\/([a-zA-Z0-9_-]{11})/i', $url, $m)) return $m[1];
        return null;
    }

    // ── Private: article scraping ─────────────────────────────────────────────

    /**
     * Fetches the article URL once and extracts both body content and an image.
     * Image priority: og:image → twitter:image → first usable <img> in body.
     */
    private function scrapeArticle(string $url): array
    {
        $result = ['content' => null, 'image' => null];
        $html   = $this->fetchUrl($url, 15,
            'Mozilla/5.0 (compatible; Dimensions/1.0; +https://dimensions.app)');
        if ($html === null) return $result;

        $result['image'] = $this->extractOgImage($html) ?? $this->extractAnyHtmlImage($html);
        $result['content'] = $this->extractBodyContent($html);
        return $result;
    }

    /**
     * Scans the page HTML for any usable <img src>, skipping trackers/icons.
     */
    private function extractAnyHtmlImage(string $html): ?string
    {
        if (! preg_match_all('/<img[^>]+src=["\']([^"\']{10,})["\'][^>]*/is', $html, $m)) {
            return null;
        }
        $skipPatterns = ['pixel', 'tracking', 'beacon', 'spacer', '1x1', 'transparent',
                         'blank', 'icon', 'logo', 'avatar', 'spinner', 'loading', 'placeholder'];
        foreach ($m[1] as $src) {
            $src = html_entity_decode($src, ENT_QUOTES, 'UTF-8');
            $url = $this->sanitiseImageUrl($src);
            if ($url === null) continue;
            if (str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.svg')) continue;
            $lower = strtolower($url);
            $skip  = false;
            foreach ($skipPatterns as $p) {
                if (str_contains($lower, $p)) { $skip = true; break; }
            }
            if ($skip) continue;
            return $url;
        }
        return null;
    }

    /** Extracts Open Graph / Twitter Card image from raw HTML. */
    private function extractOgImage(string $html): ?string
    {
        $patterns = [
            '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*/is',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*/is',
            '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*/is',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\'][^>]*/is',
        ];
        foreach ($patterns as $pat) {
            if (preg_match($pat, $html, $m)) {
                $url = $this->sanitiseImageUrl(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                if ($url !== null) return $url;
            }
        }
        return null;
    }

    private function extractBodyContent(string $html): ?string
    {

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        foreach (['script','style','nav','header','footer','aside','form',
                  'noscript','iframe','button','input','select','advertisement'] as $tag) {
            while (($nodes = $doc->getElementsByTagName($tag)) && $nodes->length > 0) {
                $nodes->item(0)->parentNode?->removeChild($nodes->item(0));
            }
        }

        $xpath     = new \DOMXPath($doc);
        $selectors = [
            '//article',
            '//*[@itemprop="articleBody"]',
            '//*[contains(@class,"article-body")]',
            '//*[contains(@class,"article-content")]',
            '//*[contains(@class,"article__body")]',
            '//*[contains(@class,"entry-content")]',
            '//*[contains(@class,"post-content")]',
            '//*[contains(@class,"story-body")]',
            '//*[contains(@class,"content-body")]',
            '//*[contains(@class,"post-body")]',
            '//*[@role="main"]',
            '//main',
        ];

        $best = '';
        foreach ($selectors as $sel) {
            $nodes = @$xpath->query($sel);
            if (! $nodes || $nodes->length === 0) continue;
            foreach ($nodes as $node) {
                $text = $this->cleanContent(@$doc->saveHTML($node)) ?? '';
                if (mb_strlen($text) > mb_strlen($best)) $best = $text;
            }
            if (mb_strlen($best) > 600) break;
        }

        if (mb_strlen($best) < 300) {
            $parts = [];
            foreach ($doc->getElementsByTagName('p') as $p) {
                $t = trim($p->textContent ?? '');
                if (mb_strlen($t) > 40) $parts[] = $t;
            }
            if (! empty($parts)) {
                $candidate = $this->cleanContent(implode("\n\n", $parts)) ?? '';
                if (mb_strlen($candidate) > mb_strlen($best)) $best = $candidate;
            }
        }

        return $best !== '' ? $best : null;
    }

    // ── Private: content cleaning ─────────────────────────────────────────────

    private function cleanContent(string $raw): ?string
    {
        if ($raw === '') return null;

        $text = preg_replace('/<\/(p|div|br|li|h[1-6]|blockquote)[^>]*>/i', "\n", $raw);
        $text = strip_tags($text ?? '');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/(\n\s*){3,}/', "\n\n", $text ?? '');
        $text = trim($text ?? '');

        if ($text === '') return null;

        return mb_strlen($text) > self::CONTENT_MAX_LEN
            ? mb_substr($text, 0, self::CONTENT_MAX_LEN)
            : $text;
    }

    private function cleanDescription(string $raw): ?string
    {
        if ($raw === '') return null;

        $stripped = strip_tags($raw);
        $stripped = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = preg_replace('/\s+/', ' ', $stripped);
        $stripped = trim($stripped);

        if ($stripped === '') return null;

        return mb_strlen($stripped) > self::DESC_MAX_LEN
            ? mb_substr($stripped, 0, self::DESC_MAX_LEN)
            : $stripped;
    }

    private function extractFirstImage(string $html): ?string
    {
        if ($html === '') return null;
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*/is', $html, $m)) {
            return $this->sanitiseImageUrl($m[1]);
        }
        return null;
    }

    private function sanitiseImageUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:') || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        return $url;
    }

    private function parseDate(string $dateStr): ?string
    {
        $dateStr = trim($dateStr);
        if ($dateStr === '') return null;
        try {
            return (new \DateTime($dateStr))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
