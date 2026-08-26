<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public API documentation
    |--------------------------------------------------------------------------
    |
    | Serves /docs and /docs/openapi.yaml. Off by default, and deliberately so:
    | the spec describes every admin endpoint -- paths, parameters, request
    | bodies, response schemas -- which is a ready-made map of the admin surface
    | for anyone who asks for it. A pentest on 27/5/2026 found it published to
    | anonymous visitors in production.
    |
    | Turn it on per environment (local, staging) via API_DOCS_ENABLED. Read
    | through config, never env() at the callsite: the deploy runs
    | `php artisan config:cache`, after which env() outside config files
    | returns null and the gate would silently fail open.
    |
    */

    'enabled' => (bool) env('API_DOCS_ENABLED', false),

];
