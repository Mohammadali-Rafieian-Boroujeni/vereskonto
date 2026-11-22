<?php
/**
 * Append translations to a .po file — only msgids it does not have yet.
 *
 *   php tools/po-add.php vereskonto/languages/vereskala-login-fa_IR.po map.php
 *
 * map.php returns an array: msgid => msgstr, or for plurals
 * msgid => ['plural' => '…', 0 => '…', 1 => '…'].
 * Then rebuild the .mo with tools/po2mo.php.
 */

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "usage: php tools/po-add.php file.po map.php\n");
    exit(2);
}

$po   = (string) file_get_contents($argv[1]);
$map  = require $argv[2];
$have = [];

preg_match_all('/^msgid "((?:[^"\\\\]|\\\\.)*)"/m', $po, $m);

foreach ($m[1] as $id) {
    $have[stripcslashes($id)] = true;
}

$q     = static fn(string $s): string => '"' . addcslashes($s, "\"\\\n\t") . '"';
$added = 0;
$out   = rtrim($po) . "\n";

foreach ($map as $id => $tr) {
    $id = (string) $id;

    if (isset($have[$id])) {
        continue;
    }

    if (is_array($tr)) {
        $out .= "\nmsgid " . $q($id) . "\nmsgid_plural " . $q((string) $tr['plural']) . "\nmsgstr[0] " . $q((string) $tr[0]) . "\nmsgstr[1] " . $q((string) $tr[1]) . "\n";
    } else {
        $out .= "\nmsgid " . $q($id) . "\nmsgstr " . $q((string) $tr) . "\n";
    }

    ++$added;
}

file_put_contents($argv[1], $out);
echo "{$added} added to {$argv[1]}\n";
