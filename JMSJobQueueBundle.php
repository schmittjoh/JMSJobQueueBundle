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

namespace JMS\JobQueueBundle;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\Connection;
use JMS\JobQueueBundle\DependencyInjection\CompilerPass\LinkGeneratorsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Doctrine\DBAL\Types\Type;

class JMSJobQueueBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        $container->addCompilerPass(new LinkGeneratorsPass());
    }

    public function boot()
    {
        if ( ! Type::hasType('jms_job_safe_object')) {
            Type::addType('jms_job_safe_object', 'JMS\JobQueueBundle\Entity\Type\SafeObjectType');
        }

        /** @var ManagerRegistry $registry*/
        $registry = $this->container->get('doctrine');
        foreach ($registry->getConnections() as $con) {
            if ($con instanceof Connection) {
                $con->getDatabasePlatform()->markDoctrineTypeCommented('jms_job_safe_object');
            }
        }
    }
}
