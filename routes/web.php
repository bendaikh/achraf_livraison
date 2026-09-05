<?php

use Illuminate\Support\Facades\Route;

/*
| SPA shell only — all UI pages are React routes (lazy-loaded).
| Laravel does not render or preload any frontend page.
*/
Route::view('/{any?}', 'app')->where('any', '.*');
