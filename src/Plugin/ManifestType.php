<?php

/**
 * Manifest Type.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Plugin;

/**
 * Enumeration of supported plugin categories.
 *
 * The eleven cases mirror the `type` enum of
 * `schemas/manifest.schema.json` bundled with this package — the
 * canonical validator schema for plugin manifests since 0.6.0. The
 * string `value` is the kebab-case form that appears in each plugin's
 * `plugin.json`.
 *
 * @package Phlix\Shared\Plugin
 * @since 0.2.0
 */
enum ManifestType: string
{
    case MetadataProvider = 'metadata-provider';
    case SubtitleProvider = 'subtitle-provider';
    case AuthProvider = 'auth-provider';
    case LibraryType = 'library-type';
    case Notifier = 'notifier';
    case Scrobbler = 'scrobbler';
    case Tuner = 'tuner';
    case TranscoderHook = 'transcoder-hook';
    case UiTheme = 'ui-theme';
    case ArrIntegration = 'arr-integration';
    case AnalyticsSink = 'analytics-sink';
}
