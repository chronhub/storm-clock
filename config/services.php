<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Psr\Clock\ClockInterface;
use Storm\Clock\SystemClock;
use Storm\Contracts\Clock\Clock;

/*
 * Clock package wiring.
 *
 * SystemClock decorates the framework's PSR-20 clock from symfony/clock so that every
 * `Psr\Clock\ClockInterface` consumer in the app receives Storm's UTC + microsecond
 * guaranteed clock. The `.inner` reference is the service being decorated.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(SystemClock::class)
        ->decorate(ClockInterface::class)
        ->args([service('.inner')]);

    // Inject the richer Storm contract that returns PointInTime wherever `Clock` is asked for.
    $services->alias(Clock::class, SystemClock::class);
};
