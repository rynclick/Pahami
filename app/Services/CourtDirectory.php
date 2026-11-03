<?php

namespace App\Services;

/**
 * The six Religious Courts in DIY, with contact data (source: Ryan, 2026-10-28).
 * All six courts share one PTSP Online portal.
 */
class CourtDirectory
{
    public const PTSP_URL = 'https://ptsp.pta-yogyakarta.go.id';

    /** Display order: the five first-instance courts, then the high court. */
    public const CODES = ['yk', 'smn', 'btl', 'wt', 'wno', 'pta'];

    /**
     * code => name, case-number suffix, aliases, address, phone, email
     */
    private const COURTS = [
        'yk' => [
            'name'    => 'Pengadilan Agama Yogyakarta',
            'suffix'  => 'PA.Yk',
            'aliases' => ['PA Yogyakarta', 'PA Yogya', 'PA Kota Yogyakarta'],
            'address' => 'Jl. Ipda Tut Harsono Kel No.53, Muja Muju, Kec. Umbulharjo, Kota Yogyakarta, Daerah Istimewa Yogyakarta 55165',
            'phone'   => '0274552998',
            'email'   => 'admin@pa-yogyakarta.go.id',
        ],
        'smn' => [
            'name'    => 'Pengadilan Agama Sleman',
            'suffix'  => 'PA.Smn',
            'aliases' => ['PA Sleman'],
            'address' => 'Jalan Parasamya, Beran, Tridadi, Beran Kidul, Tridadi, Kec. Sleman, Kabupaten Sleman, Daerah Istimewa Yogyakarta 55511',
            'phone'   => '0274868201',
            'email'   => 'pengadilanagamasleman@gmail.com',
        ],
        'btl' => [
            'name'    => 'Pengadilan Agama Bantul',
            'suffix'  => 'PA.Btl',
            'aliases' => ['PA Bantul'],
            'address' => 'Jl. Urip Sumoharjo No.8, Bejen, Bantul, Kec. Bantul, Kabupaten Bantul, Daerah Istimewa Yogyakarta 55711',
            'phone'   => '0274367423',
            'email'   => 'pa.bantul@gmail.com',
        ],
        'wt' => [
            'name'    => 'Pengadilan Agama Wates',
            'suffix'  => 'PA.Wt',
            'aliases' => ['PA Wates', 'PA Kulon Progo'],
            'address' => 'Jalan Wates - Purworejo, Jl. KH Ahmad Dahlan No.Km, RW.6, Sumberejo, Triharjo, Kec. Wates, Kabupaten Kulon Progo, Daerah Istimewa Yogyakarta 55651',
            'phone'   => '0274773059',
            'email'   => 'pa.wates@yahoo.com',
        ],
        'wno' => [
            'name'    => 'Pengadilan Agama Wonosari',
            'suffix'  => 'PA.Wno',
            'aliases' => ['PA Wonosari', 'PA Gunungkidul', 'PA Gunung Kidul'],
            'address' => 'Jl. KRT Judoningrat No.RT.05/07, Seneng, Siraman, Kec. Wonosari, Kabupaten Gunungkidul, Daerah Istimewa Yogyakarta 55851',
            'phone'   => '0274391325',
            'email'   => 'pa.wonosari@gmail.com',
        ],
        'pta' => [
            'name'    => 'Pengadilan Tinggi Agama Yogyakarta',
            'suffix'  => 'PTA.Yk',
            'aliases' => ['PTA Yogyakarta', 'PTA Yogya'],
            'address' => 'Jalan Prof. Dr. Wirjono Prodjodikoro, SH No. 321, Dongkelan, Panggungharjo, Sewon, Bantul, DI. Yogyakarta 55188',
            'phone'   => '0274380355',
            'email'   => 'surat@pta-yogyakarta.go.id',
        ],
    ];

    /**
     * Maps a free-text court name (as the model read it) to a court code.
     */
    public function match(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $needle = self::normalize($text);

        // Check the high court first: "Pengadilan Tinggi Agama Yogyakarta" also contains "Agama Yogyakarta".
        foreach (array_reverse(self::COURTS, true) as $code => $court) {
            foreach ([$court['name'], ...$court['aliases']] as $candidate) {
                if (str_contains($needle, self::normalize($candidate))) {
                    return $code;
                }
            }
        }

        return null;
    }

    /**
     * Court code from the suffix of a case number, e.g. 999/Pdt.G/2026/PA.Smn → smn.
     */
    public function codeFromCaseNumber(?string $nomorPerkara): ?string
    {
        if ($nomorPerkara === null || ! preg_match('~/([A-Z]+\.\w+)$~i', trim($nomorPerkara), $m)) {
            return null;
        }

        foreach (self::COURTS as $code => $court) {
            if (strcasecmp($court['suffix'], $m[1]) === 0) {
                return $code;
            }
        }

        return null;
    }

    public function name(string $code): ?string
    {
        return self::COURTS[$code]['name'] ?? null;
    }

    /**
     * @return array{code: string, name: string, address: string, phone: string, phone_display: string, email: string, ptsp_url: string}|null
     */
    public function contact(string $code): ?array
    {
        $court = self::COURTS[$code] ?? null;
        if ($court === null) {
            return null;
        }

        return [
            'code'          => $code,
            'name'          => $court['name'],
            'address'       => $court['address'],
            'phone'         => $court['phone'],
            'phone_display' => self::formatPhone($court['phone']),
            'email'         => $court['email'],
            'ptsp_url'      => self::PTSP_URL,
        ];
    }

    /** "0274868201" → "(0274) 868201". */
    public static function formatPhone(string $phone): string
    {
        return preg_match('/^(0274)(\d+)$/', $phone, $m) ? "({$m[1]}) {$m[2]}" : $phone;
    }

    private static function normalize(string $text): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($text)));
    }
}
