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

        /*
        | The transient bridge between the broadcast provider and the media
        | provider (spec 019).
        |
        | ⚠️ THE SAME ENV VARS AS `sessions.livekit.egress`, DELIBERATELY. It is
        | one bucket: LiveKit Egress writes the recording into it, and the media
        | provider hands a signed URL to that same object to whoever fetches it.
        | A second set of variables for the same bucket is two answers to "where
        | is the recording" that agree until the day somebody updates one.
        |
        | Path style because R2 addresses buckets by path, not by subdomain — the
        | same reason `setForcePathStyle(true)` appears on the egress side.
        */
        'r2' => [
            'driver' => 's3',
            'key' => env('LIVEKIT_EGRESS_KEY'),
            'secret' => env('LIVEKIT_EGRESS_SECRET'),
            'region' => env('LIVEKIT_EGRESS_REGION', 'auto'),
            'bucket' => env('LIVEKIT_EGRESS_BUCKET'),
            'endpoint' => env('LIVEKIT_EGRESS_ENDPOINT'),
            'use_path_style_endpoint' => true,
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
