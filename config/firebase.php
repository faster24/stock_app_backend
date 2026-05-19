<?php

return [
    'credentials_path' => env('FIREBASE_CREDENTIALS_PATH', 'firebase-key.json'),
    'project_id' => env('FIREBASE_PROJECT_ID', ''),
    'messaging' => [
        'enabled' => env('FIREBASE_MESSAGING_ENABLED', true),
    ],
];
