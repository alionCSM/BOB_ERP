# Prova dei ruoli della Zone

Chi vede e chi fa cosa, provato con i controller veri: ufficio, capo
squadra, cliente, un secondo cliente che entra dopo, e un cliente di un
altro committente. Database di prova a parte (`zonatest`), email finte.

```bash
mysql -u$USER < tests/zona_ruoli/schema.sql
php -S 127.0.0.1:8833 tests/zona_ruoli/router.php &
python3 tests/zona_ruoli/prova.py
```

Si rilancia dopo ogni modifica a permessi, condivisioni o avvisi della
Zone. Ogni riga dice cosa si aspetta: se una diventa ✗, o e' cambiata la
regola (e allora si aggiorna la prova) o si e' rotto qualcosa.
