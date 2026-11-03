<?php

namespace App\Services;

use RuntimeException;

/**
 * Picks the pages of a long verdict that matter and builds a smaller PDF.
 *
 * Meta renders only the first 50 pages of a PDF, and a 68-page verdict costs
 * ~130k tokens while its amar sits on page 67. Agreed rule (Ryan, Oct 28):
 * send the opening pages, the duduk perkara (posita + petitum), the
 * counterclaim, the legal considerations and the amar through the end.
 *
 * Uses poppler-utils (pdfinfo, pdftotext, pdfseparate, pdfunite, pdftoppm).
 */
class PdfPageSelector
{
    /** Short documents are sent whole. */
    public const WHOLE_PDF_MAX_PAGES = 15;

    /** Pages printed after the amar (signatures, costs) worth keeping. */
    private const PAGES_AFTER_AMAR = 3;

    /**
     * @param int|null $maxPages Hard cap, e.g. a provider's images-per-request limit
     *
     * @return array{bytes: string, pages: list<int>, total: int, text: string} text: the text layer of the selected pages (empty for scans)
     */
    public function prepare(string $pdf, ?int $maxPages = null): array
    {
        $dir = $this->tempDir();

        try {
            $source = "{$dir}/source.pdf";
            file_put_contents($source, $pdf);

            $total = $this->pageCount($source);
            $texts = $this->pageTexts($source, $total);
            $pages = self::selectPages($texts, $maxPages);
            $text  = implode("
", array_map(static fn (int $page) => $texts[$page], $pages));

            if (count($pages) === $total) {
                return ['bytes' => $pdf, 'pages' => $pages, 'total' => $total, 'text' => $text];
            }

            $parts = [];
            foreach ($pages as $page) {
                $part = "{$dir}/page-{$page}.pdf";
                $this->run(sprintf('pdfseparate -f %d -l %d %s %s', $page, $page, escapeshellarg($source), escapeshellarg($part)));
                $parts[] = escapeshellarg($part);
            }
            $excerpt = "{$dir}/excerpt.pdf";
            $this->run('pdfunite ' . implode(' ', $parts) . ' ' . escapeshellarg($excerpt));

            return ['bytes' => (string) file_get_contents($excerpt), 'pages' => $pages, 'total' => $total, 'text' => $text];
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * Renders every page of a (small) PDF to PNG, for providers without PDF input.
     *
     * @return list<string> PNG bytes, one per page
     */
    public function toImages(string $pdf, int $dpi = 100): array
    {
        $dir = $this->tempDir();

        try {
            file_put_contents("{$dir}/doc.pdf", $pdf);
            $this->run(sprintf('pdftoppm -r %d -png %s %s', $dpi, escapeshellarg("{$dir}/doc.pdf"), escapeshellarg("{$dir}/p")));

            $files = glob("{$dir}/p-*.png") ?: [];
            natsort($files);

            return array_values(array_map(static fn ($f) => (string) file_get_contents($f), $files));
        } finally {
            $this->removeDir($dir);
        }
    }

    /**
     * Pages are collected in priority order, so a cap keeps the most useful ones:
     * first page, amar, duduk perkara, petitum, pertimbangan, rekonvensi, then context pages.
     *
     * @param array<int, string> $texts    Page text keyed by page number (1-based)
     * @param int|null           $maxPages Hard cap on the number of pages
     *
     * @return list<int> Sorted page numbers to send
     */
    public static function selectPages(array $texts, ?int $maxPages = null): array
    {
        $total = count($texts);
        $max   = $maxPages ?? PHP_INT_MAX;

        if ($total <= self::WHOLE_PDF_MAX_PAGES && $total <= $max) {
            return range(1, max(1, $total));
        }

        // No text layer (a scan): opening pages plus the end, where the amar usually is.
        if (trim(implode('', $texts)) === '') {
            return self::take([1, $total, $total - 1, 2, $total - 2, 3, $total - 3], $total, $max);
        }

        $duduk = self::first($texts, '/DUDUK\s+PERKARA/i');
        // Petitum of the claim: the first "PRIMAIR" list after the duduk perkara starts.
        $petitum      = self::first($texts, '/\b(PRIMAIR|PRIMER)\b|putusan\s+sebagai\s+berikut/i', $duduk ?? 1);
        $rekonvensi   = self::first($texts, '/Dalam\s+Rekonvensi/i');
        $pertimbangan = self::first($texts, '/PERTIMBANGAN\s+HUKUM/');
        // The amar heading: last match, since "Mengadili" also appears in running text (only uppercase counts).
        $amar = self::last($texts, '/M\s?E\s?N\s?G\s?A\s?D\s?I\s?L\s?I|M\s?E\s?N\s?E\s?T\s?A\s?P\s?K\s?A\s?N\s*:|Amar\s+Putusan/');

        // Without an amar heading, the last pages stand in for it.
        $amarPages = $amar !== null
            ? range($amar, min($total, $amar + self::PAGES_AFTER_AMAR))
            : [$total - 1, $total, $total - 2, $total - 3];

        $priority = [
            1,
            ...array_slice($amarPages, 0, 2),
            $duduk,
            $petitum,
            $pertimbangan,
            $rekonvensi,
            // The page before the amar holds the concluding considerations.
            $amar !== null ? $amar - 1 : null,
            2,
            $duduk !== null ? $duduk + 1 : null,
            $petitum !== null ? $petitum + 1 : null,
            ...array_slice($amarPages, 2),
        ];

        return self::take($priority, $total, $max);
    }

    /**
     * First $max distinct valid pages from a priority list, sorted.
     *
     * @return list<int>
     */
    private static function take(array $priority, int $total, int $max): array
    {
        $pages = [];
        foreach ($priority as $page) {
            if ($page !== null && $page >= 1 && $page <= $total && ! in_array($page, $pages, true)) {
                $pages[] = $page;
            }
            if (count($pages) >= $max) {
                break;
            }
        }
        sort($pages);

        return $pages;
    }

    private static function first(array $texts, string $pattern, int $from = 1): ?int
    {
        foreach ($texts as $page => $text) {
            if ($page >= $from && preg_match($pattern, $text)) {
                return $page;
            }
        }

        return null;
    }

    private static function last(array $texts, string $pattern): ?int
    {
        $found = null;
        foreach ($texts as $page => $text) {
            if (preg_match($pattern, $text)) {
                $found = $page;
            }
        }

        return $found;
    }

    private function pageCount(string $pdf): int
    {
        $info = $this->run('pdfinfo ' . escapeshellarg($pdf));

        if (! preg_match('/^Pages:\s+(\d+)/m', $info, $m)) {
            throw new RuntimeException('Could not read the PDF page count.');
        }

        return (int) $m[1];
    }

    /** @return array<int, string> */
    private function pageTexts(string $pdf, int $total): array
    {
        // pdftotext separates pages with a form feed.
        $text  = $this->run('pdftotext ' . escapeshellarg($pdf) . ' -');
        $parts = explode("\f", $text);

        $texts = [];
        for ($page = 1; $page <= $total; $page++) {
            $texts[$page] = $parts[$page - 1] ?? '';
        }

        return $texts;
    }

    private function run(string $command): string
    {
        exec($command . ' 2>&1', $output, $code);

        if ($code !== 0) {
            throw new RuntimeException('PDF tool failed: ' . strtok($command, ' ') . ': ' . implode(' ', array_slice($output, 0, 3)));
        }

        return implode("\n", $output);
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/pahami-pdf-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700);

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        foreach (glob("{$dir}/*") ?: [] as $file) {
            unlink($file);
        }
        @rmdir($dir);
    }
}
