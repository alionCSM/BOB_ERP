<?php
declare(strict_types=1);

/**
 * Un Phinx piccolo, per far girare le migrazioni vere su un database di
 * prova senza avere vendor/ installato.
 *
 * Perche' non scrivere a mano le CREATE TABLE: uno schema scritto a mano
 * diverge da quello vero, e un test che passa su uno schema sbagliato non
 * dimostra niente. Qui le tabelle nascono dalle stesse migrazioni che
 * girano in produzione.
 *
 * Copre quello che le migrazioni della Zone usano davvero: table(),
 * addColumn, addIndex, create/update/save, changeColumn, removeColumn,
 * drop, hasTable, hasColumn, execute. Niente di piu'.
 */

namespace Phinx\Migration {
    abstract class AbstractMigration
    {
        public \PDO $pdo;

        public function table(string $nome, array $opzioni = []): \MiniPhinx\Tabella
        {
            return new \MiniPhinx\Tabella($this->pdo, $nome, $opzioni);
        }

        public function hasTable(string $nome): bool
        {
            return \MiniPhinx\Schema::tabellaEsiste($this->pdo, $nome);
        }

        public function execute(string $sql): void
        {
            $this->pdo->exec($sql);
        }

        public function query(string $sql)
        {
            return $this->pdo->query($sql);
        }

        public function fetchRow(string $sql)
        {
            return $this->pdo->query($sql)->fetch(\PDO::FETCH_ASSOC);
        }

        public function fetchAll(string $sql): array
        {
            return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        }

        public function getAdapter(): \MiniPhinx\Adattatore
        {
            return new \MiniPhinx\Adattatore($this->pdo);
        }

        /**
         * Phinx accetta up() oppure change(): alcune migrazioni della Zone
         * usano la seconda. Qui una chiama l'altra, invece di pretendere
         * che tutte si scrivano allo stesso modo.
         */
        public function up(): void
        {
            if (method_exists($this, 'change')) {
                $this->change();
            }
        }
    }
}

namespace MiniPhinx {

    final class Schema
    {
        public static function tabellaEsiste(\PDO $pdo, string $nome): bool
        {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables
                                 WHERE table_schema = DATABASE() AND table_name = :t');
            $st->execute([':t' => $nome]);
            return (int)$st->fetchColumn() > 0;
        }

        public static function colonnaEsiste(\PDO $pdo, string $tabella, string $colonna): bool
        {
            $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns
                                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c');
            $st->execute([':t' => $tabella, ':c' => $colonna]);
            return (int)$st->fetchColumn() > 0;
        }
    }

    final class Adattatore
    {
        public function __construct(private \PDO $pdo) {}

        public function hasColumn(string $tabella, string $colonna): bool
        {
            return Schema::colonnaEsiste($this->pdo, $tabella, $colonna);
        }

        public function hasTable(string $tabella): bool
        {
            return Schema::tabellaEsiste($this->pdo, $tabella);
        }

        /** Qualche migrazione scende fino al PDO per fare da se'. */
        public function getConnection(): \PDO
        {
            return $this->pdo;
        }
    }

    final class Tabella
    {
        private array $colonne   = [];
        private array $indici    = [];
        private array $cambi     = [];
        private array $rimozioni = [];

        public function __construct(private \PDO $pdo, private string $nome, private array $opzioni = []) {}

        public function addColumn(string $nome, string $tipo, array $o = []): self
        {
            $this->colonne[] = [$nome, $tipo, $o];
            return $this;
        }

        public function changeColumn(string $nome, string $tipo, array $o = []): self
        {
            $this->cambi[] = [$nome, $tipo, $o];
            return $this;
        }

        public function removeColumn(string $nome): self
        {
            $this->rimozioni[] = $nome;
            return $this;
        }

        public function addIndex(array $colonne, array $o = []): self
        {
            $this->indici[] = [$colonne, $o];
            return $this;
        }

        /** Le chiavi esterne non servono: l'utente MySQL di produzione non ha REFERENCES. */
        public function addForeignKey(...$a): self { return $this; }

        public function hasColumn(string $colonna): bool
        {
            return Schema::colonnaEsiste($this->pdo, $this->nome, $colonna);
        }

        public function hasIndex(array $colonne): bool { return false; }

        public function hasIndexByName(string $nome): bool
        {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics
                                       WHERE table_schema = DATABASE()
                                         AND table_name = :t AND index_name = :i');
            $st->execute([':t' => $this->nome, ':i' => $nome]);
            return (int)$st->fetchColumn() > 0;
        }

