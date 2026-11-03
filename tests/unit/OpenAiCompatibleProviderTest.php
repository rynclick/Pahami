<?php

use App\Services\Model\ModelException;
use App\Services\Model\Providers\BasetenProvider;
use App\Services\Model\Providers\MetaProvider;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class OpenAiCompatibleProviderTest extends CIUnitTestCase
{
    /**
     * Builds a MetaProvider whose HTTP layer replays the given responses.
     *
     * @param list<array{status: int, body: string, retry_after?: string}> $responses
     */
    private function fakeMeta(array $responses): MetaProvider
    {
        return new class ($responses) extends MetaProvider {
            public array $sent   = [];
            public array $pauses = [];

            public function __construct(private array $responses)
            {
                parent::__construct('test-key', 'https://api.meta.ai/v1', 'muse-spark-1.3');
            }

            protected function post(array $body): array
            {
                $this->sent[] = $body;

                return array_shift($this->responses) + ['retry_after' => ''];
            }

            protected function pause(float $seconds): void
            {
                $this->pauses[] = $seconds;
            }
        };
    }

    private static function ok(string $content, string $finish = 'stop'): array
    {
        return ['status' => 200, 'body' => json_encode([
            'model'   => 'muse-spark-1.3',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => $finish]],
            'usage'   => ['prompt_tokens' => 120, 'completion_tokens' => 30],
        ])];
    }

    public function testParsesContentAndUsage(): void
    {
        $provider = $this->fakeMeta([self::ok('{"a":1}')]);

        $result = $provider->chat([['role' => 'user', 'content' => 'hi']], ['reasoning_effort' => 'low']);

        $this->assertSame('{"a":1}', $result->content);
        $this->assertSame('meta', $result->provider);
        $this->assertSame(120, $result->promptTokens);
        $this->assertSame(30, $result->completionTokens);
        $this->assertSame('muse-spark-1.3', $provider->sent[0]['model']);
        $this->assertSame('low', $provider->sent[0]['reasoning_effort']);
    }

    public function testRetriesOnceOn429HonoringRetryAfter(): void
    {
        $provider = $this->fakeMeta([
            ['status' => 429, 'body' => '{"error":{"message":"slow down"}}', 'retry_after' => '3'],
            self::ok('done'),
        ]);

        $result = $provider->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('done', $result->content);
        $this->assertCount(2, $provider->sent);
        $this->assertSame([3.0], $provider->pauses);
    }

    public function testRetryAfterIsCapped(): void
    {
        $provider = $this->fakeMeta([
            ['status' => 503, 'body' => '', 'retry_after' => '60'],
            self::ok('done'),
        ]);

        $provider->chat([['role' => 'user', 'content' => 'hi']]);

        $this->assertSame([10.0], $provider->pauses);
    }

    public function testGivesUpAfterOneRetry(): void
    {
        $provider = $this->fakeMeta([
            ['status' => 500, 'body' => '{"error":{"message":"boom"}}'],
            ['status' => 500, 'body' => '{"error":{"message":"boom again"}}'],
        ]);

        try {
            $provider->chat([['role' => 'user', 'content' => 'hi']]);
            $this->fail('Expected ModelException');
        } catch (ModelException $e) {
            $this->assertSame(500, $e->httpStatus);
            $this->assertStringContainsString('boom again', $e->getMessage());
            $this->assertCount(2, $provider->sent);
        }
    }

    public function testDoesNotRetryClientErrorsOr504(): void
    {
        foreach ([400, 401, 504] as $status) {
            $provider = $this->fakeMeta([['status' => $status, 'body' => '{"error":{"message":"no"}}']]);

            try {
                $provider->chat([['role' => 'user', 'content' => 'hi']]);
                $this->fail("Expected ModelException for {$status}");
            } catch (ModelException $e) {
                $this->assertSame($status, $e->httpStatus);
                $this->assertCount(1, $provider->sent, "HTTP {$status} must not be retried");
            }
        }
    }

    public function testNullContentAfterReasoningIsAnError(): void
    {
        $provider = $this->fakeMeta([['status' => 200, 'body' => json_encode([
            'choices' => [['message' => ['content' => null, 'reasoning_content' => 'thinking...'], 'finish_reason' => 'length']],
        ])]]);

        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('token limit');
        $provider->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testMissingApiKeyFailsFast(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessage("provider 'baseten'");
        new BasetenProvider('', 'https://inference.baseten.co/v1', 'meta-models/muse-spark-open-1026');
    }
}
