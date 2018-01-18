<?php

namespace JMS\JobQueueBundle\Command;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use JMS\JobQueueBundle\Entity\Job;
use JMS\JobQueueBundle\Entity\Repository\JobRepository;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanUpCommand extends ContainerAwareCommand
{
    protected static $defaultName = 'jms-job-queue:clean-up';

    protected function configure()
    {
        $this
            ->setDescription('Cleans up jobs which exceed the maximum retention time.')
            ->addOption('max-retention', null, InputOption::VALUE_REQUIRED, 'The maximum retention time (value must be parsable by DateTime).', '7 days')
            ->addOption('max-retention-succeeded', null, InputOption::VALUE_REQUIRED, 'The maximum retention time for succeeded jobs (value must be parsable by DateTime).', '1 hour')
            ->addOption('per-call', null, InputOption::VALUE_REQUIRED, 'The maximum number of jobs to clean-up per call.', 1000)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var ManagerRegistry $registry */
        $registry = $this->getContainer()->get('doctrine');

        /** @var EntityManager $em */
        $em = $registry->getManagerForClass('JMSJobQueueBundle:Job');
        $con = $em->getConnection();

        $this->cleanUpExpiredJobs($em, $con, $input);
        $this->collectStaleJobs($em);
    }

    private function collectStaleJobs(EntityManager $em)
    {
        /** @var JobRepository $repository */
        $repository = $em->getRepository(Job::class);

        foreach ($this->findStaleJobs($em) as $job) {
            if ($job->isRetried()) {
                continue;
            }

            $repository->closeJob($job, Job::STATE_INCOMPLETE);
        }
    }

    /**
     * @return Job[]
     */
    private function findStaleJobs(EntityManager $em)
    {
        $excludedIds = array(-1);

        do {
            $em->clear();

            /** @var Job $job */
            $job = $em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j
                                      WHERE j.state = :running AND j.workerName IS NOT NULL AND j.checkedAt < :maxAge
                                                AND j.id NOT IN (:excludedIds)")
                ->setParameter('running', Job::STATE_RUNNING)
                ->setParameter('maxAge', new \DateTime('-5 minutes'), 'datetime')
                ->setParameter('excludedIds', $excludedIds)
                ->setMaxResults(1)
                ->getOneOrNullResult();

            if ($job !== null) {
                $excludedIds[] = $job->getId();

                yield $job;
            }
        } while ($job !== null);
    }

    private function cleanUpExpiredJobs(EntityManager $em, Connection $con, InputInterface $input)
    {
        $incomingDepsSql = $con->getDatabasePlatform()->modifyLimitQuery("SELECT 1 FROM jms_job_dependencies WHERE dest_job_id = :id", 1);

        $count = 0;
        foreach ($this->findExpiredJobs($em, $input) as $job) {
            /** @var Job $job */

            $count++;

            $result = $con->executeQuery($incomingDepsSql, array('id' => $job->getId()));
            if ($result->fetchColumn() !== false) {
                $em->transactional(function() use ($em, $job) {
                    $this->resolveDependencies($em, $job);
                    $em->remove($job);
                });

                continue;
            }

            $em->remove($job);

            if ($count >= $input->getOption('per-call')) {
                break;
            }
        }

        $em->flush();
    }

    private function resolveDependencies(EntityManager $em, Job $job)
    {
        // If this job has failed, or has otherwise not succeeded, we need to set the
        // incoming dependencies to failed if that has not been done already.
        if ( ! $job->isFinished()) {
            /** @var JobRepository $repository */
            $repository = $em->getRepository(Job::class);
            foreach ($repository->findIncomingDependencies($job) as $incomingDep) {
                if ($incomingDep->isInFinalState()) {
                    continue;
                }

                $finalState = Job::STATE_CANCELED;
                if ($job->isRunning()) {
                    $finalState = Job::STATE_FAILED;
                }

                $repository->closeJob($incomingDep, $finalState);
            }
        }

        $em->getConnection()->executeUpdate("DELETE FROM jms_job_dependencies WHERE dest_job_id = :id", array('id' => $job->getId()));
    }

    private function findExpiredJobs(EntityManager $em, InputInterface $input)
    {
        $succeededJobs = function(array $excludedIds) use ($em, $input) {
            return $em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.closedAt < :maxRetentionTime AND j.originalJob IS NULL AND j.state = :succeeded AND j.id NOT IN (:excludedIds)")
                ->setParameter('maxRetentionTime', new \DateTime('-'.$input->getOption('max-retention-succeeded')))
                ->setParameter('excludedIds', $excludedIds)
                ->setParameter('succeeded', Job::STATE_FINISHED)
                ->setMaxResults(100)
                ->getResult();
        };
        foreach ($this->whileResults($succeededJobs) as $job) {
            yield $job;
        }

        $finishedJobs = function(array $excludedIds) use ($em, $input) {
            return $em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.closedAt < :maxRetentionTime AND j.originalJob IS NULL AND j.id NOT IN (:excludedIds)")
                ->setParameter('maxRetentionTime', new \DateTime('-'.$input->getOption('max-retention')))
                ->setParameter('excludedIds', $excludedIds)
                ->setMaxResults(100)
                ->getResult();
        };
        foreach ($this->whileResults($finishedJobs) as $job) {
            yield $job;
        }

        $canceledJobs = function(array $excludedIds) use ($em, $input) {
            return $em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.state = :canceled AND j.createdAt < :maxRetentionTime AND j.originalJob IS NULL AND j.id NOT IN (:excludedIds)")
                ->setParameter('maxRetentionTime', new \DateTime('-'.$input->getOption('max-retention')))
                ->setParameter('canceled', Job::STATE_CANCELED)
                ->setParameter('excludedIds', $excludedIds)
                ->setMaxResults(100)
                ->getResult();
        };
        foreach ($this->whileResults($canceledJobs) as $job) {
            yield $job;
        }
    }

    private function whileResults(callable $resultProducer)
    {
        $excludedIds = array(-1);

        do {
            /** @var Job[] $jobs */
            $jobs = $resultProducer($excludedIds);
            foreach ($jobs as $job) {
                $excludedIds[] = $job->getId();
                yield $job;
            }
        } while ( ! empty($jobs));
    }
}