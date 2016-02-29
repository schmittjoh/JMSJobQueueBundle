<?php

namespace JMS\JobQueueBundle\Console;

use JMS\JobQueueBundle\Entity\Job;

interface CronCommand
{
    /**
     * @return Job
     */
    public function createCronJob(\DateTime $lastRunAt);

    /**
     * @return boolean
     */
    public function shouldBeScheduled(\DateTime $lastRunAt);
}