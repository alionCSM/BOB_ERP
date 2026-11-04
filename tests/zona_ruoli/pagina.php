<?php
// Al posto di Twig, che nel banco di prova non c'e': la pagina della Zone
// usa solo {% if %} e poche espressioni, e basta questo per vederla.
namespace App\Http;

final class Response
{
    public static function redirect(string $url, int $status = 302): never { http_response_code($status); header('Location: ' . $url); exit; }
    public static function json(mixed $data, int $status = 200): never {
        http_response_code($status); header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
    }
    public static function error(string $m, int $status = 400, ?bool $a = null): never { self::json(['ok' => false, 'error' => $m], $status); }
    public static function view(string $view, Request $request, array $data = []): never
    {
        $data += [
            'csrfToken' => 'prova', 'cspNonce' => 'prova',
            'user' => ['id' => (int)($request->user()->id ?? 0)],
        ];
        $t = file_get_contents(\ERP . '/templates/' . $view);
        echo self::rendi($t, $data);
        exit;
    }

    private static function valore(string $expr, array $v): mixed
    {
        $expr = trim($expr);
        // a ? b : c
        if (preg_match("/^(.+?)\\s*\\?\\s*'([^']*)'\\s*:\\s*'([^']*)'$/", $expr, $m)) {
            return self::vero($m[1], $v) ? $m[2] : $m[3];
        }
        if (preg_match("/^(.+?)\\s*\\?\\s*'\\[' ~ (.+?) ~ '\\] '\\s*:\\s*''$/", $expr, $m)) {
            return self::vero($m[1], $v) ? '[' . self::valore($m[2], $v) . '] ' : '';
        }
        $filtri = array_map('trim', explode('|', $expr));
        $base   = array_shift($filtri);
        $base   = preg_replace('/^\\((.+?)\\s*\\?\\?\\s*(.+)\\)$/', '$1', $base, 1, $conDefault);
        $x = self::percorso($base, $v);
        if ($conDefault && $x === null) $x = '';
        foreach ($filtri as $f) {
            if (str_starts_with($f, 'default')) $x = $x ?? '';
            elseif ($f === 'json_encode') $x = json_encode($x, JSON_UNESCAPED_UNICODE);
            elseif (str_starts_with($f, 'date')) $x = $x ? date('d/m/Y', strtotime((string)$x)) : '';
        }
        return $x;
    }

    private static function percorso(string $p, array $v): mixed
    {
        $x = $v;
        foreach (explode('.', trim($p)) as $k) {
            if (is_array($x) && array_key_exists($k, $x)) $x = $x[$k]; else return null;
        }
        return $x;
    }

    private static function vero(string $c, array $v): bool
    {
        $c = trim($c);
        if (str_starts_with($c, 'not ')) return !self::vero(substr($c, 4), $v);
        if (preg_match('/^([\\w.]+)\\s*>\\s*(\\d+)$/', $c, $m)) return (int)self::percorso($m[1], $v) > (int)$m[2];
        return (bool)self::percorso($c, $v);
    }

    private static function rendi(string $t, array $v): string
    {
        $t = preg_replace('/\\{#.*?#\\}/s', '', $t);
        // gli if, dal piu' interno
        $re = '/\\{%\\s*if\\s+(.+?)\\s*%\\}((?:(?!\\{%\\s*if\\s).)*?)\\{%\\s*endif\\s*%\\}/s';
        while (preg_match($re, $t)) {
            $t = preg_replace_callback($re, function ($m) use ($v) {
                $rami = preg_split('/\\{%\\s*(elseif\\s+.+?|else)\\s*%\\}/s', $m[2], -1, PREG_SPLIT_DELIM_CAPTURE);
                $cond = [$m[1]];
                $corpi = [$rami[0]];
                for ($i = 1; $i < count($rami); $i += 2) {
                    $cond[]  = str_starts_with($rami[$i], 'elseif') ? substr($rami[$i], 7) : 'true';
                    $corpi[] = $rami[$i + 1];
                }
                foreach ($cond as $i => $c) {
                    if ($c === 'true' || self::vero($c, $v)) return $corpi[$i];
                }
                return '';
            }, $t);
        }
        return preg_replace_callback('/\\{\\{(.+?)\\}\\}/s', function ($m) use ($v) {
            $x = self::valore($m[1], $v);
            $grezzo = str_contains($m[1], '|raw');
            return $grezzo ? (string)$x : htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
        }, $t);
    }
}
