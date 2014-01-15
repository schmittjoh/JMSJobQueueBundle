<?php

namespace JMS\JobQueueBundle\Controller;

use Doctrine\Common\Util\ClassUtils;
use JMS\DiExtraBundle\Annotation as DI;
use JMS\JobQueueBundle\Entity\Job;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Pagerfanta\View\TwitterBootstrapView;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class JobController
{
    /** @DI\Inject("doctrine") */
    private $registry;

    /** @DI\Inject */
    private $request;

    /** @DI\Inject */
    private $router;

    /** @DI\Inject("%jms_job_queue.statistics%") */
    private $statisticsEnabled;

    /**
     * @Route("/", name = "jms_jobs_overview")
     * @Template("JMSJobQueueBundle:Job:overview.html.twig")
     */
    public function overviewAction()
    {
        $lastJobsWithError = $this->getRepo()->findLastJobsWithError(5);

        $qb = $this->getEm()->createQueryBuilder();
        $qb->select('j')->from('JMSJobQueueBundle:Job', 'j')
                ->where($qb->expr()->isNull('j.originalJob'))
                ->orderBy('j.id', 'desc');

        foreach ($lastJobsWithError as $i => $job) {
            $qb->andWhere($qb->expr()->neq('j.id', '?'.$i));
            $qb->setParameter($i, $job->getId());
        }

        $pager = new Pagerfanta(new DoctrineORMAdapter($qb));
        $pager->setCurrentPage(max(1, (integer) $this->request->query->get('page', 1)));
        $pager->setMaxPerPage(max(5, min(50, (integer) $this->request->query->get('per_page', 20))));

        $pagerView = new TwitterBootstrapView();
        $router = $this->router;
        $routeGenerator = function($page) use ($router, $pager) {
            return $router->generate('jms_jobs_overview', array('page' => $page, 'per_page' => $pager->getMaxPerPage()));
        };

        return array(
            'jobsWithError' => $lastJobsWithError,
            'jobPager' => $pager,
            'jobPagerView' => $pagerView,
            'jobPagerGenerator' => $routeGenerator,
        );
    }

    /**
     * @Route("/{id}", name = "jms_jobs_details")
     * @Template("JMSJobQueueBundle:Job:details.html.twig")
     */
    public function detailsAction(Job $job)
    {
        $relatedEntities = array();
        foreach ($job->getRelatedEntities() as $entity) {
            $class = ClassUtils::getClass($entity);
            $relatedEntities[] = array(
                'class' => $class,
                'id' => json_encode($this->registry->getManagerForClass($class)->getClassMetadata($class)->getIdentifierValues($entity)),
                'raw' => $entity,
            );
        }

        $statisticData = $statisticOptions = array();
        if ($this->statisticsEnabled) {
            $dataPerCharacteristic = array();
            foreach ($this->registry->getManagerForClass('JMSJobQueueBundle:Job')->getConnection()->query("SELECT * FROM jms_job_statistics WHERE job_id = ".$job->getId()) as $row) {
                $dataPerCharacteristic[$row['characteristic']][] = array(
                    $row['createdAt'],
                    $row['charValue'],
                );
            }

            if ($dataPerCharacteristic) {
                $statisticData = array(array_merge(array('Time'), $chars = array_keys($dataPerCharacteristic)));
                $startTime = strtotime($dataPerCharacteristic[$chars[0]][0][0]);
                $endTime = strtotime($dataPerCharacteristic[$chars[0]][count($dataPerCharacteristic[$chars[0]])-1][0]);
                $scaleFactor = $endTime - $startTime > 300 ? 1/60 : 1;

                // This assumes that we have the same number of rows for each characteristic.
                for ($i=0,$c=count(reset($dataPerCharacteristic)); $i<$c; $i++) {
                    $row = array((strtotime($dataPerCharacteristic[$chars[0]][$i][0]) - $startTime) * $scaleFactor);
                    foreach ($chars as $name) {
                        $value = (float) $dataPerCharacteristic[$name][$i][1];

                        switch ($name) {
                            case 'memory':
                                $value /= 1024 * 1024;
                                break;
                        }

                        $row[] = $value;
                    }

                    $statisticData[] = $row;
                }
            }
        }

        return array(
            'job' => $job,
            'relatedEntities' => $relatedEntities,
            'incomingDependencies' => $this->getRepo()->getIncomingDependencies($job),
            'statisticData' => $statisticData,
            'statisticOptions' => $statisticOptions,
        );
    }

    /**
     * @Route("/{id}/retry", name = "jms_jobs_retry_job")
     */
    public function retryJobAction(Job $job)
    {
        $state = $job->getState();

        if (
            Job::STATE_FAILED !== $state &&
            Job::STATE_TERMINATED !== $state &&
            Job::STATE_INCOMPLETE !== $state
        ) {
            throw new HttpException(400, 'Given job can\'t be retried');
        }

        $retryJob = clone $job;

        $this->getEm()->persist($retryJob);
        $this->getEm()->flush();

        $url = $this->router->generate('jms_jobs_details', array('id' => $retryJob->getId()), false);

        return new RedirectResponse($url, 201);
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
