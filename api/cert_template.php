<?php
/**
 * Shared certificate HTML template — single source of truth for PDF + Preview
 * Pixel-perfect mm/pt absolute positioning, A4 landscape 297×210mm
 * Used by: api/index.php (Dompdf) and frontend/AdminSertifikat.vue (iframe/srcdoc preview)
 * No external dependencies — only htmlspecialchars / standard PHP.
 */

function cert_render_html(array $data, array $layout, array $opts = []): string {
    $isPdf = !empty($opts['is_pdf']);

    // ---- selectable fonts (preview + PDF-safe) ----
    // $opts['fonts'] = ['nama'=>..,'label'=>..,'deskripsi'=>..,'nomor'=>..,'ttd_nama'=>..]
    // Whitelist ketat agar tidak bisa inject CSS. Default = DejaVu Sans (aman Dompdf).
    // STATUS EMBED (Dompdf tidak load Google Fonts remote secara andal, jadi pakai TTF lokal):
    //   [EMBEDDED api/fonts/] Cormorant Garamond (700), Inter (400+700),
    //     Source Serif 4 (400), JetBrains Mono (400), Great Vibes (400),
    //     Playfair Display (700+800), Cinzel (600+700), DM Serif Display (400),
    //     DM Sans (400+500+700), Jost (500+600), IBM Plex Mono (400+500), Allura (400).
    //   [EMBEDDED PENUH — semua 13 family whitelist sudah ada TTF lokal, preview=PDF WYSIWYG]
    // CARA TAMBAH FONT BARU:
    //   1. Download TTF resmi (gstatic, UA curl agar dapat format truetype, bukan woff2)
    //      ke api/fonts/ dengan nama jelas, mis. PlayfairDisplay-Bold.ttf
    //   2. Tambahkan 1 baris @font-face di $faces di bawah (family KANONIS = value
    //      FONT_OPTIONS di AdminSertifikat.vue, font-weight sesuai varian)
    //   3. Nama family SUDAH ada di $allowedFonts — tidak perlu ubah whitelist
    //      kecuali family benar-benar baru (tambahkan ejaan kanonisnya).
    $allowedFonts = [
        'DejaVu Sans',
        'Cormorant Garamond', 'Playfair Display', 'Cinzel', 'DM Serif Display',
        'Inter', 'DM Sans', 'Jost',
        'Source Serif 4',
        'JetBrains Mono', 'IBM Plex Mono',
        'Great Vibes', 'Allura', 'Alex Brush',
        'Arial', 'Georgia', 'Times New Roman',
    ];
    // logging helper — selalu catat ke error_log; file log tambahan api/logs/cert_font.log jika writable
    $certLog = function(string $msg) {
        $line = '[CERT_FONT] ' . $msg;
        error_log($line);
        $lf = __DIR__ . '/logs/cert_font.log';
        if (is_dir(__DIR__.'/logs') || @mkdir(__DIR__.'/logs', 0777, true)) {
            @file_put_contents($lf, date('c') . ' ' . $line . PHP_EOL, FILE_APPEND);
        }
    };
    // warnings collector — diisi saat fallback/TTF hilang
    $fontWarnings = [];
    // expose to caller via $GLOBALS
    $GLOBALS['__cert_font_warnings'] = &$fontWarnings;
    $GLOBALS['__cert_font_log_fn'] = $certLog;

    $sanFont = function ($v, $fallback = 'DejaVu Sans') use ($allowedFonts, &$fontWarnings, $certLog): string {
        $orig = (string)($v ?? '');
        $v = trim($orig);
        if ($v === '') {
            if ($fallback !== 'DejaVu Sans') {
                // empty for nama should fallback to Great Vibes without noisy warning, but still log
                $certLog("sanFont empty -> fallback {$fallback} (orig empty)");
            }
            return $fallback;
        }
        if (!preg_match('/^[A-Za-z0-9 \\-]+$/', $v)) {
            $certLog("sanFont invalid chars '{$v}' -> fallback {$fallback}");
            $fontWarnings[] = "invalid chars '{$v}' -> {$fallback}";
            trigger_error("cert font invalid chars, fallback: {$v}", E_USER_NOTICE);
            return $fallback;
        }
        foreach ($allowedFonts as $a) {
            if (strcasecmp($a, $v) === 0) return $a;
        }
        $certLog("sanFont whitelist miss '{$v}' -> fallback {$fallback}");
        $fontWarnings[] = "whitelist miss '{$v}' -> {$fallback}";
        trigger_error("cert font whitelist fallback: {$v}", E_USER_NOTICE);
        return $fallback;
    };
    $fontsIn = is_array($opts['fonts'] ?? null) ? $opts['fonts'] : [];
    // FIX: nama default Great Vibes harus via fallback param, bukan via ?? 'Great Vibes' yang jadi '' -> DejaVu
    $fontNama     = $sanFont($fontsIn['nama'] ?? '', 'Great Vibes');
    $fontLabel    = $sanFont($fontsIn['label'] ?? '', 'DejaVu Sans');
    $fontDeskripsi= $sanFont($fontsIn['deskripsi'] ?? '', 'DejaVu Sans');
    $fontNomor    = $sanFont($fontsIn['nomor'] ?? '', 'DejaVu Sans');
    $fontTtdNama  = $sanFont($fontsIn['ttd_nama'] ?? '', 'DejaVu Sans');
    // log fontsIn vs resolved
    $certLog('fontsIn=' . json_encode($fontsIn, JSON_UNESCAPED_UNICODE) . ' resolved=' . json_encode(['nama'=>$fontNama,'label'=>$fontLabel,'deskripsi'=>$fontDeskripsi,'nomor'=>$fontNomor,'ttd_nama'=>$fontTtdNama], JSON_UNESCAPED_UNICODE) . ' isPdf=' . ($isPdf?1:0) . ' opt_keys=' . json_encode(array_keys($fontsIn), JSON_UNESCAPED_UNICODE));

    // helper: "Family", DejaVu Sans, Arial, sans-serif
    $ff = function (string $fam): string {
        if ($fam === 'DejaVu Sans') return 'DejaVu Sans, Arial, sans-serif';
        return "'".$fam."', 'DejaVu Sans', Arial, sans-serif";
    };
    // ---- @font-face TTF lokal — HANYA untuk PDF (Dompdf). Preview pakai <link> Google Fonts. ----
    // family HARUS kanonis = value FONT_OPTIONS di AdminSertifikat.vue. Fallback DejaVu Sans tetap.
    $availableWeights = [];
    $fontFaceCss = '';
    if ($isPdf) {
        // [family kanonis, nama file, font-weight]
        $faces = [
            ['Cormorant Garamond', 'CormorantGaramond-Bold.ttf', 700],
            ['Inter', 'Inter-Regular.ttf', 400],
            ['Inter', 'Inter-Medium.ttf', 500],
            ['Inter', 'Inter-Bold.ttf', 700],
            ['Source Serif 4', 'SourceSerif4-Regular.ttf', 400],
            ['JetBrains Mono', 'JetBrainsMono-Regular.ttf', 400],
            ['Great Vibes', 'GreatVibes-Regular.ttf', 400],
            ['Alex Brush', 'AlexBrush-Regular.ttf', 400],
            ['Playfair Display', 'PlayfairDisplay-Bold.ttf', 700],
            ['Playfair Display', 'PlayfairDisplay-ExtraBold.ttf', 800],
            ['Cinzel', 'Cinzel-Bold.ttf', 700],
            ['DM Serif Display', 'DMSerifDisplay-Regular.ttf', 400],
            ['DM Sans', 'DMSans-Regular.ttf', 400],
            ['DM Sans', 'DMSans-Bold.ttf', 700],
            ['Jost', 'Jost-Medium.ttf', 500],
            ['Jost', 'Jost-SemiBold.ttf', 600],
            ['IBM Plex Mono', 'IBMPlexMono-Regular.ttf', 400],
            ['Allura', 'Allura-Regular.ttf', 400],
            // alias bold/700 -> file Regular sama: layout default font_weight=bold,
            // Dompdf match exact weight, tanpa alias ini script fallback DejaVu
            ['Great Vibes', 'GreatVibes-Regular.ttf', 700],
            ['Allura', 'Allura-Regular.ttf', 700],
            ['Alex Brush', 'AlexBrush-Regular.ttf', 700],
        ];
        $certLog('TTF scan start chroot=' . __DIR__ . '/fonts isPdf=' . ($isPdf?1:0) . ' faces=' . count($faces) . ' chroot_is_dir=' . (is_dir(__DIR__.'/fonts')?1:0));
        foreach ($faces as [$fam, $file, $weight]) {
            $tryPath = __DIR__ . '/fonts/' . $file;
            $real = realpath($tryPath);
            if ($real === false) {
                $exists = is_file($tryPath) ? 'file_exists_but_realpath_fail' : 'missing';
                $certLog("TTF MISS fam='{$fam}' file='{$file}' weight={$weight} tryPath='{$tryPath}' exists={$exists} realpath=false");
                $fontWarnings[] = "TTF hilang {$fam} {$weight} ({$file}) → fallback DejaVu jika dipakai";
                continue;
            }
            $certLog("TTF OK fam='{$fam}' file='{$file}' weight={$weight} real='{$real}' uri_len=" . strlen($real));
            $uri = 'file://' . str_replace('\\', '/', $real);
            $fontFaceCss .= "@font-face{ font-family:'" . $fam . "'; font-style:normal;"
                . ' font-weight:' . (int)$weight . "; src:url('" . $uri . "') format('truetype'); }";
            $availableWeights[$fam][] = $weight;
        }
        $certLog('TTF done embeddedFamilies=' . json_encode(array_keys($availableWeights), JSON_UNESCAPED_UNICODE) . ' cssLen=' . strlen($fontFaceCss) . ' warnings=' . count($fontWarnings));
        // log fallback check per resolved font: apakah family punya TTF?
        foreach (['nama'=>$fontNama,'label'=>$fontLabel,'deskripsi'=>$fontDeskripsi,'nomor'=>$fontNomor,'ttd_nama'=>$fontTtdNama] as $k=>$fam) {
            $hasTtf = isset($availableWeights[$fam]) || $fam==='DejaVu Sans' || in_array($fam, ['Arial','Georgia','Times New Roman'], true);
            if (!$hasTtf) {
                $certLog("FALLBACK WARNING field={$k} fam='{$fam}' tidak ada TTF embedded -> akan fallback DejaVu di PDF");
                $fontWarnings[] = "field {$k} font '{$fam}' tidak punya TTF → PDF fallback DejaVu";
            }
        }
    }
    // Deduplicate & sort per-family weight lists
    foreach ($availableWeights as &$wSort) { $wSort = array_values(array_unique($wSort)); sort($wSort); }
    unset($wSort);
    // Convert CSS weight keyword/number to numeric (bold=700, normal=400)
    $toNumWeight = function($w): int {
        $s = strtolower(trim((string)$w));
        if ($s === 'bold') return 700;
        if ($s === 'normal' || $s === '') return 400;
        return max(100, min(900, (int)$s)) ?: 400;
    };
    // Clamp font-weight: nearest available ≤ requested, or max available if none ≤
    $clampWeight = function(string $family, $requested) use ($availableWeights, $toNumWeight): int {
        $req = $toNumWeight($requested);
        $avail = $availableWeights[$family] ?? [];
        if (empty($avail)) return $req; // no local TTF — leave request intact (CSS fallback)
        $best = 0;
        foreach ($avail as $w) { if ($w <= $req && $w > $best) $best = $w; }
        return $best > 0 ? $best : max($avail);
    };
    // Inline-SVG icons: hanya untuk preview HTML. PDF (Dompdf) biarkan fallback text.
    $showIcons = !$isPdf && !empty($opts['show_icons']);
    $svgWrap = function (string $inner): string {
        return '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:3mm;height:3mm;vertical-align:-0.5mm;margin-right:1mm;" aria-hidden="true">'.$inner.'</svg>';
    };

    // ---- defaults (must match api/index.php:3783 defLayout) ----
    // nama y=88 (top) -> baseline ~96-98mm, gap ~3-4mm above gold divider at 102mm
    $def = [
        'nama'     => ['x'=>148.5,'y'=>88,'w'=>180,'font_pt'=>28,'color'=>'#0E1442','font_weight'=>'bold'],
        'label'    => ['x'=>148.5,'y'=>135,'w'=>180,'font_pt'=>18,'color'=>'#b8860b','font_weight'=>'bold','uppercase'=>true],
        'deskripsi'=> ['x'=>148.5,'y'=>150,'w'=>200,'font_pt'=>11,'color'=>'#444444'],
        'ttd'      => ['x'=>55,'y'=>175,'w'=>60,'font_pt'=>10,'color'=>'#18181b'],
        'qr'       => ['x'=>242,'y'=>175,'w'=>30,'h'=>30],
        'nomor'    => ['x'=>148.5,'y'=>195,'w'=>180,'font_pt'=>7,'color'=>'#666666'],
    ];
    // backward-compat: abaikan key 'kelas' dari saved JSON lama / data lama
    unset($layout['kelas']);
    if (isset($data['kelas'])) unset($data['kelas']);
    // merge layout over defaults
    foreach ($def as $k => $v) {
        if (!isset($layout[$k]) || !is_array($layout[$k])) $layout[$k] = $v;
        else $layout[$k] = array_merge($v, $layout[$k]);
    }

    // ---- helpers ----
    $h = function($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $mm = function($n): string { return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); };
    // centered vs anchor fields
    $centeredKeys = ['nama'=>1,'label'=>1,'deskripsi'=>1,'nomor'=>1];
    $fieldPos = function(string $key) use ($layout, $centeredKeys, $mm): array {
        $v = $layout[$key];
        $x = (float)($v['x'] ?? 148.5);
        $y = (float)($v['y'] ?? 100);
        $w = (float)($v['w'] ?? 60);
        $isCentered = isset($centeredKeys[$key]);
        $left = $isCentered ? ($x - $w/2) : $x;
        $out = 'left:'.$mm($left).'mm; top:'.$mm($y).'mm; width:'.$mm($w).'mm;';
        if (isset($v['h']) && $v['h'] !== '' && $v['h'] !== null) $out .= ' height:'.$mm((float)$v['h']).'mm;';
        return [$out, $v];
    };

    // ---- data extraction ----
    $nama          = (string)($data['nama'] ?? '');
    $label         = (string)($data['label'] ?? 'PESERTA');
    if (!empty($layout['label']['uppercase'])) $label = mb_strtoupper($label, 'UTF-8');
    $deskripsiHtml = (string)($data['deskripsi_html'] ?? '');
    $ttd           = is_array($data['ttd'] ?? null) ? $data['ttd'] : [];
    $ttdDate       = (string)($ttd['date'] ?? '');
    $ttdImgUri     = (string)($ttd['img_data_uri'] ?? '');
    $ttdNama       = (string)($ttd['nama'] ?? '');
    $ttdNip        = (string)($ttd['nip'] ?? '');
    $qrDataUri     = (string)($data['qr_data_uri'] ?? '');
    $qrUrl         = (string)($data['qr_url'] ?? '');
    $nomor         = (string)($data['nomor'] ?? '-');
    $hash          = (string)($data['hash'] ?? '');
    $issuedAt      = (string)($data['issued_at'] ?? '');
    $bgDataUri     = (string)($data['bg_data_uri'] ?? '');
    $bgFallback    = (string)($data['bg_fallback_html'] ?? '');

    // ---- CSS (identical for PDF + preview, mm/pt only, no %/cqi) ----
    // NOTE: Dompdf default tetap DejaVu Sans; font selectable hanya override
    // per-field via inline font-family di bawah (fallback DejaVu Sans selalu ada).
    // Script fancy (Great Vibes/Allura/Alex Brush) = 400 (tidak bold), lainnya 800
    $isNamaScript = in_array($fontNama, ['Great Vibes','Allura','Alex Brush'], true);
    $namaCssWeight = $isNamaScript ? '400' : ($isPdf ? (string)$clampWeight($fontNama, 800) : '800');
    // Weight-aware untuk semua field: clamp ke TTF yang ada, JANGAN hardcode 400/700
    $labelCssWeight = $isPdf ? (string)$clampWeight($fontLabel, 700) : '700';
    $ttdCssWeight   = $isPdf ? (string)$clampWeight($fontTtdNama, 700) : '700';
    // deskripsi & nomor default 400 (body) — hormati $availableWeights
    $deskCssWeight = $isPdf ? (string)$clampWeight($fontDeskripsi, 400) : '400';
    $nomorCssWeight= $isPdf ? (string)$clampWeight($fontNomor, 400) : '400';
    $css = $fontFaceCss . '@page{ size:297mm 210mm landscape; margin:0; }'
         . 'body{ margin:0; padding:0; width:297mm; height:210mm; position:relative; font-family:DejaVu Sans, Arial, sans-serif; color:#18181b; }'
         . '.bg{ position:absolute; left:0; top:0; width:297mm; height:210mm; z-index:0; object-fit:cover; object-position:center; }'
         . '.field{ position:absolute; z-index:1; text-align:center; overflow:visible; line-height:1.2; word-wrap:break-word; overflow-wrap:break-word; }'
         . '.field-nama{ font-weight:'.$namaCssWeight.'; color:#0E1442; font-family:'.$ff($fontNama).'; }'
         . '.field-label{ font-weight:'.$labelCssWeight.'; color:#b8860b; letter-spacing:0.08em; text-transform:uppercase; font-family:'.$ff($fontLabel).'; }'
         . '.field-deskripsi{ color:#444444; line-height:1.5; font-family:'.$ff($fontDeskripsi).'; font-weight:'.$deskCssWeight.'; }'
         . '.field-ttd{ color:#18181b; }'
         . '.field-qr{ text-align:center; }'
         . '.field-nomor{ color:#666666; font-family:'.$ff($fontNomor).'; font-weight:'.$nomorCssWeight.'; }'
         . '.field-ttd-name{ font-family:'.$ff($fontTtdNama).'; font-weight:'.$ttdCssWeight.'; }';

    // ---- background ----
    if ($bgDataUri !== '') {
        $bgHtml = '<img class="bg" src="'.$h($bgDataUri).'" alt="bg" style="position:absolute; left:0; top:0; width:297mm; height:210mm; object-fit:cover; object-position:center;">';
    } else {
        if ($bgFallback !== '') {
            $bgHtml = $bgFallback;
        } else {
            $bgHtml = '<div class="bg" style="position:absolute; left:0; top:0; width:297mm; height:210mm; background:#f8fafc; border:3mm double #0E1442;"></div>';
        }
    }

    // ---- field positions + inner HTML ----
    [$namaPos, $namaL]   = $fieldPos('nama');
    [$labelPos, $labelL] = $fieldPos('label');
    [$descPos, $descL]   = $fieldPos('deskripsi');
    [$ttdPos, $ttdL]     = $fieldPos('ttd');
    [$qrPos, $qrL]       = $fieldPos('qr');
    [$nomorPos, $nomorL] = $fieldPos('nomor');

    // font helpers
    $fontPt = function($v, $fallback) { $pt = $v['font_pt'] ?? $fallback; return (float)$pt; };
    $fw = function($v, $defFw='normal'): string {
        if (isset($v['font_weight'])) return (string)$v['font_weight'];
        if (!empty($v['bold'])) return 'bold';
        return $defFw;
    };

    // build fields html (font-family inline per-field; fallback DejaVu Sans) — script fancy = 400 else 800
    $namaDefaultFw = $isNamaScript ? '400' : ($isPdf ? (string)$clampWeight($fontNama, 800) : '800');
    $namaHtml = '<div class="field field-nama" style="'.$namaPos.' font-size:'.$mm($fontPt($namaL,28)).'pt; font-weight:'.$namaDefaultFw.'; color:'.$h($namaL['color']??'#0E1442').'; text-align:center; font-family:'.$h($ff($fontNama)).';">'.$h($nama).'</div>';
    // Icon Award samping label (inline SVG preview saja; PDF fallback text polos)
    $labelIcon = $showIcons ? $svgWrap('<circle cx="12" cy="8" r="6"/><path d="M15.5 13 17 22l-5-3-5 3 1.5-9"/>') : '';
    $labelHtml = '<div class="field field-label" style="'.$labelPos.' font-size:'.$mm($fontPt($labelL,18)).'pt; font-weight:'.($isPdf ? (string)$clampWeight($fontLabel, $fw($labelL,'800')) : $h($fw($labelL,'800'))).'; color:'.$h($labelL['color']??'#b8860b').'; text-align:center; letter-spacing:0.08em; font-family:'.$h($ff($fontLabel)).';">'.$labelIcon.$h($label).'</div>';
    $descHtml = '<div class="field field-deskripsi" style="'.$descPos.' font-size:'.$mm($fontPt($descL,11)).'pt; color:'.$h($descL['color']??'#444444').'; text-align:center; line-height:1.5; font-family:'.$h($ff($fontDeskripsi)).';">'.$deskripsiHtml.'</div>';

    // TTD inner
    $hasTtdImg = $ttdImgUri !== '' && str_starts_with($ttdImgUri, 'data:image/');
    $ttdImgHtml = $hasTtdImg
        ? '<img src="'.$h($ttdImgUri).'" alt="TTD Kepsek" style="height:18mm; max-width:55mm; object-fit:contain; display:block; margin:2mm auto 1mm auto;">'
        : '<div style="height:18mm;"></div>';
    $ttdInner  = '<div style="font-size:7pt; color:#52525b;">'.($showIcons ? $svgWrap('<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>') : '').$h($ttdDate).'</div>'
               . $ttdImgHtml
               . '<div class="field-ttd-name" style="font-weight:'.($isPdf ? (string)$clampWeight($fontTtdNama, 700) : '700').'; border-top:0.4mm solid #18181b; padding-top:1.5mm; font-size:8pt; font-family:'.$h($ff($fontTtdNama)).';">'.$h($ttdNama).'</div>'
                . '<div style="color:#71717a; font-size:7pt;">Kepala Sekolah<br>NIP. '.$h($ttdNip).'</div>';
    $ttdHtml = '<div class="field field-ttd" style="'.$ttdPos.' font-size:'.$mm($fontPt($ttdL,10)).'pt; text-align:center; color:'.$h($ttdL['color']??'#18181b').';">'.$ttdInner.'</div>';

    // QR inner
    if ($qrDataUri !== '') {
        $qrImgHtml = '<img src="'.$h($qrDataUri).'" alt="QR Verify" style="width:30mm; height:30mm; border:0.4mm solid #e4e4e7; display:block; margin:0 auto;">';
    } else {
        $qrImgHtml = '<div style="width:30mm; height:30mm; border:0.4mm dashed #ccc; display:block; margin:0 auto;"></div>';
    }
    $qrInner = $qrImgHtml;
    $qrHtml = '<div class="field field-qr" style="'.$qrPos.' text-align:center;">'.$qrInner.'</div>';

    // Nomor/hash/footer (+ Hash icon depan nomor saat preview)
    $hashIcon = $showIcons ? $svgWrap('<line x1="4" x2="20" y1="9" y2="9"/><line x1="4" x2="20" y1="15" y2="15"/><line x1="10" x2="8" y1="3" y2="21"/><line x1="16" x2="14" y1="3" y2="21"/>') : '';
    $nomorInner = 'Nomor: '.$h($nomor).' &nbsp;|&nbsp; '.$hashIcon.'Hash: '.$h(substr($hash,0,16)).'… &nbsp;|&nbsp; Diterbitkan: '.$h($issuedAt);
    $nomorHtml = '<div class="field field-nomor" style="'.$nomorPos.' font-size:'.$mm($fontPt($nomorL,7)).'pt; color:'.$h($nomorL['color']??'#666666').'; text-align:center; font-family:'.$h($ff($fontNomor)).';">'.$nomorInner.'</div>';

    // Google Fonts link untuk preview — dinamis semua family terpilih (Dompdf tetap @font-face file://)
    $gfLinkHtml = '';
    if (!$isPdf) {
        $gfSlugs = [
            'Cormorant Garamond' => 'Cormorant+Garamond:wght@600;700',
            'Playfair Display'   => 'Playfair+Display:wght@700;800',
            'Cinzel'             => 'Cinzel:wght@600;700',
            'DM Serif Display'   => 'DM+Serif+Display:ital,wght@0,400;1,400',
            'Inter'              => 'Inter:wght@400;500;600;700;800',
            'DM Sans'            => 'DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,700',
            'Jost'               => 'Jost:wght@500;600',
            'Source Serif 4'     => 'Source+Serif+4:opsz,wght@8..60,400;8..60,600',
            'JetBrains Mono'     => 'JetBrains+Mono:wght@400;500',
            'IBM Plex Mono'      => 'IBM+Plex+Mono:wght@400;500',
            'Great Vibes'        => 'Great+Vibes',
            'Allura'             => 'Allura',
            'Alex Brush'         => 'Alex+Brush',
        ];
        $uniq = array_values(array_unique(array_filter([$fontNama,$fontLabel,$fontDeskripsi,$fontNomor,$fontTtdNama], function($v){ return $v !== 'DejaVu Sans' && $v !== '' && $v !== null; })));
        $fams = [];
        foreach ($uniq as $fam) { if (isset($gfSlugs[$fam])) $fams[] = 'family=' . $gfSlugs[$fam]; }
        if (!empty($fams)) $gfLinkHtml = '<link href="https://fonts.googleapis.com/css2?' . implode('&', $fams) . '&display=swap" rel="stylesheet">';
    }
    $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'.$gfLinkHtml.'<style>'.$css.'</style></head><body>'
          . $bgHtml
          . $namaHtml
          . $labelHtml
          . $descHtml
          . $ttdHtml
          . $qrHtml
          . $nomorHtml
          . '</body></html>';

    // stash warnings for caller to read via $GLOBALS
    $GLOBALS['__cert_font_warnings'] = $fontWarnings;
    // also if caller passed reference array, fill it
    if (isset($opts['__warnings_ref']) && is_array($opts['__warnings_ref'])) {
        // cannot directly assign due to copy, but try global reference
        $GLOBALS['__cert_font_warnings_ref_target'] = &$fontWarnings;
    }

    return $html;
}

/**
 * Helper: return list of families that have TTF embedded for PDF.
 * Used by frontend status and API diagnostics.
 */
function cert_pdf_available_families(): array {
    return ['DejaVu Sans','Arial','Georgia','Times New Roman','Cormorant Garamond','Inter','Source Serif 4','JetBrains Mono','Great Vibes','Alex Brush','Playfair Display','Cinzel','DM Serif Display','DM Sans','Jost','IBM Plex Mono','Allura'];
}
function cert_is_pdf_available(string $fam): bool {
    return in_array($fam, cert_pdf_available_families(), true);
}
function cert_last_warnings(): array {
    return $GLOBALS['__cert_font_warnings'] ?? [];
}
