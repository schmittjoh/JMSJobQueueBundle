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

use JMS\JobQueueBundle\Exception\InvalidArgumentException;
use JMS\JobQueueBundle\Event\NewOutputEvent;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;
use JMS\JobQueueBundle\Entity\Job;
use JMS\JobQueueBundle\Event\StateChangeEvent;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

class RunCommand extends ContainerAwareCommand
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
            ->addOption('max-runtime', 'r', InputOption::VALUE_OPTIONAL, 'The maximum runtime in seconds.', 900)
            ->addOption('max-concurrent-jobs', 'j', InputOption::VALUE_OPTIONAL, 'The maximum number of concurrent jobs.', 5)
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

        $processes = array();
        while (time() - $startTime < $maxRuntime) {
            $this->checkRunningJobs();

            while (count($this->runningJobs) < $maxConcurrentJobs) {
                if (null === $pendingJob = $this->registry->getManager('JMSJobQueueBundle:Job')->getRepository('JMSJobQueueBundle:Job')->findPendingJob()) {
                    $output->write('Nothing to run, waiting for 15 seconds... ');
                    sleep(15);
                    $output->writeln('Resuming.');

                    continue 2; // Check if the maximum runtime has been exceeded.
                }

                $this->startJob($pendingJob);
                sleep(1);
                $this->checkRunningJobs();
            }

            $output->write('Max concurrent jobs reached, waiting for 5 seconds... ');
            sleep(5);
            $output->writeln('Resuming.');
        }

        $output->writeln('Max runtime reached, waiting for running jobs to terminate.');
        while (count($this->runningJobs) > 0) {
            $this->checkRunningJobs();
            sleep(10);
        }
        $output->writeln('Termating.');

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
            $em = $this->registry->getManager('JMSJobQueueBundle:Job');
            $em->persist($data['job']);
            $em->flush($data['job']);

            // Check whether this process exceeds the maximum runtime, and terminate if that is
            // the case.
            $runtime = time() - $data['job']->getStartedAt()->getTimestamp();
            if ($data['job']->getMaxRuntime() > 0 && $runtime > $data['job']->getMaxRuntime()) {
                $data['process']->stop(5);

                $em = $this->registry->getManager('JMSJobQueueBundle:Job');
                $em->getRepository('JMSJobQueueBundle:Job')->closeJob($data['job'], Job::STATE_TERMINATED);
                $this->output->writeln($job.' terminated; maximum runtime exceeded.');

                unset($this->runningJobs[$i]);

                continue;
            }

            if ($data['process']->isRunning()) {
                continue;
            }

            $this->output->writeln($data['job'].' finished.');
            $data['job']->setExitCode($data['process']->getExitCode());
            $data['job']->setOutput($data['process']->getOutput());
            $data['job']->setErrorOutput($data['process']->getErrorOutput());

            $newState = 0 === $data['process']->getExitCode() ? Job::STATE_FINISHED : Job::STATE_FAILED;
            $em = $this->registry->getManager('JMSJobQueueBundle:Job');
            $em->getRepository('JMSJobQueueBundle:Job')->closeJob($data['job'], $newState);

            unset($this->runningJobs[$i]);
        }
    }

    private function startJob(Job $job)
    {
        $event = new StateChangeEvent($job, Job::STATE_RUNNING);
        $this->dispatcher->dispatch('jms_job_queue.job_state_change', $event);
        $job->setState($event->getNewState());

        $em = $this->registry->getManager('JMSJobQueueBundle:Job');
        $em->persist($job);
        $em->flush($job);

        $pb = new ProcessBuilder();
        $pb
            ->add('exec')
            ->add('php')
            ->add($this->getContainer()->getParameter('kernel.root_dir').'/console')
            ->add($job->getCommand())
            ->add('--env='.$this->env)
        ;

        if ($this->verbose) {
            $pb->add('--verbose');
        }

        foreach ($job->getArgs() as $arg) {
            $pb->add($arg);
        }
        $proc = $pb->getProcess();
        $proc->start();
        $this->output->writeln('Started Job "%d" (%s).', $job->getId(), $job->getCommand());

        $this->runningJobs[] = array(
            'process' => $proc,
            'job' => $job,
            'output_pointer' => 0,
            'error_output_pointer' => 0,
        );
    }
}