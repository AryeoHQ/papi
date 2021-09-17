# PAPI

[![Latest Version on Packagist](https://img.shields.io/packagist/v/aryeo/papi.svg?style=flat-square)](https://packagist.org/packages/aryeo/papi)
[![Total Downloads](https://img.shields.io/packagist/dt/aryeo/papi.svg?style=flat-square)](https://packagist.org/packages/aryeo/papi)

A suite of tools for spec-driven API development in Laravel.

## Installation

```bash
composer require aryeo/papi --dev
```

## Usage

```bash
# show all commands
./bin/papi help

# report safe example...
export PWD=$(pwd)
./bin/papi report safe l_spec=${PWD}/examples/out/PetStore/PetStore.LAST.json c_spec=${PWD}/examples/out/PetStore/PetStore.MERGED.json
```

# Contributing

## Running Tests

```bash
./vendor/bin/phpunit
```
