<?php

namespace App\Service;

/**
 * Description of UserHelper.
 *
 * @author Stefano Perrini <perrini.stefano@gmail.com> aka La Matrigna
 */
final class UserHelper
{
    private string $clientIp;
    private string $userAgent;
    private string $cfCountryCode;
    private string $httpCfRay;

    public function __construct()
    {
        $this->initUserIp();
        $this->initCFCountryCode();
        $this->initUserAgent();
        $this->initHttpCfRay();
    }

    /**
     * <p>Retrieve and init Remote Client IP</p>.
     *
     * @return string <p>Remote Client IP</p>
     */
    public function initUserIp(): string
    {
        $cfIp = filter_input(INPUT_SERVER, 'HTTP_CF_CONNECTING_IP');
        if (!empty($cfIp)) {
            $this->setClientIp($cfIp);

            return $this->clientIp;
        }
        $httpclusterip = filter_input(INPUT_SERVER, 'HTTP_X_CLUSTER_CLIENT_IP');
        if (!empty($httpclusterip)) {
            $this->setClientIp($httpclusterip);

            return $this->clientIp;
        }
        $remoteAddress = filter_input(INPUT_SERVER, 'REMOTE_ADDR');
        if (!empty($remoteAddress)) {
            $this->setClientIp($remoteAddress);

            return $this->clientIp;
        }

        $clientIp = '0.0.0.0';
        $this->setClientIp($clientIp);

        return $this->clientIp;
    }

    /**
     * <p>Retrieve and Init cloudflare country code variable</p>.
     *
     * @return string <p>CloudFlare Country Code</p>
     */
    public function initCFCountryCode(): string
    {
        $this->cfCountryCode = strtolower(trim((string) filter_input(INPUT_SERVER, 'HTTP_CF_IPCOUNTRY', FILTER_UNSAFE_RAW)));

        return $this->cfCountryCode;
    }

    /**
     * <p>Retrieve and Init cloudflare Cf Ray</p>.
     *
     * @return string <p>CloudFlare Cr Ray</p>
     */
    public function initHttpCfRay(): string
    {
        $this->httpCfRay = trim((string) filter_input(INPUT_SERVER, 'HTTP_CF_RAY', FILTER_UNSAFE_RAW));

        return $this->httpCfRay;
    }

    public function isLocal(): bool
    {
        return in_array($this->clientIp, ['127.0.0.1', '::1', '0:0:0:0:0:0:0:1'], true);
    }

    /**
     * Retrieve and init Client User Agent.
     */
    public function initUserAgent(): string
    {
        $this->userAgent = (string) filter_input(INPUT_SERVER, 'HTTP_USER_AGENT', FILTER_UNSAFE_RAW);

        return $this->userAgent;
    }

    public function getClientIp(): string
    {
        return $this->clientIp;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getCfCountryCode(): string
    {
        return $this->cfCountryCode;
    }

    public function getCfRay(): string
    {
        return $this->httpCfRay;
    }

    public function setClientIp(string $clientIp): self
    {
        $this->clientIp = $clientIp;

        return $this;
    }

    public function setUserAgent(string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function setCfCountryCode(string $cfCountryCode): self
    {
        $this->cfCountryCode = $cfCountryCode;

        return $this;
    }
}
