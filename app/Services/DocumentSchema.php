<?php

namespace App\Services;

/**
 * The document analysis schema (CLAUDE.md §6), defined once.
 *
 * The same definition is sent to the model as response_format and used to
 * validate the reply strictly here, so the two can never drift apart.
 * Only the JSON Schema subset used below is supported by validate().
 */
class DocumentSchema
{
    public const JENIS_DOKUMEN   = ['relaas_panggilan', 'gugatan', 'permohonan', 'putusan', 'penetapan', 'pemberitahuan', 'lainnya'];
    public const PENERIMA        = ['pemohon', 'termohon', 'penggugat', 'tergugat', 'tidak_jelas'];
    public const KEYAKINAN       = ['tinggi', 'sedang', 'rendah'];

    public static function definition(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $stringList     = ['type' => 'array', 'items' => ['type' => 'string']];

        return self::object([
            'jenis_dokumen'   => ['type' => 'string', 'enum' => self::JENIS_DOKUMEN],
            'jenis_perkara'   => ['type' => 'string', 'enum' => self::jenisPerkara()],
            // The case type as printed, e.g. "Pengesahan Anak", useful when jenis_perkara is "lainnya".
            'jenis_perkara_teks' => $nullableString,
            'nomor_perkara'   => $nullableString,
            'pengadilan'      => $nullableString,
            'penerima_adalah' => ['type' => 'string', 'enum' => self::PENERIMA],
            'sidang'          => self::object([
                'tanggal' => ['type' => ['string', 'null'], 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                'jam'     => $nullableString,
                'tempat'  => $nullableString,
            ]),
            'kutipan' => self::object([
                'nomor_perkara'   => $nullableString,
                'pengadilan'      => $nullableString,
                'sidang_tanggal'  => $nullableString,
                'sidang_jam'      => $nullableString,
                'penerima_adalah' => $nullableString,
            ]),
            'ringkasan_awam'                => ['type' => 'string', 'minLength' => 1],
            'yang_harus_dilakukan'          => $stringList,
            'dokumen_fisik_dibawa'          => $stringList,
            'yang_perlu_disiapkan'          => $stringList,
            'risiko_jika_diabaikan'         => ['type' => 'string'],
            'pertanyaan_lanjutan_yang_umum' => $stringList,
            'tingkat_keyakinan'             => ['type' => 'string', 'enum' => self::KEYAKINAN],
            'catatan_ketidakjelasan'        => $nullableString,
            // Legal terms found in the document, explained plainly (any document type).
            'istilah_hukum' => ['type' => 'array', 'items' => self::object([
                'istilah' => ['type' => 'string', 'minLength' => 1],
                'arti'    => ['type' => 'string', 'minLength' => 1],
            ])],
            // Putusan/penetapan only (§6); null for every other document type.
            'amar_ringkas'                        => $nullableString,
            'apakah_sudah_inkracht_dapat_dinilai' => ['type' => ['boolean', 'null']],
            'langkah_setelah_putusan'             => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
            'bagian_putusan'                      => self::object([
                'duduk_perkara'      => $nullableString,
                'petitum'            => $stringList,
                'pertimbangan_utama' => $stringList,
                'amar'               => $stringList,
                'kutipan_amar'       => $nullableString,
                'rekonvensi'         => $nullableString,
            ], nullable: true),
        ]);
    }

    /** Case type codes, from Config\Perkara. */
    public static function jenisPerkara(): array
    {
        return array_keys(config('Perkara')->jenis);
    }

    /** response_format block for the chat completions request. */
    public static function responseFormat(): array
    {
        return ['type' => 'json_schema', 'json_schema' => [
            'name'   => 'analisis_dokumen',
            'schema' => self::definition(),
        ]];
    }

    /**
     * @return list<string> Human-readable errors; empty when valid.
     */
    public static function validate(mixed $data): array
    {
        $errors = [];
        self::check($data, self::definition(), '$', $errors);

        return $errors;
    }

    /** Every property required, no extra properties: a strict object. */
    private static function object(array $properties, bool $nullable = false): array
    {
        return [
            'type'                 => $nullable ? ['object', 'null'] : 'object',
            'properties'           => $properties,
            'required'             => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    private static function check(mixed $value, array $schema, string $path, array &$errors): void
    {
        $types = (array) $schema['type'];
        $type  = self::typeOf($value);

        // json_decode turns both [] and {} into an empty PHP array.
        if ($value === [] && in_array('object', $types, true)) {
            $type = 'object';
        }

        if (! in_array($type, $types, true)) {
            $errors[] = "{$path}: expected " . implode('|', $types) . ", got {$type}";

            return;
        }

        if ($type === 'null') {
            return;
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$path}: '{$value}' is not one of " . implode(', ', $schema['enum']);
        }

        if (isset($schema['pattern']) && ! preg_match('~' . $schema['pattern'] . '~', $value)) {
            $errors[] = "{$path}: '{$value}' does not match {$schema['pattern']}";
        }

        if (isset($schema['minLength']) && mb_strlen(trim($value)) < $schema['minLength']) {
            $errors[] = "{$path}: must not be empty";
        }

        if ($type === 'array') {
            foreach ($value as $i => $item) {
                self::check($item, $schema['items'], "{$path}[{$i}]", $errors);
            }
        }

        if ($type === 'object') {
            foreach ($schema['required'] as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = "{$path}.{$key}: missing";
                }
            }
            foreach ($value as $key => $item) {
                if (! isset($schema['properties'][$key])) {
                    $errors[] = "{$path}.{$key}: unexpected property";

                    continue;
                }
                self::check($item, $schema['properties'][$key], "{$path}.{$key}", $errors);
            }
        }
    }

    /** JSON type of a value decoded with json_decode(..., true). */
    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null  => 'null',
            is_bool($value)  => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value)  => array_is_list($value) ? 'array' : 'object',
            default           => 'unknown',
        };
    }
}
