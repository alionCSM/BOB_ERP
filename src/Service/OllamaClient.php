<?php
declare(strict_types=1);

namespace App\Service;
use Exception;

/**
 * Il client verso il server del modello (endpoint compatibile OpenAI).
 *
 * Tre cose che prima mancavano e che costavano caro:
 *
 * - **Timeout separati.** Prima c'era un solo timeout da 60 secondi per
 *   tutto. Se il server del modello era spento, la pagina restava appesa un
 *   minuto per scoprire una cosa che si sa in due secondi; se invece era
 *   accesso e lento, 60 secondi non bastavano e la richiesta moriva a meta'
 *   di una risposta buona. Ora il tempo per *collegarsi* e il tempo per
 *   *rispondere* sono due numeri diversi.
 *
 * - **Il ragionamento spento.** Qwen3 di default scrive il suo ragionamento
 *   dentro `<think>...</think>` prima di rispondere: centinaia o migliaia di
 *   token generati, buttati via subito dopo, che qui non servono a niente
 *   perche' la risposta che ci interessa e' una riga di JSON. Si chiede al
 *   server di non farlo, e quello che arriva comunque si butta.
 *
 * - **Lo streaming.** Non per far comparire le lettere una a una, ma perche'
 *   finche' arrivano byte nessuno chiude la connessione: e' cosi' che si
 *   smette di andare in timeout su una risposta lunga.
 */
class OllamaClient
{
    private string $url;
    private string $model;
    private int $timeout;
    private int $connectTimeout;
    private bool $senzaRagionamento;

    /**
     * @param int  $timeout            secondi per l'intera risposta
     * @param int  $connectTimeout     secondi per collegarsi al server
     * @param bool $senzaRagionamento  chiede al server di non generare <think>
     */
    public function __construct(
        string $url,
        string $model,
        int $timeout = 60,
        int $connectTimeout = 5,
        bool $senzaRagionamento = false,
    ) {
        $this->url               = $url;
        $this->model             = $model;
        $this->timeout           = $timeout;
        $this->connectTimeout    = $connectTimeout;
        $this->senzaRagionamento = $senzaRagionamento;
    }

    /**
     * Send a single prompt (legacy, wraps chat()).
     */
    public function generate(string $prompt): array {
        return $this->chat([
            ['role' => 'user', 'content' => $prompt]
        ]);
    }

    /**
     * Send a multi-turn conversation as an array of messages.
     * Each message: ['role' => 'system'|'user'|'assistant', 'content' => '...']
     *
     * $options: opzionali, sovrascrivono i default. Es:
     *   ['temperature' => 0.1, 'max_tokens' => 200]
     */
    public function chat(array $messages, array $options = []): array {
        return $this->invia($messages, $options, null);
    }

    /**
     * Come chat(), ma legge la risposta mentre arriva.
     *
     * `$onPezzo` viene chiamata a ogni frammento di testo. Serve a due cose:
     * far vedere qualcosa a chi aspetta, e tenere viva la connessione col
     * browser mentre il modello scrive (senza byte in transito, un proxy o
     * il browser stesso chiudono).
     *
     * @param callable(string):void $onPezzo
     */
    public function chatStream(array $messages, array $options, callable $onPezzo): array
    {
        return $this->invia($messages, $options, $onPezzo);
    }

