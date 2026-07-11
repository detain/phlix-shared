<?php

/**
 * implements.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Plugin;

/**
 * Optional contract a plugin entry class implements to receive its
 * persisted settings from the host before {@see LifecycleInterface::onEnable()}.
 *
 * ## Why this exists
 *
 * The host loader instantiates the entry class through its PSR-11
 * container ({@see LifecycleInterface}), so the constructor MUST be
 * autowirable — it cannot take the settings array (or a bare `string
 * $apiKey`) as a required parameter, because the container has no way to
 * guess those values and resolution fails. Plugins therefore keep a
 * cheap, all-optional constructor and receive their configured values
 * through this method instead.
 *
 * ## Lifecycle placement
 *
 * The loader calls {@see self::configure()} exactly once, AFTER the
 * entry class is instantiated and BEFORE {@see LifecycleInterface::onEnable()}
 * runs — so `onEnable()` can rely on the settings already being present.
 * The array is the raw `settings_json` map persisted for the plugin
 * (the same key-set the manifest's `settings` schema declares, with
 * secrets in the clear — this is server-side, never sent to a client).
 *
 * Implementing this interface is optional: a plugin that needs no
 * configuration simply omits it and the loader skips the call.
 *
 * @package Phlix\Shared\Plugin
 * @since 0.18.0
 */
interface ConfigurableInterface
{
    /**
     * Receive the plugin's persisted settings.
     *
     * Called once by the loader between construction and
     * {@see LifecycleInterface::onEnable()}. Implementations should only
     * absorb the values (e.g. build a typed settings/config object) and
     * must not perform blocking I/O here — save that for `onEnable()` or
     * the listeners. Throwing aborts enabling; the loader surfaces the
     * throwable as a `PluginEnableException` on the host.
     *
     * @param array<string, mixed> $settings Raw persisted settings map,
     *        keyed by the manifest's declared setting keys.
     *
     * @return void
     *
     * @since 0.18.0
     */
    public function configure(array $settings): void;
}
