<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Platformsh\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Platformsh\Environment;

/**
 * CLI command for build hook. Responsible for preparing the codebase before it's moved to the server.
 */
class Build extends Command
{

    /**
     * @var Environment
     */
    private $env;

    /**
     * {@inheritdoc}
     * @throws \InvalidArgumentException
     */
    protected function configure()
    {
        $this->setName('platformsh:build')
            ->setDescription('Invokes set of steps to build source code for the Magento on Platform.sh');
        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->env = new Environment();
        $this->build();
    }

    private function build()
    {
        $this->env->log("Start build.");
        $this->applyMccPatches();
        $this->applyCommittedPatches();
        $this->compileDI();
        $this->clearInitDir();
        $this->env->execute('rm -rf app/etc/env.php');

        /**
         * Writable directories will be erased when the writable filesystem is mounted to them. This
         * step backs them up to ./init/
         */
        $this->env->log("Copying writable directories to temp directory.");
        foreach ($this->env->writableDirs as $dir) {
            $this->env->execute(sprintf('mkdir -p init/%s', $dir));
            $this->env->execute(sprintf('mkdir -p %s', $dir));
            $this->env->execute(sprintf('/bin/bash -c "shopt -s dotglob; cp -R %s/* ./init/%s/"', $dir, $dir));
            $this->env->execute(sprintf('rm -rf %s', $dir));
            $this->env->execute(sprintf('mkdir %s', $dir));
        }
    }

    /**
     * Apply patches
     */
    private function applyMccPatches()
    {
        $this->env->log("Applying patches.");
        $this->env->execute('/usr/bin/php ' . Environment::MAGENTO_ROOT . 'vendor/platformsh/magento2-configuration/patch.php');
    }

    /**
     * Apply patches
     */
    private function applyCommittedPatches()
    {
        $patchesDir = Environment::MAGENTO_ROOT . 'm2-hotfixes/';
        $this->env->log("Checking if patches exist under " . $patchesDir);
        if (is_dir($patchesDir)) {
            $files = glob($patchesDir . "*");
            sort($files);
            foreach ($files as $file) {
                $cmd = 'git apply '  . $file;
                $this->env->execute($cmd);
            }
        }
    }

    private function compileDI()
    {
        $this->env->log("Enabling all modules");
        $this->env->execute("cd bin/; /usr/bin/php ./magento module:enable --all");

        $this->env->log("Running DI compilation");
        $this->env->execute("cd bin/; /usr/bin/php ./magento setup:di:compile");
    }

    /**
     * Clear content of temp directory
     */
    private function clearInitDir()
    {
        $this->env->log("Clearing temporary directory.");
        $this->env->execute('rm -rf ../init/*');
    }

}
