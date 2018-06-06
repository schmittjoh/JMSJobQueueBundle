Installation
------------

Installing JMSJobQueueBundle
============================

You can easily install JMSJobQueueBundle with composer. Just add the following
to your `composer.json`file:

.. code-block :: js

    // composer.json
    {
        // ...
        require: {
            // ...
            "jms/job-queue-bundle": "dev-master"
        }
    }

.. note ::

    Please replace `dev-master` in the snippet above with the latest stable
    branch, for example ``1.0.*``.

Then, you can install the new dependencies by running composer's ``update``
command from the directory where your ``composer.json`` file is located:

.. code-block :: bash

    composer update jms/job-queue-bundle

Now, Composer will automatically download all required files, and install them
for you. Next you need to update your ``AppKernel.php`` file, and register the
new bundle:

.. code-block :: php

    <?php

    // in AppKernel::registerBundles()
    $bundles = array(
        // ...
        new JMS\JobQueueBundle\JMSJobQueueBundle(),
        // ...
    );


Enabling the Webinterface
=========================
If you also want to use the webinterface where you can view the outputs, and
exception stack traces for your jobs, you need to add the following to your

``routing.yml``:

.. code-block :: yaml

    JMSJobQueueBundle:
        resource: "@JMSJobQueueBundle/Controller/"
        type: annotation
        prefix: /jobs

Then, update your dependencies using

.. code-block :: bash

    php composer.phar update

And add the JMSDiExtraBundle and JMSAopBundle to your appKernel.php:

.. code-block :: php
    
    <?php

    // in AppKernel::registerBundles()
    $bundles = array(
        // ...
        new JMS\DiExtraBundle\JMSDiExtraBundle($this),
        new JMS\AopBundle\JMSAopBundle(),
        // ...
    );

Typically, you would also want to add some access control restrictions for these
actions. If you are using ``JMSSecurityExtraBundle`` this could look like this:

.. code-block :: yaml

    jms_security_extra:
        method_access_control:
            "JMSJobQueueBundle:.*:.*": "hasRole('ROLE_ADMIN')"

This will require the user to have the role ``ROLE_ADMIN`` if he wants to access
any action from this bundle.

Setting Up supervisord
======================
For this bundle to work, make sure that you run at least one instance of the console command ``jms-job-queue:run``
(you can run as many as needed to process your events or guarantee high availability).

Below, is a sample configuration that you can use with supervisord:

.. code-block :: ini

    [program:jms_job_queue_runner]
    command=php %kernel.root_dir%/console jms-job-queue:run --env=prod --verbose
    process_name=%(program_name)s
    numprocs=1
    directory=/tmp
    autostart=true
    autorestart=true
    startsecs=5
    startretries=10
    user=www-data
    redirect_stderr=false
    stdout_logfile=%capistrano.shared_dir%/jms_job_queue_runner.out.log
    stdout_capture_maxbytes=1MB
    stderr_logfile=%capistrano.shared_dir%/jms_job_queue_runner.error.log
    stderr_capture_maxbytes=1MB

.. tip ::

    For testing, or development, you can of course also run the command manually,
    but it will auto-exit after 15 minutes by default (you can change this with
    the ``--max-runtime=seconds`` option).

.. _supervisord: http://supervisord.org/
