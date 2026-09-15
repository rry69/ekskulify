<?php
// === Storage helper: semua path upload dipusatkan di sini ===
// storage_dir (default api/uploads) bisa diarahkan ke shared/object storage utk multi-instance.
function storageBaseDir(): string
{
    return rtrim((string)configValue('storage_dir', __DIR__ . '/uploads'), '/\\');
}

// Rel path bisa berupa: 'covers/x.png' (relative ke storage_dir),
// 'uploads/covers/x.png' atau 'api/uploads/covers/x.png' (legacy, relative ke api/ — prefix dibuang).
function uploadPath(string $rel = ''): string
{
    $base = storageBaseDir();
    $rel = str_replace('\\', '/', trim((string)$rel));
    if ($rel !== '') {
        if (strpos($rel, "\0") !== false || strpos($rel, '..') !== false) {
            throw new \InvalidArgumentException('uploadPath: parameter rel mengandung path traversal (..) atau null byte');
        }
        if (str_starts_with($rel, 'api/uploads/')) {
            $rel = substr($rel, strlen('api/uploads/'));
        } elseif (str_starts_with($rel, 'uploads/')) {
            $rel = substr($rel, strlen('uploads/'));
        }
        $rel = ltrim($rel, '/');
        if ($rel !== '') {
            $base .= '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        }
    }
    // auto-mkdir saat perlu: dirname bila base mengarah ke file, base itu sendiri bila direktori
    // (basename tanpa titik = kemungkinan direktori; yang ada titik dianggap file path)
    $dir = is_dir($base) ? $base : dirname($base);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_dir($base) && strpos(basename($base), '.') === false) {
        @mkdir($base, 0775, true);
    }
    return $base;
}

function uploadUrl(string $rel = ''): string
{
    return '/api/uploads/' . ltrim(str_replace('\\', '/', (string)$rel), '/');
}