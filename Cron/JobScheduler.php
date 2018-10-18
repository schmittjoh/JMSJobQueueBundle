<?php

namespace JMS\JobQueueBundle\Cron;

use JMS\JobQueueBundle\Entity\Job;

interface JobScheduler
{
    /**
     * @return boolean
     */
    public function shouldSchedule($command, \DateTimeInterface $lastRunAt);

    /**
     * @return Job
     */
    public function createJob($command, \DateTimeInterface $lastRunAt);
}