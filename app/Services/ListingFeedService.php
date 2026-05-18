<?php

namespace App\Services;

/**
 * Fetches an RSS/Atom feed and imports items as listings.
 * Used by both the admin panel and the cron endpoint.
 */
class ListingFeedService
{
    public function importFeed(array $feed): array
    {
        $url        = (string) $feed['url'];
        $categoryId = (int)    $feed['category_id'];
        $trustLevel = (string) ($feed['trust_level']   ?? 'community_submitted');
        $status     = (string) ($feed['import_status'] ?? 'pending');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Dimensions-Bot/1.0 (+https://dimensions.app)',
        ]);
        $xml      = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($xml === false || $curlErr || $httpCode < 200 || $httpCode >= 300) {
            return ['count' => 0, 'error' => "HTTP {$httpCode}: {$curlErr}"];
        }

        $xml = ltrim($xml, "\xEF\xBB\xBF \t\n\r");

        libxml_use_internal_errors(true);
        $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();

        if ($sx === false) {
            return ['count' => 0, 'error' => 'Invalid RSS/XML format'];
        }

        $db       = db_connect();
        $now      = date('Y-m-d H:i:s');
        $count    = 0;
        $isAtom   = (strtolower($sx->getName()) === 'feed');
        $channel  = ! $isAtom ? ($sx->channel ?? null) : null;
        $items    = $isAtom
            ? ($sx->entry ?? [])
            : ($channel !== null ? ($channel->item ?? []) : ($sx->item ?? []));
        $isActive = ($status === 'approved') ? 1 : 0;
        $label    = ucwords(str_replace('_', ' ', $trustLevel));

        foreach ($items as $item) {
            $title = mb_substr(trim((string) ($item->title ?? '')), 0, 255);
            if ($title === '') continue;

            if ($isAtom) {
                $link = '';
                foreach ($item->link as $l) {
                    $rel = (string) ($l['rel'] ?? 'alternate');
                    if ($rel === 'alternate' || $rel === '') { $link = (string) $l['href']; break; }
                    if ($link === '') $link = (string) $l['href'];
                }
                $rawDesc = (string) ($item->summary ?? $item->content ?? '');
            } else {
                $link    = trim((string) ($item->link ?? ''));
                $rawDesc = (string) ($item->description ?? '');
            }

            $desc = mb_substr(trim(strip_tags($rawDesc)), 0, 500);
            if ($desc === '') $desc = $title;

            if ($link !== '' && $db->table('listings')->where('external_url', $link)->countAllResults()) {
                continue;
            }

            $imageUrl = $this->extractImage($item, $rawDesc, $link);

            $db->table('listings')->insert([
                'title'        => $title,
                'description'  => $desc,
                'category_id'  => $categoryId,
                'org_name'     => '',
                'location'     => '',
                'date'         => null,
                'deadline'     => null,
                'action_type'  => 'external',
                'external_url' => $link,
                'trust_level'  => $trustLevel,
                'trust_label'  => $label,
                'cover_url'    => $imageUrl,
                'status'       => $status,
                'is_active'    => $isActive,
                'submitted_by' => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
            $count++;
        }

        return ['count' => $count, 'error' => null];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function extractImage(\SimpleXMLElement $item, string $rawDesc, string $link): ?string
    {
        // 1. media:thumbnail / media:content
        $media = $item->children('media', true);
        if (! isset($media->thumbnail) && ! isset($media->content)) {
            $media = $item->children('http://search.yahoo.com/mrss/');
        }
        if (isset($media->thumbnail)) {
            $u = $this->validUrl((string) $media->thumbnail['url']);
            if ($u) return $u;
        }
        if (isset($media->content)) {
            $mType = (string) $media->content['type'];
            if (! str_starts_with($mType, 'video/')) {
                $u = $this->validUrl((string) $media->content['url']);
                if ($u) return $u;
            }
        }

        // 2. <enclosure type="image/...">
        if (isset($item->enclosure) && str_starts_with((string) $item->enclosure['type'], 'image/')) {
            $u = $this->validUrl((string) $item->enclosure['url']);
            if ($u) return $u;
        }

        // 3. First <img> in RSS description/content HTML
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*/is', $rawDesc, $m)) {
            $u = $this->validUrl($m[1]);
            if ($u) return $u;
        }

        // 4. Scrape article page for OG image or any usable <img>
        if ($link !== '') {
            return $this->scrapePageImage($link);
        }

        return null;
    }

    private function scrapePageImage(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; Dimensions/1.0)',
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
        if (! $html) return null;

        // OG image (both attribute orderings)
        foreach ([
            '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*/is',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*/is',
            '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*/is',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\'][^>]*/is',
        ] as $pat) {
            if (preg_match($pat, $html, $m)) {
                $u = $this->validUrl(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                if ($u) return $u;
            }
        }

        // Any usable <img> in the page
        if (preg_match_all('/<img[^>]+src=["\']([^"\']{10,})["\'][^>]*/is', $html, $ms)) {
            $skip = ['pixel','tracking','beacon','spacer','1x1','transparent','blank','icon','logo','avatar','spinner','loading'];
            foreach ($ms[1] as $src) {
                $u = $this->validUrl(html_entity_decode($src, ENT_QUOTES, 'UTF-8'));
                if (! $u) continue;
                if (str_ends_with(strtolower(parse_url($u, PHP_URL_PATH) ?? ''), '.svg')) continue;
                $lower = strtolower($u);
                $bad   = false;
                foreach ($skip as $s) { if (str_contains($lower, $s)) { $bad = true; break; } }
                if (! $bad) return $u;
            }
        }

        return null;
    }

    private function validUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
        return ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) ? $url : null;
    }
}
