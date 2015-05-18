<?php

namespace JMS\JobQueueBundle\Entity\Retry;

use JMS\JobQueueBundle\Entity\Job;

interface RetryStrategyInterface
{
  public function apply(Job $original, Job $retry);
}