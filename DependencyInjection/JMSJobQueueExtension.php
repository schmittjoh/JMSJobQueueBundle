<?php

/*
 * Copyright 2012 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\JobQueueBundle\DependencyInjection;

use JMS\JobQueueBundle\Console\CronCommand;
use JMS\JobQueueBundle\Cron\JobScheduler;
use JMS\JobQueueBundle\Entity\Type\SafeObjectType;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Loader;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class JMSJobQueueExtension extends Extension implements PrependExtensionInterface
{
    /**
     * {@inheritDoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.xml');
        $loader->load('console.xml');

        $container->setParameter('jms_job_queue.statistics', $config['statistics']);
        if ($config['statistics']) {
            $loader->load('statistics.xml');
        }

        $container->registerForAutoconfiguration(JobScheduler::class)
            ->addTag('jms_job_queue.scheduler');
        $container->registerForAutoconfiguration(CronCommand::class)
            ->addTag('jms_job_queue.cron_command');

        $container->setParameter('jms_job_queue.queue_options_defaults', $config['queue_options_defaults']);
        $container->setParameter('jms_job_queue.queue_options', $config['queue_options']);
    }

    public function prepend(ContainerBuilder $container)
    {
        $container->prependExtensionConfig('doctrine', array(
            'dbal' => array(
                'types' => array(
                    'jms_job_safe_object' => array(
                        'class' => SafeObjectType::class,
                        'commented' => true,
                    )
                )
            )
        ));
    }
}
