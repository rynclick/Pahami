<?php

use App\Services\CourtDirectory;
use App\Services\Verifier;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Fixtures;

/**
 * @internal
 */
final class VerifierTest extends CIUnitTestCase
{
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = new DateTimeImmutable('2026-10-28');
    }

    private function verify(array $data): array
    {
        return (new Verifier())->verify($data, $this->today);
    }

    public function testFullyQuotedFutureHearingIsHighConfidence(): void
    {
        $v = $this->verify(Fixtures::analysis());

        foreach (Verifier::KEY_FIELDS as $field) {
            $this->assertSame('ok', $v['fields'][$field]['status'], $field);
        }
        $this->assertSame('btl', $v['pengadilan_kode']);
        $this->assertFalse($v['sidang_sudah_lewat']);
        $this->assertFalse($v['perlu_konfirmasi_jadwal']);
        $this->assertSame('tinggi', $v['tingkat_keyakinan']);
    }

    public function testModelSelfReportIsIgnored(): void
    {
        $data                      = Fixtures::analysis();
        $data['tingkat_keyakinan'] = 'rendah';

        $this->assertSame('tinggi', $this->verify($data)['tingkat_keyakinan']);
    }

    public function testImpossibleDateIsUncertainAndNeedsConfirmation(): void
    {
        $data                      = Fixtures::analysis();
        $data['sidang']['tanggal'] = '2026-11-31';

        $v = $this->verify($data);

        $this->assertSame('tidak_pasti', $v['fields']['sidang_tanggal']['status']);
        $this->assertSame('tanggal tidak ada di kalender', $v['fields']['sidang_tanggal']['alasan']);
        $this->assertTrue($v['perlu_konfirmasi_jadwal']);
        $this->assertNull($v['sidang_sudah_lewat']);
    }

    public function testDateThatDisagreesWithQuoteIsUncertain(): void
    {
        $data                      = Fixtures::analysis();
        $data['sidang']['tanggal'] = '2026-11-11';

        $v = $this->verify($data);

        $this->assertSame('tanggal tidak cocok dengan kutipan', $v['fields']['sidang_tanggal']['alasan']);
        $this->assertTrue($v['perlu_konfirmasi_jadwal']);
    }

    public function testUncertainTimeAlsoNeedsConfirmation(): void
    {
        $data                         = Fixtures::analysis();
        $data['kutipan']['sidang_jam'] = 'Pukul : 10.00 WIB';

        $v = $this->verify($data);

        $this->assertSame('tidak_pasti', $v['fields']['sidang_jam']['status']);
        $this->assertTrue($v['perlu_konfirmasi_jadwal']);
    }

    public function testPastHearingNeedsNoConfirmation(): void
    {
        $data                            = Fixtures::analysis();
        $data['sidang']['tanggal']       = '2026-10-26';
        $data['kutipan']['sidang_tanggal'] = 'Senin / 26 Oktober 2026';

        $v = $this->verify($data);

        $this->assertTrue($v['sidang_sudah_lewat']);
        $this->assertFalse($v['perlu_konfirmasi_jadwal']);
    }

    public function testMalformedCaseNumber(): void
    {
        $data                  = Fixtures::analysis();
        $data['nomor_perkara'] = '123/Pdt.X/26/PA.Btl';

        $this->assertSame('format nomor perkara tidak dikenali', $this->verify($data)['fields']['nomor_perkara']['alasan']);
    }

    public function testCaseNumberWithStraySpacesStillMatches(): void
    {
        $data                             = Fixtures::analysis();
        $data['nomor_perkara']            = '123/Pdt.G/2026/PA. Btl';
        $data['kutipan']['nomor_perkara'] = 'Nomor 123/Pdt.G/ 2026/PA.Btl';

        $this->assertSame('ok', $this->verify($data)['fields']['nomor_perkara']['status']);
    }

    public function testMissingQuoteMakesFieldUncertain(): void
    {
        $data                             = Fixtures::analysis();
        $data['kutipan']['nomor_perkara'] = null;

        $v = $this->verify($data);

        $this->assertSame('nomor tidak ditemukan di kutipan', $v['fields']['nomor_perkara']['alasan']);
        $this->assertSame('sedang', $v['tingkat_keyakinan']);
    }

    public function testCourtOutsideDiyIsUncertain(): void
    {
        $data                          = Fixtures::analysis();
        $data['pengadilan']            = 'Pengadilan Agama Jakarta Selatan';
        $data['kutipan']['pengadilan'] = 'Pengadilan Agama Jakarta Selatan';

        $v = $this->verify($data);

        $this->assertSame('bukan pengadilan agama di DIY yang dikenal', $v['fields']['pengadilan']['alasan']);
        // Code still comes from the case-number suffix.
        $this->assertSame('btl', $v['pengadilan_kode']);
    }

    public function testCourtThatContradictsCaseNumberIsUncertain(): void
    {
        $data                          = Fixtures::analysis();
        $data['pengadilan']            = 'Pengadilan Agama Sleman';
        $data['kutipan']['pengadilan'] = 'Pengadilan Agama Sleman';

        $v = $this->verify($data);

        $this->assertSame('pengadilan tidak cocok dengan kode di nomor perkara', $v['fields']['pengadilan']['alasan']);
        $this->assertNull($v['pengadilan_kode']);
    }

    public function testRoleMustAppearInQuote(): void
    {
        $data                                = Fixtures::analysis();
        $data['kutipan']['penerima_adalah'] = 'Budi Contoh bin Fiktif';

        $this->assertSame('tidak_pasti', $this->verify($data)['fields']['penerima_adalah']['status']);
    }

    public function testVerdictWithoutHearingIsStillHighConfidence(): void
    {
        $data                       = Fixtures::analysis();
        $data['jenis_dokumen']      = 'putusan';
        $data['penerima_adalah']    = 'tidak_jelas';
        $data['sidang']             = ['tanggal' => null, 'jam' => null, 'tempat' => null];
        $data['kutipan']['sidang_tanggal']  = null;
        $data['kutipan']['sidang_jam']      = null;
        $data['kutipan']['penerima_adalah'] = null;

        $v = $this->verify($data);

        $this->assertSame('kosong', $v['fields']['sidang_tanggal']['status']);
        $this->assertFalse($v['perlu_konfirmasi_jadwal']);
        $this->assertSame('tinggi', $v['tingkat_keyakinan']);
    }

    public function testNothingVerifiedIsLowConfidence(): void
    {
        $data            = Fixtures::analysis();
        $data['kutipan'] = array_fill_keys(array_keys($data['kutipan']), null);

        $this->assertSame('rendah', $this->verify($data)['tingkat_keyakinan']);
    }

    public function testParsesIndonesianAndNumericDates(): void
    {
        $this->assertSame('2026-10-26', Verifier::parseTanggal('Senin / 26 Oktober 2026'));
        $this->assertSame('2026-02-03', Verifier::parseTanggal('3 Pebruari 2026'));
        $this->assertSame('2026-11-05', Verifier::parseTanggal('tanggal 05-11-2026'));
        $this->assertNull(Verifier::parseTanggal('Senin depan'));
        $this->assertNull(Verifier::parseTanggal('31 November 2026'));
    }

    public function testCourtDirectoryMatching(): void
    {
        $courts = new CourtDirectory();

        $this->assertSame('pta', $courts->match('PENGADILAN TINGGI AGAMA YOGYAKARTA'));
        $this->assertSame('yk', $courts->match('Pengadilan Agama Yogyakarta'));
        $this->assertSame('wno', $courts->match('PA Gunungkidul'));
        $this->assertNull($courts->match('Pengadilan Negeri Sleman'));
        $this->assertSame('smn', $courts->codeFromCaseNumber('999/Pdt.G/2026/PA.Smn'));
        $this->assertSame('pta', $courts->codeFromCaseNumber('99/Pdt.G/2026/PTA.Yk'));
        $this->assertSame('yk', $courts->codeFromCaseNumber('998/Pdt.G/2026/PA.YK'));
    }

    public function testCourtContact(): void
    {
        $contact = (new CourtDirectory())->contact('smn');

        $this->assertSame('Pengadilan Agama Sleman', $contact['name']);
        $this->assertSame('(0274) 868201', $contact['phone_display']);
        $this->assertSame('https://ptsp.pta-yogyakarta.go.id', $contact['ptsp_url']);
        $this->assertNull((new CourtDirectory())->contact('jkt'));
    }

    /**
     * A PDF text layer for the synthetic summons, laid out like pdftotext
     * output: line breaks, the Mahkamah Agung watermark letters ("In", "do")
     * between lines, and enough words to count as a text layer.
     */
    private static function relaasText(): string
    {
        return "PENGADILAN AGAMA BANTUL\nRELAAS PANGGILAN SIDANG\nNomor 123/Pdt.G/2026/PA.Btl\nIn\ndo\n"
            . "Pada hari ini telah kami panggil Budi Contoh bin Fiktif, sebagai Tergugat untuk menghadap\n"
            . "sidang Pengadilan Agama Bantul yang akan dilaksanakan pada hari Selasa / 10 November 2026\n"
            . "Pukul : 09.00 WIB di ruang sidang Pengadilan Agama Bantul dengan membawa surat-surat\n"
            . str_repeat('yang berkaitan dengan perkara tersebut dan bukti identitas diri yang sah. ', 4);
    }

    public function testQuotesFoundInPdfTextStayVerified(): void
    {
        $v = (new Verifier())->verify(Fixtures::analysis(), $this->today, self::relaasText());

        $this->assertTrue($v['teks_sumber_dicek']);
        foreach (Verifier::KEY_FIELDS as $field) {
            $this->assertSame('ok', $v['fields'][$field]['status'], $field);
        }
        $this->assertSame('tinggi', $v['tingkat_keyakinan']);
    }

    public function testQuoteMissingFromPdfTextIsUncertain(): void
    {
        // The model "quoted" a time the PDF does not contain.
        $text = str_replace('Pukul : 09.00 WIB', 'Pukul : 13.30 WIB', self::relaasText());

        $v = (new Verifier())->verify(Fixtures::analysis(), $this->today, $text);

        $this->assertSame('tidak_pasti', $v['fields']['sidang_jam']['status']);
        $this->assertSame('kutipan tidak ditemukan di teks PDF', $v['fields']['sidang_jam']['alasan']);
        $this->assertTrue($v['perlu_konfirmasi_jadwal'], 'reminders wait for the user');
        $this->assertSame('ok', $v['fields']['sidang_tanggal']['status']);
    }

    public function testScanWithoutTextLayerIsNotCheckedAgainstIt(): void
    {
        $v = (new Verifier())->verify(Fixtures::analysis(), $this->today, "\f\f  \n");

        $this->assertFalse($v['teks_sumber_dicek']);
        $this->assertSame('tinggi', $v['tingkat_keyakinan']);
        $this->assertNull($v['kutipan_amar_di_teks']);
    }

    public function testAmarQuoteIsCheckedAgainstPdfText(): void
    {
        $data = Fixtures::analysis('putusan_sintetis');
        $text = "PUTUSAN Nomor 456/Pdt.G/2026/PA.Wt PENGADILAN AGAMA WATES\n"
            . str_repeat('Menimbang bahwa berdasarkan pertimbangan tersebut di atas gugatan Penggugat beralasan hukum. ', 5)
            . "1. Mengabulkan gugatan Penggugat;\nMENGADILI:\nDalam Konvensi\n";

        $this->assertTrue((new Verifier())->verify($data, $this->today, $text)['kutipan_amar_di_teks']);

        $other = str_replace('Mengabulkan gugatan Penggugat;', 'Menolak gugatan Penggugat;', $text);
        $this->assertFalse((new Verifier())->verify($data, $this->today, $other)['kutipan_amar_di_teks']);
    }

    public function testAppealVerdictNumberOfTheHighCourtIsRecognized(): void
    {
        $data                          = Fixtures::analysis('putusan_sintetis');
        $data['nomor_perkara']         = '99/Pdt.G/2026/PTA.Yk';
        $data['pengadilan']            = 'Pengadilan Tinggi Agama Yogyakarta';
        $data['kutipan']['nomor_perkara'] = 'Nomor 99/Pdt.G/2026/PTA.Yk';
        $data['kutipan']['pengadilan']    = 'PENGADILAN TINGGI AGAMA YOGYAKARTA';

        $v = $this->verify($data);

        $this->assertSame('ok', $v['fields']['nomor_perkara']['status']);
        $this->assertSame('ok', $v['fields']['pengadilan']['status']);
        $this->assertSame('pta', $v['pengadilan_kode']);
    }
}
