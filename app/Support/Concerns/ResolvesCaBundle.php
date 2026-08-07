<?php

namespace App\Support\Concerns;

/**
 * Resolve a CA certificate bundle for TLS verification.
 *
 * On a correctly configured host this returns true (use the system store). On a dev machine
 * whose php.ini leaves curl.cainfo empty — the usual cause of silent outbound-HTTPS failures on
 * XAMPP/Windows — we look for a bundle explicitly configured, set in php.ini, or shipped with a
 * common local PHP/XAMPP install, so outbound requests succeed without editing php.ini.
 *
 * Shared by every outbound HTTP client in the app (OpenAI, ingestion sources, SMS) since they
 * all hit the same class of problem on the same class of dev machine.
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
