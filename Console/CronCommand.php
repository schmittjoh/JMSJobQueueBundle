<?php

namespace JMS\JobQueueBundle\Console;

use JMS\JobQueueBundle\Entity\Job;

interface CronCommand
{
    /**
     * @return Job
     */
    public function createCronJob(\DateTimeInterface $lastRunAt);

    /**
     * @return boolean
     */
    public function shouldBeScheduled(\DateTimeInterface $lastRunAt);
}