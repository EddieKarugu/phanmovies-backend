<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TmdbController extends Controller
{
    // ─────────────────────────────────────────────
    // TMDB HELPERS
    // ─────────────────────────────────────────────

private function tmdbGet(string $endpoint, array $query = []): array
{
    $response = Http::connectTimeout(30)     // <-- new: connection handshake
        ->timeout(60)                        // <-- total request
        ->retry(2, 1000)                     // <-- retry twice, 1s apart
        ->get(
            config('services.tmdb.base_url') . $endpoint,
            array_merge(['api_key' => config('services.tmdb.key')], $query)
        );

    if ($response->failed()) {
        return [
            'error' => true,
            'status' => $response->status(),
            'body' => $response->json(),
        ];
    }

    return [
        'error' => false,
        'data' => $response->json(),
    ];
}

    private function errorResponse(array $result)
    {
        return response()->json([
            'message' => 'TMDB request failed',
            'tmdb_status' => $result['status'],
            'tmdb_body' => $result['body'],
        ], 502);
    }

    private function getGenreMap(string $mediaType): array
    {
        $cacheKey = "tmdb.genres.map.{$mediaType}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $endpoint = $mediaType === 'tv' ? '/genre/tv/list' : '/genre/movie/list';
        $result = $this->tmdbGet($endpoint, ['language' => 'en-US']);

        if ($result['error']) {
            return [];
        }

        $map = collect($result['data']['genres'] ?? [])
            ->pluck('name', 'id')
            ->toArray();

        Cache::put($cacheKey, $map, now()->addHours(24));

        return $map;
    }

    private function buildImageUrl(?string $path, string $size = 'w500'): ?string
    {
        if (empty($path)) {
            return null;
        }
        return config('services.tmdb.image_url') . '/' . $size . $path;
    }

    // ─────────────────────────────────────────────
    // FORMATTERS
    // ─────────────────────────────────────────────

    private function formatMovie(array $movie, string $mediaType = 'movie'): array
    {
        $genreIds = $movie['genre_ids'] ?? [];
        $genreMap = $this->getGenreMap($mediaType);

        $genreNames = [];
        foreach ($genreIds as $id) {
            if (isset($genreMap[$id])) {
                $genreNames[] = $genreMap[$id];
            }
        }

        return [
            'id' => $movie['id'] ?? 0,
            'title' => $movie['title'] ?? $movie['name'] ?? '',
            'overview' => $movie['overview'] ?? '',
            'poster_url' => $this->buildImageUrl($movie['poster_path'] ?? null, 'w500'),
            'backdrop_url' => $this->buildImageUrl($movie['backdrop_path'] ?? null, 'w780'),
            'release_date' => $movie['release_date'] ?? $movie['first_air_date'] ?? null,
            'vote_average' => $movie['vote_average'] ?? 0,
            'media_type' => $movie['media_type'] ?? $mediaType,
            'genre_ids' => $genreIds,
            'genres' => $genreNames,
        ];
    }

    private function formatMovieList(array $results, string $mediaType = 'movie'): array
    {
        return array_map(
            fn ($m) => $this->formatMovie($m, $mediaType),
            $results
        );
    }

    private function formatPaginated(array $data, string $mediaType = 'movie'): array
    {
        return [
            'page' => $data['page'] ?? 1,
            'total_pages' => $data['total_pages'] ?? 1,
            'total_results' => $data['total_results'] ?? count($data['results'] ?? []),
            'results' => $this->formatMovieList($data['results'] ?? [], $mediaType),
        ];
    }

    // ─────────────────────────────────────────────
    // SHARED PAGINATED ENDPOINT
    // ─────────────────────────────────────────────

    private function paginatedEndpoint(
        string $cacheKey,
        string $tmdbEndpoint,
        array $tmdbQuery,
        string $mediaType,
        int $cacheMinutes
    ) {
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached);
        }

        $result = $this->tmdbGet($tmdbEndpoint, $tmdbQuery);

        if ($result['error']) {
            return $this->errorResponse($result);
        }

        $formatted = $this->formatPaginated($result['data'], $mediaType);
        Cache::put($cacheKey, $formatted, now()->addMinutes($cacheMinutes));

        return response()->json($formatted);
    }

    // ─────────────────────────────────────────────
    // TRENDING
    // ─────────────────────────────────────────────

    /**
     * Trending across movies AND TV (the "All" tab).
     * Filters out "person" results.
     */
    public function trendingAll(Request $request)
    {
        $window = in_array($request->query('window'), ['day', 'week'])
            ? $request->query('window')
            : 'week';
        $page = max(1, min((int) $request->query('page', 1), 500));

        $cacheKey = "tmdb.trending.all.{$window}.page.{$page}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached);
        }

        $result = $this->tmdbGet("/trending/all/{$window}", [
            'page' => $page,
            'language' => 'en-US',
        ]);

        if ($result['error']) {
            return $this->errorResponse($result);
        }

        // TMDB /trending/all includes people — filter to movies and TV only
        $results = array_values(array_filter(
            $result['data']['results'] ?? [],
            fn ($m) => in_array($m['media_type'] ?? '', ['movie', 'tv'])
        ));

        $formatted = array_map(
            fn ($m) => $this->formatMovie($m, $m['media_type'] ?? 'movie'),
            $results
        );

        $data = [
            'page' => $result['data']['page'] ?? 1,
            'total_pages' => $result['data']['total_pages'] ?? 1,
            'total_results' => $result['data']['total_results'] ?? count($formatted),
            'results' => $formatted,
        ];

        Cache::put($cacheKey, $data, now()->addMinutes(15));

        return response()->json($data);
    }

    public function trendingMovies(Request $request)
    {
        $window = in_array($request->query('window'), ['day', 'week'])
            ? $request->query('window')
            : 'week';

        return $this->paginatedEndpoint(
            cacheKey: "tmdb.trending.movies.{$window}",
            tmdbEndpoint: "/trending/movie/{$window}",
            tmdbQuery: ['language' => 'en-US'],
            mediaType: 'movie',
            cacheMinutes: 15
        );
    }

    public function trendingTv(Request $request)
    {
        $window = in_array($request->query('window'), ['day', 'week'])
            ? $request->query('window')
            : 'week';

        return $this->paginatedEndpoint(
            cacheKey: "tmdb.trending.tv.{$window}",
            tmdbEndpoint: "/trending/tv/{$window}",
            tmdbQuery: ['language' => 'en-US'],
            mediaType: 'tv',
            cacheMinutes: 15
        );
    }

    // ─────────────────────────────────────────────
    // ALL / DISCOVER
    // ─────────────────────────────────────────────

    public function allMovies(Request $request)
    {
        $page = max(1, min((int) $request->query('page', 1), 500));
        $genre = $request->query('genre');
        $sort = $request->query('sort', 'popularity.desc');

        $query = [
            'page' => $page,
            'sort_by' => $sort,
            'include_adult' => false,
            'language' => 'en-US',
        ];
        if ($genre) {
            $query['with_genres'] = $genre;
        }

        return $this->paginatedEndpoint(
            cacheKey: "tmdb.movies.all.page.{$page}.genre." . ($genre ?? 'all') . ".sort.{$sort}",
            tmdbEndpoint: '/discover/movie',
            tmdbQuery: $query,
            mediaType: 'movie',
            cacheMinutes: 30
        );
    }

    public function allTv(Request $request)
    {
        $page = max(1, min((int) $request->query('page', 1), 500));
        $genre = $request->query('genre');
        $sort = $request->query('sort', 'popularity.desc');

        $query = [
            'page' => $page,
            'sort_by' => $sort,
            'include_adult' => false,
            'language' => 'en-US',
        ];
        if ($genre) {
            $query['with_genres'] = $genre;
        }

        return $this->paginatedEndpoint(
            cacheKey: "tmdb.tv.all.page.{$page}.genre." . ($genre ?? 'all') . ".sort.{$sort}",
            tmdbEndpoint: '/discover/tv',
            tmdbQuery: $query,
            mediaType: 'tv',
            cacheMinutes: 30
        );
    }

    // ─────────────────────────────────────────────
    // ANIME (Japanese animation only)
    // ─────────────────────────────────────────────

    /**
     * Anime TV shows — Animation genre + Japanese original language.
     */
    public function animeTv(Request $request)
    {
        $page = max(1, min((int) $request->query('page', 1), 500));

        return $this->paginatedEndpoint(
            cacheKey: "tmdb.anime.tv.page.{$page}",
            tmdbEndpoint: '/discover/tv',
            tmdbQuery: [
                'page' => $page,
                'with_genres' => 16,                    // Animation
                'with_original_language' => 'ja',       // Japanese
                'sort_by' => 'popularity.desc',
                'include_adult' => false,
                'language' => 'en-US',
            ],
            mediaType: 'tv',
            cacheMinutes: 60
        );
    }

    /**
     * Anime movies — Animation genre + Japanese original language.
     */
    public function animeMovies(Request $request)
    {
        $page = max(1, min((int) $request->query('page', 1), 500));

        return $this->paginatedEndpoint(
            cacheKey: "tmdb.anime.movies.page.{$page}",
            tmdbEndpoint: '/discover/movie',
            tmdbQuery: [
                'page' => $page,
                'with_genres' => 16,
                'with_original_language' => 'ja',
                'sort_by' => 'popularity.desc',
                'include_adult' => false,
                'language' => 'en-US',
            ],
            mediaType: 'movie',
            cacheMinutes: 60
        );
    }

    // ─────────────────────────────────────────────
    // SEARCH
    // ─────────────────────────────────────────────

    /**
     * Unified search.
     * GET /api/search?q=batman&type=movie|tv|multi|anime&page=1
     */
    public function search(Request $request)
    {
        $q = trim($request->query('q', ''));
        $type = $request->query('type', 'multi');
        $page = max(1, min((int) $request->query('page', 1), 500));

        if ($q === '') {
            return response()->json([
                'page' => 1,
                'total_pages' => 1,
                'total_results' => 0,
                'results' => [],
            ]);
        }

        return match ($type) {
            'movie' => $this->searchSingle('/search/movie', $q, $page, 'movie'),
            'tv' => $this->searchSingle('/search/tv', $q, $page, 'tv'),
            'anime' => $this->searchAnime($q, $page),
            default => $this->searchMulti($q, $page),
        };
    }

    /**
     * Search movies OR TV only.
     */
    private function searchSingle(string $endpoint, string $q, int $page, string $mediaType)
    {
        $normalized = strtolower($q);
        $cacheKey = "tmdb.search.{$mediaType}." . md5($normalized) . ".page.{$page}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached);
        }

        $result = $this->tmdbGet($endpoint, [
            'query' => $q,
            'page' => $page,
            'include_adult' => false,
            'language' => 'en-US',
        ]);

        if ($result['error']) {
            return $this->errorResponse($result);
        }

        $formatted = $this->formatPaginated($result['data'], $mediaType);
        Cache::put($cacheKey, $formatted, now()->addMinutes(30));

        return response()->json($formatted);
    }

    /**
     * Search across movies AND TV (the "All" tab).
     * TMDB's /search/multi includes person results, which we filter out.
     */
    private function searchMulti(string $q, int $page)
    {
        $normalized = strtolower($q);
        $cacheKey = 'tmdb.search.multi.' . md5($normalized) . ".page.{$page}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached);
        }

        $result = $this->tmdbGet('/search/multi', [
            'query' => $q,
            'page' => $page,
            'include_adult' => false,
            'language' => 'en-US',
        ]);

        if ($result['error']) {
            return $this->errorResponse($result);
        }

        // Filter out person results
        $results = array_values(array_filter(
            $result['data']['results'] ?? [],
            fn ($m) => in_array($m['media_type'] ?? '', ['movie', 'tv'])
        ));

        $formatted = array_map(
            fn ($m) => $this->formatMovie($m, $m['media_type'] ?? 'movie'),
            $results
        );

        $data = [
            'page' => $result['data']['page'] ?? 1,
            'total_pages' => $result['data']['total_pages'] ?? 1,
            'total_results' => $result['data']['total_results'] ?? count($formatted),
            'results' => $formatted,
        ];

        Cache::put($cacheKey, $data, now()->addMinutes(30));

        return response()->json($data);
    }

    /**
     * Search anime only.
     * Queries movies AND TV in parallel, then filters to Japanese animation.
     */
    private function searchAnime(string $q, int $page)
    {
        $normalized = strtolower($q);
        $cacheKey = 'tmdb.search.anime.' . md5($normalized) . ".page.{$page}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return response()->json($cached);
        }

        $apiKey = config('services.tmdb.key');
        $baseUrl = config('services.tmdb.base_url');

        // Fire both searches in parallel
        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('tv')->get("{$baseUrl}/search/tv", [
                'api_key' => $apiKey,
                'query' => $q,
                'page' => $page,
                'include_adult' => false,
                'language' => 'en-US',
            ]),
            $pool->as('movie')->get("{$baseUrl}/search/movie", [
                'api_key' => $apiKey,
                'query' => $q,
                'page' => $page,
                'include_adult' => false,
                'language' => 'en-US',
            ]),
        ]);

        $tvData = ['results' => [], 'total_pages' => 1];
        $movieData = ['results' => [], 'total_pages' => 1];

        if (($responses['tv'] ?? null) instanceof Response && $responses['tv']->successful()) {
            $tvData = $responses['tv']->json();
        }
        if (($responses['movie'] ?? null) instanceof Response && $responses['movie']->successful()) {
            $movieData = $responses['movie']->json();
        }

        // Only keep Japanese animation
        $isAnime = function ($m) {
            $isJapanese = ($m['original_language'] ?? '') === 'ja';
            $isAnimation = in_array(16, $m['genre_ids'] ?? []);
            return $isJapanese && $isAnimation;
        };

        $tvResults = array_values(array_filter($tvData['results'] ?? [], $isAnime));
        $movieResults = array_values(array_filter($movieData['results'] ?? [], $isAnime));

        // Tag each result with its media type
        $tvResults = array_map(function ($m) {
            $m['media_type'] = 'tv';
            return $m;
        }, $tvResults);

        $movieResults = array_map(function ($m) {
            $m['media_type'] = 'movie';
            return $m;
        }, $movieResults);

        // Merge and sort by popularity
        $merged = array_merge($tvResults, $movieResults);
        usort($merged, fn ($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));

        $formatted = array_map(
            fn ($m) => $this->formatMovie($m, $m['media_type']),
            $merged
        );

        $data = [
            'page' => $page,
            'total_pages' => max($tvData['total_pages'] ?? 1, $movieData['total_pages'] ?? 1),
            'total_results' => count($formatted),
            'results' => $formatted,
        ];

        Cache::put($cacheKey, $data, now()->addMinutes(30));

        return response()->json($data);
    }

    // ─────────────────────────────────────────────
    // RECOMMENDATIONS & SIMILAR
    // ─────────────────────────────────────────────

    public function movieRecommendations(int $id, Request $request)
    {
        $page = max(1, min((int) $request->query('page', 1), 500));

        return $this->paginatedEndpoint(
            cacheKey: "tmdb.movie.{$id}.recommendations.page.{$page}",
            tmdbEndpoint: "/movie/{$id}/recommendations",
            tmdbQuery: ['page' => $page, 'language' => 'en-US'],
            mediaType: 'movie',
            cacheMinutes: 60
        );
    }

    public function tvRecommendations(int $id, Request $request)
    {
        $page = max(1, min((int) $request->query('page', 1), 500));

        return $this->paginatedEndpoint(
            cacheKey: "tmdb.tv.{$id}.recommendations.page.{$page}",
            tmdbEndpoint: "/tv/{$id}/recommendations",
            tmdbQuery: ['page' => $page, 'language' => 'en-US'],
            mediaType: 'tv',
            cacheMinutes: 60
        );
    }

    public function similarMovies(int $id, Request $request)
    {
        $page = max(1, min((int) $request->query('page', 1), 500));

        return $this->paginatedEndpoint(
            cacheKey: "tmdb.movie.{$id}.similar.page.{$page}",
            tmdbEndpoint: "/movie/{$id}/similar",
            tmdbQuery: ['page' => $page, 'language' => 'en-US'],
            mediaType: 'movie',
            cacheMinutes: 60
        );
    }

    // ─────────────────────────────────────────────
    // GENRES
    // ─────────────────────────────────────────────

    public function movieGenres()
    {
        return $this->genreList('movie');
    }

    public function tvGenres()
    {
        return $this->genreList('tv');
    }

    private function genreList(string $mediaType)
    {
        $cacheKey = "tmdb.genres.list.{$mediaType}";

        $data = Cache::get($cacheKey);

        if ($data === null) {
            $endpoint = $mediaType === 'tv' ? '/genre/tv/list' : '/genre/movie/list';
            $result = $this->tmdbGet($endpoint, ['language' => 'en-US']);

            if ($result['error']) {
                return $this->errorResponse($result);
            }

            $data = $result['data']['genres'] ?? [];
            Cache::put($cacheKey, $data, now()->addHours(24));
        }

        return response()->json(['genres' => $data]);
    }

    /**
 * Mixed browse — popular movies + TV + anime, combined and sorted by popularity.
 * GET /api/browse/all?page=1
 */
