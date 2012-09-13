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

use Doctrine\Common\Util\ClassUtils;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use JMS\DiExtraBundle\Annotation as DI;
use JMS\JobQueueBundle\Entity\Job;
use JMS\JobQueueBundle\Event\StateChangeEvent;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class JobRepository extends EntityRepository
{
    private $dispatcher;
    private $registry;

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

    /**
     * @DI\InjectParams({
     *     "registry" = @DI\Inject("doctrine"),
     * })
     * @param RegistryInterface $registry
     */
    public function setRegistry(RegistryInterface $registry)
    {
        $this->registry = $registry;
    }

    public function findJob($command, array $args = array())
    {
        return $this->_em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.command = :command AND j.args = :args")
            ->setParameter('command', $command)
            ->setParameter('args', $args, Type::JSON_ARRAY)
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    public function getJob($command, array $args = array())
    {
        if (null !== $job = $this->findJob($command, $args)) {
            return $job;
        }

        throw new \RuntimeException(sprintf('Found no job for command "%s" with args "%s".', $command, json_encode($args)));
    }

    public function getOrCreateIfNotExists($command, array $args = array())
    {
        if (null !== $job = $this->findJob($command, $args)) {
            return $job;
        }

        $job = new Job($command, $args, false);
        $this->_em->persist($job);
        $this->_em->flush($job);

        $firstJob = $this->_em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.command = :command AND j.args = :args ORDER BY j.id ASC")
             ->setParameter('command', $command)
             ->setParameter('args', $args)
             ->setMaxResults(1)
             ->getSingleResult();

        if ($firstJob === $job) {
            $job->setState(Job::STATE_PENDING);
            $this->_em->persist($job);
            $this->_em->flush($job);

            return $job;
        }

        $this->_em->remove($job);
        $this->_em->flush($job);

        return $firstJob;
    }

    public function findStartableJob(array &$excludedIds = array())
    {
        while (null !== $job = $this->findPendingJob($excludedIds)) {
            if ($job->isStartable()) {
                return $job;
            }

            $excludedIds[] = $job->getId();

            // We do not want to have non-startable jobs floating around in
            // cache as they might be changed by another process. So, better
            // re-fetch them when they are not excluded anymore.
            $this->_em->detach($job);
        }

        return null;
    }

    public function findJobForRelatedEntity($command, $relatedEntity)
    {
        assert('is_object($relatedEntity)');

        $relClass = ClassUtils::getClass($relatedEntity);
        $relId = $this->registry->getManagerForClass($relClass)->getMetadataFactory()->getMetadataFor($relClass)->getIdentifierValues($relatedEntity);
        asort($relId);

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata('JMSJobQueueBundle:Job', 'j');

        return $this->_em->createNativeQuery("SELECT j.* FROM jms_jobs j INNER JOIN jms_job_related_entities r ON r.job_id = j.id WHERE r.related_class = :relClass AND r.related_id = :relId AND j.command = :command", $rsm)
                   ->setParameter('command', $command)
                   ->setParameter('relClass', $relClass)
                   ->setParameter('relId', json_encode($relId))
                   ->getOneOrNullResult();
    }

    public function findPendingJob(array $excludedIds = array())
    {
        if ( ! $excludedIds) {
            $excludedIds = array(-1);
        }

        return $this->_em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j LEFT JOIN j.dependencies d WHERE j.state = :state AND j.id NOT IN (:excludedIds) ORDER BY j.id ASC")
                    ->setParameter('state', Job::STATE_PENDING)
                    ->setParameter('excludedIds', $excludedIds)
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

        if (null !== $this->dispatcher) {
            $event = new StateChangeEvent($job, $finalState);
            $this->dispatcher->dispatch('jms_job_queue.job_state_change', $event);
            $finalState = $event->getNewState();
        }

        switch ($finalState) {
            case Job::STATE_CANCELED:
            case Job::STATE_TERMINATED:
            case Job::STATE_FAILED:
                $incomingDeps = $this->_em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j LEFT JOIN j.dependencies d WHERE :job MEMBER OF j.dependencies")
                    ->setParameter('job', $job)
                    ->getResult();
                foreach ($incomingDeps as $dep) {
                    $this->closeJobInternal($dep, Job::STATE_CANCELED, $visited);
                }
                break;

            case Job::STATE_FINISHED:
                break;

            default:
                throw new \LogicException(sprintf('The previous cases were exhaustive. Unsupported final state "%s".', $finalState));
        }

        $job->setState($finalState);
        $this->_em->persist($job);
    }

    public function getIncomingDependencies(Job $job)
    {
        return $this->_em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE :job MEMBER OF j.dependencies")
                    ->setParameter('job', $job)
                    ->getResult();
    }

    public function findLastJobsWithError($nbJobs = 10)
    {
        return $this->_em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.state IN (:errorStates) AND j.originalJob IS NULL ORDER BY j.closedAt DESC")
                    ->setParameter('errorStates', array(Job::STATE_TERMINATED, Job::STATE_FAILED))
                    ->setMaxResults($nbJobs)
                    ->getResult();
    }
}