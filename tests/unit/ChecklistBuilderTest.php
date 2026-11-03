<?php

use App\Services\ChecklistBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Fixtures;

/**
 * @internal
 */
final class ChecklistBuilderTest extends CIUnitTestCase
{
    private function build(array $overrides): array
    {
        return (new ChecklistBuilder())->build(array_merge(Fixtures::analysis(), $overrides));
    }

    public function testRespondingPartyInDivorce(): void
    {
        $list = $this->build(['jenis_perkara' => 'cerai_gugat', 'penerima_adalah' => 'tergugat']);

        $this->assertSame(['KTP asli dan fotokopinya', 'Surat panggilan (relaas) ini', 'Buku Nikah (jika Anda menyimpannya)', 'Kartu Keluarga'], $list['dibawa']);
        $this->assertStringStartsWith('Jawaban atas gugatan', $list['disiapkan'][0]);
    }

    public function testFilingPartyInDivorceBringsMarriageBookAndWitnesses(): void
    {
        $list = $this->build(['jenis_perkara' => 'cerai_talak', 'penerima_adalah' => 'pemohon', 'dokumen_fisik_dibawa' => [], 'yang_perlu_disiapkan' => []]);

        $this->assertContains('Buku Nikah asli dan fotokopinya', $list['dibawa']);
        $this->assertSame('Dua orang saksi yang mengetahui keadaan perkara Anda', $list['disiapkan'][0]);
    }

    public function testInheritanceCase(): void
    {
        $list = $this->build(['jenis_perkara' => 'waris', 'penerima_adalah' => 'penggugat']);

        $this->assertContains('Surat/akta kematian pewaris', $list['dibawa']);
    }

    public function testVerdictNeedsOnlyFollowUpPapers(): void
    {
        $list = $this->build(['jenis_dokumen' => 'putusan', 'dokumen_fisik_dibawa' => [], 'yang_perlu_disiapkan' => []]);

        $this->assertSame(['KTP asli', 'Salinan putusan/penetapan ini atau nomor perkaranya'], $list['dibawa']);
        $this->assertSame([], $list['disiapkan']);
    }

    public function testDocumentSpecificItemsAreAddedWithoutDuplicates(): void
    {
        $list = $this->build([
            'penerima_adalah'      => 'tergugat',
            'dokumen_fisik_dibawa' => ['KTP asli', 'Surat gugatan yang dilampirkan', 'Kartu Keluarga asli'],
        ]);

        // "KTP asli" and "Kartu Keluarga asli" are already covered; the attached claim is new.
        $this->assertSame(1, count(array_filter($list['dibawa'], static fn ($i) => str_contains($i, 'KTP'))));
        $this->assertContains('Surat gugatan yang dilampirkan', $list['dibawa']);
        $this->assertNotContains('Kartu Keluarga asli', $list['dibawa']);
    }

    public function testUnknownDocumentTypeStillGetsTheBasics(): void
    {
        $list = $this->build(['jenis_dokumen' => 'lainnya', 'jenis_perkara' => 'lainnya', 'penerima_adalah' => 'tidak_jelas', 'dokumen_fisik_dibawa' => []]);

        $this->assertSame(['KTP asli dan fotokopinya', 'Surat ini'], $list['dibawa']);
    }

    public function testListsAreCapped(): void
    {
        $list = $this->build(['penerima_adalah' => 'tergugat', 'dokumen_fisik_dibawa' => ['Map', 'Pulpen', 'Foto rumah', 'Struk', 'Nota']]);

        $this->assertCount(6, $list['dibawa']);
    }
}
