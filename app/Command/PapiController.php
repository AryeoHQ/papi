<?php

namespace App\Command;

use Minicli\App;
use Minicli\Command\CommandController;

class PapiController extends CommandController
{
    protected $command_map;
    protected $description;
    protected $arguments;
    protected $parameters;
    protected $notes;

    public function boot(App $app)
    {
        parent::boot($app);

        $this->command_map = $app->command_registry->getCommandMap();
        $this->description = '[description]';
        $this->arguments = [];
        $this->parameters = [];
        $this->notes = [];
    }

    public function handle()
    {
        $this->getPrinter()->out('Not implemented.', 'bold');
        $this->getPrinter()->newline();
    }

    public function printIndexHelp()
    {
        $this->printDescription();
        $this->printUsage();
        $this->printCommands();
        $this->printSubCommandHelp();
    }

    public function printCommandHelp()
    {
        $this->printDescription();
        $this->printUsage();
        $this->printArguments();
        $this->printParameters();
        $this->printNotes();
    }

    public function printDescription()
    {
        $this->getPrinter()->out('Description', 'bold');
        $this->getPrinter()->newline();
        $this->getPrinter()->rawOutput('  '.$this->description);
        $this->printSectionEnd();
    }

    public function printFileNotFound(string $filename)
    {
        $this->getPrinter()->out('👎 FAIL: Unable to find \''.$filename.'\'.', 'error');
        $this->getPrinter()->newline();
        $this->getPrinter()->newline();
    }

    public function printUsage()
    {
        $command = $this->input->command;
        $sub_command = $this->input->subcommand;
        $sub_commands = $this->command_map[$this->input->command];

        $this->getPrinter()->out('Usage', 'bold');
        $this->getPrinter()->newline();

        // get usage prefix
        $usage = '';
        if ($this->input->subcommand === 'default') {
            if (count($sub_commands) === 1) {
                $usage = $usage.'  papi [command]';
            } else {
                $usage = $usage.'  papi '.$command.' [command]';
            }
        } else {
            $usage = $usage.'  papi '.$command.' '.$sub_command;
        }

        // for each argument...
        foreach ($this->arguments as $argument) {
            $usage = $usage.' ['.$argument[0].']';
        }

        // for each parameter...
        foreach ($this->parameters as $parameter) {
            $usage = $usage.' '.$parameter[0].'=['.$parameter[0].']';
        }

        $this->getPrinter()->rawOutput($usage);
        $this->printSectionEnd();
    }

    public function printAllCommands()
    {
        $this->getPrinter()->out('Commands', 'bold');
        $this->getPrinter()->newline();

        foreach ($this->command_map as $command => $sub) {
            $this->getPrinter()->out('  '.$command, 'success');
            if (is_array($sub)) {
                foreach ($sub as $subcommand) {
                    if ($subcommand !== 'default') {
                        $this->getPrinter()->newline();
                        $this->getPrinter()->out(sprintf('    %s%s', '└──', $subcommand), 'info');
                    }
                }
            }
            $this->getPrinter()->newline();
        }
        $this->getPrinter()->newline();
    }

    public function printCommands()
    {
        if (count($this->command_map) > 0) {
            $this->getPrinter()->out('Commands', 'bold');
            $this->getPrinter()->newline();
            foreach ($this->command_map as $command => $sub) {
                if ($command === $this->input->command) {
                    if (is_array($sub)) {
                        foreach ($sub as $subcommand) {
                            if ($subcommand !== 'default') {
                                $this->getPrinter()->out('  '.$subcommand, 'success');
                                $this->getPrinter()->newline();
                            }
                        }
                    }
                }
            }
            $this->getPrinter()->newline();
        }
    }

    public function printSubCommandHelp()
    {
        $command = $this->input->command;
        $sub_commands = $this->command_map[$this->input->command];

        // help command
        $help_command = '';
        if ($this->input->subcommand === 'default') {
            if (count($sub_commands) === 1) {
                $help_command = $help_command.'    papi [command]';
            } else {
                $help_command = $help_command.'    papi '.$command.' [command]';
            }
        }

        $this->getPrinter()->out('Help', 'bold');
        $this->getPrinter()->newline();
        $this->getPrinter()->rawOutput('  To see help information for a command:');
        $this->getPrinter()->newline();
        $this->getPrinter()->newline();
        $this->getPrinter()->out($help_command, 'success');
        $this->getPrinter()->newline();
        $this->getPrinter()->newline();
    }

