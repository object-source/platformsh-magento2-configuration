<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Platformsh;

/**
 * Contains logic for interacting with the server environment
 */
class Environment
{
    const MAGENTO_ROOT = __DIR__ . '/../../../../../';

    public $writableDirs = ['app/etc', 'pub/media', 'generated'];

    /**
     * Get routes information from Platformsh environment variable.
     *
     * @return mixed
     */
    public function getRoutes()
    {
        return json_decode(base64_decode($_ENV["PLATFORM_ROUTES"]), true);
    }

    /**
     * Get relationships information from Platformsh environment variable.
     *
     * @return mixed
     */
    public function getRelationships()
    {
        return json_decode(base64_decode($_ENV["PLATFORM_RELATIONSHIPS"]), true);
    }

    /**
     * Get custom variables from Platformsh environment variable.
     *
     * @return mixed
     */
    public function getVariables()
    {
        return json_decode(base64_decode($_ENV["PLATFORM_VARIABLES"]), true);
    }


    public function log($message)
    {
        echo sprintf('[%s] %s', date("Y-m-d H:i:s"), $message) . PHP_EOL;
    }

    public function execute($command)
    {
        $this->log('Command:'.$command);

        exec(
            $command,
            $output,
            $status
        );

        $this->log('Status:'.var_export($status, true));
        $this->log('Output:'.var_export($output, true));

        if ($status != 0) {
            throw new \RuntimeException("Command $command returned code $status", $status);
        }

        return $output;
    }

    public function backgroundExecute($command)
    {
        $command = "nohup {$command} 1>/dev/null 2>&1 &";
        $this->log("Execute command in background: $command");
        shell_exec($command);
    }
}
