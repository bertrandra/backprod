<?php

declare(strict_types=1);

namespace App\Navigation\Domain;

/**
 * Where the platform's menu setup is kept. One document, platform-wide.
 */
interface NavigationSetupRepository
{
    /** Everything, when nobody has set anything yet. */
    public function load(): NavigationSetup;

    public function save(NavigationSetup $setup): void;
}
