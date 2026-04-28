<?php

function isPasswordPwned(string $password): bool
{
    $sha1 = strtoupper(sha1($password));
    $prefix = substr($sha1, 0, 5);
    $suffix = substr($sha1, 5);

    $ch = curl_init("https://api.pwnedpasswords.com/range/{$prefix}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_USERAGENT => 'BOB-Password-Check'
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        return false; // fail-open (API down → allow)
    }

    foreach (explode("\n", $response) as $line) {
        [$hashSuffix] = explode(':', trim($line));
        if ($hashSuffix === $suffix) {
            return true;
        }
    }

    return false;
}
