<?php

namespace JMS\JobQueueBundle;

use JMS\JobQueueBundle\DependencyInjection\CompilerPass\JobSchedulersPass;
use JMS\JobQueueBundle\DependencyInjection\CompilerPass\LinkGeneratorsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class JMSJobQueueBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new LinkGeneratorsPass());
        $container->addCompilerPass(new JobSchedulersPass());
    }
}
