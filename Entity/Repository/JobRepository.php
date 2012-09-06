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

namespace JMS\JobQueueBundle\Entity\Repository;

use JMS\JobQueueBundle\Event\StateChangeEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Doctrine\ORM\EntityRepository;
use JMS\JobQueueBundle\Entity\Job;
use JMS\DiExtraBundle\Annotation as DI;

class JobRepository extends EntityRepository
{
    private $dispatcher;

    /**
     * @DI\InjectParams({
     *     "dispatcher" = @DI\Inject("event_dispatcher"),
     * })
     * @param EventDispatcherInterface $dispatcher
     */
    public function setDispatcher(EventDispatcherInterface $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function findStartableJob()
    {
        while (null !== $job = $this->findPendingJob()) {
            if ($job->isStartable()) {
                return $job;
            }
        }

        return null;
    }

    public function findPendingJob()
    {
        return $this->_em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j LEFT JOIN j.dependencies d WHERE j.state = :state")
                    ->setParameter('state', Job::STATE_PENDING)
                    ->setMaxResults(1)
                    ->getOneOrNullResult();
    }

    public function closeJob(Job $job, $finalState)
    {
        $this->_em->getConnection()->beginTransaction();
        try {
            $this->closeJobInternal($job, $finalState);
            $this->_em->flush();
            $this->_em->getConnection()->commit();
        } catch (\Exception $ex) {
            $this->_em->getConnection()->rollback();

            throw $ex;
        }
    }

    private function closeJobInternal(Job $job, $finalState, array &$visited = array())
    {
        if (in_array($job, $visited, true)) {
            return;
        }
        $visited[] = $job;

        $incomingDeps = $this->_em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j LEFT JOIN j.dependencies d WHERE :job MEMBER OF j.dependencies")
                            ->setParameter('job', $job)
                            ->getResult();
        foreach ($incomingDeps as $dep) {
            $this->closeJobInternal($dep, Job::STATE_CANCELED, $visited);
        }

        if (null !== $this->dispatcher) {
            $event = new StateChangeEvent($job, $finalState);
            $this->dispatcher->dispatch('jms_job_queue.job_state_change', $event);
            $finalState = $event->getNewState();
        }

        $job->setState($finalState);
        $this->_em->persist($job);
    }
}