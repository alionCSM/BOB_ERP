<?php

declare(strict_types=1);

namespace App\View;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use App\Infrastructure\Config;

/**
 * Thin wrapper around Twig\Environment.
 *
 * Usage (from Response::view):
 *   $renderer = new TwigRenderer(new LayoutDataProvider(...));
 *   echo $renderer->render('dashboard/index.html.twig', ['pageTitle' => 'Dashboard']);
 */
final class TwigRenderer
{
    private readonly Environment $twig;

    public function __construct(private readonly ?LayoutDataProvider $layoutData)
    {
        $loader = new FilesystemLoader(APP_ROOT . '/templates');

        $isProduction = (new Config())->isProduction();

        $this->twig = new Environment($loader, [
            'autoescape' => 'html',
            'cache'      => $isProduction ? APP_ROOT . '/storage/twig-cache' : false,
            'debug'      => !$isProduction,
        ]);

        $this->registerGlobals();
        $this->registerFunctions();
    }

    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function registerGlobals(): void
    {
        if ($this->layoutData === null) {
            return;
        }

        foreach ($this->layoutData->getData() as $key => $value) {
            $this->twig->addGlobal($key, $value);
        }
    }

    private function registerFunctions(): void
    {
        // {{ asset('/assets/css/app.css') }} → appUrl + path
        $appUrl = (new Config())->appUrl();
        $this->twig->addFunction(new TwigFunction('asset', static function (string $path) use ($appUrl): string {
            return $appUrl . '/' . ltrim($path, '/');
        }));

        // {{ date_fmt('2025-01-15 10:30:00') }}
        $this->twig->addFunction(new TwigFunction('date_fmt', static function (string $dt, string $fmt = 'd/m/Y H:i'): string {
            return date($fmt, strtotime($dt));
        }));

        // {{ u.page|friendly_page }} — humanise a URI for the activity log
        $pageMap = [
            '/dashboard'         => 'Dashboard',
            '/users'             => 'Utenti',
            '/companies'         => 'Aziende',
            '/worksites'         => 'Cantieri',
            '/documents'         => 'Documenti',
            '/documents/expired' => 'Doc. Scaduti',
            '/attendance'        => 'Presenze',
            '/billing'           => 'Fatturazione',
            '/offers'            => 'Offerte',
            '/bookings'          => 'Prenotazioni',
            '/equipment'         => 'Mezzi',
        ];
        $this->twig->addFilter(new TwigFilter('friendly_page', static function (string $raw) use ($pageMap): string {
            $path = parse_url($raw, PHP_URL_PATH) ?: $raw;
            if (isset($pageMap[$path])) {
                return $pageMap[$path];
            }
            $base = basename($path, '.php');
            return ucfirst(str_replace('_', ' ', $base));
        }));

        // {{ r.action|friendly_action }} — humanise an action string
        $this->twig->addFilter(new TwigFilter('friendly_action', static function (string $action): string {
            return match ($action) {
                'page_view' => 'ha visitato',
                'login'     => 'ha effettuato accesso',
                'logout'    => 'ha effettuato uscita',
                'create'    => 'ha creato',
                'update'    => 'ha aggiornato',
                'delete'    => 'ha eliminato',
                default     => $action,
            };
        }));
    }
}
