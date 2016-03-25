<?php

namespace JMS\JobQueueBundle\DependencyInjection\CompilerPass;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class JobSchedulersPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $schedulers = array();
        foreach ($container->findTaggedServiceIds('jms_job_queue.scheduler') as $id => $attributes) {
            foreach ($attributes as $attributeData) {
                if (!isset($attributeData['command'])) {
                    throw new \RuntimeException(sprintf('The tag "jms_job_queue.schedulers" of service "%s" must have a "command" attribute.', $id));
                }

                $schedulers[$attributeData['command']] = new Reference($id);
            }
        }

        $container->getDefinition('jms_job_queue.scheduler_registry')
            ->addArgument($schedulers);
    }
}