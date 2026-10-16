<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TmdbController as MovieController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SocialAuthController;

// ── Public ──────────────────────────────────────
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);


Route::post('/auth/google', [SocialAuthController::class, 'handleGoogle']);
Route::post('/auth/apple', [SocialAuthController::class, 'handleApple']);

// ── Protected ───────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Trending (All tab / Movies tab / TV tab)
    Route::get('/trending/all', [MovieController::class, 'trendingAll']);
    Route::get('/trending/movies', [MovieController::class, 'trendingMovies']);
    Route::get('/trending/tv', [MovieController::class, 'trendingTv']);

    // Browse all
    Route::get('/movies/all', [MovieController::class, 'allMovies']);
    Route::get('/tv/all', [MovieController::class, 'allTv']);

    // Anime (Japanese animation only)
    Route::get('/anime/tv', [MovieController::class, 'animeTv']);
    Route::get('/anime/movies', [MovieController::class, 'animeMovies']);

    // Search — ?q=...&type=movie|tv|multi|anime&page=1
    Route::get('/search', [MovieController::class, 'search']);

    // Recommendations & similar
    Route::get('/movies/{id}/recommendations', [MovieController::class, 'movieRecommendations']);
    Route::get('/tv/{id}/recommendations', [MovieController::class, 'tvRecommendations']);
    Route::get('/movies/{id}/similar', [MovieController::class, 'similarMovies']);

    // Genres
    Route::get('/genres/movies', [MovieController::class, 'movieGenres']);
    Route::get('/genres/tv', [MovieController::class, 'tvGenres']);
    Route::get('/browse/all', [MovieController::class, 'browseAll']);

    Route::get('/movies/{id}/details', [MovieController::class, 'movieDetails']);
    Route::get('/tv/{id}/details', [MovieController::class, 'tvDetails']);
    Route::get('/tv/{id}/season/{seasonNumber}', [MovieController::class, 'tvSeasonDetails']);
});