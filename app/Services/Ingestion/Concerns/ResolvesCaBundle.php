<?php

namespace App\Services\Ingestion\Concerns;

/**
 * Resolve a CA certificate bundle for TLS verification.
 *
 * On a correctly configured host this returns true (use the system store). On a dev machine
 * whose php.ini leaves curl.cainfo empty — the usual cause of silent outbound-HTTPS failures on
 * XAMPP/Windows — we look for a bundle explicitly configured, set in php.ini, or shipped with a
 * common local PHP/XAMPP install, so ingestion requests succeed without editing php.ini.
 */
trait ResolvesCaBundle
{
    private function caBundle(): string|bool
    {
        foreach (['curl.cainfo', 'openssl.cafile'] as $directive) {
            $path = ini_get($directive);
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $path;
            }
        }

        foreach ([
            'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt',
            'C:\\xampp\\php\\extras\\ssl\\cacert.pem',
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return true;
    }
}
