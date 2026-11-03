<?php

use App\Services\PdfPageSelector;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Page selection on synthetic page texts (no PDF needed).
 *
 * @internal
 */
final class PdfPageSelectorTest extends CIUnitTestCase
{
    /** @return array<int, string> */
    private static function pages(int $total, array $content): array
    {
        $texts = [];
        for ($p = 1; $p <= $total; $p++) {
            $texts[$p] = $content[$p] ?? "Menimbang, bahwa halaman {$p} berisi uraian biasa.";
        }

        return $texts;
    }

    public function testShortPdfIsSentWhole(): void
    {
        $this->assertSame(range(1, 15), PdfPageSelector::selectPages(self::pages(15, [])));
    }

    public function testLongVerdictKeepsTheStructuralPages(): void
    {
        // Shaped like the 68-page PA Yogyakarta verdict used in manual testing.
        $texts = self::pages(68, [
            7  => 'DUDUK PERKARA Bahwa Para Penggugat dengan surat gugatannya',
            14 => 'dan memberikan putusan sebagai berikut: PRIMAIR 1. Mengabulkan gugatan',
            52 => 'Dalam Rekonvensi Bahwa Penggugat Rekonvensi mohon',
            58 => 'PRIMAIR dalam rekonvensi',
            62 => 'PERTIMBANGAN HUKUM Dalam Konvensi',
            67 => 'M E N G A D I L I Dalam Konvensi 1. Menolak gugatan',
        ]);

        $this->assertSame([1, 2, 7, 8, 14, 15, 52, 62, 66, 67, 68], PdfPageSelector::selectPages($texts));
    }

    public function testCapKeepsTheMostImportantPages(): void
    {
        $texts = self::pages(68, [
            7  => 'DUDUK PERKARA',
            14 => 'PRIMAIR 1. Mengabulkan gugatan',
            52 => 'Dalam Rekonvensi',
            62 => 'PERTIMBANGAN HUKUM',
            67 => 'MENGADILI',
        ]);

        // Baseten accepts 8 images: first page, amar (2), duduk, petitum, pertimbangan, rekonvensi, page before amar.
        $this->assertSame([1, 7, 14, 52, 62, 66, 67, 68], PdfPageSelector::selectPages($texts, 8));
    }

    public function testShortPdfOverTheCapIsAlsoSelected(): void
    {
        $texts = self::pages(14, [1 => 'DUDUK PERKARA', 3 => 'PRIMAIR', 7 => 'PERTIMBANGAN HUKUM', 13 => 'MENGADILI']);

        $this->assertSame([1, 2, 3, 4, 7, 12, 13, 14], PdfPageSelector::selectPages($texts, 8));
    }

    public function testLowercaseMengadiliInRunningTextIsNotTheAmar(): void
    {
        $texts = self::pages(40, [
            3  => 'Pengadilan Agama berwenang mengadili perkara ini',
            30 => 'MENGADILI: 1. Mengabulkan gugatan Penggugat',
        ]);

        $selected = PdfPageSelector::selectPages($texts);

        $this->assertContains(30, $selected);
        $this->assertContains(33, $selected);
        $this->assertNotContains(3, $selected);
    }

    public function testPenetapanHeadingCountsAsAmar(): void
    {
        $texts = self::pages(30, [20 => 'Amar Penetapan MENETAPKAN: 1. Mengabulkan permohonan Pemohon']);

        $this->assertSame([1, 2, 19, 20, 21, 22, 23], PdfPageSelector::selectPages($texts));
    }

    public function testWithoutAmarHeadingTheLastPagesAreSent(): void
    {
        $this->assertSame([1, 2, 27, 28, 29, 30], PdfPageSelector::selectPages(self::pages(30, [])));
    }

    public function testScannedPdfWithoutTextGetsStartAndEnd(): void
    {
        $texts = array_fill(1, 40, '');

        $this->assertSame([1, 2, 3, 37, 38, 39, 40], PdfPageSelector::selectPages($texts));
    }
}
