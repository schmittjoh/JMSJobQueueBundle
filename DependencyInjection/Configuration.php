<?php

namespace JMS\JobQueueBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * JMSJobQueueBundle Configuration.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('jms_job_queue');

        $rootNode
            ->children()
                ->booleanNode('statistics')->defaultTrue()->end();

        $defaultOptionsNode = $rootNode
            ->children()
                ->arrayNode('queue_options_defaults')
                    ->addDefaultsIfNotSet();
        $this->addQueueOptions($defaultOptionsNode);

        $queueOptionsNode = $rootNode
            ->children()
                ->arrayNode('queue_options')
                    ->useAttributeAsKey('queue')
                    ->prototype('array');
        $this->addQueueOptions($queueOptionsNode);

        return $treeBuilder;
    }

    private function addQueueOptions(ArrayNodeDefinition $def)
    {
        $def
            ->children()
                ->scalarNode('max_concurrent_jobs')->end()
        ;
    }
}
