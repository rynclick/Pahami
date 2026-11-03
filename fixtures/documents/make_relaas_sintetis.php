<?php

/**
 * Generates fixtures/documents/relaas_sintetis.png: a SYNTHETIC summons with
 * fictional parties and a fictional case number, for WhatsApp tests and the
 * demo video (CLAUDE.md §9). Not a real document.
 *
 * Usage (inside the app container): php fixtures/documents/make_relaas_sintetis.php [Y-m-d]
 * The optional date is the hearing date (default: a weekday 10 days from today).
 */

require __DIR__ . '/../../app/Services/SyntheticSummons.php';

use App\Services\SyntheticSummons;

$today   = new DateTimeImmutable('today', new DateTimeZone('Asia/Jakarta'));
$hearing = isset($argv[1])
    ? (DateTimeImmutable::createFromFormat('!Y-m-d', $argv[1]) ?: exit("Bad date\n"))
    : SyntheticSummons::hearingFrom($today);

$out = __DIR__ . '/relaas_sintetis.png';
file_put_contents($out, SyntheticSummons::png($hearing, $today));
echo 'Written ' . $out . ' (hearing ' . SyntheticSummons::tanggal($hearing) . ")\n";
