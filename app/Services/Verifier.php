<?php

namespace App\Services;

use DateTimeImmutable;

/**
 * Deterministic checks on a validated analysis. No model call.
 *
 * Each key field gets a status:
 *   ok          value is well-formed AND supported by its kutipan
 *   tidak_pasti value present but malformed, unknown, or not backed by the quote
 *   kosong      the document does not contain it (null)
 *
 * tingkat_keyakinan is derived from these statuses; the model's own
 * self-report is ignored (CLAUDE.md §8).
 *
 * When the PDF has a text layer, every quote must also be found in that text,
 * so a quote the model made up cannot back a field.
 */
class Verifier
{
    public const KEY_FIELDS = ['nomor_perkara', 'pengadilan', 'sidang_tanggal', 'sidang_jam', 'penerima_adalah'];

    /** CLAUDE.md §8 pattern, widened to PTA for appeal (banding) verdicts of the high court. */
    private const NOMOR_PATTERN = '~^\d+/Pdt\.(G|P)/\d{4}/(PA|PTA)\.\w+$~';
    private const JAM_PATTERN   = '~^([01]?\d|2[0-3])[.:]([0-5]\d)(\s*(WIB|WITA|WIT))?$~i';

    /** Below this many words the text layer is missing or a scan; quotes are not checked against it. */
    private const MIN_SOURCE_WORDS = 50;

    /**
     * Share of a quote's 3-word sequences that must occur in the PDF text.
     * Not 1.0: the Mahkamah Agung watermark splits words and the reading
     * order of headings and numbered items differs (measured: real quotes
     * 0.80–1.00, an invented sentence 0.22).
     */
    private const MIN_QUOTE_COVERAGE = 0.6;

    private const BULAN = [
        'januari' => 1, 'februari' => 2, 'pebruari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6,
        'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'nopember' => 11, 'november' => 11, 'desember' => 12,
    ];

    public function __construct(private readonly CourtDirectory $courts = new CourtDirectory())
    {
    }

    /**
     * @return array{
     *   fields: array<string, array{status: string, alasan: ?string}>,
     *   pengadilan_kode: ?string,
     *   sidang_sudah_lewat: ?bool,
     *   perlu_konfirmasi_jadwal: bool,
     *   tingkat_keyakinan: string,
     *   teks_sumber_dicek: bool,
     *   kutipan_amar_di_teks: ?bool
     * }
     *
     * @param string|null $sourceText Text layer of the PDF pages sent to the model; null for photos
     */
    public function verify(array $analysis, DateTimeImmutable $today, ?string $sourceText = null): array
    {
        $kutipan = $analysis['kutipan'];
        $tanggal = $analysis['sidang']['tanggal'];

        [$pengadilanStatus, $kode] = $this->checkPengadilan($analysis['pengadilan'], $kutipan['pengadilan'], $analysis['nomor_perkara']);

        $fields = [
            'nomor_perkara'   => $this->checkNomor($analysis['nomor_perkara'], $kutipan['nomor_perkara']),
            'pengadilan'      => $pengadilanStatus,
            'sidang_tanggal'  => $this->checkTanggal($tanggal, $kutipan['sidang_tanggal']),
            'sidang_jam'      => $this->checkJam($analysis['sidang']['jam'], $kutipan['sidang_jam']),
            'penerima_adalah' => $this->checkPenerima($analysis['penerima_adalah'], $kutipan['penerima_adalah']),
        ];

        $sourceWords = $sourceText !== null ? self::words($sourceText) : [];
        $checkSource = count($sourceWords) >= self::MIN_SOURCE_WORDS;
        $amarDiTeks  = null;

        if ($checkSource) {
            $source = ['words' => array_flip($sourceWords), 'sequences' => self::sequences($sourceWords)];

            foreach ($fields as $field => $check) {
                if ($check['status'] === 'ok' && ! self::inSource($kutipan[$field], $source)) {
                    $fields[$field] = self::tidakPasti('kutipan tidak ditemukan di teks PDF');
                }
            }

            $kutipanAmar = $analysis['bagian_putusan']['kutipan_amar'] ?? null;
            $amarDiTeks  = $kutipanAmar !== null ? self::inSource($kutipanAmar, $source) : null;
        }

        $sudahLewat = null;
        if ($fields['sidang_tanggal']['status'] === 'ok') {
            $sudahLewat = $tanggal < $today->format('Y-m-d');
        }

        // Reminders wait for the user's confirmation when the hearing date or time is uncertain.
        $perluKonfirmasi = $tanggal !== null && $sudahLewat !== true
            && ($fields['sidang_tanggal']['status'] !== 'ok' || $fields['sidang_jam']['status'] === 'tidak_pasti');

        return [
            'fields'                  => $fields,
            'pengadilan_kode'         => $kode,
            'sidang_sudah_lewat'      => $sudahLewat,
            'perlu_konfirmasi_jadwal' => $perluKonfirmasi,
            'tingkat_keyakinan'       => $this->keyakinan($fields),
            'teks_sumber_dicek'       => $checkSource,
            'kutipan_amar_di_teks'    => $amarDiTeks,
        ];
    }

