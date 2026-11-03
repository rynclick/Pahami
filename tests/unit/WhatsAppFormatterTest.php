<?php

use App\Services\Verifier;
use App\Services\WhatsAppFormatter;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Fixtures;

/**
 * @internal
 */
final class WhatsAppFormatterTest extends CIUnitTestCase
{
    /** @return list<string> */
    private function reply(array $analysis, string $today = '2026-10-28', array $kinds = []): array
    {
        $verification = (new Verifier())->verify($analysis, new DateTimeImmutable($today));

        return (new WhatsAppFormatter())->documentReply($analysis, $verification, $kinds);
    }

    public function testFutureHearingProducesThreeMessages(): void
    {
        $messages = $this->reply(Fixtures::analysis());

        $this->assertCount(3, $messages);
        $this->assertSame(WhatsAppFormatter::FOLLOW_UP, $messages[2]);
    }

    public function testExplanationFollowsTheFlow(): void
    {
        [$explanation] = $this->reply(Fixtures::analysis());

        $this->assertStringStartsWith("📄 *Surat Panggilan Sidang (Relaas)*\nPerkara 123/Pdt.G/2026/PA.Btl · Pengadilan Agama Bantul\n", $explanation);
        $this->assertStringContainsString('📅 *Sidang:* Selasa, 10 November 2026, jam 09.00 WIB', $explanation);
        $this->assertStringContainsString('📍 Ruang Sidang 1 Pengadilan Agama Bantul', $explanation);
        $this->assertStringContainsString("✅ *Yang harus dilakukan:*\n1. Datang ke", $explanation);
        $this->assertStringNotContainsString('tidak pasti', $explanation);
        $this->assertStringEndsWith(
            'ℹ️ Pahami membantu Anda memahami dokumen, bukan nasihat hukum. Untuk kepastian, hubungi PTSP Pengadilan Agama Bantul (0274) 367423 atau PTSP Online: https://ptsp.pta-yogyakarta.go.id',
            $explanation,
        );
    }

    public function testAtMostThreeSteps(): void
    {
        $data                         = Fixtures::analysis();
        $data['yang_harus_dilakukan'] = ['satu', 'dua', 'tiga', 'empat', 'lima'];

        [$explanation] = $this->reply($data);

        $this->assertStringContainsString('3. tiga', $explanation);
        $this->assertStringNotContainsString('empat', $explanation);
    }

    public function testChecklistPromisesExactlyTheScheduledReminders(): void
    {
        $checklist = $this->reply(Fixtures::analysis(), kinds: ['h3', 'h1', 'h0'])[1];

        $this->assertStringContainsString("📋 *Yang perlu dibawa:*\n• KTP asli dan fotokopinya\n• Surat panggilan (relaas) ini", $checklist);
        $this->assertStringContainsString('📝 *Yang perlu disiapkan:*', $checklist);
        $this->assertStringEndsWith('⏰ Saya akan mengingatkan Anda H-3, H-1, dan pagi hari sidang. Balas *stop* jika tidak perlu.', $checklist);

        $late = $this->reply(Fixtures::analysis(), kinds: ['h0'])[1];
        $this->assertStringEndsWith('Saya akan mengingatkan Anda pagi hari sidang. Balas *stop* jika tidak perlu.', $late);
    }

    public function testNoReminderPromiseWhenNothingWasScheduled(): void
    {
        $this->assertStringNotContainsString('mengingatkan', $this->reply(Fixtures::analysis())[1]);
    }

    public function testReminderMessageFollowsTheBrief(): void
    {
        $document = ['nomor_perkara' => '123/Pdt.G/2026/PA.Btl', 'pengadilan_kode' => 'btl', 'sidang_tanggal' => '2026-11-10', 'sidang_jam' => '09.00 WIB'];

        $text = (new WhatsAppFormatter())->reminder($document, Fixtures::analysis(), 'h1');

        $this->assertStringStartsWith(
            '📅 Pengingat: sidang perkara 123/Pdt.G/2026/PA.Btl di Pengadilan Agama Bantul pada Selasa, 10 November 2026 jam 09.00 WIB, Ruang Sidang 1 Pengadilan Agama Bantul.',
            $text,
        );
        $this->assertStringContainsString("\nBawa: KTP asli dan fotokopinya, Surat panggilan (relaas) ini, Buku Nikah (jika Anda menyimpannya).", $text);
        $this->assertStringContainsString('Datang 30 menit lebih awal.', $text);
        $this->assertStringContainsString('*hari ini*', (new WhatsAppFormatter())->reminder($document, Fixtures::analysis(), 'h0'));
    }

    public function testScheduleCommandText(): void
    {
        $f        = new WhatsAppFormatter();
        $document = ['nomor_perkara' => '123/Pdt.G/2026/PA.Btl', 'sidang_tanggal' => '2026-11-10', 'sidang_jam' => '09.00 WIB'];

        $this->assertStringStartsWith('Belum ada jadwal sidang', $f->schedule(null, []));
        $text = $f->schedule($document, [['send_at' => '2026-11-07 09:00:00'], ['send_at' => '2026-11-09 16:00:00']]);
        $this->assertStringContainsString("Selasa, 10 November 2026 jam 09.00 WIB", $text);
        $this->assertStringContainsString("• Sabtu, 7 November 2026 jam 09.00\n• Senin, 9 November 2026 jam 16.00", $text);
    }

