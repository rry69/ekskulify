<?php
/**
 * api/sre/config.php — Konfigurasi modul SRE (Site Reliability Engineering).
 * Dibaca via sre_config(). Mendukung "partial edit": bila admin hanya mengubah
 * sebagian key, array hasil merge dengan sre_config_defaults() tetap lengkap.
 * Zero-setup shared hosting: TANPA cron/TANPA external service — semua state
 * berbasis file + flock di api/logs/.
 */

// Guard definisi fungsi supaya file aman di-require ulang (sre_config() sendiri
// melakukan `require __DIR__.'/config.php'` untuk membaca nilai).
if (!function_exists('sre_config_defaults')) {
  /**
   * Default konfigurasi SRE — basis merge untuk semua pembaca config.
   * Bila key hilang/salah di file ini, nilai di sini yang dipakai.
   */
  function sre_config_defaults(): array {
    return [
      // Email penerima alert. KOSONG = kirim mail DISABLED, namun pipeline
      // alert TETAP jalan: deteksi + throttle + tulis api/logs/alerts.log
      // tetap dieksekusi; hanya mail() yang di-skip dan dicatat di log.
      'alert_email'               => '',
      // Cooldown alert per tipe: maksimal 1 alert per tipe dalam X detik.
      'alert_cooldown_sec'        => 3600,
      // Rotasi log harian SRE: bila sebuah file sre-*.log melebihi ukuran ini,
      // file di-geser ke sre-YYYY-MM-DD.1.log dan nomor lama di-geser.
      'log_max_bytes'             => 5242880, // 5MB
      'log_keep'                  => 5,       // maksimal .1..N yang dipertahankan
      // Backup database & uploads (PDO-based, tanpa mysqldump, tanpa cron).
      'backup_enabled'            => true,
      'backup_dir'                => __DIR__ . '/../backups',
      'backup_uploads_enabled'    => true,
      'backup_retention_daily'    => 7,
      'backup_retention_weekly'   => 4,
      'backup_every_sec'          => 86400,   // 1x / 24 jam
      // Circuit breaker generik (per service, state berbasis file).
      'circuit_fail_threshold'    => 5,
      'circuit_cooldown_sec'      => 30,
      'circuit_half_open_max'     => 1,
      // Cache hasil sre_health_ready() dalam detik.
      'health_cache_sec'          => 15,
      // Housekeeping traffic-triggered: 1 dari N request menjalankan sre_tick()
      // (selalu jalan juga bila terakhir tick > 300 detik — lihat tick.php).
      'tick_sample_divisor'       => 20,
    ];
  }
}

if (!function_exists('sre_config')) {
  /**
   * Konfigurasi aktif SRE (memoized per-request). Membaca file ini via
   * `return require __DIR__.'/config.php'`, lalu merge dengan default.
   */
  function sre_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = array_merge(sre_config_defaults(), (array)require __DIR__ . '/config.php');
    return $cfg;
  }
}

if (!function_exists('sre_sec')) {
  /**
   * Akses config SRE dengan fallback default (memakai sre_config()).
   */
  function sre_sec(string $key, $default = null) {
    $c = sre_config();
    return array_key_exists($key, $c) ? $c[$key] : $default;
  }
}

return [
  'alert_email'               => '',
  'alert_cooldown_sec'        => 3600,
  'log_max_bytes'             => 5242880,
  'log_keep'                  => 5,
  'backup_enabled'            => true,
  'backup_dir'                => __DIR__ . '/../backups',
  'backup_uploads_enabled'    => true,
  'backup_retention_daily'    => 7,
  'backup_retention_weekly'   => 4,
  'backup_every_sec'          => 86400,
  'circuit_fail_threshold'    => 5,
  'circuit_cooldown_sec'      => 30,
  'circuit_half_open_max'     => 1,
  'health_cache_sec'          => 15,
  'tick_sample_divisor'       => 20,
];