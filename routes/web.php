<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Both routes are unauthenticated by nature -- this is an API-only backend with
// no session login, so there is no browser identity to check here. The gate is
// therefore the environment, not the caller: off unless a deployment opts in.
//
// The spec documents every admin endpoint down to its request bodies, so serving
// it publicly hands over the admin surface to anyone who asks. 404 rather than
// 403 on the closed path: a disabled endpoint should not advertise that it
// exists.
Route::get('/docs', function () {
    abort_unless(config('docs.enabled'), 404);
    abort_unless(File::exists(base_path('docs/openapi.yaml')), 404);

    return view('docs.openapi', [
        'specUrl' => url('/docs/openapi.yaml'),
    ]);
});

Route::get('/docs/openapi.yaml', function () {
    abort_unless(config('docs.enabled'), 404);

    $path = base_path('docs/openapi.yaml');

    abort_unless(File::exists($path), 404);

    return response(File::get($path), 200, [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
});

Route::middleware(['role:admin'])->get('/dashboard', function () {
    return response('Admin dashboard.', 200);
});
