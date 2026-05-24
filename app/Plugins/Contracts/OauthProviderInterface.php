<?php

namespace App\Plugins\Contracts;

interface OauthProviderInterface extends PluginInterface
{
    public function getAuthUrl(string $state): string;

    public function getAccessToken(string $code): array;

    public function getUserInfo(string $accessToken): array;
}