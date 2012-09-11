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
                $em = $this->getContainer()->get('doctrine')->getManagerForClass('JMSJobQueueBundle:Job');
                $job = $em->find('JMSJobQueueBundle:Job', $jobId);
                $job->setStackTrace(FlattenException::create($ex));
                $em->persist($job);
                $em->flush($job);
            }

            throw $ex;
        }
    }
}