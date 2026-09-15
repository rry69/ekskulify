<?php
// === Rate limit terpusat (Opsi A scale-ready). Driver: file (default) | redis ===
// Catatan: limiter existing (loginRateLimit/usersRateLimit/sertifikatRateLimit/laporanRateLimit/socialRateLimit)
// tetap berjalan apa adanya; pemanggil baru bisa memakai rlHit() di bawah.
function rlRedisConn(): ?Redis
{
    static $conn = null;
    static $tried = false;
    if ($tried) return $conn;
    $tried = true;
    if (!class_exists('Redis')) {
        @error_log('rate_limit_driver=redis tapi ekstensi phpredis tidak ada; fallback ke file rate limit', 0);
        return null;
    }
    try {
        $conn = new Redis();
        $conn->connect(configValue('redis_host', '127.0.0.1'), (int)configValue('redis_port', 6379), 1.0);
        return $conn;
    } catch (Exception $e) {
        $conn = null;
    }
    return $conn;
}

// Fixed-window counter berbasis file + flock (gaya existing), json {count, reset_at}
function rlHitFile(string $key, int $max, int $windowSec): array
{
    $file = sys_get_temp_dir() . '/rl_' . md5($key) . '.json';
    $now = time();
    $fh = @fopen($file, 'c+');
    if (!$fh) return ['allowed' => true, 'retryAfter' => 0];
    @flock($fh, LOCK_EX);
    $raw = @stream_get_contents($fh);
    $j = $raw ? @json_decode($raw, true) : null;
    $count = 0;
    $resetAt = $now + $windowSec;
    if ($j && isset($j['reset_at'])) {
        $resetAt = (int)$j['reset_at'];
        if ($resetAt > $now) {
            $count = (int)($j['count'] ?? 0);
        } else {
            $count = 0;
            $resetAt = $now + $windowSec;
        }
    }
    $count++;
    $allowed = $count <= $max;
    $retryAfter = $allowed ? 0 : max(1, $resetAt - $now);
    @ftruncate($fh, 0);
    @rewind($fh);
    @fwrite($fh, json_encode(['count' => $count, 'reset_at' => $resetAt]));
    @fflush($fh);
    @flock($fh, LOCK_UN);
    @fclose($fh);
    return ['allowed' => $allowed, 'retryAfter' => $retryAfter];
}

// Fixed-window via INCR + EXPIRE + TTL
function rlHitRedis(Redis $r, string $key, int $max, int $windowSec): array
{
    $rk = 'apprl:' . md5($key);
    $n = $r->incr($rk);
    if ($n === 1) {
        $r->expire($rk, $windowSec);
    }
    $ttl = (int)$r->ttl($rk);
    if ($ttl < 0) $ttl = $windowSec;
    $allowed = $n <= $max;
    if (!$allowed && $ttl < 1) {
        $r->expire($rk, $windowSec);
        $ttl = $windowSec;
    }
    return ['allowed' => $allowed, 'retryAfter' => $allowed ? 0 : max(1, $ttl)];
}

// return: ['allowed'=>bool, 'retryAfter'=>int]
function rlHit(string $key, int $max, int $windowSec): array
{
    if (configValue('rate_limit_driver', 'file') === 'redis') {
        $r = rlRedisConn();
        if ($r) {
            try {
                return rlHitRedis($r, $key, $max, $windowSec);
            } catch (Exception $e) {
                // fallback ke file bila redis gagal mid-request
            }
        }
    }
    return rlHitFile($key, $max, $windowSec);
}