<?php

use App\Services\Fonnte\FonnteClient;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * FonnteClient with the HTTP call faked.
 *
 * @internal
 */
final class FonnteClientTest extends CIUnitTestCase
{
    private function client(string $response, string $token = 'test-token'): FonnteClient
    {
        return new class ($token, $response) extends FonnteClient {
            public array $requests = [];

            public function __construct(string $token, private string $response)
            {
                parent::__construct($token);
            }

            protected function post(string $url, array $fields): string
            {
                $this->requests[] = ['url' => $url, 'fields' => $fields];

                return $this->response;
            }
        };
    }

    public function testSendTextPostsFormFieldsAndReturnsTheId(): void
    {
        $client = $this->client('{"detail":"success! message in queue","id":["80367170"],"process":"pending","status":true,"target":["6281200000001"]}');

        $this->assertSame('80367170', $client->sendText('6281200000001', 'Halo'));
        $this->assertSame([[
            'url'    => 'https://api.fonnte.com/send',
            'fields' => ['target' => '6281200000001', 'message' => 'Halo', 'countryCode' => '62'],
        ]], $client->requests);
    }

    public function testRefusedMessageThrowsWithFonntesReason(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fonnte send failed: token invalid');

        $this->client('{"Status":false,"reason":"token invalid"}')->sendText('6281200000001', 'Halo');
    }

    public function testMissingTokenFailsWithoutARequest(): void
    {
        $client = $this->client('{}', '');

        try {
            $client->sendText('6281200000001', 'Halo');
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('FONNTE_TOKEN is not set in .env.', $e->getMessage());
        }
        $this->assertSame([], $client->requests);
    }

    public function testDownloadRefusesPlainHttp(): void
    {
        $this->expectExceptionMessage('Refusing to download a non-HTTPS media URL.');

        (new FonnteClient('test-token'))->download('http://fonnte.example/surat.jpg');
    }
}
