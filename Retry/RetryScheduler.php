<?php

namespace JMS\JobQueueBundle\Retry;

use JMS\JobQueueBundle\Entity\Job;

interface RetryScheduler
{
    /**
     * Schedules the next retry of a job.
     *
     * When this method is called, it has already been decided that a retry should be attempted. The implementation
     * should needs to decide when that should happen.
     *
     * @return \DateTime
     */
    public function scheduleNextRetry(Job $originalJob);
}