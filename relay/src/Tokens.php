<?php

namespace AxelFerdinand\StatamicSecretaryRelay;

final class Tokens
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public static function route(): string
    {
        return 'r'.self::random(25);
    }

    public static function conversation(): string
    {
        return 'c'.self::random(25);
    }

    public static function installation(): string
    {
        return 'si_'.self::random(32);
    }

    public static function signingSecret(): string
    {
        return random_bytes(32);
    }

    public static function pairingCode(): string
    {
        return 'pc_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function secretRotation(): string
    {
        return 'sr_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function routeRotation(): string
    {
        return 'rr_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function random(int $length): string
    {
        $token = '';
        $maximum = strlen(self::ALPHABET) - 1;

        for ($index = 0; $index < $length; $index++) {
            $token .= self::ALPHABET[random_int(0, $maximum)];
        }

        return $token;
    }
}
