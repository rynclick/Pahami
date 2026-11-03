<?php

namespace App\Services;

use DateTimeImmutable;

/**
 * Draws a SYNTHETIC summons (relaas panggilan) as a PNG: fictional parties,
 * fictional case number, marked "bukan dokumen asli" (CLAUDE.md §9).
 * Used by the chat simulator's sample button and by
 * fixtures/documents/make_relaas_sintetis.php. Needs GD with FreeType.
 */
class SyntheticSummons
{
    private const HARI  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    private const SERIF = '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf';
    private const BOLD  = '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf';

    /** A weekday hearing $days after $today, so a sample always has reminders ahead. */
    public static function hearingFrom(DateTimeImmutable $today, int $days = 10): DateTimeImmutable
    {
        $hearing = $today->setTime(0, 0)->modify("+{$days} days");
        while ((int) $hearing->format('N') >= 6) {
            $hearing = $hearing->modify('+1 day');
        }

        return $hearing;
    }

    /** "Selasa / 3 November 2026" */
    public static function tanggal(DateTimeImmutable $date, string $separator = ' / '): string
    {
        return self::HARI[(int) $date->format('w')] . $separator
            . (int) $date->format('j') . ' ' . self::BULAN[(int) $date->format('n')] . ' ' . $date->format('Y');
    }

    /** @return string PNG bytes */
    public static function png(DateTimeImmutable $hearing, DateTimeImmutable $served): string
    {
        $img   = imagecreatetruecolor(1240, 1500);
        $paper = imagecolorallocate($img, 250, 249, 245);
        $ink   = imagecolorallocate($img, 25, 25, 30);
        $red   = imagecolorallocate($img, 170, 30, 30);
        imagefill($img, 0, 0, $paper);

        $y    = 110;
        $line = static function (string $text, int $size = 22, int $x = 110, bool $isBold = false, ?int $color = null) use (&$y, $img, $ink): void {
            imagettftext($img, $size, 0, $x, $y, $color ?? $ink, $isBold ? self::BOLD : self::SERIF, $text);
            $y += (int) ($size * 1.9);
        };
        $center = static function (string $text, int $size, bool $isBold = true) use (&$y, $img, $ink): void {
            $font = $isBold ? self::BOLD : self::SERIF;
            $box  = imagettfbbox($size, 0, $font, $text);
            imagettftext($img, $size, 0, (int) ((1240 - ($box[2] - $box[0])) / 2), $y, $ink, $font, $text);
            $y += (int) ($size * 1.9);
        };

        $line('CONTOH DOKUMEN SINTETIS — BUKAN DOKUMEN ASLI', 16, 110, true, $red);
        $y += 20;
        $center('RELAAS PANGGILAN', 28);
        $center('Nomor 123/Pdt.G/2026/PA.Btl', 22, false);
        $y += 10;
        $line('Pada hari ini ' . self::tanggal($served, ' tanggal ') . ' saya Juru Sita Contoh, S.H.');
        $line('sebagai Jurusita pada Pengadilan Agama Bantul atas perintah Ketua Majelis');
        $line('dalam perkara Nomor 123/Pdt.G/2026/PA.Btl,');
        $y += 10;
        $center('TELAH MEMANGGIL', 22);
        $line('Budi Fiktif bin Contoh, umur 35 tahun, agama Islam, pekerjaan Wiraswasta,');
        $line('tempat tinggal di Jalan Contoh No. 1, Bantul, sebagai Tergugat;');
        $y += 10;
        $line('agar datang menghadap di muka sidang Pengadilan Agama Bantul pada:');
        $line('Hari/Tanggal  : ' . self::tanggal($hearing), 22, 150);
        $line('Pukul             : 09.00 WIB', 22, 150);
        $line('Tempat           : Ruang Sidang 1 Pengadilan Agama Bantul', 22, 150);
        $line('                      Jl. Urip Sumoharjo No.8, Bejen, Bantul', 22, 150);
        $y += 10;
        $line('untuk pemeriksaan perkara Cerai Gugat antara:');
        $line('Siti Contoh binti Fiktif sebagai Penggugat, lawan Budi Fiktif bin Contoh', 22);
        $line('sebagai Tergugat.', 22);
        $y += 10;
        $line('Bersama surat ini saya lampirkan salinan surat gugatan, dan diberitahukan');
        $line('bahwa Tergugat dapat menjawab secara lisan atau tertulis serta dapat');
        $line('membawa saksi-saksi dan surat-surat bukti.');
        $y += 30;
        $line('Jurusita,', 22, 820);
        $y += 50;
        $line('Juru Sita Contoh, S.H.', 22, 820);

        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
