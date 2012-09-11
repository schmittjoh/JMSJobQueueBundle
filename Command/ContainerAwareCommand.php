<?php

namespace JMS\JobQueueBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\Exception\FlattenException;

/**
 * Extends Symfony2's default container aware command to add better logging of
 * exceptions that might occur during execution.
 *
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
abstract class ContainerAwareCommand extends \Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand
{
    public function __construct($name = null)
    {
        parent::__construct($name);

        $this->addOption('jms-job-id', null, InputOption::VALUE_REQUIRED, 'The Job ID of this command.', null);
    }

    public function run(InputInterface $input, OutputInterface $output)
    {
        try {
            return parent::run($input, $output);
        } catch (\Exception $ex) {
            if ($input->hasOption('jms-job-id') && null !== $jobId = $input->getOption('jms-job-id')) {
                // We do use the connection directly to avoid any issues if the entity manager
                // has already been closed, for example if a transaction was rolled back.
                $con = $this->getContainer()->get('doctrine')->getManagerForClass('JMSJobQueueBundle:Job')->getConnection();
                $con->exec("UPDATE jms_jobs SET stackTrace = ".$con->quote(serialize(FlattenException::create($ex)))." WHERE id = ".$jobId);
            }

            throw $ex;
        }
    }
}