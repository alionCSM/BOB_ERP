<?php
namespace App\Service;
use RuntimeException;

use Firebase\JWT\JWT;

class OnlyOfficeService
{
    private string $secret;
    private string $serverUrl;

    public function __construct()
    {
        $this->secret    = $_ENV['ONLYOFFICE_JWT_SECRET'] ?? '';
        $this->serverUrl = rtrim($_ENV['ONLYOFFICE_SERVER_URL'] ?? '', '/');

        if (empty($this->secret)) {
            throw new RuntimeException('ONLYOFFICE_JWT_SECRET not configured in .env');
        }
        if (empty($this->serverUrl)) {
            throw new RuntimeException('ONLYOFFICE_SERVER_URL not configured in .env');
        }
    }

    public function buildConfig(array $params): array
    {
        $config = [
            "document" => [
                "fileType" => $params['fileType'],
                "key"      => $params['key'],
                "title"    => $params['title'],
                "url"      => $params['url']
            ],
            "editorConfig" => [
                "mode" => $params['mode'] ?? 'edit',
                "callbackUrl" => $params['callbackUrl'],
                "user" => [
                    "id"   => (string)$params['userId'],
                    "name" => $params['userName']
                ]
            ]
        ];

        $token = JWT::encode($config, $this->secret, 'HS256');

        $config['token'] = $token;

        return $config;
    }
}
