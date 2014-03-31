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
use DateTime;
use Doctrine\DBAL\Connection;

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
             ->setParameter('args', $args, 'json_array')
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

    public function findStartableJob(array &$excludedIds = array(), $excludedQueues = array())
    {
        while (null !== $job = $this->findPendingJob($excludedIds, $excludedQueues)) {
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

    public function findAllForRelatedEntity($relatedEntity)
    {
        list($relClass, $relId) = $this->getRelatedEntityIdentifier($relatedEntity);

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata('JMSJobQueueBundle:Job', 'j');

        return $this->_em->createNativeQuery("SELECT j.* FROM jms_jobs j INNER JOIN jms_job_related_entities r ON r.job_id = j.id WHERE r.related_class = :relClass AND r.related_id = :relId", $rsm)
                    ->setParameter('relClass', $relClass)
                    ->setParameter('relId', $relId)
                    ->getResult();
    }

    public function findJobForRelatedEntity($command, $relatedEntity)
    {
        list($relClass, $relId) = $this->getRelatedEntityIdentifier($relatedEntity);

        $rsm = new ResultSetMappingBuilder($this->_em);
        $rsm->addRootEntityFromClassMetadata('JMSJobQueueBundle:Job', 'j');

        return $this->_em->createNativeQuery("SELECT j.* FROM jms_jobs j INNER JOIN jms_job_related_entities r ON r.job_id = j.id WHERE r.related_class = :relClass AND r.related_id = :relId AND j.command = :command", $rsm)
                   ->setParameter('command', $command)
                   ->setParameter('relClass', $relClass)
                   ->setParameter('relId', $relId)
                   ->getOneOrNullResult();
    }

    private function getRelatedEntityIdentifier($entity)
    {
        assert('is_object($entity)');

        if ($entity instanceof \Doctrine\Common\Persistence\Proxy) {
            $entity->__load();
        }

        $relClass = ClassUtils::getClass($entity);
        $relId = $this->registry->getManagerForClass($relClass)->getMetadataFactory()
                    ->getMetadataFor($relClass)->getIdentifierValues($entity);
        asort($relId);

        if ( ! $relId) {
            throw new \InvalidArgumentException(sprintf('The identifier for entity of class "%s" was empty.', $relClass));
        }

        return array($relClass, json_encode($relId));
    }

    public function findPendingJob(array $excludedIds = array(), array $excludedQueues = array())
    {
        $qb = $this->_em->createQueryBuilder();
        $qb->select('j')->from('JMSJobQueueBundle:Job', 'j')
            ->leftJoin('j.dependencies', 'd')
            ->orderBy('j.priority', 'ASC')
            ->addOrderBy('j.id', 'ASC');

        $conditions = array();

        $conditions[] = $qb->expr()->lt('j.executeAfter', ':now');
        $qb->setParameter(':now', new \DateTime(), 'datetime');

        $conditions[] = $qb->expr()->eq('j.state', ':state');
        $qb->setParameter('state', Job::STATE_PENDING);

        if ( ! empty($excludedIds)) {
            $conditions[] = $qb->expr()->notIn('j.id', ':excludedIds');
            $qb->setParameter('excludedIds', $excludedIds, Connection::PARAM_INT_ARRAY);
        }

        if ( ! empty($excludedQueues)) {
            $conditions[] = $qb->expr()->notIn('j.queue', ':excludedQueues');
            $qb->setParameter('excludedQueues', $excludedQueues, Connection::PARAM_STR_ARRAY);
        }

        $qb->where(call_user_func_array(array($qb->expr(), 'andX'), $conditions));

        return $qb->getQuery()->setMaxResults(1)->getOneOrNullResult();
    }

    public function closeJob(Job $job, $finalState)
    {
        $this->_em->getConnection()->beginTransaction();
        try {
            $visited = array();
            $this->closeJobInternal($job, $finalState, $visited);
            $this->_em->flush();
            $this->_em->getConnection()->commit();

            // Clean-up entity manager to allow for garbage collection to kick in.
            foreach ($visited as $job) {
                // If the job is an original job which is now being retried, let's
                // not remove it just yet.
                if ( ! $job->isClosedNonSuccessful() || $job->isRetryJob()) {
                    continue;
                }

                $this->_em->detach($job);
            }
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

        if (null !== $this->dispatcher && ($job->isRetryJob() || 0 === count($job->getRetryJobs()))) {
            $event = new StateChangeEvent($job, $finalState);
            $this->dispatcher->dispatch('jms_job_queue.job_state_change', $event);
            $finalState = $event->getNewState();
        }

        switch ($finalState) {
            case Job::STATE_CANCELED:
                $job->setState(Job::STATE_CANCELED);
                $this->_em->persist($job);

                if ($job->isRetryJob()) {
                    $this->closeJobInternal($job->getOriginalJob(), Job::STATE_CANCELED, $visited);

                    return;
                }

                foreach ($this->findIncomingDependencies($job) as $dep) {
                    $this->closeJobInternal($dep, Job::STATE_CANCELED, $visited);
                }

                return;

            case Job::STATE_FAILED:
            case Job::STATE_TERMINATED:
            case Job::STATE_INCOMPLETE:
                if ($job->isRetryJob()) {
                    $job->setState($finalState);
                    $this->_em->persist($job);

                    $this->closeJobInternal($job->getOriginalJob(), $finalState);

                    return;
                }

                // The original job has failed, and we are allowed to retry it.
                if ($job->isRetryAllowed()) {
                    $retryJob = new Job($job->getCommand(), $job->getArgs());
                    $retryJob->setMaxRuntime($job->getMaxRuntime());
                    $retryJob->setExecuteAfter(new \DateTime('+'.(pow(5, count($job->getRetryJobs()))).' seconds'));

                    $job->addRetryJob($retryJob);
                    $this->_em->persist($retryJob);
                    $this->_em->persist($job);

                    return;
                }

                $job->setState($finalState);
                $this->_em->persist($job);

                // The original job has failed, and no retries are allowed.
                foreach ($this->findIncomingDependencies($job) as $dep) {
                    $this->closeJobInternal($dep, Job::STATE_CANCELED, $visited);
                }

                return;

            case Job::STATE_FINISHED:
                if ($job->isRetryJob()) {
                    $job->getOriginalJob()->setState($finalState);
                    $this->_em->persist($job->getOriginalJob());
                }
                $job->setState($finalState);
                $this->_em->persist($job);

                return;

            default:
                throw new \LogicException(sprintf('Non allowed state "%s" in closeJobInternal().', $finalState));
        }
    }

    public function findIncomingDependencies(Job $job)
    {
        return $this->_em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j LEFT JOIN j.dependencies d WHERE :job MEMBER OF j.dependencies")
                    ->setParameter('job', $job)
                    ->getResult();
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

    public function getAvailableQueueList()
    {
        $queues =  $this->_em->createQuery("SELECT DISTINCT j.queue FROM JMSJobQueueBundle:Job j WHERE j.state IN (:availableStates)  GROUP BY j.queue")
            ->setParameter('availableStates', array(Job::STATE_RUNNING, Job::STATE_NEW, Job::STATE_PENDING))
            ->getResult();

        $newQueueArray = array();

        foreach($queues as $queue) {
            $newQueue = $queue['queue'];
            $newQueueArray[] = $newQueue;
        }

        return $newQueueArray;
    }


    public function getAvailableJobsForQueueCount($jobQueue)
    {
        $result = $this->_em->createQuery("SELECT j.queue FROM JMSJobQueueBundle:Job j WHERE j.state IN (:availableStates) AND j.queue = :queue")
            ->setParameter('availableStates', array(Job::STATE_RUNNING, Job::STATE_NEW, Job::STATE_PENDING))
            ->setParameter('queue', $jobQueue)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return count($result);
    }
}