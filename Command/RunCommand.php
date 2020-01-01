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

namespace JMS\JobQueueBundle\Command;

use Doctrine\ORM\EntityManager;
use JMS\JobQueueBundle\Entity\Job;
use JMS\JobQueueBundle\Entity\Repository\JobManager;
use JMS\JobQueueBundle\Event\NewOutputEvent;
use JMS\JobQueueBundle\Event\StateChangeEvent;
use JMS\JobQueueBundle\Exception\InvalidArgumentException;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class RunCommand extends Command
{
    protected static $defaultName = 'jms-job-queue:run';

    /** @var string */
    private $env;

    /** @var boolean */
    private $verbose;

    /** @var OutputInterface */
    private $output;

    /** @var ManagerRegistry */
    private $registry;

    /** @var JobManager */
    private $jobManager;

    /** @var EventDispatcherInterface */
    private $dispatcher;

    /** @var array */
    private $runningJobs = array();

    /** @var bool */
    private $shouldShutdown = false;

    /** @var array */
    private $queueOptionsDefault;

    /** @var array */
    private $queueOptions;

    public function __construct(ManagerRegistry $managerRegistry, JobManager $jobManager, EventDispatcherInterface $dispatcher, array $queueOptionsDefault, array $queueOptions)
    {
        parent::__construct();

        $this->registry = $managerRegistry;
        $this->jobManager = $jobManager;
        $this->dispatcher = $dispatcher;
        $this->queueOptionsDefault = $queueOptionsDefault;
        $this->queueOptions = $queueOptions;
    }

    protected function configure()
    {
        $this
            ->setDescription('Runs jobs from the queue.')
            ->addOption('max-runtime', 'r', InputOption::VALUE_REQUIRED, 'The maximum runtime in seconds.', 900)
            ->addOption('max-concurrent-jobs', 'j', InputOption::VALUE_REQUIRED, 'The maximum number of concurrent jobs.', 4)
            ->addOption('idle-time', null, InputOption::VALUE_REQUIRED, 'Time to sleep when the queue ran out of jobs.', 2)
            ->addOption('queue', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Restrict to one or more queues.', array())
            ->addOption('worker-name', null, InputOption::VALUE_REQUIRED, 'The name that uniquely identifies this worker process.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $startTime = time();

        $maxRuntime = (integer) $input->getOption('max-runtime');
        if ($maxRuntime <= 0) {
            throw new InvalidArgumentException('The maximum runtime must be greater than zero.');
        }

        if ($maxRuntime > 600) {
            $maxRuntime += random_int(-120, 120);
        }

        $maxJobs = (integer) $input->getOption('max-concurrent-jobs');
        if ($maxJobs <= 0) {
            throw new InvalidArgumentException('The maximum number of jobs per queue must be greater than zero.');
        }

        $idleTime = (integer) $input->getOption('idle-time');
        if ($idleTime <= 0) {
            throw new InvalidArgumentException('Time to sleep when idling must be greater than zero.');
        }

        $restrictedQueues = $input->getOption('queue');

        $workerName = $input->getOption('worker-name');
        if ($workerName === null) {
            $workerName = gethostname().'-'.getmypid();
        }

        if (strlen($workerName) > 50) {
            throw new \RuntimeException(sprintf(
                '"worker-name" must not be longer than 50 chars, but got "%s" (%d chars).',
                $workerName,
                strlen($workerName)
            ));
        }

        $this->env = $input->getOption('env');
        $this->verbose = $input->getOption('verbose');
        $this->output = $output;
        $this->getEntityManager()->getConnection()->getConfiguration()->setSQLLogger(null);

        if ($this->verbose) {
            $this->output->writeln('Cleaning up stale jobs');
        }

        $this->cleanUpStaleJobs($workerName);

        $this->runJobs(
            $workerName,
            $startTime,
            $maxRuntime,
            $idleTime,
            $maxJobs,
            $restrictedQueues,
            $this->queueOptionsDefault,
            $this->queueOptions
        );
    }

    private function runJobs($workerName, $startTime, $maxRuntime, $idleTime, $maxJobs, array $restrictedQueues, array $queueOptionsDefaults, array $queueOptions)
    {
        $hasPcntl = extension_loaded('pcntl');

        if ($this->verbose) {
            $this->output->writeln('Running jobs');
        }

        if ($hasPcntl) {
            $this->setupSignalHandlers();
            if ($this->verbose) {
                $this->output->writeln('Signal Handlers have been installed.');
            }
        } elseif ($this->verbose) {
            $this->output->writeln('PCNTL extension is not available. Signals cannot be processed.');
        }

        while (true) {
            if ($hasPcntl) {
                pcntl_signal_dispatch();
            }

            if ($this->shouldShutdown || time() - $startTime > $maxRuntime) {
                break;
            }

            $this->checkRunningJobs();
            $this->startJobs($workerName, $idleTime, $maxJobs, $restrictedQueues, $queueOptionsDefaults, $queueOptions);

            $waitTimeInMs = random_int(500, 1000);
            usleep($waitTimeInMs * 1E3);
        }

        if ($this->verbose) {
            $this->output->writeln('Entering shutdown sequence, waiting for running jobs to terminate...');
        }

        while ( ! empty($this->runningJobs)) {
            sleep(5);
            $this->checkRunningJobs();
        }

        if ($this->verbose) {
            $this->output->writeln('All jobs finished, exiting.');
        }
    }

    private function setupSignalHandlers()
    {
        pcntl_signal(SIGTERM, function() {
            if ($this->verbose) {
                $this->output->writeln('Received SIGTERM signal.');
            }

            $this->shouldShutdown = true;
        });
    }

    private function startJobs($workerName, $idleTime, $maxJobs, array $restrictedQueues, array $queueOptionsDefaults, array $queueOptions)
    {
        $excludedIds = array();
        while (count($this->runningJobs) < $maxJobs) {
            $pendingJob = $this->jobManager->findStartableJob(
                $workerName,
                $excludedIds,
                $this->getExcludedQueues($queueOptionsDefaults, $queueOptions, $maxJobs),
                $restrictedQueues
            );

            if (null === $pendingJob) {
                sleep($idleTime);

                return;
            }

            $this->startJob($pendingJob);
        }
    }

    private function getExcludedQueues(array $queueOptionsDefaults, array $queueOptions, $maxConcurrentJobs)
    {
        $excludedQueues = array();
        foreach ($this->getRunningJobsPerQueue() as $queue => $count) {
            if ($count >= $this->getMaxConcurrentJobs($queue, $queueOptionsDefaults, $queueOptions, $maxConcurrentJobs)) {
                $excludedQueues[] = $queue;
            }
        }

        return $excludedQueues;
    }

    private function getMaxConcurrentJobs($queue, array $queueOptionsDefaults, array $queueOptions, $maxConcurrentJobs)
    {
        if (isset($queueOptions[$queue]['max_concurrent_jobs'])) {
            return (integer) $queueOptions[$queue]['max_concurrent_jobs'];
        }

        if (isset($queueOptionsDefaults['max_concurrent_jobs'])) {
            return (integer) $queueOptionsDefaults['max_concurrent_jobs'];
        }

        return $maxConcurrentJobs;
    }

    private function getRunningJobsPerQueue()
    {
        $runningJobsPerQueue = array();
        foreach ($this->runningJobs as $jobDetails) {
            /** @var Job $job */
            $job = $jobDetails['job'];

            $queue = $job->getQueue();
            if ( ! isset($runningJobsPerQueue[$queue])) {
                $runningJobsPerQueue[$queue] = 0;
            }
            $runningJobsPerQueue[$queue] += 1;
        }

        return $runningJobsPerQueue;
    }

    private function checkRunningJobs()
    {
        foreach ($this->runningJobs as $i => &$data) {
            $newOutput = substr($data['process']->getOutput(), $data['output_pointer']);
            $data['output_pointer'] += strlen($newOutput);

            $newErrorOutput = substr($data['process']->getErrorOutput(), $data['error_output_pointer']);
            $data['error_output_pointer'] += strlen($newErrorOutput);

            if ( ! empty($newOutput)) {
                $event = new NewOutputEvent($data['job'], $newOutput, NewOutputEvent::TYPE_STDOUT);
                $this->dispatcher->dispatch($event, 'jms_job_queue.new_job_output');
                $newOutput = $event->getNewOutput();
            }

            if ( ! empty($newErrorOutput)) {
                $event = new NewOutputEvent($data['job'], $newErrorOutput, NewOutputEvent::TYPE_STDERR);
                $this->dispatcher->dispatch($event, 'jms_job_queue.new_job_output');
                $newErrorOutput = $event->getNewOutput();
            }

            if ($this->verbose) {
                if ( ! empty($newOutput)) {
                    $this->output->writeln('Job '.$data['job']->getId().': '.str_replace("\n", "\nJob ".$data['job']->getId().": ", $newOutput));
                }

                if ( ! empty($newErrorOutput)) {
                    $this->output->writeln('Job '.$data['job']->getId().': '.str_replace("\n", "\nJob ".$data['job']->getId().": ", $newErrorOutput));
                }
            }

            // Check whether this process exceeds the maximum runtime, and terminate if that is
            // the case.
            $runtime = time() - $data['job']->getStartedAt()->getTimestamp();
            if ($data['job']->getMaxRuntime() > 0 && $runtime > $data['job']->getMaxRuntime()) {
                $data['process']->stop(5);

                $this->output->writeln($data['job'].' terminated; maximum runtime exceeded.');
                $this->jobManager->closeJob($data['job'], Job::STATE_TERMINATED);
                unset($this->runningJobs[$i]);

                continue;
            }

            if ($data['process']->isRunning()) {
                // For long running processes, it is nice to update the output status regularly.
                $data['job']->addOutput($newOutput);
                $data['job']->addErrorOutput($newErrorOutput);
                $data['job']->checked();
                $em = $this->getEntityManager();
                $em->persist($data['job']);
                $em->flush($data['job']);

                continue;
            }

            $this->output->writeln($data['job'].' finished with exit code '.$data['process']->getExitCode().'.');

            // If the Job exited with an exception, let's reload it so that we
            // get access to the stack trace. This might be useful for listeners.
            $this->getEntityManager()->refresh($data['job']);

            $data['job']->setExitCode($data['process']->getExitCode());
            $data['job']->setOutput($data['process']->getOutput());
            $data['job']->setErrorOutput($data['process']->getErrorOutput());
            $data['job']->setRuntime(time() - $data['start_time']);

            $newState = 0 === $data['process']->getExitCode() ? Job::STATE_FINISHED : Job::STATE_FAILED;
            $this->jobManager->closeJob($data['job'], $newState);
            unset($this->runningJobs[$i]);
        }

        gc_collect_cycles();
    }

    private function startJob(Job $job)
    {
        $event = new StateChangeEvent($job, Job::STATE_RUNNING);
        $this->dispatcher->dispatch($event, 'jms_job_queue.job_state_change');
        $newState = $event->getNewState();

        if (Job::STATE_CANCELED === $newState) {
            $this->jobManager->closeJob($job, Job::STATE_CANCELED);

            return;
        }

        if (Job::STATE_RUNNING !== $newState) {
            throw new \LogicException(sprintf('Unsupported new state "%s".', $newState));
        }

        $job->setState(Job::STATE_RUNNING);
        $em = $this->getEntityManager();
        $em->persist($job);
        $em->flush($job);

        $args = $this->getBasicCommandLineArgs();
        $args[] = $job->getCommand();
        $args[] = '--jms-job-id='.$job->getId();

        foreach ($job->getArgs() as $arg) {
            $args[] = $arg;
        }

        $proc = new Process($args);
        $proc->start();
        $this->output->writeln(sprintf('Started %s.', $job));

        $this->runningJobs[] = array(
            'process' => $proc,
            'job' => $job,
            'start_time' => time(),
            'output_pointer' => 0,
            'error_output_pointer' => 0,
        );
    }

    /**
     * Cleans up stale jobs.
     *
     * A stale job is a job where this command has exited with an error
     * condition. Although this command is very robust, there might be cases
     * where it might be terminated abruptly (like a PHP segfault, a SIGTERM signal, etc.).
     *
     * In such an error condition, these jobs are cleaned-up on restart of this command.
     */
    private function cleanUpStaleJobs($workerName)
    {
        /** @var Job[] $staleJobs */
        $staleJobs = $this->getEntityManager()->createQuery("SELECT j FROM ".Job::class." j WHERE j.state = :running AND (j.workerName = :worker OR j.workerName IS NULL)")
            ->setParameter('worker', $workerName)
            ->setParameter('running', Job::STATE_RUNNING)
            ->getResult();

        foreach ($staleJobs as $job) {
            // If the original job has retry jobs, then one of them is still in
            // running state. We can skip the original job here as it will be
            // processed automatically once the retry job is processed.
            if ( ! $job->isRetryJob() && count($job->getRetryJobs()) > 0) {
                continue;
            }

            $args = $this->getBasicCommandLineArgs();
            $args[] = 'jms-job-queue:mark-incomplete';
            $args[] = $job->getId();

            // We use a separate process to clean up.
            $proc = new Process($args);
            if (0 !== $proc->run()) {
                $ex = new ProcessFailedException($proc);

                $this->output->writeln(sprintf('There was an error when marking %s as incomplete: %s', $job, $ex->getMessage()));
            }
        }
    }

    private function getBasicCommandLineArgs(): array
    {
        $args = array(
            PHP_BINARY,
            $_SERVER['SYMFONY_CONSOLE_FILE'] ?? $_SERVER['argv'][0],
            '--env='.$this->env
        );

        if ($this->verbose) {
            $args[] = '--verbose';
        }

        return $args;
    }

    private function getEntityManager(): EntityManager
    {
        return /** @var EntityManager */ $this->registry->getManagerForClass('JMSJobQueueBundle:Job');
    }
}
