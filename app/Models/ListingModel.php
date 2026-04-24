<?php

namespace App\Models;

use CodeIgniter\Model;

class ListingModel extends Model
{
    protected $table          = 'listings';
    protected $primaryKey     = 'id';
    protected $returnType     = 'array';
    protected $useSoftDeletes = false;
    protected $useTimestamps  = true;
    protected $createdField   = 'created_at';
    protected $updatedField   = 'updated_at';

    protected $allowedFields = [
        'title', 'description', 'category_id', 'org_id', 'org_name',
        'trust_level', 'trust_label', 'location', 'lat', 'lng',
        'date', 'deadline', 'action_type', 'is_live', 'external_url',
        'cover_url', 'tags', 'submitted_by', 'status', 'is_active',
        'like_count', 'comment_count', 'rsvp_count',
    ];

    // ── Core query: listing row + per-user meta (single JOIN, no N+1) ──────────

    /**
     * Returns the base SELECT with per-user isSaved / isRsvped / isLiked flags
     * and aggregate counts — all in a single query.
     */
    public function withMeta(int $userId): static
    {
        return $this
            ->select("
                listings.*,
                c.name      AS category_name,
                c.slug      AS category_slug,
                c.icon_name AS category_icon,
                COALESCE(ls_agg.save_count,  0)                   AS save_count,
                CASE WHEN user_save.id  IS NOT NULL THEN 1 ELSE 0 END AS is_saved,
                CASE WHEN user_rsvp.id  IS NOT NULL THEN 1 ELSE 0 END AS is_rsvped,
                CASE WHEN user_like.id  IS NOT NULL THEN 1 ELSE 0 END AS is_liked
            ", false)
            ->join('categories c', 'c.id = listings.category_id', 'left')
            ->join(
                "(SELECT listing_id, COUNT(*) AS save_count FROM listing_saves GROUP BY listing_id) ls_agg",
                'ls_agg.listing_id = listings.id',
                'left'
            )
            ->join(
                "listing_saves user_save",
                "user_save.listing_id = listings.id AND user_save.user_id = {$userId}",
                'left'
            )
            ->join(
                "listing_rsvps user_rsvp",
                "user_rsvp.listing_id = listings.id AND user_rsvp.user_id = {$userId}",
                'left'
            )
            ->join(
                "listing_likes user_like",
                "user_like.listing_id = listings.id AND user_like.user_id = {$userId}",
                'left'
            );
    }

    // ── Feed scopes ───────────────────────────────────────────────────────────

    /** Only approved, active listings */
    public function active(): static
    {
        return $this->where('listings.is_active', 1)->where('listings.status', 'approved');
    }

    /**
     * Personalised feed filtered by the user's interest categories.
     *
     * @param string[] $interests  e.g. ['education','health']
     */
    public function personalised(array $interests): static
    {
        if (! empty($interests)) {
            $this->join('categories pi_cat', 'pi_cat.id = listings.category_id', 'left')
                 ->whereIn('pi_cat.slug', $interests);
        }
        return $this;
    }

    /**
     * Listings from organisations the user has followed.
     * (Follow table not yet created — returns all active for now, extendable.)
     */
    public function following(int $userId): static
    {
        // Placeholder: will filter by user_follows when that table exists.
        // For now returns all active listings ordered by newest.
        return $this;
    }

    /**
     * Nearby listings using the Haversine formula.
     *
     * @param float $lat     User latitude
     * @param float $lng     User longitude
     * @param float $radiusKm  Search radius in kilometres
     */
    public function nearby(float $lat, float $lng, float $radiusKm = 25.0): static
    {
        $havDist = "
            (6371 * ACOS(
                COS(RADIANS({$lat})) * COS(RADIANS(listings.lat)) *
                COS(RADIANS(listings.lng) - RADIANS({$lng})) +
                SIN(RADIANS({$lat})) * SIN(RADIANS(listings.lat))
            ))
        ";

        return $this
            ->select("{$havDist} AS distance_km", false)
            ->where("listings.lat IS NOT NULL")
            ->where("listings.lng IS NOT NULL")
            ->having("distance_km <=", $radiusKm)
            ->orderBy('distance_km', 'ASC');
    }

    // ── Cursor-based pagination ───────────────────────────────────────────────

    /**
     * Apply cursor (last seen id) and return a page of results.
     *
     * @param int|null $lastId   The id of the last item from the previous page
     * @param int      $perPage
     */
    public function cursor(?int $lastId, int $perPage = 20): array
    {
        if ($lastId !== null) {
            $this->where('listings.id <', $lastId);
        }
        return $this->orderBy('listings.id', 'DESC')->findAll($perPage + 1);
    }

    // ── Category filter ───────────────────────────────────────────────────────

    public function byCategory(int $categoryId): static
    {
        return $this->where('listings.category_id', $categoryId);
    }

    // ── Formatting ────────────────────────────────────────────────────────────

    /** Cast DB row to the shape the Flutter app expects */
    public static function format(array $row): array
    {
        return [
            'id'            => (string) $row['id'],
            'title'         => (string) $row['title'],
            'description'   => (string) ($row['description'] ?? ''),
            'category'      => $row['category_slug']  ?? null,
            'category_name' => $row['category_name']  ?? null,
            'category_icon' => $row['category_icon']  ?? null,
            'org_name'      => (string) ($row['org_name'] ?? ''),
            'trust_level'   => $row['trust_level'],
            'trust_label'   => (string) ($row['trust_label'] ?? ''),
            'location'      => $row['location']     ?? null,
            'date'          => $row['date']         ?? null,
            'deadline'      => $row['deadline']     ?? null,
            'action_type'   => $row['action_type']  ?? null,
            'is_live'       => (bool) ($row['is_live'] ?? false),
            'external_url'  => $row['external_url'] ?? null,
            'image_url'     => $row['cover_url']    ?? null,
            'like_count'    => (int)  ($row['like_count']    ?? 0),
            'comment_count' => (int)  ($row['comment_count'] ?? 0),
            'is_saved'      => (bool) ($row['is_saved']  ?? false),
            'is_rsvped'     => (bool) ($row['is_rsvped'] ?? false),
            'is_liked'      => (bool) ($row['is_liked']  ?? false),
            'created_at'    => $row['created_at'] ?? null,
        ];
    }
}