    /** @param array{words: array<string, int>, sequences: array<string, true>} $source */
    private static function inSource(?string $quote, array $source): bool
    {
        $words = self::words((string) $quote);

        if (count($words) < 3) {
            // Too short for sequences ("09.00", "Penggugat"): every word must occur.
            return $words !== [] && array_diff_key(array_flip($words), $source['words']) === [];
        }

        $sequences = self::sequences($words);
        $found     = count(array_intersect_key($sequences, $source['sequences']));

        return $found / count($sequences) >= self::MIN_QUOTE_COVERAGE;
    }

    /** @return list<string> lowercase letters and digits only */
    private static function words(string $text): array
    {
        preg_match_all('/[a-z0-9]+/', strtolower($text), $m);

        return $m[0];
    }

    /**
     * @param list<string> $words
     *
     * @return array<string, true>
     */
    private static function sequences(array $words): array
    {
        $sequences = [];
        for ($i = 0; $i + 2 < count($words); $i++) {
            $sequences["{$words[$i]} {$words[$i + 1]} {$words[$i + 2]}"] = true;
        }

        return $sequences;
    }

    private function checkNomor(?string $value, ?string $quote): array
    {
        if ($value === null) {
            return self::kosong();
        }

        $compact = preg_replace('/\s+/', '', $value);
        if (! preg_match(self::NOMOR_PATTERN, $compact)) {
            return self::tidakPasti('format nomor perkara tidak dikenali');
        }

        if ($quote === null || ! str_contains(strtolower(preg_replace('/\s+/', '', $quote)), strtolower($compact))) {
            return self::tidakPasti('nomor tidak ditemukan di kutipan');
        }

        return self::ok();
    }

    /** @return array{0: array, 1: ?string} [status, court code] */
    private function checkPengadilan(?string $value, ?string $quote, ?string $nomor): array
    {
        $fromNomor = $this->courts->codeFromCaseNumber($nomor === null ? null : preg_replace('/\s+/', '', $nomor));

        if ($value === null) {
            return [self::kosong(), $fromNomor];
        }

        $code = $this->courts->match($value);
        if ($code === null) {
            return [self::tidakPasti('bukan pengadilan agama di DIY yang dikenal'), $fromNomor];
        }

        if ($fromNomor !== null && $fromNomor !== $code) {
            return [self::tidakPasti('pengadilan tidak cocok dengan kode di nomor perkara'), null];
        }

        if ($quote === null || $this->courts->match($quote) !== $code) {
            return [self::tidakPasti('nama pengadilan tidak ditemukan di kutipan'), $code];
        }

        return [self::ok(), $code];
    }

    private function checkTanggal(?string $value, ?string $quote): array
    {
        if ($value === null) {
            return self::kosong();
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));
        if (! checkdate($m, $d, $y)) {
            return self::tidakPasti('tanggal tidak ada di kalender');
        }

        if ($quote === null || self::parseTanggal($quote) !== $value) {
            return self::tidakPasti('tanggal tidak cocok dengan kutipan');
        }

        return self::ok();
    }

    private function checkJam(?string $value, ?string $quote): array
    {
        if ($value === null) {
            return self::kosong();
        }

        if (! preg_match(self::JAM_PATTERN, trim($value), $m)) {
            return self::tidakPasti('format jam tidak dikenali');
        }

        $time = sprintf('%02d.%02d', $m[1], $m[2]);
        if ($quote === null || ! preg_match('~(?<!\d)([01]?\d|2[0-3])[.:]([0-5]\d)(?!\d)~', $quote, $q)
            || sprintf('%02d.%02d', $q[1], $q[2]) !== $time) {
            return self::tidakPasti('jam tidak cocok dengan kutipan');
        }

        return self::ok();
    }

    private function checkPenerima(string $value, ?string $quote): array
    {
        if ($value === 'tidak_jelas') {
            return self::kosong();
        }

        if ($quote === null || ! str_contains(strtolower($quote), $value)) {
            return self::tidakPasti('peran penerima tidak ditemukan di kutipan');
        }

        return self::ok();
    }

    /**
     * Parses "26 Oktober 2026" or "26-10-2026" / "26/10/2026" anywhere in a quote.
     *
     * @return string|null Y-m-d
     */
    public static function parseTanggal(string $text): ?string
    {
        $bulan = implode('|', array_keys(self::BULAN));

        if (preg_match('~(\d{1,2})\s+(' . $bulan . ')\s+(\d{4})~i', $text, $m)) {
            [$d, $mo, $y] = [(int) $m[1], self::BULAN[strtolower($m[2])], (int) $m[3]];
        } elseif (preg_match('~(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})~', $text, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * tinggi: every field the document contains is verified (at least two).
     * sedang: at least half verified. rendah: otherwise.
     */
    private function keyakinan(array $fields): string
    {
        $present  = array_filter($fields, static fn ($f) => $f['status'] !== 'kosong');
        $verified = array_filter($present, static fn ($f) => $f['status'] === 'ok');

        if ($present !== [] && count($verified) === count($present) && count($verified) >= 2) {
            return 'tinggi';
        }

        if ($present !== [] && count($verified) * 2 >= count($present)) {
            return 'sedang';
        }

        return 'rendah';
    }

    private static function ok(): array
    {
        return ['status' => 'ok', 'alasan' => null];
    }

    private static function kosong(): array
    {
        return ['status' => 'kosong', 'alasan' => null];
    }

    private static function tidakPasti(string $alasan): array
    {
        return ['status' => 'tidak_pasti', 'alasan' => $alasan];
    }
}
