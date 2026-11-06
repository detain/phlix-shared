<?php

/**
 * Throwaway TLS material + free-port reservation for the real-TLS redirect
 * walk E2E (tests/Arr/TrashGuidesProviderTest.php, LW-061 minor cure).
 *
 * Everything is generated IN-PROCESS with ext-openssl — no openssl CLI
 * dependency, no committed key files, nothing that outlives the test tmp
 * dir. The recipe mirrors the estate-proven pattern from phlix-hub's
 * tests/Support/Federation/FederationTlsMaterial.php: a CA, a leaf server
 * cert signed by it (SAN DNS:localhost + loopback IPs, and the E2E dials
 * the literal 127.0.0.1 so DNS never enters the picture).
 *
 * Why real TLS at all: fetchUrl reads its hop stack from the
 * $http_response_header symbol that ONLY the internal HTTP wrapper writes
 * into the caller scope — userland stream wrappers cannot supply it (the
 * http_response_header property never propagates; measured on PHP 8.3.6).
 * A fake `https` wrapper therefore cannot drive fetchUrl through an actual
 * redirect hop; only a genuinely dialable endpoint can, and a loopback
 * listener with a test-only CA is the hermetic way to be one.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Shared\Tests\Support\Arr;

use RuntimeException;

/**
 * Generates the disposable certificate chain and loopback port the
 * redirect-walk E2E runs against.
 */
final class TrashGuidesTlsMaterial
{
    /**
     * Write ca.pem / server.pem (leaf + CA + key) / openssl.cnf into $dir.
     *
     * @param string $dir Existing, test-owned directory (removed by the caller).
     *
     * @return array{ca: string, server: string} Absolute paths.
     */
    public static function generate(string $dir): array
    {
        $caKey = self::privateKey();
        $caCert = self::signCa($caKey, 'Phlix E2E TRaSH-Guides CA');

        $serverKey = self::privateKey();
        $serverCert = self::signServer($serverKey, $caKey, $caCert);

        $paths = [
            'ca' => $dir . '/ca.pem',
            'server' => $dir . '/server.pem',
        ];

        self::write($paths['ca'], self::exportCertificate($caCert));
        self::write(
            $paths['server'],
            self::exportCertificate($serverCert) . self::exportCertificate($caCert) . self::exportKey($serverKey)
        );

        return $paths;
    }

    /**
     * Reserve (bind-then-release) an ephemeral loopback port for the child
     * TLS listener, so the parent can bake the port into the dial URLs
     * before the server exists. Mirrors the hub federation venue helper.
     */
    public static function reservePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("reservePort: cannot bind loopback: $errstr");
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        if ($port <= 0) {
            throw new RuntimeException("reservePort: could not read back port from '$name'");
        }

        return $port;
    }

    /**
     * @return \OpenSSLAsymmetricKey
     */
    private static function privateKey()
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => self::configPath(),
        ]);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw new RuntimeException('TLS material: openssl_pkey_new failed: ' . openssl_error_string());
        }

        return $key;
    }

    /**
     * Self-signed CA certificate.
     *
     * @param \OpenSSLAsymmetricKey $key
     *
     * @return \OpenSSLCertificate
     */
    private static function signCa($key, string $cn)
    {
        $csr = openssl_csr_new(['CN' => $cn], $key, ['digest_alg' => 'sha256', 'config' => self::configPath()]);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('TLS material: CA csr failed: ' . openssl_error_string());
        }

        $cert = openssl_csr_sign($csr, null, $key, 2, [
            'digest_alg' => 'sha256',
            'x509_extensions' => 'phlix_tg_ca_ext',
            'config' => self::configPath(),
        ]);
        if (!$cert instanceof \OpenSSLCertificate) {
            throw new RuntimeException('TLS material: CA sign failed: ' . openssl_error_string());
        }

        return $cert;
    }

    /**
     * Server leaf signed by the CA, SAN DNS:localhost + loopback IPs so
     * peer-name verification genuinely exercises the SAN path (CN fallback
     * is long dead).
     *
     * @param \OpenSSLAsymmetricKey $serverKey
     * @param \OpenSSLAsymmetricKey $caKey
     * @param \OpenSSLCertificate   $caCert
     *
     * @return \OpenSSLCertificate
     */
    private static function signServer($serverKey, $caKey, $caCert)
    {
        $csr = openssl_csr_new(
            ['CN' => 'localhost'],
            $serverKey,
            ['digest_alg' => 'sha256', 'config' => self::configPath()]
        );
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('TLS material: server csr failed: ' . openssl_error_string());
        }

        $cert = openssl_csr_sign($csr, $caCert, $caKey, 2, [
            'digest_alg' => 'sha256',
            'x509_extensions' => 'phlix_tg_server_ext',
            'config' => self::configPath(),
        ]);
        if (!$cert instanceof \OpenSSLCertificate) {
            throw new RuntimeException('TLS material: server sign failed: ' . openssl_error_string());
        }

        return $cert;
    }

    /**
     * A tiny openssl.cnf the extension blocks openssl_csr_sign's
     * x509_extensions names reference.
     */
    private static function configPath(): string
    {
        $path = sys_get_temp_dir() . '/phlix-tg-tls-' . getmypid() . '.cnf';
        if (is_file($path)) {
            return $path;
        }

        $cnf = <<<'CNF'
        [req]
        distinguished_name = req_dn
        prompt = no

        [req_dn]
        CN = localhost

        [phlix_tg_ca_ext]
        basicConstraints = critical, CA:TRUE
        keyUsage = critical, keyCertSign, cRLSign

        [phlix_tg_server_ext]
        basicConstraints = critical, CA:FALSE
        keyUsage = critical, digitalSignature, keyEncipherment
        extendedKeyUsage = serverAuth
        subjectAltName = DNS:localhost, IP:127.0.0.1, IP:0:0:0:0:0:0:0:1
        CNF;

        file_put_contents($path, $cnf);

        return $path;
    }

    private static function exportCertificate(\OpenSSLCertificate $cert): string
    {
        $out = '';
        if (!openssl_x509_export($cert, $out)) {
            throw new RuntimeException('TLS material: x509 export failed');
        }

        return $out;
    }

    private static function exportKey(\OpenSSLAsymmetricKey $key): string
    {
        $out = '';
        if (!openssl_pkey_export($key, $out, null, ['config' => self::configPath()])) {
            throw new RuntimeException('TLS material: key export failed');
        }

        return $out;
    }

    private static function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("TLS material: cannot write $path");
        }
    }
}
