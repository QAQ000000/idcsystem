<?php

namespace App\Plugins\Contracts;

interface CertificationInterface extends PluginInterface
{
    public function verify(array $data): array;
}