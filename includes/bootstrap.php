<?php
// includes/bootstrap.php

defined('APP_ROOT') || define('APP_ROOT', dirname(__DIR__));

require_once __DIR__ . '/../vendor/autoload.php';

// APP_ROOT is always set to the directory whose PARENT contains .env
// (main app: public/index.php sets APP_ROOT = repo root's parent of public/,
//  portal: portal/index.php sets APP_ROOT = public/).
// So dirname(APP_ROOT) reliably points to where .env lives.
$dotenv = Dotenv\Dotenv::createImmutable(dirname(APP_ROOT));
$dotenv->load();

// hard fail if required env vars missing
\App\Infrastructure\Config::validate();
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure',   '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

// ── Backward-compatibility aliases ──────────────────────────────────────────
// These allow legacy code in views/, controllers/, ajax/, portal/, public/
// to reference classes by their short name without a namespace.

// Domain
class_alias(\App\Domain\Billing::class,             'Billing');
class_alias(\App\Domain\Extra::class,               'Extra');
// App\Domain\Offer was removed (refactored into App\Service\Offers\OfferManagementService)
class_alias(\App\Infrastructure\SqlServerConnection::class, 'SQLServer');
class_alias(\App\Domain\User::class,                'User');
class_alias(\App\Domain\UserActivity::class,        'UserActivity');
class_alias(\App\Domain\UserAnalytics::class,       'UserAnalytics');
class_alias(\App\Domain\Worksite::class,            'Worksite');
class_alias(\App\Domain\WorksiteStats::class,       'WorksiteStats');
class_alias(\App\Domain\YardWorksite::class,        'YardWorksite');
class_alias(\App\Domain\YardWorksiteBilling::class, 'YardWorksiteBilling');
class_alias(\App\Domain\YardWorksiteExtra::class,   'YardWorksiteExtra');

// Infrastructure / Support
class_alias(\App\Infrastructure\Database::class,   'Database');
class_alias(\App\Support\CloudPath::class,         'CloudPath');

// Services
class_alias(\App\Service\AnomalyCheckerService::class,      'AnomalyCheckerService');
class_alias(\App\Service\AttendanceService::class,          'AttendanceService');
class_alias(\App\Service\AuditLogger::class,                'AuditLogger');
class_alias(\App\Service\DocumentExpiryAlertService::class, 'DocumentExpiryAlertService');
class_alias(\App\Service\Mailer::class,                     'Mailer');
class_alias(\App\Service\OllamaClient::class,               'OllamaClient');
class_alias(\App\Service\OnlyOfficeService::class,          'OnlyOfficeService');
class_alias(\App\Service\RateLimiter::class,                'RateLimiter');
class_alias(\App\Service\WorksiteAIService::class,          'WorksiteAIService');
class_alias(\App\Service\WorksiteMarginService::class,      'WorksiteMarginService');
class_alias(\App\Service\WorksiteContextBuilder::class,     'WorksiteContextBuilder');
class_alias(\App\Service\YardWorksiteStatusService::class,  'YardWorksiteStatusService');
class_alias(\App\Service\Bookings\BookingService::class,    'BookingService');
class_alias(\App\Service\Clients\ClientService::class,      'ClientService');
class_alias(\App\Service\Companies\CompanyService::class,   'CompanyService');
class_alias(\App\Service\Documents\WorkerDocumentService::class,  'WorkerDocumentService');
class_alias(\App\Service\Offers\OfferManagementService::class,    'OfferManagementService');
class_alias(\App\Service\Share\SharedLinkService::class,          'SharedLinkService');
class_alias(\App\Service\Tickets\MealTicketService::class,        'MealTicketService');
class_alias(\App\Service\Workers\WorkerManagementService::class,  'WorkerManagementService');

// Repositories
class_alias(\App\Repository\AttendanceRepository::class,                   'AttendanceRepository');
class_alias(\App\Repository\Attendance\AdvanceRepository::class,           'AdvanceRepository');
class_alias(\App\Repository\Attendance\FineRepository::class,              'FineRepository');
class_alias(\App\Repository\Attendance\RefundRepository::class,            'RefundRepository');
class_alias(\App\Repository\Bookings\BookingRepository::class,             'BookingRepository');
class_alias(\App\Repository\Bookings\StrutturaRepository::class,           'StrutturaRepository');
class_alias(\App\Repository\Clients\ClientRepository::class,               'ClientRepository');
class_alias(\App\Repository\Companies\CompanyRepository::class,            'CompanyRepository');
class_alias(\App\Repository\Documents\WorkerDocumentRepository::class,     'WorkerDocumentRepository');
class_alias(\App\Repository\Offers\OfferRepository::class,                 'OfferRepository');
class_alias(\App\Repository\Share\SharedLinkRepository::class,             'SharedLinkRepository');
class_alias(\App\Repository\Tickets\MealTicketRepository::class,           'MealTicketRepository');
class_alias(\App\Repository\Workers\WorkerRepository::class,               'WorkerRepository');

// Security
class_alias(\App\Security\AccessProfileResolver::class,  'AccessProfileResolver');
class_alias(\App\Security\AuthorizationService::class,   'AuthorizationService');
class_alias(\App\Security\CapabilityService::class,      'CapabilityService');
class_alias(\App\Security\RoutePolicyMap::class,         'RoutePolicyMap');
class_alias(\App\Security\ScopeService::class,           'ScopeService');

// Validators
class_alias(\App\Validator\AttendanceValidator::class,                      'AttendanceValidator');
class_alias(\App\Validator\Clients\ClientValidator::class,                  'ClientValidator');
class_alias(\App\Validator\Companies\CompanyValidator::class,               'CompanyValidator');
class_alias(\App\Validator\Documents\WorkerDocumentUpdateValidator::class,  'WorkerDocumentUpdateValidator');
class_alias(\App\Validator\Documents\WorkerDocumentUploadValidator::class,  'WorkerDocumentUploadValidator');
class_alias(\App\Validator\Offers\OfferPayloadValidator::class,             'OfferPayloadValidator');
class_alias(\App\Validator\Share\CreateShareLinkValidator::class,           'CreateShareLinkValidator');
class_alias(\App\Validator\Workers\WorkerCreateValidator::class,            'WorkerCreateValidator');