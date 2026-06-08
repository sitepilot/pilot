<?php

namespace App\Updater;

use Humbug\SelfUpdate\Strategy\GithubStrategy as HumbugGithubStrategy;
use Humbug\SelfUpdate\Updater;
use LaravelZero\Framework\Components\Updater\Strategy\StrategyInterface;
use Phar;

class GithubReleasesStrategy extends HumbugGithubStrategy implements StrategyInterface
{
    /**
     * The stock updater Provider never sets a phar name, so set it here to the
     * basename of the running phar. This makes the download URL resolve to the
     * release asset: .../releases/download/<tag>/pilot
     */
    public function getCurrentRemoteVersion(Updater $updater)
    {
        if (! $this->getPharName()) {
            $this->setPharName(basename(Phar::running(false)) ?: 'pilot');
        }

        return parent::getCurrentRemoteVersion($updater);
    }
}
