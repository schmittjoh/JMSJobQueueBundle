<?php

namespace JMS\JobQueueBundle\Entity\Retry;

use JMS\JobQueueBundle\Entity\Job;

/**
 * Class ExponentialIntervalStrategy
 * @package JMS\JobQueueBundle\Entity\Retry
 */
class ExponentialIntervalStrategy implements RetryStrategyInterface {
  private $config;

  /**
   * Constructor.
   *
   * @param array $config
   *   Array of configuration options.
   */
  public function __construct($config = NULL) {
    $this->config = $config;
  }

  /**
   * Apply the strategy.
   *
   * @param Job $original
   * @param Job $retry
   */
  public function apply(Job $original, Job $retry) {
    $seconds = 5;

    if (isset($this->config)) {
      $seconds = $this->config['seconds'];
    }

    $retry->setExecuteAfter(new \DateTime('+' . (pow($seconds, count($original->getRetryJobs()))) . ' seconds'));
  }
}