    public function printArguments()
    {
        if (count($this->arguments) > 0) {
            $this->getPrinter()->out('Arguments', 'bold');
            foreach ($this->arguments as $argument) {
                $this->getPrinter()->newline();
                $this->getPrinter()->out('  '.str_pad($argument[0], 10, ' ', STR_PAD_RIGHT), 'success');
                $this->getPrinter()->rawOutput("\t".$argument[1]);
                if (!empty($argument[2])) {
                    $this->getPrinter()->out(' [ex: '.$argument[2].']', 'info');
                }
            }
            $this->printSectionEnd();
        }
    }

    public function printParameters()
    {
        if (count($this->parameters) > 0) {
            $required_parameters = array_filter($this->parameters, function ($parameter) {
                return $parameter[3];
            });

            $optional_parameters = array_filter($this->parameters, function ($parameter) {
                return !$parameter[3];
            });

            $this->getPrinter()->out('Parameters', 'bold');
            foreach ($required_parameters as $parameter) {
                $this->getPrinter()->newline();
                $this->getPrinter()->out('  '.str_pad($parameter[0], 10, ' ', STR_PAD_RIGHT), 'success');
                $this->getPrinter()->rawOutput("\t".$parameter[1]);
                if (!empty($parameter[2])) {
                    $this->getPrinter()->out(' [ex: '.$parameter[2].']', 'info');
                }
            }
            $this->printSectionEnd();

            $this->getPrinter()->out('Optional Parameters', 'bold');
            foreach ($optional_parameters as $parameter) {
                $this->getPrinter()->newline();
                $this->getPrinter()->out('  '.str_pad($parameter[0], 10, ' ', STR_PAD_RIGHT), 'success');
                $this->getPrinter()->rawOutput("\t".$parameter[1]);
                if (!empty($parameter[2])) {
                    $this->getPrinter()->out(' [ex: '.$parameter[2].']', 'info');
                }
            }
            $this->printSectionEnd();
        }
    }

    public function printNotes()
    {
        if (count($this->notes) > 0) {
            $this->getPrinter()->out('Notes', 'bold');
            $this->getPrinter()->newline();
            foreach ($this->notes as $note) {
                $this->getPrinter()->rawOutput('  '.$note);
                $this->getPrinter()->newline();
            }
            $this->getPrinter()->newline();
        }
    }

    public function printSectionEnd()
    {
        $this->getPrinter()->newline();
        $this->getPrinter()->newline();
    }

    public function safetyHeader()
    {
        $this->getPrinter()->rawOutput('/=============================');
        $this->getPrinter()->newline();
        $this->getPrinter()->rawOutput('| SAFETY CHECKS              |');
        $this->getPrinter()->newline();
        $this->getPrinter()->rawOutput('=============================/');
        $this->getPrinter()->newline();
    }

    public function displaySectionResultsWithErrors(array $section_results)
    {
        $errors_encountered = false;

        // header
        $this->safetyHeader();

        foreach ($section_results as $section_result) {
            if (count($section_result->errors) === 0) {
                $this->getPrinter()->out('👍 PASS: '.$section_result->name.'.', 'success');
                $this->getPrinter()->newline();
            } else {
                $errors_encountered = true;
                $this->getPrinter()->out('👎 FAIL: '.$section_result->name.'.', 'error');
                $this->getPrinter()->newline();
                foreach ($section_result->errors as $error) {
                    $this->getPrinter()->rawOutput($error);
                    $this->getPrinter()->newline();
                }
            }
            $this->getPrinter()->newline();
        }

        // footer
        $this->getPrinter()->rawOutput('/=============================');
        $this->getPrinter()->newline();
        $this->getPrinter()->rawOutput('| SAFETY RESULTS             |');
        $this->getPrinter()->newline();
        $this->getPrinter()->rawOutput('=============================/');
        $this->getPrinter()->newline();

        return $errors_encountered;
    }

    public function checkValidInputs($args)
    {
        $proper_args = (count($args) === count($this->arguments));

        $proper_params = true;
        foreach ($this->parameters as $parameter) {
            if (!$this->hasParam($parameter[0]) && $parameter[3]) {
                $proper_params = false;
                break;
            }
        }

        return $proper_args && $proper_params;
    }
}
