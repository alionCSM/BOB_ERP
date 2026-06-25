<?php

namespace App\Support;
use RuntimeException;

class CloudPath
{
    private static function root(): string
    {
        $root = $_ENV['CLOUD_ROOT'] ?? getenv('CLOUD_ROOT');

        if (!$root) {
            throw new RuntimeException('CLOUD_ROOT not defined in environment');
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }


    public static function getRoot(): string
    {
        return self::root();
    }

    /**
     * Get the offers upload directory path.
     * Returns: cloud/offers/
     */
    public static function getOffersDir(): string
    {
        return self::root() . DIRECTORY_SEPARATOR . 'offers' . DIRECTORY_SEPARATOR;
    }

    private static function sanitize(string $value): string
    {
        $value = trim($value);
        $value = str_replace(['/', '\\'], '-', $value);
        $value = preg_replace('/[^a-zA-Z0-9 _\-.]/', '', $value);
        return trim($value);
    }

    public static function getDisegniPath(array $worksite): string
    {
        $client = self::sanitize($worksite['client_name']);

        $year = !empty($worksite['start_date'])
            ? date('Y', strtotime($worksite['start_date']))
            : date('Y', strtotime($worksite['created_at']));

        $folderName = self::sanitize(
            $worksite['worksite_code'] . ' - ' . $worksite['worksite_name']
        );

        return implode(DIRECTORY_SEPARATOR, [
            self::root(),
            'Worksites',
            $client,
            $year,
            $folderName,
            'Disegni'
        ]);
    }


    public static function ensureDisegniPath(array $worksite, string $category): string
    {
        $base = self::getBaseWorksitePath($worksite);

        $category = self::sanitize(strtolower($category));
        if ($category === '') {
            $category = 'altri';
        }

        $path = $base . DIRECTORY_SEPARATOR . 'Disegni' . DIRECTORY_SEPARATOR . $category;

        if (!is_dir($path)) {
            // Cattura l'errore reale di mkdir invece di proseguire silenziosamente
            // e far fallire move_uploaded_file con un messaggio generico.
            if (!@mkdir($path, 0775, true) && !is_dir($path)) {
                $err = error_get_last()['message'] ?? 'motivo sconosciuto';
                throw new RuntimeException(
                    "Impossibile creare la cartella disegni: {$path} ({$err}). "
                    . "Verifica CLOUD_ROOT e i permessi di scrittura del web server."
                );
            }
        }

        if (!is_writable($path)) {
            throw new RuntimeException("Cartella disegni non scrivibile: {$path}");
        }

        return $path;
    }



    private static function getBaseWorksitePath(array $worksite): string
    {
        $client = self::sanitize($worksite['client_name']);

        $year = !empty($worksite['start_date'])
            ? date('Y', strtotime($worksite['start_date']))
            : date('Y', strtotime($worksite['created_at']));

        $folderName = self::sanitize(
            $worksite['worksite_code'] . ' - ' . $worksite['worksite_name']
        );

        return implode(DIRECTORY_SEPARATOR, [
            self::root(),
            'Worksites',
            $client,
            $year,
            $folderName
        ]);
    }

    /**
     * Cartella foto BOB Zone per un cantiere: <root>/BOBZone/<worksiteId>/photos
     * Struttura piatta (non serve client/anno): le foto sono allegati operativi.
     */
    public static function ensureZonePhotosDir(int $worksiteId): string
    {
        $path = implode(DIRECTORY_SEPARATOR, [self::root(), 'BOBZone', (string)$worksiteId, 'photos']);
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            $err = error_get_last()['message'] ?? '?';
            throw new RuntimeException("Impossibile creare la cartella foto: {$path} ({$err})");
        }
        if (!is_writable($path)) {
            throw new RuntimeException("Cartella foto non scrivibile: {$path}");
        }
        return $path;
    }

    /** Cartella moduli BOB Zone (firme/foto compilazioni): <root>/BOBZone/<id>/forms */
    public static function ensureZoneFormsDir(int $worksiteId): string
    {
        $path = implode(DIRECTORY_SEPARATOR, [self::root(), 'BOBZone', (string)$worksiteId, 'forms']);
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            $err = error_get_last()['message'] ?? '?';
            throw new RuntimeException("Impossibile creare la cartella moduli: {$path} ({$err})");
        }
        if (!is_writable($path)) {
            throw new RuntimeException("Cartella moduli non scrivibile: {$path}");
        }
        return $path;
    }

    /** Cartella file BOB Zone: <root>/BOBZone/<worksiteId>/files */
    public static function ensureZoneFilesDir(int $worksiteId): string
    {
        $path = implode(DIRECTORY_SEPARATOR, [self::root(), 'BOBZone', (string)$worksiteId, 'files']);
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            $err = error_get_last()['message'] ?? '?';
            throw new RuntimeException("Impossibile creare la cartella file: {$path} ({$err})");
        }
        if (!is_writable($path)) {
            throw new RuntimeException("Cartella file non scrivibile: {$path}");
        }
        return $path;
    }

    public static function relativeToRoot(string $absolutePath): string
    {
        $root = rtrim(self::root(), DIRECTORY_SEPARATOR);
        return ltrim(str_replace($root, '', $absolutePath), DIRECTORY_SEPARATOR);
    }



}
