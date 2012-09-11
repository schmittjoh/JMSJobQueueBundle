<?php

namespace JMS\JobQueueBundle\Controller;

use JMS\DiExtraBundle\Annotation as DI;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

class JobController
{
    /** @DI\Inject("doctrine") */
    private $registry;

    /** @DI\Inject */
    private $request;

    /**
     * @Route("/", name = "jms_jobs_overview")
     * @Template
     */
    public function overviewAction()
    {
        $em = $this->getEm();
        $repo = $this->getRepo();

        $query = $em->createQuery("SELECT j FROM JMSJobQueueBundle:Job j ORDER BY j.id DESC");
        $pager = new \Pagerfanta\Pagerfanta(new \Pagerfanta\Adapter\DoctrineORMAdapter($query));
        $pager->setCurrentPage(max(1, (integer) $this->request->query->get('page')));
        $pager->setMaxPerPage(max(5, min(50, (integer) $this->request->query->get('per_page'))));

        return array(
            'jobsWithError' => $repo->findLastJobsWithError(5),
            'jobPager' => $pager,
        );
    }

    /** @return \Doctrine\ORM\EntityManager */
    private function getEm()
    {
        return $this->registry->getManagerForClass('JMSJobQueueBundle:Job');
    }

    /** @return \JMS\JobQueueBundle\Entity\Repository\JobRepository */
    private function getRepo()
    {
        return $this->getEm()->getRepository('JMSJobQueueBundle:Job');
    }
}