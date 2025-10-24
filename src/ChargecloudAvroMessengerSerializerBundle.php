<?php

declare(strict_types=1);

namespace Chargecloud\AvroMessengerSerializerBundle;

use Chargecloud\AvroMessengerSerializerBundle\DependencyInjection\Compiler\MessageMetadataCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class ChargecloudAvroMessengerSerializerBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new MessageMetadataCompilerPass());
    }
}
