<?php

use Illuminate\Support\Facades\Route;

// Serve index.html for root route
Route::get('/', function () {
    $filePath = public_path('index.html');
    if (file_exists($filePath)) {
        return file_get_contents($filePath);
    }
    abort(404);
});

// Serve static HTML files
Route::get('/{page}', function ($page) {
    $filePath = public_path($page . '.html');
    if (file_exists($filePath)) {
        return file_get_contents($filePath);
    }
    abort(404);
})->where('page', '.*');