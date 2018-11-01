<?php

declare(strict_types = 1);

namespace JMS\JobQueueBundle\Console;

use JMS\JobQueueBundle\Entity\Job;
use Symfony\Component\Console\Command\Command;

trait ScheduleInSecondInterval
{
    public function shouldBeScheduled(\DateTime $lastRunAt): bool
    {
        return time() - $lastRunAt->getTimestamp() >= $this->getScheduleInterval();
    }

    public function createCronJob(\DateTime $_): Job
    {
        if ( ! $this instanceof Command) {
            throw new \LogicException('This trait must be used in Symfony console commands only.');
        }

        $job = new Job($this->getName());
        $job->setMaxRuntime((integer)min(300, $this->getScheduleInterval()));

        return $job;
    }

    /**
     * @return integer
     */
    abstract protected function getScheduleInterval(): int;
}