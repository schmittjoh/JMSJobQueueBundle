<?php

namespace JMS\JobQueueBundle\Entity\Retry;

use JMS\JobQueueBundle\Entity\Job;

class ExponentialIntervalStrategy implements RetryStrategyInterface
{
  public function __construct()
  {
  }
  public function apply(Job $original, Job $retry)
  {
    $retry->setExecuteAfter(new \DateTime('+'.(pow(5, count($original->getRetryJobs()))).' seconds'));
  }
}