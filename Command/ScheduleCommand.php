<?php

namespace JMS\JobQueueBundle\Command;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use JMS\JobQueueBundle\Console\CronCommand;
use JMS\JobQueueBundle\Cron\CommandScheduler;
use JMS\JobQueueBundle\Cron\JobScheduler;
use JMS\JobQueueBundle\Entity\CronJob;
use JMS\JobQueueBundle\Entity\Job;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ScheduleCommand extends ContainerAwareCommand
{
    protected static $defaultName = 'jms-job-queue:schedule';

    protected function configure()
    {
        $this
            ->setDescription('Schedules jobs at defined intervals')
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'The maximum runtime of this command.', 3600)
            ->addOption('min-job-interval', null, InputOption::VALUE_REQUIRED, 'The minimum time between schedules jobs in seconds.', 5)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var ManagerRegistry $registry */
        $registry = $this->getContainer()->get('doctrine');

        $maxRuntime = $input->getOption('max-runtime');
        if ($maxRuntime > 300) {
            $maxRuntime += mt_rand(0, (integer)($input->getOption('max-runtime') * 0.05));
        }
        if ($maxRuntime <= 0) {
            throw new \RuntimeException('Max. runtime must be greater than zero.');
        }

        $minJobInterval = (integer)$input->getOption('min-job-interval');
        if ($minJobInterval <= 0) {
            throw new \RuntimeException('Min. job interval must be greater than zero.');
        }

        $jobSchedulers = $this->populateJobSchedulers();
        if (empty($jobSchedulers)) {
            $output->writeln('No job schedulers found, exiting...');

            return 0;
        }

        $jobsLastRunAt = $this->populateJobsLastRunAt($registry->getManagerForClass(CronJob::class), $jobSchedulers);

        $startedAt = time();
        while (true) {
            $lastRunAt = microtime(true);
            $now = time();
            if ($now - $startedAt > $maxRuntime) {
                $output->writeln('Max. runtime reached, exiting...');
                break;
            }

            $this->scheduleJobs($output, $registry, $jobSchedulers, $jobsLastRunAt);

            $timeToWait = microtime(true) - $lastRunAt + $minJobInterval;
            if ($timeToWait > 0) {
                usleep($timeToWait * 1E6);
            }
        }

        return 0;
    }

    /**
     * @param JobScheduler[] $jobSchedulers
     * @param \DateTime[] $jobsLastRunAt
     */
    private function scheduleJobs(OutputInterface $output, ManagerRegistry $registry, array $jobSchedulers, array &$jobsLastRunAt)
    {
        foreach ($jobSchedulers as $name => $scheduler) {
            $lastRunAt = $jobsLastRunAt[$name];

            if ( ! $scheduler->shouldSchedule($name, $lastRunAt)) {
                continue;
            }

            list($success, $newLastRunAt) = $this->acquireLock($registry, $name, $lastRunAt);
            $jobsLastRunAt[$name] = $newLastRunAt;

            if ($success) {
                $output->writeln('Scheduling command '.$name);
                $job = $scheduler->createJob($name, $lastRunAt);
                $em = $registry->getManagerForClass(Job::class);
                $em->persist($job);
                $em->flush($job);
            }
        }
    }

    private function acquireLock(ManagerRegistry $registry, $commandName, \DateTime $lastRunAt)
    {
        /** @var EntityManager $em */
        $em = $registry->getManagerForClass(CronJob::class);
        $con = $em->getConnection();

        $now = new \DateTime();
        $affectedRows = $con->executeUpdate(
            "UPDATE jms_cron_jobs SET lastRunAt = :now WHERE command = :command AND lastRunAt = :lastRunAt",
            array(
                'now' => $now,
                'command' => $commandName,
                'lastRunAt' => $lastRunAt,
            ),
            array(
                'now' => 'datetime',
                'lastRunAt' => 'datetime',
            )
        );

        if ($affectedRows > 0) {
            return array(true, $now);
        }

        /** @var CronJob $cronJob */
        $cronJob = $em->createQuery("SELECT j FROM ".CronJob::class." j WHERE j.command = :command")
            ->setParameter('command', $commandName)
            ->setHint(Query::HINT_REFRESH, true)
            ->getSingleResult();

        return array(false, $cronJob->getLastRunAt());
    }

    private function populateJobSchedulers()
    {
        $schedulers = $this->getContainer()->get('jms_job_queue.scheduler_registry')->getSchedulers();

        foreach ($this->getApplication()->all() as $name => $command) {
            if ( ! $command instanceof CronCommand) {
                continue;
            }

            $schedulers[$name] = new CommandScheduler($command);
        }

        return $schedulers;
    }

    private function populateJobsLastRunAt(EntityManager $em, array $jobSchedulers)
    {
        $jobsLastRunAt = array();

        foreach ($em->getRepository(CronJob::class)->findAll() as $job) {
            /** @var CronJob $job */
            $jobsLastRunAt[$job->getCommand()] = $job->getLastRunAt();
        }

        foreach (array_keys($jobSchedulers) as $name) {
            if ( ! isset($jobsLastRunAt[$name])) {
                $job = new CronJob($name);
                $em->persist($job);
                $jobsLastRunAt[$name] = $job->getLastRunAt();
            }
        }
        $em->flush();

        return $jobsLastRunAt;
    }
}
