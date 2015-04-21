<?php

namespace JMS\JobQueueBundle\Command;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManager;
use JMS\JobQueueBundle\Entity\Job;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanUpCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('jms-job-queue:clean-up')
            ->setDescription('Cleans up jobs which exceed the maximum retention time.')
            ->addOption('max-retention', null, InputOption::VALUE_REQUIRED, 'The maximum retention time (value must be parsable by DateTime).', '30 days')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var ManagerRegistry $registry */
        $registry = $this->getContainer()->get('doctrine');

        /** @var EntityManager $em */
        $em = $registry->getManagerForClass('JMSJobQueueBundle:Job');
        $con = $em->getConnection();

        $jobs = $em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.closedAt < :maxRetentionTime AND j.originalJob IS NULL")
            ->setParameter('maxRetentionTime', new \DateTime('-'.$input->getOption('max-retention')))
            ->setMaxResults(1000)
            ->getResult();

        $incomingDepsSql = $con->getDatabasePlatform()->modifyLimitQuery("SELECT 1 FROM jms_job_dependencies WHERE dest_job_id = :id", 1);

        foreach ($jobs as $job) {
            /** @var Job $job */

            $result = $con->executeQuery($incomingDepsSql, array('id' => $job->getId()));
            if ($result->fetchColumn() !== false) {
                // There are still other jobs that depend on this, we will come back later.
                continue;
            }

            $em->remove($job);
        }

        $em->flush();
    }
}