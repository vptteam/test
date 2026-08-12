<?php

declare(strict_types=1);

namespace Modules;

class ModuleManager
{
    private array $modules = [];

    public function __construct()
    {
        $this->discover();
    }

    /**
     * Discover all installed modules automatically.
     */
    private function discover(): void
    {
        $directories = glob(BASE_PATH . '/modules/*', GLOB_ONLYDIR);

        foreach ($directories as $directory) {

            $module = basename($directory);

            $class = "Modules\\{$module}\\{$module}Module";

            $file = $directory . "/{$module}Module.php";

            if (!file_exists($file)) {
                continue;
            }

            require_once $file;

            if (class_exists($class)) {

                $this->modules[] = new $class();

            }

        }
    }

    public function all(): array
    {
        return $this->modules;
    }
    
    public function registry(): \Core\CommandRegistry
{
    $registry = new \Core\CommandRegistry();

    foreach ($this->modules as $module) {

        $folder = BASE_PATH

            . "/modules/"

            . $module->name()

            . "/commands.php";

        if (!file_exists($folder)) {
            continue;
        }

        $commands = require $folder;

        $registry->register(

            $module->name(),

            $commands

        );

    }

    return $registry;
}

}