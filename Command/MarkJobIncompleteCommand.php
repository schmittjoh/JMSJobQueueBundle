<?php

namespace JMS\JobQueueBundle\Command;

use JMS\JobQueueBundle\Entity\Job;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

class MarkJobIncompleteCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('jms-job-queue:mark-incomplete')
            ->setDescription('Internal command (do not use). It marks jobs as incomplete.')
            ->addArgument('job-id', InputArgument::REQUIRED, 'The ID of the Job.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $c = $this->getContainer();

        $em = $c->get('doctrine')->getManagerForClass('JMSJobQueueBundle:Job');
        $repo = $em->getRepository('JMSJobQueueBundle:Job');

        $repo->closeJob($em->find('JMSJobQueueBundle:Job', $input->getArgument('job-id')), Job::STATE_INCOMPLETE);
    }
}