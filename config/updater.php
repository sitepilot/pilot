<?php

use App\Updater\GithubReleasesStrategy;

return [

    /*
    |--------------------------------------------------------------------------
    | Self-Update Strategy
    |--------------------------------------------------------------------------
    |
    | The strategy used by the "self-update" command to resolve and download a
    | new version. GithubReleasesStrategy resolves the latest stable version
    | via Packagist and downloads the PHAR attached to the matching GitHub
    | release (.../releases/download/<tag>/pilot).
    |
    */

    'strategy' => GithubReleasesStrategy::class,

];
