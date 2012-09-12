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

    /** @DI\Inject */
    private $router;

    /**
     * @Route("/", name = "jms_jobs_overview")
     * @Template
     */
    public function overviewAction()
    {
        $query = $this->getEm()->createQuery("SELECT j FROM JMSJobQueueBundle:Job j ORDER BY j.id DESC");
        $pager = new \Pagerfanta\Pagerfanta(new \Pagerfanta\Adapter\DoctrineORMAdapter($query));
        $pager->setCurrentPage(max(1, (integer) $this->request->query->get('page', 1)));
        $pager->setMaxPerPage(max(5, min(50, (integer) $this->request->query->get('per_page', 20))));

        $pagerView = new \Pagerfanta\View\TwitterBootstrapView();
        $router = $this->router;
        $routeGenerator = function($page) use ($router, $pager) {
            return $router->generate('jms_jobs_overview', array('page' => $page, 'per_page' => $pager->getMaxPerPage()));
        };

        return array(
            'jobsWithError' => $this->getRepo()->findLastJobsWithError(5),
            'jobPager' => $pager,
            'jobPagerView' => $pagerView,
            'jobPagerGenerator' => $routeGenerator,
        );
    }

    /**
     * @Route("/{id}", name = "jms_jobs_details")
     * @Template
     */
    public function detailsAction(\JMS\JobQueueBundle\Entity\Job $job)
    {
        return array(
            'job' => $job,
            'incomingDependencies' => $this->getRepo()->getIncomingDependencies($job),
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