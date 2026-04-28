<?php

namespace App\Command\Clean;

use App\Command\PapiController;
use Minicli\App;
use Minicli\Command\CommandCall;

class DefaultController extends PapiController
{
    public function boot(App $app, CommandCall $input): void
    {
        parent::boot($app, $input);
        $this->description = 'clean spec-related assets';
    }

    public function handle(): void
    {
        $this->printIndexHelp();
    }
}
