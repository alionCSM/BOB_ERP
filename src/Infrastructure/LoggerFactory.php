<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Processor\WebProcessor;
use Monolog\Processor\IntrospectionProcessor;
use Psr\Log\LoggerInterface;

/**
 * LoggerFactory — creates configured Monolog loggers per channel.
 *
 * Channels:
 *   - app      — general application logs
 *   - auth     — authentication and authorization events
 *   - api      — external API calls (Ollama, etc.)
 *   - database — database operations
 *   - mail     — email sending
 *   - security — security-related events
 *
 * Usage:
 *   use App\Infrastructure\LoggerFactory;
 *
 *   $logger = LoggerFactory::channel('app');
 *   $logger->info('User logged in', ['user_id' => 123]);
 *
 *   $authLogger = LoggerFactory::channel('auth');
 *   $authLogger->warning('Failed login attempt', ['username' => 'admin']);
 */
class LoggerFactory
{
    private static array $loggers = [];

    /**
     * Get a logger for a specific channel.
     *
     * @param string $channel One of: app, auth, api, database, mail, security
     * @return LoggerInterface
     */
    public static function channel(string $channel = 'app'): LoggerInterface
    {
        if (isset(self::$loggers[$channel])) {
            return self::$loggers[$channel];
        }

        $config = new Config();
        $environment = $config->appEnv();
        $isProduction = $environment === 'production';

        $logger = new Logger($channel);

        // Log directory — APP_ROOT is public/, so storage/logs lives inside it
        $logDir = (defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 2)) . '/storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Add processors
        $logger->pushProcessor(new WebProcessor());

        // In development, log to stderr only (no file permissions needed)
        if (!$isProduction) {
            $logLevel = 'debug';
            $stderrHandler = new StreamHandler('php://stderr', Logger::DEBUG);
            $stderrHandler->setFormatter(new \Monolog\Formatter\LineFormatter(
                "[%datetime%] [{$channel}] [%level_name%] %message%",
                null,
                true,
                true
            ));
            $logger->pushHandler($stderrHandler);
        } else {
            // Production: file logging
            $logLevel = 'notice';
            $logger->pushProcessor(new IntrospectionProcessor($logLevel));
            $handler = new RotatingFileHandler(
                $logDir . "/{$channel}.log",
                7,
                $logLevel,
                false,
                10485760
            );
            $handler->setFormatter(new \Monolog\Formatter\LineFormatter(
                "[%datetime%] [{$channel}] [%level_name%] %message% %context% %extra%",
                null,
                true,
                true
            ));
            $logger->pushHandler($handler);
        }

        self::$loggers[$channel] = $logger;

        return $logger;
    }

    /**
     * Get the main application logger.
     *
     * @return LoggerInterface
     */
    public static function app(): LoggerInterface
    {
        return self::channel('app');
    }

    /**
     * Get the authentication logger.
     *
     * @return LoggerInterface
     */
    public static function auth(): LoggerInterface
    {
        return self::channel('auth');
    }

    /**
     * Get the API logger.
     *
     * @return LoggerInterface
     */
    public static function api(): LoggerInterface
    {
        return self::channel('api');
    }

    /**
     * Get the database logger.
     *
     * @return LoggerInterface
     */
    public static function database(): LoggerInterface
    {
        return self::channel('database');
    }

    /**
     * Get the mail logger.
     *
     * @return LoggerInterface
     */
    public static function mail(): LoggerInterface
    {
        return self::channel('mail');
    }

    /**
     * Get the security logger.
     *
     * @return LoggerInterface
     */
    public static function security(): LoggerInterface
    {
        return self::channel('security');
    }
}
