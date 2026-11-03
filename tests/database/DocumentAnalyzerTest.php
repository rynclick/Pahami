<?php

use App\Services\DocumentAnalyzer;
use App\Services\PdfPageSelector;
use App\Services\Verifier;
use App\Services\Model\ModelClient;
use App\Services\Model\ModelException;
use App\Services\Model\ModelResult;
use App\Services\Model\Providers\ProviderInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\Fixtures;

/**
 * @internal
 */
final class DocumentAnalyzerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private ?PdfPageSelector $pdfFake = null;

    /**
     * A provider that replays replies; a ModelException in the list is thrown.
     *
     * @param list<string|ModelException> $replies
     */
    private function provider(array $replies, bool $supportsPdf = true): ProviderInterface
    {
        return new class ($replies, $supportsPdf) implements ProviderInterface {
            public array $requests = [];

            public function __construct(private array $replies, private bool $pdf)
            {
            }

            public function name(): string
            {
                return 'meta';
            }

            public function model(): string
            {
                return 'muse-spark-1.3';
            }

            public function supportsPdf(): bool
            {
                return $this->pdf;
            }

            public function maxImages(): int
            {
                return 8;
            }

            public function chat(array $messages, array $params = []): ModelResult
            {
                $this->requests[] = ['messages' => $messages, 'params' => $params];
                $reply            = array_shift($this->replies);

                if ($reply instanceof ModelException) {
                    throw $reply;
                }

                return new ModelResult($reply, 'meta', 'muse-spark-1.3', 2500, 400, 5000);
            }
        };
    }

    private function analyze(ProviderInterface $provider, string $mime = 'image/jpeg', string $pdfText = '')
    {
        return (new DocumentAnalyzer(new ModelClient($provider), new Verifier(), $this->fakePdf($pdfText)))
            ->analyze('fake-bytes', $mime, 'surat.jpg', null, new DateTimeImmutable('2026-10-28'));
    }

    /** Pretends every PDF has 68 pages of which 3 are selected; renders each page as "png-N". */
    private function fakePdf(string $text = ''): PdfPageSelector
    {
        return $this->pdfFake = new class ($text) extends PdfPageSelector {
            public ?int $maxPages = -1;

            public function __construct(private string $text)
            {
            }

            public function prepare(string $pdf, ?int $maxPages = null): array
            {
                $this->maxPages = $maxPages;

                return ['bytes' => 'excerpt', 'pages' => [1, 7, 67], 'total' => 68, 'text' => $this->text];
            }

            public function toImages(string $pdf, int $dpi = 100): array
            {
                return ['png-1', 'png-2', 'png-3'];
            }
        };
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    public function testValidReplyOnFirstAttempt(): void
    {
        $provider = $this->provider([self::json(Fixtures::analysis())]);

        $result = $this->analyze($provider);

        $this->assertTrue($result->ok);
        $this->assertSame(1, $result->attempts);
        $this->assertSame('btl', $result->verification['pengadilan_kode']);
        $this->seeNumRecords(1, 'model_calls', ['purpose' => 'document_analysis']);
    }

    public function testRequestCarriesPromptTodayAndImage(): void
    {
        $provider = $this->provider([self::json(Fixtures::analysis())]);

        $this->analyze($provider);

        $request = $provider->requests[0];
        $this->assertSame('system', $request['messages'][0]['role']);
        $this->assertStringContainsString('Never invent', $request['messages'][0]['content']);
        $this->assertStringContainsString('2026-10-28 (28 Oktober 2026)', $request['messages'][1]['content'][0]['text']);
        $this->assertSame('image_url', $request['messages'][1]['content'][1]['type']);
        $this->assertSame('low', $request['params']['reasoning_effort']);
        $this->assertSame('json_schema', $request['params']['response_format']['type']);
    }

    public function testLongPdfIsSentAsExcerptWithPageNote(): void
    {
        $provider = $this->provider([self::json(Fixtures::analysis('putusan_sintetis'))]);

        $result = $this->analyze($provider, 'application/pdf');

        $content = $provider->requests[0]['messages'][1]['content'];
        $this->assertCount(2, $content);
        $this->assertSame('file', $content[1]['type']);
        $this->assertSame('data:application/pdf;base64,' . base64_encode('excerpt'), $content[1]['file']['file_data']);
        $this->assertStringContainsString('Dokumen ini PDF 68 halaman. Yang dilampirkan hanya halaman 1, 7, 67', $content[0]['text']);
        $this->assertSame(['jenis' => 'pdf', 'halaman' => [1, 7, 67], 'total_halaman' => 68], $result->input);
        $this->assertSame([1, 7, 67], $result->documentFields()['analysis_json']['input']['halaman']);
        $this->assertNull($this->pdfFake->maxPages, 'no page cap when the PDF goes as a file');
    }

    public function testPdfBecomesImagesForProvidersWithoutPdfInput(): void
    {
        $provider = $this->provider([self::json(Fixtures::analysis('putusan_sintetis'))], supportsPdf: false);

        $result = $this->analyze($provider, 'application/pdf');

        $content = $provider->requests[0]['messages'][1]['content'];
        $this->assertCount(4, $content);
        $this->assertSame(['image_url', 'image_url', 'image_url'], array_column(array_slice($content, 1), 'type'));
        $this->assertSame('data:image/png;base64,' . base64_encode('png-1'), $content[1]['image_url']['url']);
        $this->assertSame('pdf_sebagai_gambar', $result->input['jenis']);
        $this->assertSame(8, $this->pdfFake->maxPages, 'page cap must follow the provider image limit');
        $this->assertTrue($result->ok);
    }

    public function testPdfToolFailureIsAFailedResult(): void
    {
        $broken = new class () extends PdfPageSelector {
            public function prepare(string $pdf, ?int $maxPages = null): array
            {
                throw new RuntimeException('PDF tool failed: pdfinfo: Syntax Error');
            }
        };
        $provider = $this->provider([]);

        $result = (new DocumentAnalyzer(new ModelClient($provider), new Verifier(), $broken))
            ->analyze('not-a-pdf', 'application/pdf');

        $this->assertFalse($result->ok);
        $this->assertSame('PDF tool failed: pdfinfo: Syntax Error', $result->error);
        $this->assertSame([], $provider->requests);
    }

    public function testQuotesAreCheckedAgainstThePdfText(): void
    {
        // A text layer that holds none of the model's quotes: nothing can be verified.
        $text = str_repeat('Menimbang bahwa perkara ini telah diperiksa sesuai hukum acara yang berlaku. ', 8);

        $result = $this->analyze($this->provider([self::json(Fixtures::analysis())]), 'application/pdf', $text);

        $this->assertTrue($result->verification['teks_sumber_dicek']);
        $this->assertSame('kutipan tidak ditemukan di teks PDF', $result->verification['fields']['nomor_perkara']['alasan']);
        $this->assertSame('rendah', $result->analysis['tingkat_keyakinan']);
    }

    public function testPhotosHaveNoTextToCheck(): void
    {
        $result = $this->analyze($this->provider([self::json(Fixtures::analysis())]));

        $this->assertFalse($result->verification['teks_sumber_dicek']);
    }

    public function testVerdictSectionsArePreserved(): void
    {
        $result = $this->analyze($this->provider([self::json(Fixtures::analysis('putusan_sintetis'))]), 'application/pdf');

        $this->assertTrue($result->ok);
        $this->assertStringStartsWith('MENGADILI', $result->analysis['bagian_putusan']['kutipan_amar']);
        $this->assertCount(3, $result->analysis['bagian_putusan']['amar']);
    }

    public function testJsonInMarkdownFencesIsAccepted(): void
    {
        $provider = $this->provider(["```json\n" . self::json(Fixtures::analysis()) . "\n```"]);

        $this->assertTrue($this->analyze($provider)->ok);
    }

    public function testRetriesOnceWithSchemaErrors(): void
    {
        $broken = Fixtures::analysis();
        unset($broken['risiko_jika_diabaikan']);
        $provider = $this->provider([self::json($broken), self::json(Fixtures::analysis())]);

        $result = $this->analyze($provider);

        $this->assertTrue($result->ok);
        $this->assertSame(2, $result->attempts);
        $retry = $provider->requests[1]['messages'];
        $this->assertSame('assistant', $retry[2]['role']);
        $this->assertStringContainsString('$.risiko_jika_diabaikan: missing', $retry[3]['content']);
    }

    public function testFailsAfterTwoInvalidReplies(): void
    {
        $provider = $this->provider(['bukan json', '{"juga": "salah"}']);

        $result = $this->analyze($provider);

        $this->assertFalse($result->ok);
        $this->assertSame(2, $result->attempts);
        $this->assertStringStartsWith('Schema validation failed', $result->error);
        $fields = $result->documentFields();
        $this->assertSame('failed', $fields['status']);
        $this->assertSame(2, $fields['analysis_json']['attempts']);
        $this->assertSame('meta', $fields['provider']);
    }

    public function testModelErrorBecomesFailedResult(): void
    {
        $provider = $this->provider([new ModelException('meta returned HTTP 503: overloaded', 503, 'meta')]);

        $result = $this->analyze($provider);

        $this->assertFalse($result->ok);
        $this->assertSame('meta returned HTTP 503: overloaded', $result->error);
        $this->seeInDatabase('model_calls', ['purpose' => 'document_analysis', 'error' => 'meta returned HTTP 503: overloaded']);
    }

    public function testDerivedConfidenceReplacesSelfReport(): void
    {
        $data                             = Fixtures::analysis();
        $data['kutipan']['nomor_perkara'] = null;

        $result = $this->analyze($this->provider([self::json($data)]));

        $this->assertSame('sedang', $result->analysis['tingkat_keyakinan']);
    }

    public function testOnlyVerifiedHearingReachesDocumentColumns(): void
    {
        $data                      = Fixtures::analysis();
        $data['sidang']['tanggal'] = '2026-11-11'; // disagrees with the quote

        $fields = $this->analyze($this->provider([self::json($data)]))->documentFields();

        $this->assertSame('done', $fields['status']);
        $this->assertNull($fields['sidang_tanggal']);
        // The time is held back too until the user confirms the schedule.
        $this->assertNull($fields['sidang_jam']);
        $this->assertSame('123/Pdt.G/2026/PA.Btl', $fields['nomor_perkara']);
        $this->assertSame('btl', $fields['pengadilan_kode']);
    }

    public function testUnsupportedMimeIsRejectedBeforeAnyCall(): void
    {
        $provider = $this->provider([]);

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->analyze($provider, 'image/gif');
        } finally {
            $this->assertSame([], $provider->requests);
        }
    }
}
