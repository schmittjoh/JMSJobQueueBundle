<?php

namespace JMS\JobQueueBundle\Entity\Retry;

use JMS\JobQueueBundle\Entity\Job;

class FixedIntervalStrategy implements RetryStrategyInterface
{
  protected $config;
  public function __construct($config)
  {
    $this->config = $config;
  }
  public function apply(Job $original, Job $retry)
  {
    $retry->getExecuteAfter()->modify($this->config->interval);
  }
}