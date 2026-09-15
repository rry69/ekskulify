<?php
// === DbSessionHandler: sessions disimpan di tabel MySQL/SQLite (driver session 'db') ===
final class DbSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;
    private int $ttl;

    public function __construct(?PDO $pdo = null, int $ttl = 2592000)
    {
        $this->pdo = $pdo ?? pdo();
        $this->ttl = max(1, $ttl);
    }

    #[\Override]
    public function open(string $savePath, string $sessionName): bool
    {
        return true;
    }

    #[\Override]
    public function close(): bool
    {
        return true;
    }

    #[\Override]
    public function read(string $id): string
    {
        try {
            $st = $this->pdo->prepare('SELECT data FROM sessions WHERE id=:id AND expiry > :now');
            $st->execute([':id' => $id, ':now' => time()]);
            $row = $st->fetchColumn();
            return $row === false ? '' : (string)$row;
        } catch (Exception $e) {
            return '';
        }
    }

    #[\Override]
    public function write(string $id, string $data): bool
    {
        try {
            $now = time();
            $exp = $now + $this->ttl;
            $isMysql = ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
            if ($isMysql) {
                $st = $this->pdo->prepare('INSERT INTO sessions (id,data,last_accessed,expiry) VALUES (:id,:data,:now,:exp) ON DUPLICATE KEY UPDATE data=VALUES(data), last_accessed=VALUES(last_accessed), expiry=VALUES(expiry)');
            } else {
                $st = $this->pdo->prepare('INSERT OR REPLACE INTO sessions (id,data,last_accessed,expiry) VALUES (:id,:data,:now,:exp)');
            }
            $st->execute([':id' => $id, ':data' => $data, ':now' => $now, ':exp' => $exp]);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    #[\Override]
    public function destroy(string $id): bool
    {
        try {
            $this->pdo->prepare('DELETE FROM sessions WHERE id=:id')->execute([':id' => $id]);
        } catch (Exception $e) {
        }
        return true;
    }

    #[\Override]
    public function gc(int $max_lifetime): int|false
    {
        try {
            $st = $this->pdo->prepare('DELETE FROM sessions WHERE expiry < :now');
            $st->execute([':now' => time()]);
            return $st->rowCount();
        } catch (Exception $e) {
            return 0;
        }
    }
}

// Idempotent DDL (MySQL + SQLite compat). TIDAK menyentuh SCHEMA_VERSION / ensureAll db.php.
function ensureSessionTable(PDO $pdo): void
{
    $isSqlite = false;
    try { $isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'); } catch (Exception $e) {
    }
    $sql = $isSqlite
        ? "CREATE TABLE IF NOT EXISTS sessions (id VARCHAR(64) PRIMARY KEY, data BLOB, last_accessed BIGINT, expiry BIGINT)"
        : "CREATE TABLE IF NOT EXISTS sessions (id CHAR(64) PRIMARY KEY, data MEDIUMBLOB, last_accessed BIGINT, expiry BIGINT, INDEX idx_sessions_expiry (expiry)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    try { $pdo->exec($sql); } catch (Exception $e) {
    }
    // idempotent: bila kolom data sudah pernah ter-create dengan MEDIUMTEXT (utf8mb4 strict bisa menolak payload biner), ALTER ke MEDIUMBLOB. SQLite tidak perlu (type affinity BLOB longgar).
    if (!$isSqlite) {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM sessions LIKE 'data'")->fetch();
            if ($col && stripos((string)($col['Type'] ?? ''), 'blob') === false) {
                $pdo->exec('ALTER TABLE sessions MODIFY data MEDIUMBLOB');
            }
        } catch (Exception $e) {
        }
    }
    try { $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sessions_expiry ON sessions(expiry)"); } catch (Exception $e) {
    }
}

// Dipanggil dari index.php SEBELUM session_start(). TIDAK memanggil session_start() di sini.
// Driver 'file' = perilaku existing (save_path file). Driver 'db' = tabel sessions via PDO.
function initSessionFromConfig(array $config): void
{
    if (($config['session_driver'] ?? 'file') !== 'db') {
        return;
    }
    try {
        $pdo = pdo();
        ensureSessionTable($pdo);
        session_set_save_handler(new DbSessionHandler($pdo, (int)($config['session_ttl'] ?? 2592000)), true);
    } catch (\Throwable $e) {
        // fallback: biarkan handler default (file) — jangan fatal
        @error_log('Session driver db gagal init, fallback ke file session: ' . $e->getMessage(), 0);
    }
}