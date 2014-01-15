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

use Symfony\Component\Process\Exception\ProcessFailedException;

use JMS\JobQueueBundle\Exception\LogicException;
use JMS\JobQueueBundle\Exception\InvalidArgumentException;
use JMS\JobQueueBundle\Event\NewOutputEvent;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;
use JMS\JobQueueBundle\Entity\Job;
use JMS\JobQueueBundle\Event\StateChangeEvent;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunCommand extends \Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand
{
    private $env;
    private $verbose;
    private $output;
    private $registry;
    private $dispatcher;
    private $runningJobs = array();
    private $runningJobQueueCount = array();

    protected function configure()
    {
        $this
            ->setName('jms-job-queue:run')
            ->setDescription('Runs jobs from the queue.')
            ->addOption('max-runtime', 'r', InputOption::VALUE_REQUIRED, 'The maximum runtime in seconds.', 900)
            ->addOption('max-concurrent-queues', 'cq', InputOption::VALUE_REQUIRED, 'The maximum number of concurrent queues.', 4)
            ->addOption('max-concurrent-jobs', 'j', InputOption::VALUE_REQUIRED, 'The maximum number of concurrent jobs in a queue.', 4)
            ->addOption('queue', 'qu', InputOption::VALUE_OPTIONAL, 'The queue to run this command for.',null)

        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $startTime = time();

        $maxRuntime = (integer) $input->getOption('max-runtime');
        if ($maxRuntime <= 0) {
            throw new InvalidArgumentException('The maximum runtime must be greater than zero.');
        }

        $maxConcurrentQueues = (integer) $input->getOption('max-concurrent-queues');
        $maxQueueJobs = (integer) $input->getOption('max-concurrent-jobs');

        if ($maxQueueJobs <= 0) {
            throw new InvalidArgumentException('The maximum number of jobs per queue must be greater than zero.');
        }

        $this->env = $input->getOption('env');
        $this->verbose = $input->getOption('verbose');
        $this->output = $output;
        $this->registry = $this->getContainer()->get('doctrine');
        $this->dispatcher = $this->getContainer()->get('event_dispatcher');
        $this->getEntityManager()->getConnection()->getConfiguration()->setSQLLogger(null);

        $this->cleanUpStaleJobs();

        $queue = $input->getOption('queue');

        while (time() - $startTime < $maxRuntime) {
            $this->checkRunningJobs();

            $jobQueueList = null;

            if($queue == null) {
                $jobQueueList  = $this->getRepository()->getAvailableQueueList();
            } else {
                $jobQueueList  = array($queue);
            }

            foreach($jobQueueList as $jobQueue) {
                $excludedIds = array();

                if(!isset($this->runningJobQueueCount[$jobQueue])) {
                    $this->runningJobQueueCount[$jobQueue] = 0;
                }

                while ($this->runningJobQueueCount[$jobQueue] < $maxQueueJobs && $this->canStartJobForQueue($maxConcurrentQueues, $jobQueue)) {
                    if (null === $pendingJob = $this->getRepository()->findStartableJob($excludedIds,$jobQueue)) {
                        sleep(2);
                        continue 2;
                    }

                    $this->startJob($pendingJob);
                    sleep(1);
                    $this->checkRunningJobs();
                }

            }


            sleep(2);
        }

        if (count($this->runningJobs) > 0) {
            while (count($this->runningJobs) > 0) {
                $this->checkRunningJobs();
                sleep(2);
            }
        }

        return 0;
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
                $this->dispatcher->dispatch('jms_job_queue.new_job_output', $event);
                $newOutput = $event->getNewOutput();
            }

            if ( ! empty($newErrorOutput)) {
                $event = new NewOutputEvent($data['job'], $newErrorOutput, NewOutputEvent::TYPE_STDERR);
                $this->dispatcher->dispatch('jms_job_queue.new_job_output', $event);
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
                $this->getRepository()->closeJob($data['job'], Job::STATE_TERMINATED);
                $jobQueue = $data['job']->getQueue();
                $this->runningJobQueueCount[$jobQueue]--;
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
            $this->getRepository()->closeJob($data['job'], $newState);
            $jobQueue = $data['job']->getQueue();
            $this->runningJobQueueCount[$jobQueue]--;
            unset($this->runningJobs[$i]);
        }

        gc_collect_cycles();
    }

    private function startJob(Job $job)
    {
        $event = new StateChangeEvent($job, Job::STATE_RUNNING);
        $this->dispatcher->dispatch('jms_job_queue.job_state_change', $event);
        $newState = $event->getNewState();

        if (Job::STATE_CANCELED === $newState) {
            $this->getRepository()->closeJob($job, Job::STATE_CANCELED);

            return;
        }

        if (Job::STATE_RUNNING !== $newState) {
            throw new \LogicException(sprintf('Unsupported new state "%s".', $newState));
        }

        $job->setState(Job::STATE_RUNNING);
        $em = $this->getEntityManager();
        $em->persist($job);
        $em->flush($job);

        $pb = $this->getCommandProcessBuilder();
        $pb
            ->add($job->getCommand())
            ->add('--jms-job-id='.$job->getId())
        ;

        foreach ($job->getArgs() as $arg) {
            $pb->add($arg);
        }
        $proc = $pb->getProcess();
        $proc->start();
        $this->output->writeln(sprintf('Started %s.', $job));

        $this->runningJobs[] = array(
            'process' => $proc,
            'job' => $job,
            'start_time' => time(),
            'output_pointer' => 0,
            'error_output_pointer' => 0,
        );

        $jobQueue = $job->getQueue();
        if(isset($this->runningJobQueueCount[$jobQueue])) {
            $this->runningJobQueueCount[$jobQueue] = $this->runningJobQueueCount[$jobQueue] + 1;
        } else {
            $this->runningJobQueueCount[$jobQueue] = 1;
        }

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
    private function cleanUpStaleJobs()
    {
        $repo = $this->getRepository();
        foreach ($repo->findBy(array('state' => Job::STATE_RUNNING)) as $job) {
            // If the original job has retry jobs, then one of them is still in
            // running state. We can skip the original job here as it will be
            // processed automatically once the retry job is processed.
            if ( ! $job->isRetryJob() && count($job->getRetryJobs()) > 0) {
                continue;
            }

            $pb = $this->getCommandProcessBuilder();
            $pb
                ->add('jms-job-queue:mark-incomplete')
                ->add($job->getId())
                ->add('--env='.$this->env)
                ->add('--verbose')
            ;

            // We use a separate process to clean up.
            $proc = $pb->getProcess();
            if (0 !== $proc->run()) {
                $ex = new ProcessFailedException($proc);

                $this->output->writeln(sprintf('There was an error when marking %s as incomplete: %s', $job, $ex->getMessage()));
            }
        }
    }

    private function getCommandProcessBuilder()
    {
        $pb = new ProcessBuilder();

        // PHP wraps the process in "sh -c" by default, but we need to control
        // the process directly.
        if ( ! defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $pb->add('exec');
        }

        $pb
            ->add('php')
            ->add($this->getContainer()->getParameter('kernel.root_dir').'/console')
            ->add('--env='.$this->env)
        ;

        if ($this->verbose) {
            $pb->add('--verbose');
        }

        return $pb;
    }

    private function getEntityManager()
    {
        return $this->registry->getManagerForClass('JMSJobQueueBundle:Job');
    }

    private function getRepository()
    {
        return $this->getEntityManager()->getRepository('JMSJobQueueBundle:Job');
    }

    private function canStartJobForQueue($maxConcurrentQueues, $queue) {
        if($maxConcurrentQueues < 0) {
            return true;
        }

        $runningQueues = $this->getRunningQueueCount();

        $availableJobs = $this->getRepository()->getAvailableJobsForQueueCount($queue);

        if($runningQueues == $maxConcurrentQueues && $this->runningJobQueueCount[$queue] > 0 && $availableJobs > 0) {
            return true;
        } else if($runningQueues < $maxConcurrentQueues && $availableJobs > 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Retrieves how many queues are currently running.
     *
     * @return int
     */
    private function getRunningQueueCount() {

        $runningCount = 0;
        foreach($this->runningJobQueueCount as $key => $queueCount){
            if($queueCount > 0) {
                $runningCount += $queueCount;
            }
        }

        return $runningCount;
    }
}
