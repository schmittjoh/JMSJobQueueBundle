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

        $jobs = $em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j WHERE j.closedAt < :maxRetentionTime AND j.originalJob IS NULL")
            ->setParameter('maxRetentionTime', new \DateTime('-'.$input->getOption('max-retention')))
            ->setMaxResults(1000)
            ->getResult();

        foreach ($jobs as $job) {
            /** @var Job $job */

            $incomingDepsCount = (integer) $em->createQuery("SELECT COUNT(j) FROM JMSJobQueueBundle:Job j WHERE :job MEMBER OF j.dependencies")
                ->setParameter('job', $job)
                ->getSingleScalarResult();

            if ($incomingDepsCount > 0) {
                continue;
            }

            $em->remove($job);
        }

        $em->flush();
    }
}