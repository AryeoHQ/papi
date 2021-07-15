<?php

namespace App\Command\Help;

use App\Command\PapiController;
use Minicli\App;

class DefaultController extends PapiController
{
    public function boot(App $app)
    {
        parent::boot($app);
        $this->description = 'describe how to use papi';
    }

    public function handle()
    {
        $this->printDescription();
        $this->printUsage();
        $this->printAllCommands();
        $this->printSubcommandHelp();
    }
}
