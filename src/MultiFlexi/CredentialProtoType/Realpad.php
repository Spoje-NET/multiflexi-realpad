<?php

declare(strict_types=1);

/**
 * This file is part of the MultiFlexi package
 *
 * https://multiflexi.eu/
 *
 * (c) Vítězslav Dvořák <http://vitexsoftware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MultiFlexi\CredentialProtoType;

/**
 * Description of Realpad.
 *
 * author Vitex <info@vitexsoftware.cz>
 *
 * @no-named-arguments
 */
class Realpad extends \MultiFlexi\CredentialProtoType implements \MultiFlexi\credentialTypeInterface, \MultiFlexi\checkableCredentialInterface
{
    public static string $logo = 'Realpad.svg';

    /**
     * Realpad API base URL, overridden in tests.
     */
    protected string $baseEndpoint = 'https://cms.realpad.eu/';

    public function __construct()
    {
        parent::__construct();

        $usernameField = new \MultiFlexi\ConfigField('REALPAD_USERNAME', 'string', _('Realpad API Username'), _('Username for Realpad API authentication'));
        $passwordField = new \MultiFlexi\ConfigField('REALPAD_PASSWORD', 'password', _('Realpad API Password'), _('Password for Realpad API authentication'));

        $this->configFieldsProvided->addField($usernameField);
        $this->configFieldsProvided->addField($passwordField);
    }

    #[\Override]
    public function prepareConfigForm(): void
    {
        // Implement the configuration form logic if needed
    }

    public function name(): string
    {
        return _('Realpad');
    }

    public function description(): string
    {
        return _('Realpad API Credentials');
    }

    #[\Override]
    public function uuid(): string
    {
        return '48f09877-37a0-48a7-8f5b-0d66b26c8efd';
    }

    #[\Override]
    public function logo(): string
    {
        return self::$logo;
    }

    #[\Override]
    public function checkAvailability(): \MultiFlexi\CredentialCheckResult
    {
        $login = (string) ($this->configFieldsProvided->getFieldByCode('REALPAD_USERNAME')?->getValue() ?? '');
        $pass  = (string) ($this->configFieldsProvided->getFieldByCode('REALPAD_PASSWORD')?->getValue() ?? '');

        if ($login === '' || $pass === '') {
            $missing = array_keys(array_filter([
                'REALPAD_USERNAME' => $login === '',
                'REALPAD_PASSWORD' => $pass  === '',
            ]));

            return new \MultiFlexi\CredentialCheckResult(
                \MultiFlexi\CredentialState::Misconfigured,
                sprintf(_('Required fields not set: %s'), implode(', ', $missing)),
                time(),
            );
        }

        // Realpad expects the credentials as form fields, it ignores HTTP Basic auth.
        $ch = curl_init($this->baseEndpoint.'ws/v10/list-resources');
        curl_setopt_array($ch, [
            \CURLOPT_POST           => true,
            \CURLOPT_POSTFIELDS     => http_build_query(['login' => $login, 'password' => $pass], '', '&'),
            \CURLOPT_CONNECTTIMEOUT => 5,
            \CURLOPT_TIMEOUT        => 5,
            \CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);

        if ($errno !== 0) {
            return new \MultiFlexi\CredentialCheckResult(
                \MultiFlexi\CredentialState::Unavailable,
                sprintf(_('Realpad server unreachable: %s'), curl_strerror($errno)),
                time(),
                60,
            );
        }

        return self::resultForHttpCode(
            (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE),
            (int) curl_getinfo($ch, \CURLINFO_RETRY_AFTER),
        );
    }

    /**
     * Only a 2xx answer means the credentials work.
     *
     * @param int $httpCode   HTTP status returned by Realpad
     * @param int $retryAfter Retry-After in seconds, 0 when not sent
     */
    private static function resultForHttpCode(int $httpCode, int $retryAfter = 0): \MultiFlexi\CredentialCheckResult
    {
        if ($httpCode >= 200 && $httpCode < 300) {
            return new \MultiFlexi\CredentialCheckResult(\MultiFlexi\CredentialState::Available, '', time(), 300);
        }

        if ($httpCode === 429) {
            return new \MultiFlexi\CredentialCheckResult(
                \MultiFlexi\CredentialState::Degraded,
                _('Realpad is rate limiting requests (HTTP 429)'),
                time(),
                $retryAfter > 0 ? $retryAfter : 300,
            );
        }

        $misconfigured = match ($httpCode) {
            400 => _('Realpad rejected the request parameters (HTTP 400)'),
            401 => _('Realpad authentication failed (HTTP 401): invalid credentials, insufficient permissions or a banned account'),
            403 => _('Realpad denied access (HTTP 403): the account may require consent acceptance'),
            default => null,
        };

        if ($misconfigured !== null) {
            return new \MultiFlexi\CredentialCheckResult(
                \MultiFlexi\CredentialState::Misconfigured,
                $misconfigured,
                time(),
            );
        }

        return new \MultiFlexi\CredentialCheckResult(
            \MultiFlexi\CredentialState::Unavailable,
            sprintf(_('Realpad server returned HTTP %d'), $httpCode),
            time(),
            60,
        );
    }
}
