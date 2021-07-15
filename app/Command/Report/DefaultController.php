<?php

namespace App\Command\Report;

use App\Command\PapiController;
use Minicli\App;

class DefaultController extends PapiController
{
    public function boot(App $app)
    {
        parent::boot($app);
        $this->description = 'report information about an api';
    }

    public function handle()
    {
        $this->printIndexHelp();
    }
}
