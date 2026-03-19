<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\File;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/docs', function () {
    abort_unless(File::exists(base_path('docs/openapi.yaml')), 404);

    return view('docs.openapi', [
        'specUrl' => url('/docs/openapi.yaml'),
    ]);
});

Route::get('/docs/openapi.yaml', function () {
    $path = base_path('docs/openapi.yaml');

    abort_unless(File::exists($path), 404);

    return response(File::get($path), 200, [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
});

Route::middleware(['role:admin'])->get('/dashboard', function () {
    return response('Admin dashboard.', 200);
});
