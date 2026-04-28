<?php

namespace App\Command\Report;

use App\Command\PapiController;
use Minicli\App;
use Minicli\Command\CommandCall;

class DefaultController extends PapiController
{
    public function boot(App $app, CommandCall $input): void
    {
        parent::boot($app, $input);
        $this->description = 'report information about an api';
    }

    public function handle(): void
    {
        $this->printIndexHelp();
    }
}
