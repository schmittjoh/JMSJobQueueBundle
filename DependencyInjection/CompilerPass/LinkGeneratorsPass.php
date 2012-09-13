<?php

namespace JMS\JobQueueBundle\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class LinkGeneratorsPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $generators = array();
        foreach ($container->findTaggedServiceIds('jms_job_queue.link_generator') as $id => $attrs) {
            $generators[] = new Reference($id);
        }

        $container->getDefinition('jms_job_queue.twig.extension')
                ->addArgument($generators);
    }
}