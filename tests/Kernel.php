<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle\Tests;

use ChargeCloud\AvroMessengerSerializerBundle\ChargeCloudAvroMessengerSerializerBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

final class Kernel extends BaseKernel
{
    use \Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new ChargeCloudAvroMessengerSerializerBundle(),
        ];
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $loader->load(__DIR__.'/Fixtures/config/config.yaml');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}
