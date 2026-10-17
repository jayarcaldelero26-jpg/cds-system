<?php

namespace App\Services\Archive;

use RuntimeException;

/** Contains only a safe message and an HTTP status; never retains a Google response body. */
final class GoogleDriveArchiveException extends RuntimeException
{
    private const SAFE_OAUTH_CATEGORIES = [
        'invalid_request', 'invalid_client', 'invalid_grant', 'unauthorized_client',
        'unsupported_grant_type', 'invalid_scope', 'deleted_client', 'admin_policy_enforced',
        'invalid_dpop_proof', 'use_dpop_nonce',
    ];

    public readonly ?string $oauthErrorCategory;
    public readonly ?string $oauthErrorSubtype;

    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        ?string $oauthErrorCategory = null,
        ?string $oauthErrorSubtype = null,
    )
    {
        $this->oauthErrorCategory = in_array($oauthErrorCategory, self::SAFE_OAUTH_CATEGORIES, true)
            ? $oauthErrorCategory
            : null;
        $this->oauthErrorSubtype = $this->oauthErrorCategory === 'invalid_grant' && $oauthErrorSubtype === 'invalid_rapt'
            ? $oauthErrorSubtype
            : null;

        parent::__construct($message);
    }
}
