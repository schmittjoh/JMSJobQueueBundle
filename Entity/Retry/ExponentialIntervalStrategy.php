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
    $base = 2;
    $unit = 'second';

    if (isset($this->config)) {
      if (isset($this->config['base'])) {
        $base = $this->config['base'];
      }
      if (isset($this->config['unit'])) {
        $unit = $this->config['unit'];
      }
    }

    $increase = (pow($base, count($original->getRetryJobs())));

    if ($increase > 1) {
      $unit = $unit . 's';
    }

    $retry->setExecuteAfter(new \DateTime('+' . $increase . ' ' . $unit));
  }
}