<?php

use App\Services\Model\ModelClient;
use App\Services\Model\ModelResult;
use App\Services\Model\Providers\ProviderInterface;
use App\Services\Translator;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\Mock\MockCache;

/**
 * @internal
 */
final class TranslatorTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = 'App';

    public function testTranslatesOnceThenServesFromCache(): void
    {
        $provider = new class () implements ProviderInterface {
            public array $requests = [];

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
                return true;
            }

            public function maxImages(): int
            {
                return 50;
            }

            public function chat(array $messages, array $params = []): ModelResult
            {
                $this->requests[] = $messages;

                return new ModelResult('*Hearing schedule* for case 123', 'meta', 'muse-spark-1.3', 200, 20, 900);
            }
        };
        $translator = new Translator(new ModelClient($provider), new MockCache());

        $this->assertSame('*Hearing schedule* for case 123', $translator->toEnglish('*Jadwal sidang* perkara 123'));
        $this->assertSame('*Hearing schedule* for case 123', $translator->toEnglish('*Jadwal sidang* perkara 123'));
        $this->assertCount(1, $provider->requests);
        $this->assertSame('*Jadwal sidang* perkara 123', $provider->requests[0][1]['content']);
        $this->seeInDatabase('model_calls', ['purpose' => 'translation']);
        $this->assertSame('', $translator->toEnglish('   '));
    }
}
