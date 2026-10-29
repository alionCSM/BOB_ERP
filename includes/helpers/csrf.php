<?php

/**
 * Generate (or return) the per-request CSP nonce.
 * Stored in $GLOBALS so it survives across includes without a session write.
 */
function csp_nonce(): string
{
    if (empty($GLOBALS['csp_nonce'])) {
        $GLOBALS['csp_nonce'] = base64_encode(random_bytes(16));
    }
    return $GLOBALS['csp_nonce'];
}

/**
 * Generate (or return existing) CSRF token for the current session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token from POST body (_csrf) or X-CSRF-Token header.
 *
 * Si prende la prima fonte che porta qualcosa, non la prima che esiste.
 *
 * Prima era `$_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf'] ?? ''`, e `??`
 * cade solo su null: un header X-CSRF-Token presente ma **vuoto** vinceva, e
 * il token nel corpo non veniva nemmeno guardato. Basta un pezzo di JS che
 * scriva l'header con il valore di un meta tag che non c'e' per far fallire
 * il controllo con il token buono nel corpo della richiesta — un 403 che non
 * si spiega guardando il form.
 */
function csrf_verify(): bool
{
    $submitted = '';

    foreach ([$_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $_POST['_csrf'] ?? null] as $fonte) {
        if (is_string($fonte) && $fonte !== '') {
            $submitted = $fonte;
            break;
        }
    }

    // Un header mandato due volte arriva a PHP come un unico valore con i
    // pezzi separati da virgola ("abc, abc"), e hash_equals lo boccia.
    //
    // Succede piu' facilmente di quanto sembri: footer.js aggiunge
    // 'X-CSRF-Token' a ogni fetch POST con Object.assign, e un pezzo di JS
    // che scriva lo stesso header con un'altra grafia ('X-CSRF-TOKEN')
    // produce DUE chiavi distinte nell'oggetto JavaScript — le chiavi sono
    // sensibili alle maiuscole, gli header HTTP no — che il browser unisce
    // in un header ripetuto. Il token era giusto due volte, e il controllo
    // falliva. Qui si prende il primo valore, che e' la lettura corretta di
    // un header ripetuto.
    if (str_contains($submitted, ',')) {
        $submitted = trim(explode(',', $submitted)[0]);
    }

    return $submitted !== ''
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}
