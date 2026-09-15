<?php
// Token-based extractor: ambil deklarasi fungsi dari monolit api/index.php
// tanpa mengeksekusi top-level code-nya (session/router/exit).
// Braces di dalam string/komentar aman: token_get_all mengembalikan
// mereka sebagai token tunggal, bukan '{' / '}'.
function tests_extract_functions(string $file, array $names): string {
  $src = file_get_contents($file);
  if ($src === false) throw new RuntimeException("cannot read $file");
  $toks = token_get_all($src);
  $out = '';
  $n = count($toks);
  $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
  $isAmp = static function ($tk): bool {
    if ($tk === '&') return true; // PHP < 8
    return is_array($tk) && ($tk[1] ?? '') === '&'; // PHP 8: T_AMPERSAND_* token array
  };
  for ($i = 0; $i < $n; $i++) {
    $t = $toks[$i];
    if (!is_array($t) || $t[0] !== T_FUNCTION) continue;
    $j = $i + 1;
    while ($j < $n && is_array($toks[$j]) && in_array($toks[$j][0], $skip, true)) $j++;
    if ($j < $n && $isAmp($toks[$j])) { // function &name() — bukan kasus kita, skip aman
      $j++;
      while ($j < $n && is_array($toks[$j]) && in_array($toks[$j][0], $skip, true)) $j++;
    }
    if ($j >= $n || !is_array($toks[$j]) || $toks[$j][0] !== T_STRING) continue;
    if (!in_array($toks[$j][1], $names, true)) continue;
    $buf = '';
    $depth = 0;
    $started = false;
    for ($k = $i; $k < $n; $k++) { // dari keyword `function`, bukan dari nama
      $tk = $toks[$k];
      $txt = is_array($tk) ? $tk[1] : $tk;
      $buf .= $txt;
      if ($txt === '{') { $depth++; $started = true; }
      elseif ($txt === '}') { $depth--; if ($started && $depth === 0) break; }
      elseif ($txt === ';' && !$started) break; // deklarasi tanpa body
    }
    $out .= $buf . "\n";
  }
  return $out;
}
