<?php

declare(strict_types=1);

namespace Core;


class ModuleManager
{

    /**
     * Load all bot modules
     */
    public function all(): array
    {

        return [

            new \Modules\Marketplace\MarketplaceModule(),

        ];

    }

}