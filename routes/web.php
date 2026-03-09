<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['role:admin'])->get('/dashboard', function () {
    return response('Admin dashboard.', 200);
});
