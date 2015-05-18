<?php

namespace JMS\JobQueueBundle\Entity\Retry;

interface RetryStrategyInterface
{
  public function apply(Job $original, Job $retry);
}