<?php

namespace JMS\JobQueueBundle\Cron;

use JMS\JobQueueBundle\Entity\Job;

interface JobScheduler
{
    /**
     * @return boolean
     */
    public function shouldSchedule($command, \DateTime $lastRunAt);

    /**
     * @return Job
     */
    public function createJob($command, \DateTime $lastRunAt);
}