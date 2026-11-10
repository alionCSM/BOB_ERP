<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Dove si scarica l'app, in un posto solo.
 *
 * Il link di Google Play era scritto a mano in due punti (email di
 * benvenuto e controllo della versione) e quello dell'App Store in un
 * terzo. Si cambiano da .env:
 *   PLAY_STORE_URL  (se manca: la pagina di BOB su Google Play)
 *   APP_STORE_URL   (se manca: niente link iPhone)
 */
final class AppStore
{
    public const PLAY_PREDEFINITO = 'https://play.google.com/store/apps/details?id=it.csmontaggi.bob';

    public static function android(): string
    {
        return trim((string)($_ENV['PLAY_STORE_URL'] ?? '')) ?: self::PLAY_PREDEFINITO;
    }

    public static function ios(): string
    {
        return trim((string)($_ENV['APP_STORE_URL'] ?? ''));
    }

    public static function per(string $piattaforma): string
    {
        return $piattaforma === 'ios' ? self::ios() : self::android();
    }
}