public function browseAll(Request $request)
{
    $page = max(1, min((int) $request->query('page', 1), 500));

    $cacheKey = "tmdb.browse.all.page.{$page}";
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        return response()->json($cached);
    }

    // Fetch 4 sources in parallel
    $apiKey = config('services.tmdb.key');
    $baseUrl = config('services.tmdb.base_url');

    $responses = Http::connectTimeout(30)
        ->timeout(60)
        ->retry(2, 1000)
        ->pool(fn (Pool $pool) => [
            $pool->as('movies')->get("{$baseUrl}/discover/movie", [
                'api_key' => $apiKey,
                'page' => $page,
                'sort_by' => 'popularity.desc',
                'include_adult' => false,
                'language' => 'en-US',
            ]),
            $pool->as('tv')->get("{$baseUrl}/discover/tv", [
                'api_key' => $apiKey,
                'page' => $page,
                'sort_by' => 'popularity.desc',
                'include_adult' => false,
                'language' => 'en-US',
            ]),
            $pool->as('animeMovies')->get("{$baseUrl}/discover/movie", [
                'api_key' => $apiKey,
                'page' => $page,
                'with_genres' => 16,
                'with_original_language' => 'ja',
                'sort_by' => 'popularity.desc',
                'include_adult' => false,
                'language' => 'en-US',
            ]),
            $pool->as('animeTv')->get("{$baseUrl}/discover/tv", [
                'api_key' => $apiKey,
                'page' => $page,
                'with_genres' => 16,
                'with_original_language' => 'ja',
                'sort_by' => 'popularity.desc',
                'include_adult' => false,
                'language' => 'en-US',
            ]),
        ]);

    $movies = [];
    $tv = [];
    $animeMovies = [];
    $animeTv = [];

    if (($responses['movies'] ?? null) instanceof Response && $responses['movies']->successful()) {
        $movies = $responses['movies']->json('results') ?? [];
    }
    if (($responses['tv'] ?? null) instanceof Response && $responses['tv']->successful()) {
        $tv = $responses['tv']->json('results') ?? [];
    }
    if (($responses['animeMovies'] ?? null) instanceof Response && $responses['animeMovies']->successful()) {
        $animeMovies = $responses['animeMovies']->json('results') ?? [];
    }
    if (($responses['animeTv'] ?? null) instanceof Response && $responses['animeTv']->successful()) {
        $animeTv = $responses['animeTv']->json('results') ?? [];
    }

    // Tag media_type on each
    $tag = function (array $items, string $type): array {
        return array_map(function ($m) use ($type) {
            $m['media_type'] = $type;
            return $m;
        }, $items);
    };

    $movies = $tag($movies, 'movie');
    $tv = $tag($tv, 'tv');
    $animeMovies = $tag($animeMovies, 'movie');
    $animeTv = $tag($animeTv, 'tv');

    // Merge and dedupe by (id + media_type)
    $merged = array_merge($movies, $tv, $animeMovies, $animeTv);

    $seen = [];
    $unique = [];
    foreach ($merged as $item) {
        $key = ($item['media_type'] ?? 'movie') . '-' . ($item['id'] ?? 0);
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $item;
        }
    }

    // Sort by popularity desc
    usort($unique, fn ($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));

    // Format
    $formatted = array_map(
        fn ($m) => $this->formatMovie($m, $m['media_type'] ?? 'movie'),
        $unique
    );

    $data = [
        'page' => $page,
        'total_pages' => 500,
        'total_results' => count($formatted),
        'results' => $formatted,
    ];

    Cache::put($cacheKey, $data, now()->addMinutes(30));

    return response()->json($data);
}


