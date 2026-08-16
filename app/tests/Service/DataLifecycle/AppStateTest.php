<?php

declare(strict_types=1);

/*
 * Phritzbox
 *
 * (c) Oliver G. Mueller <oliver@teqneers.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Service\DataLifecycle;

use App\Service\DataLifecycle\AppState;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AppStateTest extends KernelTestCase
{
    private AppState $appState;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->appState = static::getContainer()->get(AppState::class);
    }

    public function testMissingKeyReadsAsNull(): void
    {
        self::assertNull($this->appState->get('no_such_key'));
        self::assertNull($this->appState->getInstant('no_such_key'));
    }

    public function testSetThenGet(): void
    {
        $this->appState->set('test_key', 'hello');

        self::assertSame('hello', $this->appState->get('test_key'));
    }

    public function testSetOverwritesRatherThanDuplicating(): void
    {
        $this->appState->set('test_key', 'first');
        $this->appState->set('test_key', 'second');

        self::assertSame('second', $this->appState->get('test_key'));
    }

    public function testEmptyValueReadsAsNull(): void
    {
        $this->appState->set('test_key', '');

        self::assertNull($this->appState->get('test_key'));
    }

    public function testInstantRoundTrip(): void
    {
        $now = new \DateTimeImmutable('2026-08-16 14:32:00');
        $this->appState->setInstant('test_instant', $now);

        $read = $this->appState->getInstant('test_instant');

        self::assertNotNull($read);
        self::assertSame($now->getTimestamp(), $read->getTimestamp());
    }

    public function testCorruptInstantReadsAsNullRatherThanThrowing(): void
    {
        // A watermark that no longer parses must degrade to "unknown" so a cron
        // job re-derives it, instead of throwing on every run.
        $this->appState->set('test_instant', 'not-a-date');

        self::assertNull($this->appState->getInstant('test_instant'));
    }
}
