<?php

namespace JMS\JobQueueBundle\Twig;

class JobQueueExtension extends \Twig_Extension
{
    private $linkGenerators = array();

    public function __construct(array $generators = array())
    {
        $this->linkGenerators = $generators;
    }

    public function getTests()
    {
        return array(
            'jms_job_queue_linkable' => new \Twig_Test_Method($this, 'isLinkable'),
        );
    }

    public function getFunctions()
    {
        return array(
            'jms_job_queue_path' => new \Twig_Function_Method($this, 'generatePath', array('is_safe' => array('html' => true))),
        );
    }

    public function getFilters()
    {
        return array(
            'jms_job_queue_linkname' => new \Twig_Filter_Method($this, 'getLinkname'),
        );
    }

    public function isLinkable($entity)
    {
        foreach ($this->linkGenerators as $generator) {
            if ($generator->supports($entity)) {
                return true;
            }
        }

        return false;
    }

    public function generatePath($entity)
    {
        foreach ($this->linkGenerators as $generator) {
            if ($generator->supports($entity)) {
                return $generator->generate($entity);
            }
        }

        throw new \RuntimeException(sprintf('The entity "%s" has no link generator.', get_class($entity)));
    }

    public function getLinkname($entity)
    {
        foreach ($this->linkGenerators as $generator) {
            if ($generator->supports($entity)) {
                return $generator->getLinkname($entity);
            }
        }

        throw new \RuntimeException(sprintf('The entity "%s" has no link generator.', get_class($entity)));
    }

    public function getName()
    {
        return 'jms_job_queue';
    }
}