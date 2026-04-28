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

        $path = $base . DIRECTORY_SEPARATOR . 'Disegni' . DIRECTORY_SEPARATOR . $category;

        if (!is_dir($path)) {
            mkdir($path, 0775, true);
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

    public static function relativeToRoot(string $absolutePath): string
    {
        $root = rtrim(self::root(), DIRECTORY_SEPARATOR);
        return ltrim(str_replace($root, '', $absolutePath), DIRECTORY_SEPARATOR);
    }



}
