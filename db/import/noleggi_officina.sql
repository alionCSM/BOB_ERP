-- ============================================================================
-- BOB — Import autocarrate e noleggi dal vecchio database noleggi_officina
-- ============================================================================
--
-- Alternativa allo script PHP, per quando l'utente dell'applicazione non ha
-- accesso al vecchio database: queste query si lanciano con un utente che
-- vede entrambe le basi, che stanno sullo stesso server.
--
-- PRIMA DI TUTTO:
--   1. applicare le migration (servono le colonne origine_id e
--      commerciale_testo, altrimenti la parte 3 non parte);
--   2. posizionarsi sul database di BOB:  USE nome_db_bob;
--      Le tabelle vecchie restano indicate con il prefisso noleggi_officina.
--   3. impostare la societa' di destinazione qui sotto.
--
-- Le parti 1 e 2 leggono soltanto. La 3 scrive, ed e' ripetibile: le righe
-- gia' importate si riconoscono da origine_id e non vengono duplicate.
-- ============================================================================


-- Id della societa' del gruppo di destinazione (Poti Noleggi).
-- Si legge dalla pagina Societa' del gruppo, oppure con la query qui sotto.
SELECT id, nome, codice FROM bb_group_companies ORDER BY id;

SET @soc := 2;   -- <<< METTERE QUI L'ID GIUSTO


-- ============================================================================
-- PARTE 1 — Cosa c'e' nel vecchio database (sola lettura)
-- ============================================================================

-- 1.1 Quantita' complessive
SELECT (SELECT COUNT(*) FROM noleggi_officina.mezzi_soll) AS mezzi_totali,
       (SELECT COUNT(*) FROM noleggi_officina.mezzi_soll WHERE descr LIKE '%autocarr%') AS autocarrate,
       (SELECT COUNT(*) FROM noleggi_officina.mov_mezzi)  AS movimenti_totali;

-- 1.2 Tipi di mezzo presenti: serve a confermare che "AUTOCARRATA" e' scritto
--     sempre allo stesso modo e che non ci siano varianti sfuggite al filtro
SELECT descr, COUNT(*) AS quanti
FROM noleggi_officina.mezzi_soll
GROUP BY descr
ORDER BY quanti DESC;

-- 1.3 mezzi_soll.stato — quali valori esistono
--     (l'import considera 1 = attiva, tutto il resto = dismessa)
SELECT stato, COUNT(*) AS quanti
FROM noleggi_officina.mezzi_soll
GROUP BY stato ORDER BY quanti DESC;

-- 1.4 Matricole mancanti o doppie fra le autocarrate.
--     La matricola diventa la targa, che in BOB e' unica per societa':
--     le righe senza matricola non si possono importare, quelle doppie
--     entrano una volta sola.
SELECT COUNT(*)                                             AS autocarrate,
       SUM(matricola IS NULL OR TRIM(matricola) = '')       AS senza_matricola,
       COUNT(DISTINCT UPPER(TRIM(matricola)))               AS matricole_distinte
FROM noleggi_officina.mezzi_soll
WHERE descr LIKE '%autocarr%';

-- 1.5 Quali matricole sono ripetute
SELECT UPPER(TRIM(matricola)) AS matricola, COUNT(*) AS quante,
       GROUP_CONCAT(id ORDER BY id) AS id_mezzi
FROM noleggi_officina.mezzi_soll
WHERE descr LIKE '%autocarr%' AND TRIM(COALESCE(matricola,'')) <> ''
GROUP BY UPPER(TRIM(matricola))
HAVING COUNT(*) > 1;

-- 1.6 mov_mezzi.stato e tipo — quali valori esistono.
--     Se uno degli stati indica le prenotazioni annullate va detto, perche'
--     l'import le porta tutte come confermate.
SELECT stato, COUNT(*) AS quanti FROM noleggi_officina.mov_mezzi GROUP BY stato ORDER BY quanti DESC;
SELECT tipo,  COUNT(*) AS quanti FROM noleggi_officina.mov_mezzi GROUP BY tipo  ORDER BY quanti DESC;

-- 1.7 Com'e' scritto l'importo (nel vecchio database e' un VARCHAR)
SELECT importo, COUNT(*) AS quanti
FROM noleggi_officina.mov_mezzi
WHERE COALESCE(importo,'') <> ''
GROUP BY importo ORDER BY quanti DESC LIMIT 30;

-- 1.8 Date mancanti o incoerenti fra i noleggi delle autocarrate
SELECT COUNT(*)                     AS noleggi,
       SUM(m.inizio IS NULL)        AS senza_inizio,
       SUM(m.fine   IS NULL)        AS senza_fine,
       SUM(m.fine < m.inizio)       AS fine_prima_di_inizio,
       MIN(m.inizio)                AS piu_vecchio,
       MAX(m.fine)                  AS piu_recente
