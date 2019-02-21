<?php

namespace JMS\JobQueueBundle\Tests\Functional;

// Set-up composer auto-loading if Client is insulated.
call_user_func(function() {
    if ( ! is_file($autoloadFile = __DIR__.'/../../vendor/autoload.php')) {
        throw new LogicException('The autoload file "vendor/autoload.php" was not found. Did you run "composer install --dev"?');
    }

    require_once $autoloadFile;
});

\Doctrine\Common\Annotations\AnnotationRegistry::registerLoader('class_exists');

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle;
use JMS\JobQueueBundle\JMSJobQueueBundle;
use JMS\JobQueueBundle\Tests\Functional\TestBundle\TestBundle;
use LogicException;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;

class AppKernel extends Kernel
{
    private $config;

    public function __construct($config)
    {
        parent::__construct('test', false);

        $fs = new Filesystem();
        if (!$fs->isAbsolutePath($config)) {
            $config = __DIR__.'/config/'.$config;
        }

        if ( ! is_file($config)) {
            throw new RuntimeException(sprintf('The config file "%s" does not exist.', $config));
        }

        $this->config = $config;
    }

    public function registerBundles()
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new DoctrineFixturesBundle(),
            new TwigBundle(),

            new TestBundle(),
            new JMSJobQueueBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load($this->config);
    }

    public function getCacheDir()
    {
        return sys_get_temp_dir().'/'.Kernel::VERSION.'/JMSJobQueueBundle/'.substr(sha1($this->config), 0, 6).'/cache';
    }

    public function getContainerClass()
    {
        return parent::getContainerClass().'_'.substr(sha1($this->config), 0, 6);
    }

    public function getLogDir()
    {
        return sys_get_temp_dir().'/'.Kernel::VERSION.'/JMSJobQueueBundle/'.substr(sha1($this->config), 0, 6).'/logs';
    }

    public function serialize()
    {
        return $this->config;
    }

    public function unserialize($config)
    {
        $this->__construct($config);
    }
}