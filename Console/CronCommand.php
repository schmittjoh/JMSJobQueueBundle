<?php

declare(strict_types = 1);

namespace JMS\JobQueueBundle\Console;

use DateTime;
use JMS\JobQueueBundle\Entity\Job;

interface CronCommand
{
    /**
     * Returns the job when this command is scheduled.
     *
     * @param DateTime $lastRunAt
     * @return Job
     */
    public function createCronJob(\DateTime $lastRunAt): Job;

    /**
     * Returns whether this command should be scheduled.
     *
     * @param DateTime $lastRunAt
     * @return boolean
     */
    public function shouldBeScheduled(\DateTime $lastRunAt): bool;
}