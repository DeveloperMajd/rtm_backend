<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Avatar Disk
    |--------------------------------------------------------------------------
    |
    | Profile avatars are public and long-lived, so they live on a disk that
    | can hand out a stable, cacheable URL: the "public" disk locally (needs
    | `php artisan storage:link`) and the "s3" disk (Cloudflare R2, public
    | bucket via AWS_URL) in production.
    |
    */

    'avatars' => env('AVATAR_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Default Disk vs. Avatar Disk — why two separate R2 buckets
    |--------------------------------------------------------------------------
    |
    | R2 public access is a bucket-wide switch, not per-prefix — enabling it
    | for avatars would also make attachment objects fetchable by anyone who
    | has/guesses their path, undermining the "attachments are never on a
    | public bucket path" guarantee. So in production FILESYSTEM_DISK points
    | at the "r2" disk below (a second, private bucket — no AWS_URL, access
    | only ever via presigned temporaryUrl()), while AVATAR_DISK points at
    | "s3" (the public-access bucket). Same R2 account/credentials/endpoint,
    | different bucket name.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Attachments' bucket — same R2 account/token as "s3" above, but a
        // separate, non-public bucket. No "url" key: never used to build a
        // direct link, only ever through Attachment::temporaryUrl()'s
        // presigned GET, which works against a private bucket regardless.
        'r2' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('R2_ATTACHMENTS_BUCKET'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