    /**
     * @param null|callable(string):void $onPezzo
     */
    private function invia(array $messages, array $options, ?callable $onPezzo): array
    {
        $t0 = microtime(true);

        try {
            $stream  = $onPezzo !== null;
            $payload = array_merge([
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => 0.2,
                'stream'      => $stream,
            ], $options);

            // Qwen3 e simili: si chiede al template di non aprire il blocco
            // di ragionamento. I server che non conoscono questa chiave la
            // ignorano, quindi resta comunque la pulizia su quello che torna.
            if ($this->senzaRagionamento) {
                $payload['chat_template_kwargs'] = array_merge(
                    ['enable_thinking' => false],
                    $payload['chat_template_kwargs'] ?? []
                );
            }

            $testo    = '';
            $completo = '';

            $ch = curl_init($this->url);
            $opt = [
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: ' . ($stream ? 'text/event-stream' : 'application/json')],
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_TIMEOUT        => $this->timeout,
            ];

            if ($stream) {
                // In streaming non si accumula in memoria: ogni pezzo che
                // arriva viene consegnato subito a chi aspetta.
                $buffer = '';
                $opt[CURLOPT_RETURNTRANSFER] = false;
                $opt[CURLOPT_WRITEFUNCTION]  = function ($ch, string $dati) use (&$buffer, &$testo, $onPezzo): int {
                    $buffer .= $dati;

                    while (($fine = strpos($buffer, "\n")) !== false) {
                        $riga   = substr($buffer, 0, $fine);
                        $buffer = substr($buffer, $fine + 1);

                        $pezzo = $this->pezzoDaRiga(rtrim($riga, "\r"));
                        if ($pezzo === null || $pezzo === '') {
                            continue;
                        }

                        $testo .= $pezzo;
                        $onPezzo($pezzo);
                    }

                    return strlen($dati);
                };
            } else {
                $opt[CURLOPT_RETURNTRANSFER] = true;
            }

            curl_setopt_array($ch, $opt);

            $raw       = curl_exec($ch);
            $latencyMs = (int)round((microtime(true) - $t0) * 1000);

            if ($raw === false && !$stream) {
                $err = curl_error($ch);
                curl_close($ch);
                return ['ok' => false, 'error' => $this->spiegaErrore($err), 'latency_ms' => $latencyMs];
            }

            $errCurl = curl_errno($ch) !== 0 ? curl_error($ch) : '';
            $http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errCurl !== '' && ($stream ? $testo === '' : true)) {
                return ['ok' => false, 'error' => $this->spiegaErrore($errCurl), 'latency_ms' => $latencyMs];
            }

            if ($http < 200 || $http >= 300) {
                return [
                    'ok'         => false,
                    'error'      => "Il server del modello ha risposto {$http}.",
                    'latency_ms' => $latencyMs,
                ];
            }

            if ($stream) {
                $completo = $testo;
            } else {
                $json = json_decode((string)$raw, true);
                if (!is_array($json) || !isset($json['choices'][0]['message']['content'])) {
                    return ['ok' => false, 'error' => 'Formato risposta non valido', 'latency_ms' => $latencyMs];
                }
                $completo = (string)$json['choices'][0]['message']['content'];
            }

            if ($completo === '') {
                return ['ok' => false, 'error' => 'Il modello non ha risposto niente.', 'latency_ms' => $latencyMs];
            }

            return [
                'ok'         => true,
                'response'   => self::senzaRagionamento($completo),
                'latency_ms' => $latencyMs,
            ];
        } catch (Exception $e) {
            return ['ok' => false, 'error' => 'Eccezione: ' . $e->getMessage()];
        }
    }

    /**
     * Estrae il testo da una riga SSE `data: {...}`.
     * Restituisce null per le righe che non portano testo (commenti, [DONE]).
     */
    private function pezzoDaRiga(string $riga): ?string
    {
        if ($riga === '' || !str_starts_with($riga, 'data:')) {
            return null;
        }

        $dati = trim(substr($riga, 5));
        if ($dati === '' || $dati === '[DONE]') {
            return null;
        }

        $json = json_decode($dati, true);
        if (!is_array($json)) {
            return null;
        }

        $delta = $json['choices'][0]['delta'] ?? [];

        // Alcuni server mandano il ragionamento in un campo a parte: va
        // scartato, non concatenato alla risposta.
        return isset($delta['content']) && is_string($delta['content'])
            ? $delta['content']
            : null;
    }

    /**
     * Toglie il blocco di ragionamento se il server l'ha generato comunque.
     * Anche il caso del blocco aperto e mai chiuso, che succede quando la
     * generazione viene tagliata dal limite di token.
     */
    public static function senzaRagionamento(string $testo): string
    {
        $pulito = preg_replace('#<think>.*?</think>#is', '', $testo);
        $pulito = preg_replace('#<think>.*$#is', '', $pulito ?? $testo);

        return trim($pulito ?? $testo);
    }

    /** Messaggi di cURL tradotti in qualcosa che si possa leggere. */
    private function spiegaErrore(string $err): string
    {
        $e = strtolower($err);

        if (str_contains($e, 'timed out') || str_contains($e, 'timeout')) {
            return 'Il modello non ha risposto in tempo.';
        }
        if (str_contains($e, 'could not resolve') || str_contains($e, 'couldn\'t resolve')) {
            return 'Il server del modello non e\' raggiungibile (nome non risolto).';
        }
        if (str_contains($e, 'connection refused') || str_contains($e, 'failed to connect')) {
            return 'Il server del modello non risponde: probabilmente e\' spento.';
        }

        return 'Errore di collegamento al modello: ' . $err;
    }
}
