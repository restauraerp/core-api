<?php

use App\Http\Controllers\StorageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Uploads that are not on this server's disk - i.e. in the bucket. See
// StorageController. Outside the web group: an image needs no session, and a
// session would set a cookie on every one of them.
Route::get('/storage/{path}', StorageController::class)
    ->where('path', '.*')
    ->withoutMiddleware('web')
    ->name('storage.media');
