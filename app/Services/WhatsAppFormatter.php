<?php

namespace App\Services;

/**
 * Analysis JSON → WhatsApp message texts (CLAUDE.md §7, flow 2).
 *
 * Uses WhatsApp formatting (*bold*, _italic_). Fields the Verifier marked
 * tidak_pasti are labelled as such; nothing uncertain is stated as fact.
 */
class WhatsAppFormatter
{
    public const READING    = 'Sedang saya baca… ⏳';
    public const UNREADABLE = 'Sebagian surat tidak terbaca. Mohon foto ulang dengan cahaya cukup dan seluruh halaman terlihat.';
    public const FOLLOW_UP  = 'Mau tanya apa lagi tentang surat ini?';
    public const INTRO      = "Halo! Saya *Pahami* 👋 Saya membantu Anda memahami surat dari Pengadilan Agama.\nKirim foto, PDF, atau pesan suara tentang surat dari pengadilan.";
    public const GANGGUAN   = 'Maaf, Pahami sedang mengalami gangguan. Silakan kirim ulang surat Anda beberapa menit lagi.';
    public const AUDIO_KOSONG = 'Maaf, pesan suara Anda tidak terdengar jelas. Silakan ulangi di tempat yang lebih tenang, atau ketik pertanyaan Anda.';
    public const AUDIO_GAGAL  = 'Maaf, pesan suara belum bisa saya dengarkan saat ini. Silakan ketik pertanyaan Anda.';
    public const FILE_TIDAK_DIDUKUNG = 'Maaf, jenis file ini belum bisa saya baca. Kirim foto (JPG/PNG) atau PDF surat dari pengadilan.';
    public const AMAR_TIDAK_DITEMUKAN = 'Bagian amar ini belum bisa saya cocokkan dengan teks PDF. Cocokkan dengan salinan putusan Anda.';

    private const MAX_STEPS = 3;
    private const MAX_TERMS = 3;

    private const JENIS_LABEL = [
        'relaas_panggilan' => 'Surat Panggilan Sidang (Relaas)',
        'gugatan'          => 'Surat Gugatan',
        'permohonan'       => 'Surat Permohonan',
        'putusan'          => 'Putusan Pengadilan',
        'penetapan'        => 'Penetapan Pengadilan',
        'pemberitahuan'    => 'Surat Pemberitahuan',
        'lainnya'          => 'Dokumen Pengadilan',
    ];

    private const HARI  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    private const KIND_LABEL = ['h3' => 'H-3', 'h1' => 'H-1', 'h0' => 'pagi hari sidang'];

    public function __construct(
        private readonly CourtDirectory $courts = new CourtDirectory(),
        private readonly ChecklistBuilder $checklists = new ChecklistBuilder(),
    ) {
    }

    /**
     * The messages sent after a document is analyzed, in order.
     * Low confidence yields only the "tidak terbaca" message: never guess.
     *
     * @param list<string> $reminderKinds Reminders actually scheduled (h3, h1, h0); only these are promised
     *
     * @return list<string>
     */
    public function documentReply(array $analysis, array $verification, array $reminderKinds = []): array
    {
        if ($verification['tingkat_keyakinan'] === 'rendah') {
            return [self::UNREADABLE];
        }

        // The checklist message is dropped when there is nothing to list.
        return array_values(array_filter([
            $this->explanation($analysis, $verification),
            $this->checklist($analysis, $verification, $reminderKinds),
            self::FOLLOW_UP,
        ], static fn (string $message) => $message !== ''));
    }

    /** $withContact false when the message already names the court's contact (escalation). */
    public function disclaimer(?string $courtCode, bool $withContact = true): string
    {
        $text = 'ℹ️ Pahami membantu Anda memahami dokumen, bukan nasihat hukum.';
        if (! $withContact) {
            return $text;
        }

        $court  = $courtCode !== null ? $this->courts->contact($courtCode) : null;
        $target = $court !== null ? "{$court['name']} {$court['phone_display']}" : 'pengadilan yang menangani perkara Anda';

        return "{$text} Untuk kepastian, hubungi PTSP {$target} atau PTSP Online: " . CourtDirectory::PTSP_URL;
    }

