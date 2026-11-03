<?php

use App\Services\DocumentSchema;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Fixtures;

/**
 * @internal
 */
final class DocumentSchemaTest extends CIUnitTestCase
{
    public function testFixtureIsValid(): void
    {
        $this->assertSame([], DocumentSchema::validate(Fixtures::analysis()));
    }

    public function testMissingFieldIsReported(): void
    {
        $data = Fixtures::analysis();
        unset($data['ringkasan_awam']);

        $this->assertContains('$.ringkasan_awam: missing', DocumentSchema::validate($data));
    }

    public function testUnexpectedFieldIsReported(): void
    {
        $data           = Fixtures::analysis();
        $data['saran']  = 'Sebaiknya banding';

        $this->assertContains('$.saran: unexpected property', DocumentSchema::validate($data));
    }

    public function testEnumIsEnforced(): void
    {
        $data                  = Fixtures::analysis();
        $data['jenis_dokumen'] = 'surat_cinta';

        $errors = DocumentSchema::validate($data);

        $this->assertCount(1, $errors);
        $this->assertStringStartsWith("\$.jenis_dokumen: 'surat_cinta' is not one of", $errors[0]);
    }

    public function testNestedTypesAndDatePattern(): void
    {
        $data                       = Fixtures::analysis();
        $data['sidang']['tanggal']  = '10 November 2026';
        $data['kutipan']['pengadilan'] = 42;
        $data['yang_harus_dilakukan'][] = ['bukan' => 'string'];

        $errors = DocumentSchema::validate($data);

        $this->assertContains("\$.sidang.tanggal: '10 November 2026' does not match ^\\d{4}-\\d{2}-\\d{2}$", $errors);
        $this->assertContains('$.kutipan.pengadilan: expected string|null, got number', $errors);
        $this->assertContains('$.yang_harus_dilakukan[2]: expected string, got object', $errors);
    }

    public function testNullableFieldsAcceptNull(): void
    {
        $data                  = Fixtures::analysis();
        $data['nomor_perkara'] = null;
        $data['sidang']        = ['tanggal' => null, 'jam' => null, 'tempat' => null];
        $data['langkah_setelah_putusan'] = ['Ambil akta cerai di PTSP'];

        $this->assertSame([], DocumentSchema::validate($data));
    }

    public function testEmptyRingkasanIsRejected(): void
    {
        $data                   = Fixtures::analysis();
        $data['ringkasan_awam'] = '  ';

        $this->assertContains('$.ringkasan_awam: must not be empty', DocumentSchema::validate($data));
    }

    public function testEmptyObjectIsNotAnArray(): void
    {
        $data           = Fixtures::analysis();
        $data['sidang'] = [];

        $errors = DocumentSchema::validate($data);

        $this->assertContains('$.sidang.tanggal: missing', $errors);
    }

    public function testNonObjectRootIsRejected(): void
    {
        $this->assertSame(['$: expected object, got string'], DocumentSchema::validate('halo'));
    }

    public function testVerdictFixtureIsValid(): void
    {
        $this->assertSame([], DocumentSchema::validate(Fixtures::analysis('putusan_sintetis')));
    }

    public function testVerdictSectionsAreStrict(): void
    {
        $data = Fixtures::analysis('putusan_sintetis');
        unset($data['bagian_putusan']['petitum']);
        $data['bagian_putusan']['amar']      = 'Mengabulkan gugatan';
        $data['istilah_hukum'][]             = ['istilah' => 'Verstek'];

        $errors = DocumentSchema::validate($data);

        $this->assertContains('$.bagian_putusan.petitum: missing', $errors);
        $this->assertContains('$.bagian_putusan.amar: expected array, got string', $errors);
        $this->assertContains('$.istilah_hukum[4].arti: missing', $errors);
    }

    public function testResponseFormatCarriesTheSchema(): void
    {
        $format = DocumentSchema::responseFormat();

        $this->assertSame('json_schema', $format['type']);
        $this->assertSame(DocumentSchema::definition(), $format['json_schema']['schema']);
    }
}
