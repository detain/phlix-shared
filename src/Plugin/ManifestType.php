<?php

declare(strict_types=1);

namespace Phlex\Shared\Plugin;

/**
 * Enumeration of supported plugin categories.
 *
 * Each case maps to one of the eleven plugin types listed in
 * `PHLEX_EXPANSION_PLAN.md` §5. The string `value` is the kebab-case form
 * that appears in `plugin.json` and in `docs/plugins/manifest.schema.json`
 * shipped with `phlex-server`.
 *
 * @package Phlex\Shared\Plugin
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