public function movieDetails(int $id)
{
    $cacheKey = "tmdb.movie.{$id}.details";
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        return response()->json($cached);
    }

    $apiKey = config('services.tmdb.key');
    $baseUrl = config('services.tmdb.base_url');

    $responses = Http::connectTimeout(30)
        ->timeout(60)
        ->retry(2, 1000)
        ->pool(fn (Pool $pool) => [
            $pool->as('details')->get("{$baseUrl}/movie/{$id}", [
                'api_key' => $apiKey,
                'language' => 'en-US',
                'append_to_response' => 'videos,credits,recommendations',
            ]),
        ]);

    $details = null;
    if (($responses['details'] ?? null) instanceof Response && $responses['details']->successful()) {
        $details = $responses['details']->json();
    }

    if (! $details) {
        return response()->json(['message' => 'Movie not found'], 404);
    }

    // Pick the best YouTube trailer
    $videos = $details['videos']['results'] ?? [];
    $trailerKey = null;
    foreach ($videos as $v) {
        if (($v['site'] ?? '') === 'YouTube' && ($v['type'] ?? '') === 'Trailer') {
            $trailerKey = $v['key'];
            break;
        }
    }
    // Fall back to any YouTube video if no Trailer type found
    if (! $trailerKey) {
        foreach ($videos as $v) {
            if (($v['site'] ?? '') === 'YouTube') {
                $trailerKey = $v['key'];
                break;
            }
        }
    }

    // Format top-billed cast (limit to 15)
    $cast = collect($details['credits']['cast'] ?? [])
        ->take(15)
        ->map(fn ($c) => [
            'id' => $c['id'] ?? 0,
            'name' => $c['name'] ?? '',
            'character' => $c['character'] ?? '',
            'profile_url' => $this->buildImageUrl($c['profile_path'] ?? null, 'w185'),
        ])
        ->values()
        ->toArray();

    // Format recommendations
    $recs = collect($details['recommendations']['results'] ?? [])
        ->take(20)
        ->map(fn ($m) => $this->formatMovie($m, 'movie'))
        ->values()
        ->toArray();

    // Format the movie itself using your existing formatter
    $movie = $this->formatMovie($details, 'movie');

    $payload = [
        'movie' => $movie,
        'trailer_key' => $trailerKey,          
        'cast' => $cast,
        'recommendations' => $recs,
        'seasons' => [], // Movies don't have seasons, so this is empty
    ];

    Cache::put($cacheKey, $payload, now()->addHours(6));

    return response()->json($payload);
}


