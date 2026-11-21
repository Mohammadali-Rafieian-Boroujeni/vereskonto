<?php
/**
 * Service container.
 *
 * Minimal dependency-injection container for the Vereskonto platform.
 * Core infrastructure only — no business logic.
 *
 * @package Vereskonto\Core
 * @since   1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class VKL_Container
 *
 * Lightweight service container supporting lazy singletons and factories.
 */
final class VKL_Container
{
    /**
     * Registered factory callables, keyed by service id.
     *
     * @var array<string, callable>
     */
    private array $bindings = [];

    /**
     * Which service ids are singletons.
     *
     * @var array<string, bool>
     */
    private array $shared = [];

    /**
     * Resolved singleton instances.
     *
     * @var array<string, mixed>
     */
    private array $instances = [];

    /**
     * Register a singleton service (lazy — resolved on first use).
     *
     * @param string   $id      Service identifier, e.g. 'events'.
     * @param callable $factory Factory receiving the container.
     */
    public function singleton(string $id, callable $factory): void
    {
        $this->bindings[$id] = $factory;
        $this->shared[$id]   = true;
        unset($this->instances[$id]);
    }

    /**
     * Register a factory service (new instance per resolution).
     *
     * @param string   $id      Service identifier.
     * @param callable $factory Factory receiving the container.
     */
    public function bind(string $id, callable $factory): void
    {
        $this->bindings[$id] = $factory;
        $this->shared[$id]   = false;
        unset($this->instances[$id]);
    }

    /**
     * Register an already-constructed instance as a singleton.
     *
     * @param string $id       Service identifier.
     * @param mixed  $instance The instance.
     */
    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
        $this->shared[$id]    = true;
    }

    /**
     * Whether a service id is known to the container.
     *
     * @param string $id Service identifier.
     */
    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]);
    }

    /**
     * Resolve a service.
     *
     * @param string $id Service identifier.
     *
     * @return mixed
     *
     * @throws RuntimeException When the service is unknown.
     */
    public function make(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (!isset($this->bindings[$id])) {
            throw new RuntimeException(
                sprintf('VKL_Container: unknown service "%s".', $id)
            );
        }

        $resolved = ($this->bindings[$id])($this);

        if (!empty($this->shared[$id])) {
            $this->instances[$id] = $resolved;
        }

        return $resolved;
    }
}