FROM noleggi_officina.mov_mezzi m
JOIN noleggi_officina.mezzi_soll s ON s.id = m.mezzo_id
WHERE s.descr LIKE '%autocarr%';

-- 1.9 Commerciali presenti (finiranno in commerciale_testo)
SELECT commerc, COUNT(*) AS quanti
FROM noleggi_officina.mov_mezzi
WHERE COALESCE(commerc,'') <> ''
GROUP BY commerc ORDER BY quanti DESC;


-- ============================================================================
-- PARTE 2 — Prova a vuoto: quante righe entrerebbero e quante no
-- ============================================================================

-- 2.1 Autocarrate che entrerebbero
SELECT COUNT(*) AS mezzi_da_inserire FROM (
    SELECT MIN(s.id) AS id
    FROM noleggi_officina.mezzi_soll s
    WHERE s.descr LIKE '%autocarr%'
      AND TRIM(COALESCE(s.matricola,'')) <> ''
    GROUP BY UPPER(TRIM(s.matricola))
) x
WHERE NOT EXISTS (
    SELECT 1 FROM pn_autocarrate a
    WHERE a.group_company_id = @soc AND a.origine_id = x.id
);

-- 2.2 Noleggi che entrerebbero, e quelli che restano fuori con il motivo
SELECT
    SUM(m.inizio IS NOT NULL AND m.fine IS NOT NULL)                    AS con_date_ok,
    SUM(m.inizio IS NULL OR m.fine IS NULL)                             AS scartati_senza_date,
    SUM(COALESCE(m.importo,'') <> ''
        AND REGEXP_REPLACE(m.importo, '[^0-9,.-]', '') = '')            AS importi_illeggibili
FROM noleggi_officina.mov_mezzi m
JOIN noleggi_officina.mezzi_soll s ON s.id = m.mezzo_id
WHERE s.descr LIKE '%autocarr%';

-- 2.3 Noleggi che punterebbero a un mezzo non importato (matricola mancante
--     o mezzo cancellato): sarebbero persi, quindi meglio saperlo prima
SELECT COUNT(*) AS noleggi_senza_mezzo
FROM noleggi_officina.mov_mezzi m
LEFT JOIN noleggi_officina.mezzi_soll s
       ON s.id = m.mezzo_id AND s.descr LIKE '%autocarr%'
                            AND TRIM(COALESCE(s.matricola,'')) <> ''
WHERE s.id IS NULL
  AND m.mezzo_id IN (SELECT id FROM noleggi_officina.mezzi_soll WHERE descr LIKE '%autocarr%');


-- ============================================================================
-- PARTE 3 — Import vero (scrive)
-- ============================================================================
-- Lanciare 3.1 e poi 3.2, in quest'ordine: le prenotazioni si agganciano ai
-- mezzi tramite origine_id, quindi i mezzi devono esserci gia'.

START TRANSACTION;

-- 3.1 Autocarrate
--     La matricola diventa la targa. Il GROUP BY tiene una riga sola per
--     matricola: e' unica per societa', e senza raggruppamento due mezzi con
--     la stessa matricola farebbero fallire tutto l'insert.
-- Il COLLATE non e' un vezzo: BOB usa utf8mb4_unicode_ci e il vecchio
-- database utf8mb4_0900_ai_ci, quindi ogni confronto fra testo delle due
-- basi va dichiarato, altrimenti MySQL si ferma con "Illegal mix of
-- collations".
INSERT INTO pn_autocarrate
    (group_company_id, targa, modello, note, stato, origine_id)
SELECT
    @soc,
    UPPER(TRIM(s.matricola))                    COLLATE utf8mb4_unicode_ci,
    NULLIF(TRIM(COALESCE(s.modello, '')), '')   COLLATE utf8mb4_unicode_ci,
    NULLIF(TRIM(COALESCE(s.descr,   '')), '')   COLLATE utf8mb4_unicode_ci,
    CASE WHEN s.stato = 1 THEN 'attiva' ELSE 'dismessa' END,
    s.id
FROM noleggi_officina.mezzi_soll s
JOIN (
    SELECT MIN(id) AS id
    FROM noleggi_officina.mezzi_soll
    WHERE descr LIKE '%autocarr%'
      AND TRIM(COALESCE(matricola, '')) <> ''
    GROUP BY UPPER(TRIM(matricola))
) uno ON uno.id = s.id
WHERE NOT EXISTS (
        SELECT 1 FROM pn_autocarrate a
        WHERE a.group_company_id = @soc AND a.origine_id = s.id)
  AND NOT EXISTS (
        SELECT 1 FROM pn_autocarrate a
        WHERE a.group_company_id = @soc
          AND a.targa = UPPER(TRIM(s.matricola)) COLLATE utf8mb4_unicode_ci);

