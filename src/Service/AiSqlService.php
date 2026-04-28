<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

class AiSqlService
{
    // Tables that require a specific module permission to query
    private const MODULE_TABLE_MAP = [
        'billing'     => ['bb_billing', 'bb_pagamenti_consorziate'],
        'attendance'  => ['bb_presenze', 'bb_presenze_consorziate', 'bb_advances', 'bb_fines', 'bb_refunds'],
        'clients'     => ['bb_clients'],
        'ordini'      => ['bb_ordini'],
        'offers'      => ['bb_offers', 'bb_offer_items'],
        'bookings'    => ['bb_bookings', 'bb_booking_periods'],
        'equipment'   => ['bb_equipment', 'bb_worksite_equipment'],
        'tickets'     => ['bb_meal_tickets'],
        'documents'   => ['bb_worker_documents', 'bb_documents'],
    ];

    // Price-sensitive columns the AI should never select for restricted users
    private const PRICE_COLUMNS = [
        'importo', 'total', 'totale', 'costo', 'costo_unitario', 'prezzo', 'prezzo_persona',
        'ricavo', 'margine', 'budget', 'ext_total_offer', 'total_offer', 'imponibile',
    ];

    private PDO $mysql;
    private OllamaClient $client;

    public function __construct(PDO $mysql, OllamaClient $client)
    {
        $this->mysql  = $mysql;
        $this->client = $client;
    }