    /** "2026-10-26" → "Senin, 26 Oktober 2026". */
    public static function tanggal(string $ymd): string
    {
        $ts = strtotime($ymd);

        return self::HARI[(int) date('w', $ts)] . ', ' . (int) date('j', $ts) . ' ' . self::BULAN[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    }

    private function explanation(array $a, array $v): string
    {
        $fields = $v['fields'];
        $lines  = ['📄 *' . (self::JENIS_LABEL[$a['jenis_dokumen']] ?? self::JENIS_LABEL['lainnya']) . '*'];

        $header = [];
        if ($a['nomor_perkara'] !== null) {
            $header[] = 'Perkara ' . $a['nomor_perkara'] . self::uncertain($fields['nomor_perkara']);
        }
        $court = $v['pengadilan_kode'] !== null ? $this->courts->name($v['pengadilan_kode']) : $a['pengadilan'];
        if ($court !== null) {
            $header[] = $court . self::uncertain($fields['pengadilan']);
        }
        if ($header !== []) {
            $lines[] = implode(' · ', $header);
        }

        $lines[] = '';
        $lines[] = $a['ringkasan_awam'];

        if ($a['jenis_dokumen'] === 'putusan' && $a['amar_ringkas'] !== null) {
            $lines[] = '';
            $lines[] = '⚖️ *Isi putusan:* ' . $a['amar_ringkas'];
            if (($v['kutipan_amar_di_teks'] ?? null) === false) {
                $lines[] = '_' . self::AMAR_TIDAK_DITEMUKAN . '_';
            }
        }

        $sidang = $this->sidang($a['sidang'], $v);
        if ($sidang !== []) {
            $lines[] = '';
            array_push($lines, ...$sidang);
        }

        $steps = array_slice($a['yang_harus_dilakukan'], 0, self::MAX_STEPS);
        if ($steps !== []) {
            $lines[] = '';
            $lines[] = '✅ *Yang harus dilakukan:*';
            foreach ($steps as $i => $step) {
                $lines[] = ($i + 1) . '. ' . $step;
            }
        }

        $lines[] = '';
        $lines[] = $this->disclaimer($v['pengadilan_kode']);

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function sidang(array $sidang, array $v): array
    {
        if ($sidang['tanggal'] === null && $sidang['jam'] === null) {
            return [];
        }

        $dateCheck = $v['fields']['sidang_tanggal'];
        $timeCheck = $v['fields']['sidang_jam'];

        $when = [];
        if ($sidang['tanggal'] !== null) {
            $when[] = ($dateCheck['status'] === 'ok' ? self::tanggal($sidang['tanggal']) : $sidang['tanggal']) . self::uncertain($dateCheck);
        }
        if ($sidang['jam'] !== null) {
            $when[] = 'jam ' . $sidang['jam'] . self::uncertain($timeCheck);
        }

        $lines = ['📅 *Sidang:* ' . implode(', ', $when)];
        if ($sidang['tempat'] !== null) {
            $lines[] = '📍 ' . $sidang['tempat'];
        }
        if ($v['sidang_sudah_lewat'] === true) {
            $lines[] = '⚠️ Tanggal sidang ini sudah lewat. Hubungi PTSP atau PTSP Online (' . CourtDirectory::PTSP_URL . ') untuk menanyakan jadwal berikutnya.';
        }

        return $lines;
    }

    /** The checklist on its own, for the "checklist" command. */
    public function checklistOnly(array $analysis): string
    {
        $text = $this->checklistLines($analysis);

        return $text === '' ? 'Belum ada daftar dokumen untuk surat ini.' : $text;
    }

    private function checklist(array $a, array $v, array $reminderKinds): string
    {
        $text  = $this->checklistLines($a);
        $lines = $text === '' ? [] : explode("\n", $text);

        $terms = array_slice($a['istilah_hukum'] ?? [], 0, self::MAX_TERMS);
        if ($terms !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = '📖 *Istilah dalam surat ini:*';
            foreach ($terms as $term) {
                $lines[] = "• _{$term['istilah']}_: {$term['arti']}";
            }
        }

        $closing = $this->reminderLine($a['sidang'], $v, $reminderKinds);
        if ($closing !== null) {
            $lines[] = '';
            $lines[] = $closing;
        }

        return implode("\n", $lines);
    }

    private function checklistLines(array $a): string
    {
        $checklist = $this->checklists->build($a);
        $lines     = [];

        foreach ([
            '📋 *Yang perlu dibawa:*'    => $checklist['dibawa'],
            '📝 *Yang perlu disiapkan:*' => $checklist['disiapkan'],
            '📌 *Setelah putusan:*'      => $a['langkah_setelah_putusan'] ?? [],
        ] as $title => $items) {
            if ($items === []) {
                continue;
            }
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = $title;
            foreach ($items as $item) {
                $lines[] = '• ' . $item;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Reminder promise for a verified future hearing, or a confirmation
     * question when the date/time is uncertain (CLAUDE.md §8).
     */
    private function reminderLine(array $sidang, array $v, array $reminderKinds): ?string
    {
        if ($v['perlu_konfirmasi_jadwal']) {
            $ask = $sidang['tanggal'];
            if (($parsed = strtotime($sidang['tanggal'])) !== false && $v['fields']['sidang_tanggal']['status'] === 'ok') {
                $ask = (int) date('j', $parsed) . ' ' . self::BULAN[(int) date('n', $parsed)] . ' ' . date('Y', $parsed);
            }
            $jam = $sidang['jam'] !== null ? ' jam ' . $sidang['jam'] : '';

            return "❓ Apakah sidang Anda tanggal {$ask}{$jam}? Balas *ya* atau kirim tanggal yang benar.";
        }

        if ($reminderKinds !== []) {
            return '⏰ Saya akan mengingatkan Anda ' . self::kinds($reminderKinds) . '. Balas *stop* jika tidak perlu.';
        }

        return null;
    }

    /**
     * Reminder message (CLAUDE.md §7 flow 6).
     *
     * @param array $document documents row (verified sidang_tanggal / sidang_jam)
     */
    public function reminder(array $document, array $analysis, string $kind): string
    {
        $court  = $document['pengadilan_kode'] !== null ? $this->courts->name($document['pengadilan_kode']) : ($analysis['pengadilan'] ?? 'pengadilan');
        $tempat = $analysis['sidang']['tempat'] ?? null;
        $bawa   = array_slice($this->checklists->build($analysis)['dibawa'], 0, 3);
        $nomor  = $document['nomor_perkara'] ?? $analysis['nomor_perkara'] ?? '';
        $prefix = $kind === 'h0' ? '📅 Pengingat: *hari ini* sidang perkara' : '📅 Pengingat: sidang perkara';

        return "{$prefix} {$nomor} di {$court} pada " . self::when($document) . ($tempat !== null ? ", {$tempat}" : '') . '.'
            . "\nBawa: " . implode(', ', $bawa) . '.'
            . "\nDatang 30 menit lebih awal."
            . "\n\nBalas *stop* jika tidak perlu diingatkan lagi.";
    }

    /**
     * "jadwal" command.
     *
     * @param list<array> $pending Pending reminder rows
     */
    public function schedule(?array $document, array $pending): string
    {
        if ($document === null || $document['sidang_tanggal'] === null) {
            return 'Belum ada jadwal sidang yang tersimpan. Kirim foto surat panggilan sidang Anda.';
        }

        $lines = ["📅 *Jadwal sidang* perkara {$document['nomor_perkara']}:", self::when($document), ''];

        if ($pending === []) {
            $lines[] = 'Tidak ada pengingat aktif.';
        } else {
            $lines[] = '⏰ Pengingat:';
            foreach ($pending as $row) {
                $ts      = strtotime($row['send_at']);
                $lines[] = '• ' . self::tanggal(date('Y-m-d', $ts)) . ' jam ' . date('H.i', $ts);
            }
            $lines[] = '';
            $lines[] = 'Balas *stop* untuk menghentikan pengingat.';
        }

        return implode("\n", $lines);
    }

    /** "pengadilan" command: one court, or all six when unknown. */
    public function court(?string $code): string
    {
        $contact = $code !== null ? $this->courts->contact($code) : null;

        if ($contact !== null) {
            return "🏛️ *{$contact['name']}*\n{$contact['address']}\n☎️ {$contact['phone_display']}\n✉️ {$contact['email']}"
                . "\n\nLayanan PTSP Online: {$contact['ptsp_url']}";
        }

        $lines = ['🏛️ *Pengadilan Agama di DIY*'];
        foreach (CourtDirectory::CODES as $c) {
            $info    = $this->courts->contact($c);
            $lines[] = "• {$info['name']}: {$info['phone_display']}";
        }
        $lines[] = '';
        $lines[] = 'Layanan PTSP Online: ' . CourtDirectory::PTSP_URL;

        return implode("\n", $lines);
    }

    /** Added to an escalated answer: who to contact, and the desk summary offer. */
    public function escalation(?string $courtCode): string
    {
        $contact = $courtCode !== null ? $this->courts->contact($courtCode) : null;
        $where   = $contact !== null ? "PTSP {$contact['name']} {$contact['phone_display']}" : 'PTSP pengadilan yang menangani perkara Anda';

        return "📞 Silakan tanyakan ke {$where}, atau lewat PTSP Online: " . CourtDirectory::PTSP_URL
            . "\nKetik *ringkasan* untuk mendapat ringkasan surat yang bisa Anda tunjukkan ke petugas.";
    }

    /** "ringkasan" command: a short summary the user can show at the service desk. */
    public function deskSummary(array $document, ?string $lastQuestion): string
    {
        $a     = $document['analysis_json']['analysis'] ?? [];
        $court = $document['pengadilan_kode'] !== null ? $this->courts->name($document['pengadilan_kode']) : ($a['pengadilan'] ?? '—');
        $label = config('Perkara')->jenis[$a['jenis_perkara'] ?? 'lainnya']['label'] ?? 'Perkara lainnya';
        if (($a['jenis_perkara'] ?? null) === 'lainnya' && ! empty($a['jenis_perkara_teks'])) {
            $label = $a['jenis_perkara_teks'];
        }

        $lines = [
            '📝 *Ringkasan untuk petugas PTSP*',
            'Jenis surat: ' . (self::JENIS_LABEL[$a['jenis_dokumen'] ?? 'lainnya'] ?? self::JENIS_LABEL['lainnya']),
            'Jenis perkara: ' . $label,
            'Nomor perkara: ' . ($document['nomor_perkara'] ?? $a['nomor_perkara'] ?? '—'),
            'Pengadilan: ' . $court,
        ];
        if ($document['sidang_tanggal'] !== null) {
            $lines[] = 'Jadwal sidang: ' . self::when($document);
        }
        if ($lastQuestion !== null && $lastQuestion !== '') {
            $lines[] = 'Pertanyaan saya: ' . $lastQuestion;
        }
        $lines[] = '';
        $lines[] = '_Ringkasan ini dibuat oleh Pahami dari surat yang saya terima._';

        return implode("\n", $lines);
    }

    public function help(): string
    {
        return implode("\n", [
            '🤝 *Yang bisa saya bantu:*',
            '• Kirim *foto* atau *PDF* surat dari pengadilan, saya jelaskan isinya.',
            '• *checklist*: daftar dokumen yang perlu dibawa',
            '• *jadwal*: jadwal sidang dan pengingat',
            '• *stop*: hentikan pengingat',
            '• *pengadilan*: alamat dan telepon pengadilan',
            '• *ringkasan*: ringkasan surat untuk ditunjukkan ke petugas PTSP',
            '• *bantuan*: pesan ini',
            '',
            'Anda juga bisa bertanya tentang isi surat, dengan tulisan atau pesan suara 🎙️.',
        ]);
    }

    public function stopped(int $cancelled): string
    {
        return $cancelled > 0
            ? '✅ Pengingat sidang dihentikan. Kirim surat panggilan baru kapan saja jika ingin diingatkan lagi.'
            : 'Tidak ada pengingat aktif.';
    }

    /** Reply after the user confirmed or corrected an uncertain hearing date. */
    public function scheduleConfirmed(array $document, array $reminderKinds): string
    {
        $text = '✅ Terima kasih. Jadwal sidang dicatat: ' . self::when($document) . '.';

        return $reminderKinds === []
            ? $text . "\nWaktu pengingat sudah lewat, jadi tidak ada pengingat yang dijadwalkan."
            : $text . "\n⏰ Saya akan mengingatkan Anda " . self::kinds($reminderKinds) . '. Balas *stop* jika tidak perlu.';
    }

    private static function when(array $document): string
    {
        return self::tanggal($document['sidang_tanggal']) . ($document['sidang_jam'] !== null ? ' jam ' . $document['sidang_jam'] : '');
    }

    /** ['h3', 'h1', 'h0'] → "H-3, H-1, dan pagi hari sidang". */
    private static function kinds(array $kinds): string
    {
        $parts = array_map(static fn ($k) => self::KIND_LABEL[$k], $kinds);

        if (count($parts) <= 2) {
            return implode(' dan ', $parts);
        }

        return implode(', ', array_slice($parts, 0, -1)) . ', dan ' . end($parts);
    }

    private static function uncertain(array $check): string
    {
        return $check['status'] === 'tidak_pasti' ? ' _(tidak pasti)_' : '';
    }
}
