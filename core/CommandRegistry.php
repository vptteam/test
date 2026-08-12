<?php

declare(strict_types=1);

namespace Core;

class CommandRegistry
{
    private array $commands = [];

    public function register(string $module, array $commands): void
    {
        foreach ($commands as $command) {

            $this->commands[strtolower($command)] = $module;

        }
    }

    public function find(string $command): ?string
    {
        $command = strtolower(trim($command));

        return $this->commands[$command] ?? null;
    }

    public function all(): array
    {
        return $this->commands;
    }
}