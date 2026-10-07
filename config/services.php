<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Psr\Clock\ClockInterface;
use Storm\Clock\SystemClock;
use Storm\Contracts\Clock\Clock;

/*
 * Clock package wiring.
 *
 * SystemClock adapts the application's PSR-20 clock for the Storm port only.
 * PSR-20 consumers keep the source clock and its native datetime behavior.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(SystemClock::class)
        ->args([service(ClockInterface::class)]);

    // Inject the richer Storm contract that returns PointInTime wherever `Clock` is asked for.
    $services->alias(Clock::class, SystemClock::class);
};
