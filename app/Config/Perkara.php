<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Case types of the Religious Courts (kewenangan PA, UU 3/2006 Pasal 49), in one place.
 *
 * Adding or editing a case type here is all that is needed: the list feeds
 * the analysis schema (the model must pick one of these codes), the labels
 * and the checklist. Codes are stored in documents.jenis_perkara, so do not
 * rename an existing code. "lainnya" must stay; the model uses it, plus the
 * free-text jenis_perkara_teks, for anything not listed.
 *
 * 'dibawa' lists the extra papers to bring, on top of KTP and the letter:
 * [0] for the filing party (penggugat/pemohon), [1] for the responding party.
 * Checklist texts are general information and should be reviewed by court staff.
 */
class Perkara extends BaseConfig
{
    /**
     * @var array<string, array{label: string, dibawa?: array{0: list<string>, 1: list<string>}}>
     */
    public array $jenis = [
        // Perkawinan
        'cerai_talak' => [
            'label'  => 'Cerai Talak',
            'dibawa' => [
                ['Buku Nikah asli dan fotokopinya', 'Kartu Keluarga', 'Akta kelahiran anak (jika ada anak)'],
                ['Buku Nikah (jika Anda menyimpannya)', 'Kartu Keluarga'],
            ],
        ],
        'cerai_gugat' => [
            'label'  => 'Cerai Gugat',
            'dibawa' => [
                ['Buku Nikah asli dan fotokopinya', 'Kartu Keluarga', 'Akta kelahiran anak (jika ada anak)'],
                ['Buku Nikah (jika Anda menyimpannya)', 'Kartu Keluarga'],
            ],
        ],
        'harta_bersama' => [
            'label'  => 'Harta Bersama',
            'dibawa' => [
                ['Buku Nikah atau Akta Cerai', 'Bukti kepemilikan harta (sertifikat, BPKB, dsb.)'],
                ['Bukti kepemilikan harta yang Anda pegang (jika ada)'],
            ],
        ],
        'hadhanah' => [
            'label'  => 'Hak Asuh Anak (Hadhanah)',
            'dibawa' => [
                ['Akta kelahiran anak', 'Kartu Keluarga', 'Akta Cerai (jika sudah bercerai)'],
                ['Akta kelahiran anak (jika Anda menyimpannya)', 'Kartu Keluarga'],
            ],
        ],
        'nafkah' => [
            'label'  => 'Nafkah Istri/Anak',
            'dibawa' => [
                ['Buku Nikah atau Akta Cerai', 'Akta kelahiran anak', 'Bukti penghasilan pihak lawan (jika ada)'],
                ['Bukti penghasilan Anda (slip gaji, dsb.)'],
            ],
        ],
        'izin_poligami' => [
            'label'  => 'Izin Poligami',
            'dibawa' => [
                ['Buku Nikah', 'Surat pernyataan persetujuan istri', 'Bukti penghasilan', 'Surat pernyataan berlaku adil'],
                ['Buku Nikah'],
            ],
        ],
        'pembatalan_perkawinan' => [
            'label'  => 'Pembatalan Perkawinan',
            'dibawa' => [['Buku Nikah', 'Kartu Keluarga'], ['Buku Nikah (jika Anda menyimpannya)']],
        ],
        'dispensasi_kawin' => [
            'label'  => 'Dispensasi Kawin',
            'dibawa' => [
                ['Kartu Keluarga', 'Akta kelahiran anak yang akan menikah', 'Surat penolakan dari KUA'],
                ['Kartu Keluarga'],
            ],
        ],
        'wali_adhol' => [
            'label'  => 'Wali Adhol',
            'dibawa' => [['Kartu Keluarga', 'Akta kelahiran', 'Surat penolakan dari KUA'], ['Kartu Keluarga']],
        ],
        'itsbat_nikah' => [
            'label'  => 'Itsbat Nikah',
            'dibawa' => [
                ['Kartu Keluarga', 'Surat keterangan dari KUA bahwa pernikahan tidak tercatat'],
                ['Kartu Keluarga'],
            ],
        ],
        'asal_usul_anak' => [
            'label'  => 'Asal Usul Anak',
            'dibawa' => [['Akta kelahiran anak', 'Buku Nikah orang tua', 'Kartu Keluarga'], ['Kartu Keluarga']],
        ],
        'perwalian' => [
            'label'  => 'Perwalian',
            'dibawa' => [['Akta kelahiran anak', 'Akta kematian orang tua (jika ada)', 'Kartu Keluarga'], ['Kartu Keluarga']],
        ],
        'pengangkatan_anak' => [
            'label'  => 'Pengangkatan Anak',
            'dibawa' => [['Akta kelahiran anak', 'Buku Nikah', 'Kartu Keluarga', 'Surat keterangan dari dinas sosial (jika ada)'], ['Kartu Keluarga']],
        ],
        // Kewarisan dan lainnya
        'waris' => [
            'label'  => 'Waris',
            'dibawa' => [
                ['Surat/akta kematian pewaris', 'Kartu Keluarga dan bukti hubungan keluarga (akta kelahiran, buku nikah)', 'Bukti kepemilikan harta (sertifikat, BPKB, dsb.)'],
                ['Kartu Keluarga dan bukti hubungan keluarga', 'Bukti kepemilikan harta yang Anda pegang (jika ada)'],
            ],
        ],
        'penetapan_ahli_waris' => [
            'label'  => 'Penetapan Ahli Waris',
            'dibawa' => [['Surat/akta kematian pewaris', 'Kartu Keluarga dan bukti hubungan keluarga'], ['Kartu Keluarga']],
        ],
        'wasiat' => ['label' => 'Wasiat'],
        'hibah'  => ['label' => 'Hibah'],
        'wakaf'  => ['label' => 'Wakaf'],
        'zakat_infaq_shadaqah' => ['label' => 'Zakat, Infaq, dan Shadaqah'],
        'ekonomi_syariah'      => ['label' => 'Ekonomi Syariah'],
        'lainnya'              => ['label' => 'Perkara lainnya'],
    ];
}