-- 3.2 Prenotazioni
--     LEAST/GREATEST raddrizza le date invertite invece di scartare la riga.
--     L'importo si ripulisce dei simboli e si converte solo se resta un
--     numero: altrimenti NULL, perche' uno zero non si distinguerebbe da un
--     importo davvero a zero.
INSERT INTO pn_prenotazioni
    (group_company_id, autocarrata_id, cliente, luogo, data_inizio, data_fine,
     stato, totale, note, contratto, commerciale_testo, pagamento, origine_id)
SELECT
    @soc,
    a.id,
    COALESCE(NULLIF(TRIM(m.cliente), ''), 'senza nome') COLLATE utf8mb4_unicode_ci,
    NULLIF(TRIM(COALESCE(m.cantiere, '')), '')          COLLATE utf8mb4_unicode_ci,
    LEAST(m.inizio, m.fine),
    GREATEST(m.inizio, m.fine),
    'confermata',
    CASE
        WHEN num.pulito REGEXP '^-?[0-9]+(\\.[0-9]+)?$' THEN CAST(num.pulito AS DECIMAL(10,2))
        ELSE NULL
    END,
    NULLIF(TRIM(COALESCE(m.note,      '')), '') COLLATE utf8mb4_unicode_ci,
    NULLIF(TRIM(COALESCE(m.contratto, '')), '') COLLATE utf8mb4_unicode_ci,
    NULLIF(TRIM(COALESCE(m.commerc,   '')), '') COLLATE utf8mb4_unicode_ci,
    'da_pagare',
    m.id
FROM noleggi_officina.mov_mezzi m
JOIN pn_autocarrate a
      ON a.group_company_id = @soc
     AND a.origine_id = m.mezzo_id
JOIN (
    -- ripulitura dell'importo: via i simboli, poi il formato italiano
    -- 1.234,56 diventa 1234.56
    SELECT id,
           CASE
               WHEN REGEXP_REPLACE(COALESCE(importo, ''), '[^0-9,.-]', '') LIKE '%,%'
                   THEN REPLACE(REPLACE(REGEXP_REPLACE(COALESCE(importo, ''), '[^0-9,.-]', ''), '.', ''), ',', '.')
               ELSE REGEXP_REPLACE(COALESCE(importo, ''), '[^0-9,.-]', '')
           END AS pulito
    FROM noleggi_officina.mov_mezzi
) num ON num.id = m.id
WHERE m.inizio IS NOT NULL
  AND m.fine   IS NOT NULL
  AND NOT EXISTS (
        SELECT 1 FROM pn_prenotazioni p
        WHERE p.group_company_id = @soc AND p.origine_id = m.id);

-- Controllare i due conteggi qui sotto PRIMA di confermare.
SELECT COUNT(*) AS autocarrate_in_bob  FROM pn_autocarrate  WHERE group_company_id = @soc;
SELECT COUNT(*) AS prenotazioni_in_bob FROM pn_prenotazioni WHERE group_company_id = @soc;

-- Se i numeri tornano:
COMMIT;
-- Se qualcosa non va, al posto del COMMIT:
-- ROLLBACK;


-- ============================================================================
-- PARTE 4 — Verifiche dopo l'import
-- ============================================================================

-- 4.1 Prenotazioni sovrapposte sullo stesso mezzo.
--     BOB le impedisce quando si inserisce a mano, ma i dati vecchi possono
--     contenerne: sono state importate lo stesso, per non perdere storico.
SELECT p1.id, p2.id AS id_2, a.targa,
       p1.cliente AS cliente_1, p1.data_inizio, p1.data_fine,
       p2.cliente AS cliente_2, p2.data_inizio AS inizio_2, p2.data_fine AS fine_2
FROM pn_prenotazioni p1
JOIN pn_prenotazioni p2
      ON p2.autocarrata_id = p1.autocarrata_id
     AND p2.id > p1.id
     AND p1.data_inizio <= p2.data_fine
     AND p1.data_fine   >= p2.data_inizio
JOIN pn_autocarrate a ON a.id = p1.autocarrata_id
WHERE p1.group_company_id = @soc
  AND p1.stato <> 'annullata' AND p2.stato <> 'annullata'
ORDER BY a.targa, p1.data_inizio;

-- 4.2 Righe importate senza totale, da ricontrollare a mano
SELECT p.id, p.origine_id, a.targa, p.cliente, p.data_inizio, p.data_fine
FROM pn_prenotazioni p
JOIN pn_autocarrate a ON a.id = p.autocarrata_id
WHERE p.group_company_id = @soc AND p.origine_id IS NOT NULL AND p.totale IS NULL
ORDER BY p.data_inizio DESC;

-- 4.3 Se un valore di mov_mezzi.stato indica le annullate, si sistemano dopo
--     (mettere al posto di 9 il valore giusto):
-- UPDATE pn_prenotazioni p
-- JOIN noleggi_officina.mov_mezzi m ON m.id = p.origine_id
-- SET p.stato = 'annullata'
-- WHERE p.group_company_id = @soc AND m.stato = 9;
