<?php

namespace App\Command\Help;

use App\Command\PapiController;
use Minicli\App;
use Minicli\Command\CommandCall;

class DefaultController extends PapiController
{
    public function boot(App $app, CommandCall $input): void
    {
        parent::boot($app, $input);
        $this->description = 'describe how to use papi';
    }

    public function handle(): void
    {
        $this->printDescription();
        $this->printUsage();
        $this->printAllCommands();
        $this->printSubCommandHelp();
    }
}
