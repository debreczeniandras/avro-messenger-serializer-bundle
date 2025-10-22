<?php

declare(strict_types=1);

namespace ChargeCloud\AvroMessengerSerializerBundle;

use ChargeCloud\AvroMessengerSerializerBundle\DependencyInjection\Compiler\MessageMetadataCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class ChargeCloudAvroMessengerSerializerBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new MessageMetadataCompilerPass());
    }
}
