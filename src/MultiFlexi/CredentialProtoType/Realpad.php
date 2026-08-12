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

    private const BASE_ENDPOINT = 'https://cms.realpad.eu/';

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

        $ch = curl_init(self::BASE_ENDPOINT.'ws/v10/list-resources');
        curl_setopt_array($ch, [
            \CURLOPT_CUSTOMREQUEST  => 'POST',
            \CURLOPT_CONNECTTIMEOUT => 5,
            \CURLOPT_TIMEOUT        => 5,
            \CURLOPT_USERPWD        => $login.':'.$pass,
            \CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        $errno    = curl_errno($ch);
        $httpCode = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);

        if ($errno !== 0) {
            return new \MultiFlexi\CredentialCheckResult(
                \MultiFlexi\CredentialState::Unavailable,
                sprintf(_('Realpad server unreachable: %s'), curl_strerror($errno)),
                time(),
                60,
            );
        }

        if ($httpCode === 401 || $httpCode === 403) {
            return new \MultiFlexi\CredentialCheckResult(
                \MultiFlexi\CredentialState::Misconfigured,
                sprintf(_('Authentication failed (HTTP %d) — check REALPAD_USERNAME and REALPAD_PASSWORD'), $httpCode),
                time(),
            );
        }

        if ($httpCode >= 500) {
            return new \MultiFlexi\CredentialCheckResult(
                \MultiFlexi\CredentialState::Unavailable,
                sprintf(_('Realpad server returned HTTP %d'), $httpCode),
                time(),
                60,
            );
        }

        return new \MultiFlexi\CredentialCheckResult(\MultiFlexi\CredentialState::Available, '', time(), 300);
    }
}
