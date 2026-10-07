<?php

declare(strict_types=1);

namespace Storm\Clock\Tests;

use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Storm\Clock\PointInTime;
use Storm\Contracts\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class ClockWiringTest extends TestCase
{
    #[Test]
    public function psr_consumers_can_render_the_time_in_a_local_timezone(): void
    {
        $container = $this->container();
        $clock = $container->get(ClockInterface::class); // @phpstan-ignore symfonyContainer.privateService (the compiled test container exposes this alias)
        self::assertInstanceOf(ClockInterface::class, $clock);

        $local = $clock->now()->setTimezone(new DateTimeZone('Europe/Paris'));

        self::assertSame('2026-01-15T11:00:00.123456+01:00', $local->format('Y-m-d\TH:i:s.uP'));
    }

    #[Test]
    public function storm_clock_preserves_utc_and_observes_the_replaced_source_clock(): void
    {
        $container = $this->container();
        $clock = $container->get(Clock::class); // @phpstan-ignore symfonyContainer.privateService (the compiled test container exposes this alias)
        self::assertInstanceOf(Clock::class, $clock);
        self::assertInstanceOf(PointInTime::class, $clock->now());
        self::assertSame('2026-01-15T10:00:00.123456+00:00', $clock->now()->format('Y-m-d\TH:i:s.uP'));

        $source = $container->get('test.source_clock'); // @phpstan-ignore symfonyContainer.serviceNotFound (registered by this test container)
        self::assertInstanceOf(MockClock::class, $source);
        $source->sleep(60);

        self::assertSame('2026-01-15T10:01:00.123456+00:00', $clock->now()->format('Y-m-d\TH:i:s.uP'));
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder;
        $container->register('test.source_clock', MockClock::class)
            ->setArguments(['2026-01-15T10:00:00.123456+00:00'])
            ->setPublic(true);
        $container->setAlias(ClockInterface::class, 'test.source_clock')->setPublic(true);
        new PhpFileLoader($container, new FileLocator(dirname(__DIR__).'/config'))->load('services.php');
        $container->getAlias(Clock::class)->setPublic(true);
        $container->compile();

        return $container;
    }
}
