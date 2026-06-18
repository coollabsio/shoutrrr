<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Media Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk used for storing uploaded media (images and videos).
    | Set MEDIA_DISK=s3 in production. The public disk is used locally.
    |
    */

    'disk' => env('MEDIA_DISK', 'public'),

];
