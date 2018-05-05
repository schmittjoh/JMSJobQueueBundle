<?php

namespace JMS\JobQueueBundle\Cron;

use JMS\JobQueueBundle\Entity\Job;

interface JobScheduler
{
    /**
     * Returns an array of commands managed by this scheduler.
     *
     * @return string[]
     */
    public function getCommands(): array;

    /**
     * Returns whether to schedule the given command again.
     *
     * @return boolean
     */
    public function shouldSchedule(string $command, \DateTime $lastRunAt): bool;

    /**
     * Creates the given command when it is scheduled.
     *
     * @param string $command
     * @param \DateTime $lastRunAt
     * @return Job
     */
    public function createJob(string $command, \DateTime $lastRunAt): Job;
}