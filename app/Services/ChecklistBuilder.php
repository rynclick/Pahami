<?php

namespace App\Services;

/**
 * Case type + party role → document checklist (CLAUDE.md §3.5).
 *
 * The base lists are fixed text (to be reviewed by court staff), so the core
 * of every checklist is the same for everyone in the same situation. The
 * per-case-type papers live in Config\Perkara. Items the model read from the
 * specific document are appended when not already covered.
 */
class ChecklistBuilder
{
    private const MAX_DIBAWA    = 6;
    private const MAX_DISIAPKAN = 5;

    /** Every hearing visitor needs an ID. */
    private const KTP = 'KTP asli dan fotokopinya';

    private const DISIAPKAN = [
        'pengaju' => [
            'Dua orang saksi yang mengetahui keadaan perkara Anda',
            'Fotokopi bukti surat yang diberi meterai dan dilegalisasi (nazegelen) di kantor pos',
        ],
        'termohon' => [
            'Jawaban atas gugatan/permohonan (boleh lisan di sidang atau tertulis)',
            'Saksi dan bukti surat jika Anda ingin membantah isi gugatan/permohonan',
        ],
    ];

    /** For a verdict or ruling: what is needed to follow up at the PTSP desk. */
    private const SETELAH_PUTUSAN = ['KTP asli', 'Salinan putusan/penetapan ini atau nomor perkaranya'];

    /** Keywords that mark two items as the same thing. */
    private const KUNCI = ['ktp', 'buku nikah', 'akta nikah', 'kartu keluarga', 'kk', 'relaas', 'surat panggilan', 'akta kelahiran', 'akta kematian', 'surat kematian', 'saksi', 'jawaban', 'putusan', 'sertifikat', 'meterai'];

    /**
     * @param array $analysis Schema-valid analysis JSON
     *
     * @return array{dibawa: list<string>, disiapkan: list<string>}
     */
    public function build(array $analysis): array
    {
        $jenisDokumen = $analysis['jenis_dokumen'];

        if (in_array($jenisDokumen, ['putusan', 'penetapan'], true)) {
            $dibawa    = self::SETELAH_PUTUSAN;
            $disiapkan = [];
        } else {
            $peran   = in_array($analysis['penerima_adalah'], ['termohon', 'tergugat'], true) ? 'termohon' : 'pengaju';
            $perkara = config('Perkara')->jenis[$analysis['jenis_perkara']]['dibawa'] ?? [[], []];

            $surat     = $jenisDokumen === 'relaas_panggilan' ? 'Surat panggilan (relaas) ini' : 'Surat ini';
            $dibawa    = [self::KTP, $surat, ...$perkara[$peran === 'pengaju' ? 0 : 1]];
            $disiapkan = self::DISIAPKAN[$peran];
        }

        return [
            'dibawa'    => self::merge($dibawa, $analysis['dokumen_fisik_dibawa'] ?? [], self::MAX_DIBAWA),
            'disiapkan' => self::merge($disiapkan, $analysis['yang_perlu_disiapkan'] ?? [], self::MAX_DISIAPKAN),
        ];
    }

    /** Base items first; document-specific items only when they add something new. */
    private static function merge(array $base, array $fromDocument, int $max): array
    {
        $items   = $base;
        $covered = self::keys($base);

        foreach ($fromDocument as $item) {
            $keys = self::keys([$item]);
            if ($keys !== [] && array_intersect($keys, $covered) !== []) {
                continue;
            }
            if (in_array(mb_strtolower(trim($item)), array_map(static fn ($i) => mb_strtolower(trim($i)), $items), true)) {
                continue;
            }
            $items[] = $item;
            $covered = [...$covered, ...$keys];
        }

        return array_slice($items, 0, $max);
    }

    /** @return list<string> */
    private static function keys(array $items): array
    {
        $found = [];
        foreach ($items as $item) {
            $text = ' ' . mb_strtolower($item) . ' ';
            foreach (self::KUNCI as $key) {
                if (preg_match('/\b' . preg_quote($key, '/') . '\b/u', $text)) {
                    $found[] = $key;
                }
            }
        }

        return array_values(array_unique($found));
    }
}
