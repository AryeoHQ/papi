<?php

namespace App\Command\Clean;

use App\Command\PapiController;
use Minicli\App;

class DefaultController extends PapiController
{
    public function boot(App $app)
    {
        parent::boot($app);
        $this->description = 'clean spec-related assets';
    }

    public function handle()
    {
        $this->printIndexHelp();
    }
}