        public function create(): void
        {
            $pezzi = [];
            $chiave = $this->opzioni['primary_key'] ?? 'id';

            if (($this->opzioni['id'] ?? true) !== false) {
                $pezzi[] = '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT';
            }
            foreach ($this->colonne as [$nome, $tipo, $o]) {
                $pezzi[] = $this->definizione($nome, $tipo, $o);
            }
            if ($chiave) {
                $cols = is_array($chiave) ? $chiave : [$chiave];
                $pezzi[] = 'PRIMARY KEY (`' . implode('`, `', $cols) . '`)';
            }
            foreach ($this->indici as [$cols, $o]) {
                $pezzi[] = (!empty($o['unique']) ? 'UNIQUE ' : '') . 'KEY `'
                    . ($o['name'] ?? ('idx_' . implode('_', $cols))) . '` (`'
                    . implode('`, `', $cols) . '`)';
            }

            $sql = "CREATE TABLE `{$this->nome}` (\n  " . implode(",\n  ", $pezzi)
                 . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            $this->pdo->exec($sql);
        }

        public function update(): void { $this->save(); }

        public function save(): void
        {
            foreach ($this->colonne as [$nome, $tipo, $o]) {
                $this->pdo->exec("ALTER TABLE `{$this->nome}` ADD COLUMN " . $this->definizione($nome, $tipo, $o));
            }
            foreach ($this->cambi as [$nome, $tipo, $o]) {
                $this->pdo->exec("ALTER TABLE `{$this->nome}` MODIFY COLUMN " . $this->definizione($nome, $tipo, $o));
            }
            foreach ($this->rimozioni as $nome) {
                $this->pdo->exec("ALTER TABLE `{$this->nome}` DROP COLUMN `$nome`");
            }
            foreach ($this->indici as [$cols, $o]) {
                $this->pdo->exec("ALTER TABLE `{$this->nome}` ADD "
                    . (!empty($o['unique']) ? 'UNIQUE ' : '') . 'INDEX `'
                    . ($o['name'] ?? ('idx_' . implode('_', $cols))) . '` (`'
                    . implode('`, `', $cols) . '`)');
            }
            $this->colonne = $this->cambi = $this->rimozioni = $this->indici = [];
        }

        public function drop(): self
        {
            $this->pdo->exec("DROP TABLE IF EXISTS `{$this->nome}`");
            return $this;
        }

        private function definizione(string $nome, string $tipo, array $o): string
        {
            $sql = "`$nome` " . $this->tipoSql($tipo, $o);

            if (($o['signed'] ?? true) === false
                && in_array($tipo, ['integer', 'biginteger', 'smallinteger'], true)) {
                $sql .= ' UNSIGNED';
            }
            $sql .= (($o['null'] ?? true) === false) ? ' NOT NULL' : ' NULL';

            if (array_key_exists('default', $o) && $o['default'] !== null) {
                $d = $o['default'];
                if ($d === 'CURRENT_TIMESTAMP') {
                    $sql .= ' DEFAULT CURRENT_TIMESTAMP';
                } elseif (is_bool($d)) {
                    $sql .= ' DEFAULT ' . ($d ? '1' : '0');
                } elseif (is_int($d) || is_float($d)) {
                    $sql .= ' DEFAULT ' . $d;
                } else {
                    $sql .= " DEFAULT '" . addslashes((string)$d) . "'";
                }
            }
            if (($o['update'] ?? null) === 'CURRENT_TIMESTAMP') {
                $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
            }
            if (!empty($o['comment'])) {
                $sql .= " COMMENT '" . addslashes((string)$o['comment']) . "'";
            }
            if (!empty($o['after'])) {
                $sql .= " AFTER `{$o['after']}`";
            }
            return $sql;
        }

        private function tipoSql(string $tipo, array $o): string
        {
            $limite = $o['limit'] ?? null;
            return match ($tipo) {
                'string'       => 'VARCHAR(' . ($limite ?: 255) . ')',
                'char'         => 'CHAR(' . ($limite ?: 255) . ')',
                'text'         => 'TEXT',
                'integer'      => 'INT',
                'smallinteger' => 'SMALLINT',
                'biginteger'   => 'BIGINT',
                'boolean'      => 'TINYINT(1)',
                'datetime'     => 'DATETIME',
                'timestamp'    => 'TIMESTAMP',
                'date'         => 'DATE',
                'time'         => 'TIME',
                'float'        => 'FLOAT',
                'decimal'      => 'DECIMAL(' . ($o['precision'] ?? 10) . ',' . ($o['scale'] ?? 2) . ')',
                'json'         => 'JSON',
                'enum'         => "ENUM('" . implode("','", array_map('addslashes', $o['values'])) . "')",
                default        => throw new \RuntimeException("tipo sconosciuto: $tipo"),
            };
        }
    }
}
