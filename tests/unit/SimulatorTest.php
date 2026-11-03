<?php

use App\Services\Fonnte\SimulatorClient;
use App\Services\SyntheticSummons;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The simulator transport and the synthetic sample summons.
 *
 * @internal
 */
final class SimulatorTest extends CIUnitTestCase
{
    public function testDownloadReadsOnlyInboxFiles(): void
    {
        $root = sys_get_temp_dir() . '/pahami-sim-' . bin2hex(random_bytes(4)) . '/';
        mkdir($root . SimulatorClient::INBOX, 0777, true);
        $name = str_repeat('a', 32) . '.png';
        file_put_contents($root . SimulatorClient::INBOX . $name, 'bytes');
        $client = new SimulatorClient($root);

        try {
            $this->assertSame('bytes', $client->download('sim://' . $name));

            foreach (['sim://../../.env', 'https://fonnte.example/a.png', 'sim://' . str_repeat('b', 32) . '.png'] as $url) {
                try {
                    $client->download($url);
                    $this->fail("{$url} must be refused");
                } catch (RuntimeException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            unlink($root . SimulatorClient::INBOX . $name);
        }
    }

    public function testSendingNeverReachesFonnte(): void
    {
        $this->assertNull((new SimulatorClient())->sendText('sim0123456789ab', 'Halo'));
        $this->assertTrue(SimulatorClient::isSimulated('sim0123456789ab'));
        $this->assertFalse(SimulatorClient::isSimulated('6281200000001'));
    }

    public function testSampleHearingIsAWeekdayTenDaysAhead(): void
    {
        $tz = new DateTimeZone('Asia/Jakarta');

        $this->assertSame('2026-11-11', SyntheticSummons::hearingFrom(new DateTimeImmutable('2026-11-01 15:00', $tz))->format('Y-m-d'));
        // 28 Oct + 10 days is a Saturday: moved to Monday.
        $this->assertSame('2026-11-09', SyntheticSummons::hearingFrom(new DateTimeImmutable('2026-10-28', $tz))->format('Y-m-d'));
        $this->assertSame('Rabu / 11 November 2026', SyntheticSummons::tanggal(new DateTimeImmutable('2026-11-11')));
    }

    public function testSampleIsAPng(): void
    {
        $png = SyntheticSummons::png(new DateTimeImmutable('2026-11-11'), new DateTimeImmutable('2026-11-01'));

        $this->assertStringStartsWith("\x89PNG", $png);
        $this->assertSame([1240, 1500], array_slice(getimagesizefromstring($png), 0, 2));
    }
}
