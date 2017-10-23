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
     * Options for build_options.ini
     */
    const BUILD_OPT_SKIP_DI_COMPILATION = 'skip_di_compilation';
    const BUILD_OPT_SKIP_DI_CLEARING = 'skip_di_clearing';

    /**
     * @var Environment
     */
    private $env;

    /**
     * @var array
     */
    private $buildOptions;

    private $cleanStaticViewFiles;
    private $staticContentStashLocation;
    private $staticDeployThreads;
    private $staticDeployExcludeThemes = [];
    private $verbosityLevel;

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
        $this->buildOptions = $this->parseBuildOptions();
        $this->env = new Environment();
        $this->build();
    }

    private function build()
    {
        $this->env->log("Start build.");
        $this->setEnvData();
        $this->applyMccPatches();
        $this->applyCommittedPatches();
        $this->importConfiguration();
        $this->compileDI();
        $this->generateFreshStaticContent();
        $this->clearInitDir();
        $this->env->execute('rm -rf app/etc/env.php');
        $this->env->execute('rm -rf app/etc/config.php');

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

    private function setEnvData()
    {
        $var = $this->env->getVariables();
        $this->cleanStaticViewFiles = isset($var["CLEAN_STATIC_FILES"]) && $var["CLEAN_STATIC_FILES"] == 'disabled' ? false : true;
        $this->staticContentStashLocation = isset($var["STATIC_CONTENT_STASH_LOCATION"]) ? $var["STATIC_CONTENT_STASH_LOCATION"] : false;
        $this->staticDeployExcludeThemes = isset($var["STATIC_CONTENT_EXCLUDE_THEMES"])
            ? explode(',', $var["STATIC_CONTENT_EXCLUDE_THEMES"])
            : [];
        if (isset($var["STATIC_CONTENT_THREADS"])) {
            $this->staticDeployThreads = (int)$var["STATIC_CONTENT_THREADS"];
        } else if (isset($_ENV["STATIC_CONTENT_THREADS"])) {
            $this->staticDeployThreads = (int)$_ENV["STATIC_CONTENT_THREADS"];
        } else if (isset($_ENV["PLATFORM_MODE"]) && $_ENV["PLATFORM_MODE"] === 'enterprise') {
            $this->staticDeployThreads = 3;
        } else { // if Paas environment
            $this->staticDeployThreads = 1;
        }
        $this->verbosityLevel = isset($var['VERBOSE_COMMANDS']) && $var['VERBOSE_COMMANDS'] == 'enabled' ? ' -vv ' : '';
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
        $this->env->execute('rm -rf generated/*');

        $this->env->log("Enabling all modules");
        $this->env->execute("cd bin/; /usr/bin/php ./magento module:enable --all");

        if (!$this->getBuildOption(self::BUILD_OPT_SKIP_DI_COMPILATION)) {
            $this->env->log("Running DI compilation");
            $this->env->execute("cd bin/; /usr/bin/php ./magento setup:di:compile");
        } else {
            $this->env->log("Skip running DI compilation");
        }
    }

    private function importConfiguration()
    {
        $this->env->log('Import configuration');
        $this->env->execute('cd bin/; /usr/bin/php ./magento app:config:import');
    }

    /**
     * Clear content of temp directory
     */
    private function clearInitDir()
    {
        $this->env->log("Clearing temporary directory.");
        $this->env->execute('rm -rf ../init/*');
    }

    /**
     * Parse optional build_options.ini file in Magento root directory
     */
    private function parseBuildOptions()
    {
        $fileName = Environment::MAGENTO_ROOT . '/build_options.ini';
        return file_exists($fileName)
            ? parse_ini_file(Environment::MAGENTO_ROOT . '/build_options.ini')
            : [];
    }

    private function getBuildOption($key) {
        return isset($this->buildOptions[$key]) ? $this->buildOptions[$key] : false;
    }

    private function generateFreshStaticContent()
    {
        $excludeThemesOptions = $this->staticDeployExcludeThemes
            ? "--exclude-theme=" . implode(' --exclude-theme=', $this->staticDeployExcludeThemes)
            : '';
        $jobsOption = $this->staticDeployThreads
            ? "--jobs={$this->staticDeployThreads}"
            : '';

        $this->env->execute(
            "/usr/bin/php ./bin/magento setup:static-content:deploy -f $jobsOption $excludeThemesOptions {$this->verbosityLevel}"
        );
    }
}
