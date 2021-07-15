<?php

namespace App\Command\Make;

use App\Command\PapiController;
use Minicli\App;

class DefaultController extends PapiController
{
    public function boot(App $app)
    {
        parent::boot($app);
        $this->description = 'make spec-related assets';
    }

    public function handle()
    {
        $this->printIndexHelp();
    }
}