    /**
     * @param array $userContext {
     *   username: string,
     *   full_name: string,
     *   is_superadmin: bool,
     *   allowed_modules: string[]|null,  // null = unrestricted (superadmin)
     *   can_see_prices: bool
     * }
     */
    public function ask(string $question, array $history, array $userContext): array
    {
        $canSeePrices   = $userContext['can_see_prices']  ?? false;
        $isSuperAdmin   = $userContext['is_superadmin']   ?? false;
        $allowedModules = $userContext['allowed_modules'] ?? []; // null = all
        $fullName       = $userContext['full_name']       ?? ($userContext['username'] ?? 'Utente');
        $username       = $userContext['username']        ?? '';

        $schemaMysql = $this->getSchemaInfo();

        // ── Module access section ─────────────────────────────────────────────
        if ($isSuperAdmin || $allowedModules === null) {
            $moduleSection = "ACCESSO MODULI: accesso completo a tutti i moduli.";
        } else {
            $moduleLabels = [
                'attendance'     => 'Presenze',
                'billing'        => 'Fatturazione',
                'bookings'       => 'Prenotazioni',
                'clients'        => 'Clienti',
                'companies'      => 'Aziende/Consorziate',
                'documents'      => 'Documenti',
                'equipment'      => 'Mezzi/Attrezzature',
                'offers'         => 'Offerte',
                'ordini'         => 'Ordini Consorziate',
                'pianificazione' => 'Pianificazione',
                'presenze'       => 'Presenze',
                'tickets'        => 'Buoni Pasto',
                'users'          => 'Operai/Utenti',
                'worksites'      => 'Cantieri',
            ];
            $granted = array_map(
                fn($m) => $moduleLabels[$m] ?? $m,
                $allowedModules
            );
            $denied = [];
            foreach (self::MODULE_TABLE_MAP as $module => $tables) {
                if (!in_array($module, $allowedModules, true)) {
                    $denied[] = implode(', ', $tables);
                }
            }

            $moduleSection  = "MODULI ACCESSIBILI da {$fullName}: " . (empty($granted) ? 'nessuno' : implode(', ', $granted)) . ".\n";
            $moduleSection .= "TABELLE VIETATE (non generare mai query su): " . (empty($denied) ? 'nessuna' : implode(', ', $denied)) . ".";
        }

        // ── Price access section ──────────────────────────────────────────────
        if ($canSeePrices) {
            $priceSection = "DATI ECONOMICI: {$fullName} è autorizzato a vedere prezzi, costi, importi e fatturati. Puoi includere colonne economiche nelle query.";
        } else {
            $priceSection = "RESTRIZIONE ECONOMICA: {$fullName} NON è autorizzato a vedere dati economici.\n"
                . "- Non selezionare MAI colonne: importo, total, costo, costo_unitario, prezzo, prezzo_persona, ricavo, margine, imponibile, budget, ext_total_offer, total_offer.\n"
                . "- Se l'utente chiede prezzi, costi, importi, fatturati, margini, euro → rispondi: \"Non hai i permessi per visualizzare i dati economici.\"\n"
                . "- Puoi rispondere su: quantità, giorni, conteggi, nomi, stati, date — mai euro.";
        }

        $priceListStr   = implode(', ', $userContext['price_access_list'] ?? []);
        $priceStatusStr = $canSeePrices ? 'è' : 'NON è';

        $systemPrompt = <<<PROMPT
Sei BOB AI, l'assistente intelligente del gestionale BOB. Rispondi SEMPRE in italiano.

UTENTE CORRENTE: {$fullName} (username: {$username})

{$moduleSection}

{$priceSection}

CONOSCENZA FISSA — NON INTERROGARE IL DATABASE PER QUESTI FATTI:
- Gli utenti con accesso ai dati economici (prezzi, costi, importi) sono: {$priceListStr}. Questa lista è fissa nel codice — rispondi direttamente senza fare query se ti viene chiesta.
- L'utente corrente ({$username}) {$priceStatusStr} nella lista degli utenti autorizzati ai prezzi.

TABELLE RISERVATE — non generare mai query su:
- bb_user_permissions, bb_user_activity, bb_login_verifications, bb_user_remember_tokens, bb_user_login_history → dati di sessione/permessi, mai esporre
- bb_users.password, bb_users.token → mai selezionare questi campi
- Non mostrare mai dati personali in bulk (liste di utenti con nome+cognome+ruolo) a meno che non sia esplicitamente richiesto per un'analisi operativa

REGOLE SQL:
- Genera SOLO query SELECT (mai INSERT/UPDATE/DELETE/DROP/ALTER/TRUNCATE/CREATE)
- Seleziona SOLO le colonne significative: nomi, codici, stati, date operative, quantità
- NON includere mai: id, created_at, updated_at, deleted_at, uuid, uid, created_by, updated_by, password, remember_token, token
- Usa LIMIT quando non si chiede l'intera lista
- Metti il SQL in un blocco ```sql ... ``` seguito dalla risposta in italiano

SEMANTICA DEL DATABASE:
- bb_worksites → cantieri. Colonne chiave: name, worksite_code, status ('In corso'/'Chiuso'/...), client_id
- bb_companies → aziende/consorziate. consorziata=1 significa consorziata
- bb_presenze → presenze dei lavoratori dipendenti. worksite_id, user_id, data, quantita
- bb_presenze_consorziate → presenze operai di una consorziata. azienda_id=company.id, worksite_id, data_presenza, quantita
- bb_billing → fatture emesse. worksite_id, emessa=1 se già emessa, data, total, client_id
- bb_ordini → ordini emessi alle consorziate. destinatario_id=company.id (consorziata), worksite_id, total, order_date
- bb_pagamenti_consorziate → pagamenti alle consorziate. azienda_id=company.id, worksite_id, importo, data_pagamento
- bb_bookings → prenotazioni alloggi. worksite_id, consorziata_id, a_carico_consorziata=1 se a carico consorziata
- bb_users → operai/utenti interni. name, surname, type, worksite_id
- bb_clients → clienti. name, codice_fiscale

STILE RISPOSTA:
- Inizia con una frase introduttiva ("Ho trovato X cantieri:", "Ecco i risultati per {$fullName}:")
- Se zero risultati: spiega cosa hai cercato e perché potrebbe essere vuoto
- Se 1 risultato: descrivi in prosa
- Se più risultati: usa la tabella markdown

SCHEMA COMPLETO (BOB - MySQL):
{$schemaMysql}
PROMPT;

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $question];

        $result = $this->client->chat($messages);

