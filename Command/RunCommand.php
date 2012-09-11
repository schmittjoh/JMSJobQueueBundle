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

    protected function configure()
    {
        $this
            ->setName('jms-job-queue:run')
            ->setDescription('Runs jobs from the queue.')
            ->addOption('max-runtime', 'r', InputOption::VALUE_REQUIRED, 'The maximum runtime in seconds.', 900)
            ->addOption('max-concurrent-jobs', 'j', InputOption::VALUE_REQUIRED, 'The maximum number of concurrent jobs.', 5)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $startTime = time();

        $maxRuntime = (integer) $input->getOption('max-runtime');
        if ($maxRuntime <= 0) {
            throw new InvalidArgumentException('The maximum runtime must be greater than zero.');
        }

        $maxConcurrentJobs = (integer) $input->getOption('max-concurrent-jobs');
        if ($maxConcurrentJobs <= 0) {
            throw new InvalidArgumentException('The maximum number of concurrent jobs must be greater than zero.');
        }

        $this->env = $input->getOption('env');
        $this->verbose = $input->getOption('verbose');
        $this->output = $output;
        $this->registry = $this->getContainer()->get('doctrine');
        $this->dispatcher = $this->getContainer()->get('event_dispatcher');

        while (time() - $startTime < $maxRuntime) {
            $this->checkRunningJobs();

            $excludedIds = array();
            while (count($this->runningJobs) < $maxConcurrentJobs) {
                if (null === $pendingJob = $this->getRepository()->findStartableJob($excludedIds)) {
                    sleep(2);
                    continue 2; // Check if the maximum runtime has been exceeded.
                }

                $this->startJob($pendingJob);
                sleep(1);
                $this->checkRunningJobs();
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

            // For long running processes, it is nice to update the output status regularly.
            $data['job']->addOutput($newOutput);
            $data['job']->addErrorOutput($newErrorOutput);
            $data['job']->checked();
            $em = $this->getEntityManager();
            $em->persist($data['job']);
            $em->flush($data['job']);

            // Check whether this process exceeds the maximum runtime, and terminate if that is
            // the case.
            $runtime = time() - $data['job']->getStartedAt()->getTimestamp();
            if ($data['job']->getMaxRuntime() > 0 && $runtime > $data['job']->getMaxRuntime()) {
                $data['process']->stop(5);

                $this->getRepository()->closeJob($data['job'], Job::STATE_TERMINATED);
                $this->output->writeln($job.' terminated; maximum runtime exceeded.');

                unset($this->runningJobs[$i]);

                continue;
            }

            if ($data['process']->isRunning()) {
                continue;
            }

            $this->output->writeln($data['job'].' finished with exit code '.$data['process']->getExitCode().'.');
            $data['job']->setExitCode($data['process']->getExitCode());
            $data['job']->setOutput($data['process']->getOutput());
            $data['job']->setErrorOutput($data['process']->getErrorOutput());

            $newState = 0 === $data['process']->getExitCode() ? Job::STATE_FINISHED : Job::STATE_FAILED;
            $newState = $this->stateChange($data['job'], $newState);

            $em = $this->getEntityManager();

            // For retry jobs, we set the state directly as we do not need to take care of
            // dependencies for it.
            if ($data['job']->isRetryJob()) {
                $newState = $this->stateChange($data['job'], $newState);
                $data['job']->setState($newState);
            }

            // If the job is set to failed, check whether we can schedule a retry job.
            if (Job::STATE_FAILED === $newState) {
                $originalJob = $data['job']->getOriginalJob();
                if (count($originalJob->getRetryJobs()) < $originalJob->getMaxRetries()) {
                    $retryJob = new Job($originalJob->getCommand(), $originalJob->getArgs());
                    $retryJob->setMaxRuntime($originalJob->getMaxRuntime());
                    $originalJob->addRetryJob($retryJob);

                    $em->persist($retryJob);
                    $em->persist($originalJob);
                    $em->persist($data['job']);
                    $em->flush();

                    unset($this->runningJobs[$i]);

                    continue;
                }

                $em->persist($data['job']);
                $em->flush($data['job']);
                $this->getRepository()->closeJob($originalJob, Job::STATE_FAILED);
                unset($this->runningJobs[$i]);

                continue;
            }

            if (Job::STATE_FINISHED === $newState) {
                $this->getRepository()->closeJob($data['job']->getOriginalJob(), Job::STATE_FINISHED);

                unset($this->runningJobs[$i]);

                continue;
            }

            throw new LogicException(sprintf('The final state must either be finished, or failed, but got "%s".', $newState));
        }
    }

    private function stateChange(Job $job, $newState)
    {
        $event = new StateChangeEvent($job, $newState);
        $this->dispatcher->dispatch('jms_job_queue.job_state_change', $event);

        return $event->getNewState();
    }

    private function startJob(Job $job)
    {
        $event = new StateChangeEvent($job, Job::STATE_RUNNING);
        $this->dispatcher->dispatch('jms_job_queue.job_state_change', $event);
        $job->setState($event->getNewState());

        $em = $this->getEntityManager();
        $em->persist($job);
        $em->flush($job);

        // Job was canceled by an event listener.
        if ($event->getNewState() === Job::STATE_CANCELED) {
            return;
        } else if ($event->getNewState() !== Job::STATE_RUNNING) {
            throw new \LogicException(sprintf('Unsupported state "%s".', $event->getNewState()));
        }

        $pb = new ProcessBuilder();

        // PHP wraps the process in "sh -c" by default, but we need to control
        // the process directly.
        if ( ! defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $pb->add('exec');
        }

        $pb
            ->add('php')
            ->add($this->getContainer()->getParameter('kernel.root_dir').'/console')
            ->add($job->getCommand())
            ->add('--env='.$this->env)
            ->add('--jms-job-id='.$job->getId())
        ;

        if ($this->verbose) {
            $pb->add('--verbose');
        }

        foreach ($job->getArgs() as $arg) {
            $pb->add($arg);
        }
        $proc = $pb->getProcess();
        $proc->start();
        $this->output->writeln(sprintf('Started %s.', $job));

        $this->runningJobs[] = array(
            'process' => $proc,
            'job' => $job,
            'output_pointer' => 0,
            'error_output_pointer' => 0,
        );
    }

    private function getEntityManager()
    {
        return $this->registry->getManagerForClass('JMSJobQueueBundle:Job');
    }

    private function getRepository()
    {
        return $this->getEntityManager()->getRepository('JMSJobQueueBundle:Job');
    }
}