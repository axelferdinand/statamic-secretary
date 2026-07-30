<?php

namespace AxelFerdinand\StatamicSecretary\Developer;

use AxelFerdinand\StatamicSecretary\Contracts\SecretaryTool;
use InvalidArgumentException;
use RuntimeException;

final class ToolRegistry
{
    /** @var array<string, SecretaryTool> */
    private array $tools = [];

    private bool $configurationLoaded = false;

    public function register(SecretaryTool $tool): self
    {
        $name = $tool->name();

        if (! preg_match('/^[a-z][a-z0-9_]{2,63}$/', $name)) {
            throw new InvalidArgumentException("Secretary tool name [{$name}] is invalid.");
        }

        if (isset($this->tools[$name])) {
            throw new InvalidArgumentException("Secretary tool [{$name}] is already registered.");
        }

        $this->tools[$name] = $tool;

        return $this;
    }

    /** @return array<string, SecretaryTool> */
    public function all(): array
    {
        $this->loadConfiguration();

        return $this->tools;
    }

    public function find(string $name): ?SecretaryTool
    {
        return $this->all()[$name] ?? null;
    }

    /** @return array<int, string> */
    public function names(): array
    {
        return array_keys($this->all());
    }

    private function loadConfiguration(): void
    {
        if ($this->configurationLoaded) {
            return;
        }

        $this->configurationLoaded = true;

        foreach ((array) config('secretary.developer.tools', []) as $class) {
            if (! is_string($class) || $class === '') {
                throw new RuntimeException('Every configured Secretary tool must be a class name.');
            }

            $tool = app($class);

            if (! $tool instanceof SecretaryTool) {
                throw new RuntimeException("Configured Secretary tool [{$class}] must implement ".SecretaryTool::class.'.');
            }

            $this->register($tool);
        }
    }
}