        if (!$result['ok']) {
            return ['ok' => false, 'error' => $result['error'] ?? 'Errore LLM'];
        }

        $response = $result['response'];
        $sql = $this->extractSql($response);

        if ($sql === null) {
            return ['ok' => true, 'answer' => $response, 'query' => null];
        }

        // Strip trailing semicolons — LLMs almost always add one
        $sql = rtrim(trim($sql), ';');

        $queryResult = $this->executeSafeSelect($sql, $userContext);

        if (!$queryResult['ok']) {
            return ['ok' => false, 'error' => $queryResult['error']];
        }

        $rows = $queryResult['rows'];
        $cols = $queryResult['columns'] ?? [];

        $answer = $this->formatResults($cols, $rows, $canSeePrices);

        return [
            'ok'           => true,
            'answer'       => $answer,
            'query'        => $sql,
            'rows_returned'=> count($rows),
            'db'           => 'mysql',
        ];
    }

    private function extractSql(string $response): ?string
    {
        if (str_contains($response, '```sql')) {
            preg_match('/```sql\s*\n(.*?)\n```/s', $response, $matches);
            if (!empty($matches[1])) {
                return trim($matches[1]);
            }
        }

        foreach (explode("\n", $response) as $line) {
            $line = trim($line);
            if (preg_match('/^\s*SELECT\s/i', $line)) {
                return $line;
            }
        }

        return null;
    }

    private function validateSelect(string $sql): array
    {
        $sql = trim($sql);

        if (!preg_match('/^\s*SELECT\s/i', $sql)) {
            return ['ok' => false, 'error' => 'Solo query SELECT sono permesse'];
        }

        $upper = strtoupper($sql);
        foreach (['INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'EXEC', 'TRUNCATE'] as $kw) {
            if (preg_match('/\b' . $kw . '\b/', $upper)) {
                return ['ok' => false, 'error' => "Query non permessa: $kw"];
            }
        }

        if (str_contains($sql, ';')) {
            return ['ok' => false, 'error' => 'Query multiple non permesse'];
        }

        return ['ok' => true];
    }

    // Internal/security tables that are never queryable regardless of role
    private const ALWAYS_BLOCKED_TABLES = [
        'bb_user_permissions',
        'bb_user_activity',
        'bb_login_verifications',
        'bb_user_remember_tokens',
        'bb_user_login_history',
        'bb_ai_sql_logs',
    ];

    /**
     * Server-side enforcement: block tables the user can't access.
     * This backs up the prompt-level instructions.
     */
    private function checkPermissions(string $sql, array $userContext): ?string
    {
        $sqlLower = strtolower($sql);

        // Always-blocked tables (security/internals) — applies to everyone including superadmin
        foreach (self::ALWAYS_BLOCKED_TABLES as $table) {
            if (preg_match('/\b' . preg_quote($table, '/') . '\b/', $sqlLower)) {
                return "Questa tabella non è accessibile tramite BOB AI.";
            }
        }

        if ($userContext['is_superadmin'] ?? false) {
            return null; // superadmin bypasses module restrictions (but not always-blocked)
        }

        $allowedModules = $userContext['allowed_modules'] ?? [];
        if ($allowedModules === null) {
            return null; // null = unrestricted
        }

        foreach (self::MODULE_TABLE_MAP as $module => $tables) {
            if (!in_array($module, $allowedModules, true)) {
                foreach ($tables as $table) {
                    // Check if table is referenced in the SQL
                    if (preg_match('/\b' . preg_quote($table, '/') . '\b/', $sqlLower)) {
                        return "Non hai accesso al modulo richiesto per questa query.";
                    }
                }
            }
        }

        // Block price columns if user can't see prices
        if (!($userContext['can_see_prices'] ?? false)) {
            foreach (self::PRICE_COLUMNS as $col) {
                // Match col name as a selected column (after SELECT or comma, or in WHERE)
                if (preg_match('/\bSELECT\b.*\b' . preg_quote($col, '/') . '\b/is', $sql)) {
                    return "Non hai i permessi per visualizzare i dati economici.";
                }
            }
        }

        return null; // all clear
    }

    private function executeSafeSelect(string $sql, array $userContext): array
    {
        $validation = $this->validateSelect($sql);
        if (!$validation['ok']) {
            return ['ok' => false, 'error' => $validation['error']];
        }

        $permError = $this->checkPermissions($sql, $userContext);
        if ($permError !== null) {
            return ['ok' => false, 'error' => $permError];
        }

        try {
            $stmt = $this->mysql->query($sql);
            $colCount = $stmt->columnCount();
            $columns = [];
            for ($i = 0; $i < $colCount; $i++) {
                $meta = $stmt->getColumnMeta($i);
                if ($meta !== false) {
                    $columns[] = $meta['name'];
                }
            }

            $rows = [];
            $limit = 200;
            $i = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = $row;
                if (++$i >= $limit) break;
            }

            return ['ok' => true, 'rows' => $rows, 'columns' => $columns];
        } catch (\PDOException $e) {
            error_log('[AI_SQL] query=' . $sql . ' error=' . $e->getMessage());
            return ['ok' => false, 'error' => 'Errore query: ' . $e->getMessage()];
        }
    }

    private function getSchemaInfo(): string
    {
        try {
            $stmt = $this->mysql->query("
                SELECT TABLE_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) as columns
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                GROUP BY TABLE_NAME
                ORDER BY TABLE_NAME
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $info = "-- Tabelle e colonne:\n";
            foreach ($rows as $r) {
                $info .= "  - {$r['TABLE_NAME']}: {$r['columns']}\n";
            }
            return $info;
        } catch (\Throwable $e) {
            return "-- Schema non disponibile: " . $e->getMessage() . "\n";
        }
    }

    private function formatResults(array $columns, array $rows, bool $canSeePrices): string
    {
        if (empty($rows)) {
            return "Nessun risultato trovato.";
        }

        // Columns to always hide
        $alwaysHide = ['id', 'created_at', 'updated_at', 'deleted_at', 'uuid', 'uid',
                       'created_by', 'updated_by', 'password', 'remember_token'];

        // Also hide price columns if user can't see prices
        $hideCols = $alwaysHide;
        if (!$canSeePrices) {
            $hideCols = array_merge($hideCols, self::PRICE_COLUMNS);
        }

        $displayCols = array_values(array_filter(
            $columns,
            fn($c) => !in_array(strtolower($c), array_map('strtolower', $hideCols), true)
        ));

        if (empty($displayCols)) {
            $displayCols = $columns; // fallback
        }
        $displayCols = array_slice($displayCols, 0, 7);

        $count = count($rows);

        // Single row → prose list
        if ($count === 1) {
            $row = $rows[0];
            $lines = [];
            foreach ($displayCols as $col) {
                $val = $row[$col] ?? '';
                $val = ($val === null || $val === '') ? '—' : (string)$val;
                $label = ucfirst(str_replace('_', ' ', $col));
                $lines[] = "**{$label}:** {$val}";
            }
            return implode("\n", $lines);
        }

        // Markdown table
        $headers = array_map(fn($c) => ucfirst(str_replace('_', ' ', $c)), $displayCols);
        $table   = "| " . implode(" | ", $headers) . " |\n";
        $table  .= "| " . implode(" | ", array_fill(0, count($displayCols), "---")) . " |\n";

        foreach ($rows as $row) {
            $vals = [];
            foreach ($displayCols as $col) {
                $val = $row[$col] ?? '';
                $val = ($val === null) ? '—' : (string)$val;
                $val = mb_substr($val, 0, 50);
                $val = str_replace('|', '\\|', $val);
                $vals[] = $val;
            }
            $table .= "| " . implode(" | ", $vals) . " |\n";
        }

        $footer = '';
        if (count($columns) > 7) {
            $footer .= "\n*(" . count($columns) . " colonne totali, mostrate 7)*";
        }
        if ($count >= 200) {
            $footer .= "\n*(Risultati limitati a 200 righe)*";
        }

        return $table . $footer;
    }
}
