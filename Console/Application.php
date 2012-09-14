<?php

namespace JMS\JobQueueBundle\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\Exception\FlattenException;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Records debugging information for executed commands.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
class Application extends BaseApplication
{
    public function __construct(KernelInterface $kernel)
    {
        parent::__construct($kernel);

        $this->getDefinition()->addOption(new InputOption('--jms-job-id', null, InputOption::VALUE_REQUIRED, 'The ID of the Job.'));
    }

    public function doRun(InputInterface $input, OutputInterface $output)
    {
        try {
            $rs = parent::doRun($input, $output);
            $this->saveDebugInformation($input);

            return $rs;
        } catch (\Exception $ex) {
            $this->saveDebugInformation($input, $ex);

            throw $ex;
        }
    }

    private function saveDebugInformation($input, \Exception $ex = null)
    {
        if ( ! $input->hasOption('jms-job-id') || null === $jobId = $input->getOption('jms-job-id')) {
            return;
        }

        $this->getConnection()->executeUpdate("UPDATE jms_jobs SET stackTrace = :trace, memoryUsage = :memoryUsage, memoryUsageReal = :memoryUsageReal WHERE id = :id", array(
            'id' => $jobId,
            'memoryUsage' => memory_get_peak_usage(),
            'memoryUsageReal' => memory_get_peak_usage(true),
            'trace' => serialize($ex ? FlattenException::create($ex) : null),
        ));
    }

    private function getConnection()
    {
        return $this->getKernel()->getContainer()->get('doctrine')->getManagerForClass('JMSJobQueueBundle:Job')->getConnection();
    }
}