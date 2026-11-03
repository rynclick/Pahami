<?php

use App\Services\Model\ModelClient;
use App\Services\Model\ModelException;
use App\Services\Model\ModelResult;
use App\Services\Model\Providers\ProviderInterface;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ModelClientTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    private function fakeProvider(string $name, ?string $fail = null): ProviderInterface
    {
        return new class ($name, $fail) implements ProviderInterface {
            public array $calls = [];

            public function __construct(private string $providerName, private ?string $fail)
            {
            }

            public function name(): string
            {
                return $this->providerName;
            }

            public function model(): string
            {
                return "{$this->providerName}-model";
            }

            public function supportsPdf(): bool
            {
                return true;
            }

            public function maxImages(): int
            {
                return 50;
            }

            public function chat(array $messages, array $params = []): ModelResult
            {
                $this->calls[] = $params;

                if ($this->fail !== null) {
                    throw new ModelException($this->fail, 503, $this->providerName);
                }

                return new ModelResult('ok', $this->providerName, $this->model(), 100, 20, 1500);
            }
        };
    }

    private static function imageMessage(): array
    {
        return [['role' => 'user', 'content' => [
            ModelClient::textPart('Baca surat ini.'),
            ModelClient::imagePart('fake-bytes', 'image/jpeg'),
        ]]];
    }

    public function testTextGoesToMainProvider(): void
    {
        $client = new ModelClient($this->fakeProvider('baseten'), $this->fakeProvider('meta'));

        $result = $client->chat([['role' => 'user', 'content' => 'halo']], 'test');

        $this->assertSame('baseten', $result->provider);
    }

    public function testMediaGoesToVisionProviderWhenSet(): void
    {
        $client = new ModelClient($this->fakeProvider('baseten'), $this->fakeProvider('meta'));

        $this->assertSame('meta', $client->chat(self::imageMessage(), 'test')->provider);
    }

    public function testMediaUsesMainProviderWithoutVisionOverride(): void
    {
        $client = new ModelClient($this->fakeProvider('baseten'));

        $this->assertSame('baseten', $client->chat(self::imageMessage(), 'test')->provider);
    }

    public function testDefaultMaxTokensCanBeOverridden(): void
    {
        $provider = $this->fakeProvider('meta');
        $client   = new ModelClient($provider);

        $client->chat([['role' => 'user', 'content' => 'a']], 'test');
        $client->chat([['role' => 'user', 'content' => 'b']], 'test', ['max_completion_tokens' => 500]);

        $this->assertSame(8000, $provider->calls[0]['max_completion_tokens']);
        $this->assertSame(500, $provider->calls[1]['max_completion_tokens']);
    }

    public function testSuccessfulCallIsLogged(): void
    {
        $client = new ModelClient($this->fakeProvider('meta'));

        $client->chat([['role' => 'user', 'content' => 'halo']], 'document_analysis');

        $this->seeInDatabase('model_calls', [
            'provider' => 'meta', 'model' => 'meta-model', 'purpose' => 'document_analysis',
            'prompt_tokens' => 100, 'completion_tokens' => 20, 'latency_ms' => 1500, 'error' => null,
        ]);
    }

    public function testFailedCallIsLoggedAndRethrown(): void
    {
        $client = new ModelClient($this->fakeProvider('baseten', 'meta returned HTTP 503: overloaded'));

        try {
            $client->chat([['role' => 'user', 'content' => 'halo']], 'qa');
            $this->fail('Expected ModelException');
        } catch (ModelException) {
            $this->seeInDatabase('model_calls', [
                'provider' => 'baseten', 'purpose' => 'qa', 'error' => 'meta returned HTTP 503: overloaded',
            ]);
        }
    }

    public function testPdfPartUsesFileDataUri(): void
    {
        $part = ModelClient::pdfPart('%PDF', 'putusan.pdf');

        $this->assertSame('file', $part['type']);
        $this->assertSame('putusan.pdf', $part['file']['filename']);
        $this->assertSame('data:application/pdf;base64,' . base64_encode('%PDF'), $part['file']['file_data']);
    }

    public function testUnknownProviderNameIsRejected(): void
    {
        $this->expectException(ModelException::class);
        ModelClient::makeProvider('openai');
    }
}
