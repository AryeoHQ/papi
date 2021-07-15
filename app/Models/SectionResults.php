<?php

namespace App\Models;

class SectionResults
{
    public $name;
    public $errors;

    public function __construct(string $name, array $errors)
    {
        $this->name = $name;
        $this->errors = $errors;
    }
}