public function tvDetails(int $id)
{
    $cacheKey = "tmdb.tv.{$id}.details";
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        return response()->json($cached);
    }

    $apiKey = config('services.tmdb.key');
    $baseUrl = config('services.tmdb.base_url');

    $responses = Http::connectTimeout(30)
        ->timeout(60)
        ->retry(2, 1000)
        ->pool(fn (Pool $pool) => [
            $pool->as('details')->get("{$baseUrl}/tv/{$id}", [
                'api_key' => $apiKey,
                'language' => 'en-US',
                'append_to_response' => 'videos,credits,recommendations',
            ]),
        ]);

    $details = null;
    if (($responses['details'] ?? null) instanceof Response && $responses['details']->successful()) {
        $details = $responses['details']->json();
    }

    if (! $details) {
        return response()->json(['message' => 'TV show not found'], 404);
    }

    $videos = $details['videos']['results'] ?? [];
    $trailerKey = null;
    foreach ($videos as $v) {
        if (($v['site'] ?? '') === 'YouTube' && ($v['type'] ?? '') === 'Trailer') {
            $trailerKey = $v['key'];
            break;
        }
    }
    if (! $trailerKey) {
        foreach ($videos as $v) {
            if (($v['site'] ?? '') === 'YouTube') {
                $trailerKey = $v['key'];
                break;
            }
        }
    }

    $cast = collect($details['credits']['cast'] ?? [])
        ->take(15)
        ->map(fn ($c) => [
            'id' => $c['id'] ?? 0,
            'name' => $c['name'] ?? '',
            'character' => $c['character'] ?? '',
            'profile_url' => $this->buildImageUrl($c['profile_path'] ?? null, 'w185'),
        ])
        ->values()
        ->toArray();

    $recs = collect($details['recommendations']['results'] ?? [])
        ->take(20)
        ->map(fn ($m) => $this->formatMovie($m, 'tv'))
        ->values()
        ->toArray();

    $movie = $this->formatMovie($details, 'tv');

 $seasons = collect($details['seasons'] ?? [])
    ->filter(fn ($s) => ($s['season_number'] ?? 0) > 0) 
    ->map(fn ($s) => [
        'season_number' => $s['season_number'],
        'name' => $s['name'] ?? 'Season ' . $s['season_number'],
        'episode_count' => $s['episode_count'] ?? 0,
        'poster_url' => $this->buildImageUrl($s['poster_path'] ?? null, 'w342'),
        'air_date' => $s['air_date'] ?? null,
    ])
    ->values()
    ->toArray();

