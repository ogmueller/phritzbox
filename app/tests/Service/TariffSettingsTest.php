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

namespace App\Tests\Service;

use App\Entity\Tariff;
use App\Service\TariffSettings;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TariffSettingsTest extends KernelTestCase
{
    private TariffSettings $tariff;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->tariff = static::getContainer()->get(TariffSettings::class);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testUnconfiguredByDefault(): void
    {
        // The migration creates the table empty on purpose.
        self::assertNull($this->tariff->pricePerKwh());
        self::assertFalse($this->tariff->isConfigured());
        self::assertSame(0.0, $this->tariff->standingChargePerMonth());
        self::assertSame('EUR', $this->tariff->currency());
    }

    /**
     * The distinction the whole feature rests on: a price of zero is a real
     * configuration (somebody generating their own power), and must not read as
     * "no tariff set" — otherwise every cost would render as 0.00 for both.
     */
    public function testAPriceOfZeroCountsAsConfigured(): void
    {
        $this->tariff->save(0.0, 0.0, 'EUR');

        self::assertTrue($this->tariff->isConfigured());
        self::assertSame(0.0, $this->tariff->pricePerKwh());
    }

    public function testSavingNullClearsBackToUnconfigured(): void
    {
        $this->tariff->save(0.32, 12.50, 'EUR');
        self::assertTrue($this->tariff->isConfigured());

        $this->tariff->save(null, 12.50, 'EUR');

        self::assertFalse($this->tariff->isConfigured());
        self::assertNull($this->tariff->pricePerKwh());
        // Clearing the price must not discard the rest of the tariff.
        self::assertSame(12.50, $this->tariff->standingChargePerMonth());
    }

    public function testRoundTrip(): void
    {
        $this->tariff->save(0.3421, 11.99, 'CHF');

        self::assertSame(0.3421, $this->tariff->pricePerKwh());
        self::assertSame(11.99, $this->tariff->standingChargePerMonth());
        self::assertSame('CHF', $this->tariff->currency());
    }

    public function testSavingTwiceUpdatesRatherThanDuplicating(): void
    {
        // The fixed primary key is what enforces the singleton.
        $this->tariff->save(0.30, 10.0, 'EUR');
        $this->tariff->save(0.35, 11.0, 'EUR');

        $rows = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM tariff');
        self::assertSame(1, $rows);
        self::assertSame(0.35, $this->tariff->pricePerKwh());
    }

    public function testToArrayCarriesTheConfiguredFlag(): void
    {
        // The API contract: a client must be able to tell "no price" from "zero"
        // without inspecting the number.
        self::assertSame(
            ['pricePerKwh' => null, 'standingChargeMonth' => 0.0, 'currency' => 'EUR', 'configured' => false],
            $this->tariff->toArray(),
        );

        $this->tariff->save(0.32, 12.0, 'GBP');

        self::assertSame(
            ['pricePerKwh' => 0.32, 'standingChargeMonth' => 12.0, 'currency' => 'GBP', 'configured' => true],
            $this->tariff->toArray(),
        );
    }

    public function testTheSingletonIdIsFixed(): void
    {
        $this->tariff->save(0.32, 0.0, 'EUR');

        $id = (int) $this->em->getConnection()->fetchOne('SELECT id FROM tariff');
        self::assertSame(Tariff::SINGLETON_ID, $id);
    }
}