    public function testCourtCommandText(): void
    {
        $f = new WhatsAppFormatter();

        $this->assertStringContainsString("🏛️ *Pengadilan Agama Sleman*\nJalan Parasamya", $f->court('smn'));
        $this->assertStringContainsString('https://ptsp.pta-yogyakarta.go.id', $f->court('smn'));
        $all = $f->court(null);
        $this->assertStringContainsString('• Pengadilan Agama Wonosari: (0274) 391325', $all);
        $this->assertStringContainsString('• Pengadilan Tinggi Agama Yogyakarta: (0274) 380355', $all);
    }

    public function testPastHearingIsFlaggedWithoutReminderPromise(): void
    {
        $messages = $this->reply(Fixtures::analysis(), '2026-11-12');

        $this->assertStringContainsString('⚠️ Tanggal sidang ini sudah lewat.', $messages[0]);
        $this->assertStringNotContainsString('mengingatkan', $messages[1]);
    }

    public function testUncertainTimeIsLabelledAndAskedBack(): void
    {
        $data                          = Fixtures::analysis();
        $data['kutipan']['sidang_jam'] = 'Pukul : 10.00 WIB';

        [$explanation, $checklist] = $this->reply($data);

        $this->assertStringContainsString('jam 09.00 WIB _(tidak pasti)_', $explanation);
        $this->assertStringContainsString('❓ Apakah sidang Anda tanggal 10 November 2026 jam 09.00 WIB? Balas *ya* atau kirim tanggal yang benar.', $checklist);
        $this->assertStringNotContainsString('mengingatkan', $checklist);
    }

    public function testLowConfidenceOnlyAsksForABetterPhoto(): void
    {
        $data            = Fixtures::analysis();
        $data['kutipan'] = array_fill_keys(array_keys($data['kutipan']), null);

        $this->assertSame([WhatsAppFormatter::UNREADABLE], $this->reply($data));
    }

    public function testVerdictShowsOutcomeAndNextSteps(): void
    {
        $data                    = Fixtures::analysis();
        $data['jenis_dokumen']   = 'putusan';
        $data['penerima_adalah'] = 'tidak_jelas';
        $data['sidang']          = ['tanggal' => null, 'jam' => null, 'tempat' => null];
        $data['kutipan']         = array_merge($data['kutipan'], ['sidang_tanggal' => null, 'sidang_jam' => null, 'penerima_adalah' => null]);
        $data['amar_ringkas']    = 'Pengadilan mengabulkan gugatan cerai.';
        $data['langkah_setelah_putusan'] = ['Akta cerai dapat diambil di PTSP setelah putusan berkekuatan hukum tetap.'];

        [$explanation, $checklist] = $this->reply($data);

        $this->assertStringStartsWith('📄 *Putusan Pengadilan*', $explanation);
        $this->assertStringContainsString('⚖️ *Isi putusan:* Pengadilan mengabulkan gugatan cerai.', $explanation);
        $this->assertStringNotContainsString('📅', $explanation);
        $this->assertStringContainsString("📌 *Setelah putusan:*\n• Akta cerai", $checklist);
        $this->assertStringNotContainsString('mengingatkan', $checklist);
    }

    public function testAmarNotFoundInPdfTextIsFlagged(): void
    {
        $data         = Fixtures::analysis('putusan_sintetis');
        $verification = (new Verifier())->verify($data, new DateTimeImmutable('2026-10-28'));
        $formatter    = new WhatsAppFormatter();

        $this->assertStringNotContainsString(WhatsAppFormatter::AMAR_TIDAK_DITEMUKAN, $formatter->documentReply($data, $verification)[0]);

        $verification['kutipan_amar_di_teks'] = false;
        $this->assertStringContainsString('_' . WhatsAppFormatter::AMAR_TIDAK_DITEMUKAN . '_', $formatter->documentReply($data, $verification)[0]);
    }

    public function testUpToThreeLegalTermsAreExplained(): void
    {
        $checklist = $this->reply(Fixtures::analysis('putusan_sintetis'))[1];

        $this->assertStringContainsString("📖 *Istilah dalam surat ini:*\n• _Amar_: bagian akhir putusan", $checklist);
        $this->assertStringContainsString('_Verstek_', $checklist);
        $this->assertStringNotContainsString('Petitum', $checklist);
    }

    public function testUnknownCourtFallsBackInDisclaimer(): void
    {
        $this->assertStringEndsWith('hubungi PTSP pengadilan yang menangani perkara Anda atau PTSP Online: https://ptsp.pta-yogyakarta.go.id', (new WhatsAppFormatter())->disclaimer(null));
    }

    public function testIndonesianDate(): void
    {
        $this->assertSame('Senin, 26 Oktober 2026', WhatsAppFormatter::tanggal('2026-10-26'));
    }
}