$payload = [
    'movie' => $movie,
    'trailer_key' => $trailerKey,
    'cast' => $cast,
    'recommendations' => $recs,
    'seasons' => $seasons,   
];

    Cache::put($cacheKey, $payload, now()->addHours(6));

    return response()->json($payload);
}

public function tvSeasonDetails(int $id, int $seasonNumber)
{
    $cacheKey = "tmdb.tv.{$id}.season.{$seasonNumber}.details";
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        return response()->json($cached);
    }

    $result = $this->tmdbGet("/tv/{$id}/season/{$seasonNumber}", [
        'language' => 'en-US',
    ]);

    if ($result['error']) {
        return $this->errorResponse($result);
    }

    $data = $result['data'];

    $episodes = collect($data['episodes'] ?? [])
        ->map(fn ($e) => [
            'episode_number' => $e['episode_number'] ?? 0,
            'name' => $e['name'] ?? '',
            'overview' => $e['overview'] ?? '',
            'still_url' => $this->buildImageUrl($e['still_path'] ?? null, 'w300'),
            'runtime' => $e['runtime'] ?? null,
            'air_date' => $e['air_date'] ?? null,
            'vote_average' => $e['vote_average'] ?? 0,
        ])
        ->values()
        ->toArray();

    $payload = [
        'season_number' => $data['season_number'] ?? $seasonNumber,
        'name' => $data['name'] ?? '',
        'overview' => $data['overview'] ?? '',
        'poster_url' => $this->buildImageUrl($data['poster_path'] ?? null, 'w342'),
        'episodes' => $episodes,
    ];

    Cache::put($cacheKey, $payload, now()->addHours(6));

    return response()->json($payload);
}

}