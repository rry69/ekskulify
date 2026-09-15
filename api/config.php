<?php
// guard: config.php boleh di-require berulang (index.php init, pdo(), configValue) tanpa redeclare
// require_once kedua kali mengembalikan true — cache di GLOBALS agar pdoRaw() tetap dapat array.
if (isset($GLOBALS['__app_config']) && is_array($GLOBALS['__app_config'])) return $GLOBALS['__app_config'];
if(!function_exists('loadEnv')){
function loadEnv(string $path): void {
  if(!file_exists($path)) return;
  $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if($lines === false) return;
  foreach($lines as $line) {
    $line = trim($line);
    if($line === '' || $line[0] === '#') continue;
    $eq = strpos($line, '=');
    if($eq === false) continue;
    $key = trim(substr($line, 0, $eq));
    $val = trim(substr($line, $eq + 1));
    if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) continue;
    if(strlen($val) >= 2 && (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === "'" && substr($val, -1) === "'"))) {
      $val = substr($val, 1, -1);
    }
    if(getenv($key) !== false) continue;
    if(isset($_ENV[$key])) continue;
    $_ENV[$key] = $val;
    putenv($key . '=' . $val);
  }
}
}
loadEnv(__DIR__ . '/.env');
$GLOBALS['__app_config'] = [
  'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
  'db_name' => getenv('DB_NAME') ?: 'ekskul',
  'db_user' => getenv('DB_USER') ?: 'root',
  'db_pass' => getenv('DB_PASS') ?: '',
  'app_key' => getenv('APP_KEY') ?: (file_exists(__DIR__ . '/.app_key') ? trim(file_get_contents(__DIR__ . '/.app_key')) : ''),
  // === Scaling / multi-instance (Opsi A scale-ready) ===
  'session_driver' => getenv('SESSION_DRIVER') ?: 'file',                 // file|db (db = session di MySQL)
  'session_ttl' => (int)(getenv('SESSION_TTL') ?: 2592000),               // detik (default 30 hari)
  'cache_driver' => getenv('CACHE_DRIVER') ?: 'auto',                     // auto|file|redis
  'rate_limit_driver' => getenv('RATE_LIMIT_DRIVER') ?: 'file',           // file|redis
  'storage_dir' => getenv('STORAGE_DIR') ?: (__DIR__ . '/uploads'),       // shared/object storage utk multi-instance
  'redis_host' => getenv('REDIS_HOST') ?: '127.0.0.1',
  'redis_port' => (int)(getenv('REDIS_PORT') ?: 6379),
];
return $GLOBALS['__app_config'];
