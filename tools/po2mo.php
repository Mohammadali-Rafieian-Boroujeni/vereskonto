<?php
/**
 * Compile a gettext .po file into a .mo file (no gettext tools needed).
 *
 *   php tools/po2mo.php vereskonto/languages/vereskala-login-fa_IR.po
 *   php tools/po2mo.php in.po out.mo
 *   php tools/po2mo.php --dump file.mo      (list a .mo's entries, for diffs)
 *
 * Supports msgctxt, msgid_plural / msgstr[n] and multi-line strings.
 * Fuzzy and untranslated entries are skipped, as msgfmt does.
 */

declare(strict_types=1);

function po_unquote(string $s): string
{
    return stripcslashes(substr($s, 1, -1));
}

/** @return array<string, string> key (ctxt\4id[\0plural]) => translation(s joined by \0) */
function po_parse(string $file): array
{
    $entries = [];
    $cur     = [];
    $last    = null;
    $fuzzy   = false;

    $flush = static function () use (&$cur, &$entries, &$fuzzy, &$last): void {
        if (isset($cur['msgid'])) {
            $key = (isset($cur['msgctxt']) ? $cur['msgctxt'] . "\x04" : '') . $cur['msgid'];

            if (isset($cur['msgid_plural'])) {
                $key .= "\0" . $cur['msgid_plural'];
                ksort($cur['msgstr']);
                $val = implode("\0", $cur['msgstr']);
            } else {
                $val = $cur['msgstr'][0] ?? '';
            }

            $empty = '' === str_replace("\0", '', $val);

            if ((!$fuzzy || '' === $cur['msgid']) && !$empty) {
                $entries[$key] = $val;
            }
        }

        $cur   = [];
        $last  = null;
        $fuzzy = false;
    };

    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $line = rtrim($line, "\r");

        if ('' === trim($line)) {
            $flush();
            continue;
        }

        if (str_starts_with($line, '#,') && str_contains($line, 'fuzzy')) {
            $fuzzy = true;
            continue;
        }

        if (str_starts_with($line, '#')) {
            continue;
        }

        if (preg_match('/^(msgctxt|msgid|msgid_plural)\s+(".*")$/', $line, $m)) {
            if ('msgid' === $m[1] && isset($cur['msgid'])) {
                $keep = $fuzzy;
                $flush();
                $fuzzy = $keep;
            }

            $cur[$m[1]] = po_unquote($m[2]);
            $last       = [$m[1], null];
        } elseif (preg_match('/^msgstr(?:\[(\d+)\])?\s+(".*")$/', $line, $m)) {
            $i                 = '' === $m[1] ? 0 : (int) $m[1];
            $cur['msgstr'][$i] = po_unquote($m[2]);
            $last              = ['msgstr', $i];
        } elseif (preg_match('/^".*"$/', $line) && null !== $last) {
            if ('msgstr' === $last[0]) {
                $cur['msgstr'][$last[1]] .= po_unquote($line);
            } else {
                $cur[$last[0]] .= po_unquote($line);
            }
        }
    }

    $flush();

    return $entries;
}

/** @param array<string, string> $entries */
function mo_build(array $entries): string
{
    ksort($entries, SORT_STRING);

    $n       = count($entries);
    $ids     = '';
    $strs    = '';
    $id_tab  = [];
    $str_tab = [];

    foreach ($entries as $id => $str) {
        $id_tab[]  = [strlen((string) $id), strlen($ids)];
        $ids      .= $id . "\0";
        $str_tab[] = [strlen($str), strlen($strs)];
        $strs     .= $str . "\0";
    }

    $header   = 28;
    $ids_at   = $header + 16 * $n;
    $strs_at  = $ids_at + strlen($ids);
    $out      = pack('V7', 0x950412de, 0, $n, $header, $header + 8 * $n, 0, $header + 16 * $n);

    foreach ($id_tab as [$len, $off]) {
        $out .= pack('V2', $len, $ids_at + $off);
    }

    foreach ($str_tab as [$len, $off]) {
        $out .= pack('V2', $len, $strs_at + $off);
    }

    return $out . $ids . $strs;
}

/** @return array<string, string> */
function mo_read(string $file): array
{
    $d = (string) file_get_contents($file);
    $h = unpack('Vmagic/Vrev/Vn/Vo/Vt', substr($d, 0, 20));
    $r = [];

    for ($i = 0; $i < $h['n']; $i++) {
        $o = unpack('Vlen/Voff', substr($d, $h['o'] + 8 * $i, 8));
        $t = unpack('Vlen/Voff', substr($d, $h['t'] + 8 * $i, 8));
        $r[substr($d, $o['off'], $o['len'])] = substr($d, $t['off'], $t['len']);
    }

    ksort($r, SORT_STRING);

    return $r;
}

$args = array_slice($argv, 1);

if ('--dump' === ($args[0] ?? '')) {
    foreach (mo_read($args[1]) as $k => $v) {
        echo json_encode([$k, $v], JSON_UNESCAPED_UNICODE), "\n";
    }
    exit(0);
}

if (!isset($args[0]) || !is_readable($args[0])) {
    fwrite(STDERR, "usage: php tools/po2mo.php in.po [out.mo] | --dump file.mo\n");
    exit(2);
}

$out     = $args[1] ?? preg_replace('/\.po$/', '.mo', $args[0]);
$entries = po_parse($args[0]);
file_put_contents($out, mo_build($entries));
echo count($entries) . " entries -> {$out}\n";
