<?php

namespace JMS\JobQueueBundle\Entity\Retry;

class FixedIntervalStrategy implements RetryStrategyInterface
{
  protected $interval;
  public function __construct($interval)
  {
    $this->interval = $interval;
  }
  public function apply(Job $original, Job $retry)
  {
    $retry->getExecuteAfter()->modify($this->interval);
  }
}