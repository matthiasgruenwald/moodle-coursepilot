<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot;

/**
 * A user's OAuth connection: consent, token generations, access and revocation.
 * Token hashes and grant locking stay behind this boundary. Discovery and client
 * metadata belong to oauth_lib.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class connection {
    /** @var string Client metadata used by the management views. */
    private const CLIENT_TABLE = 'local_coursepilot_oauth_client';

    /** @var string Database table for short-lived, PKCE-bound authorization codes. */
    private const CODE_TABLE = 'local_coursepilot_oauth_code';

    /** @var string Database table for access/refresh tokens. */
    private const TOKEN_TABLE = 'local_coursepilot_oauth_token';

    /** @var string Stable user/client connections, shared by every token generation. */
    private const GRANT_TABLE = 'local_coursepilot_oauth_grant';

    /** @var int|null Stable connection authenticated in this request. */
    private static ?int $currentconnectionid = null;

    /** @var int Authorization code lifetime in seconds (RFC 6749 recommends a short lifetime). */
    private const CODE_TTL = 120;

    /** @var int Access token lifetime in seconds: one hour. */
    public const ACCESS_TOKEN_TTL = 3600;

    /** @var int Refresh token lifetime in seconds: 30 days. */
    public const REFRESH_TOKEN_TTL = 30 * 24 * 3600;

    /**
     * Issue an authorization code only after teacher consent in oauth/authorize.php.
     *
     * @param string $clientid
     * @param int $userid
     * @param string $redirecturi Must match exactly on redemption.
     * @param string $codechallenge Client PKCE-S256 challenge.
     * @return string Authorization code.
     */
    public static function issue_code(string $clientid, int $userid, string $redirecturi, string $codechallenge): string {
        global $DB;

        $code = bin2hex(random_bytes(32));
        $record = new \stdClass();
        $record->code = $code;
        $record->clientid = $clientid;
        $record->userid = $userid;
        $record->redirecturi = $redirecturi;
        $record->codechallenge = $codechallenge;
        $record->expires = time() + self::CODE_TTL;
        $record->used = 0;
        $DB->insert_record(self::CODE_TABLE, $record);

        return $code;
    }

    /**
     * Exchange an authorization code for a token pair (RFC 6749 §4.1.3).
     * Check single use, expiry, client/redirect binding and the PKCE verifier
     * against the challenge stored by issue_code().
     *
     * @param string $code
     * @param string $clientid
     * @param string $redirecturi
     * @param string $codeverifier
     * @return mixed[]|null null on any error (RFC 6749: invalid_grant,
     *         without exposing the detailed cause).
     */
    public static function exchange_code(string $code, string $clientid, string $redirecturi, string $codeverifier): ?array {
        global $DB;

        $record = $DB->get_record(self::CODE_TABLE, ['code' => $code]);
        if (!$record || (int) $record->used === 1 || $record->expires < time()) {
            return null;
        }
        if (!self::code_matches($record, $clientid, $redirecturi, $codeverifier)) {
            return null;
        }

        // Claim and token issuance share one database transaction. If
        // issuance fails, roll back the claim rather than permanently consuming the code.
        $tokens = null;
        $transaction = $DB->start_delegated_transaction();
        try {
            if (!self::claim_code($record->code)) {
                // Another claimant won: indistinguishable from invalid_grant to the caller.
                $transaction->allow_commit();
                return null;
            }
            $tokens = self::issue_token_pair($clientid, (int) $record->userid);
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();

        return $tokens;
    }

    /**
     * Enforce the code's client, redirect and PKCE bindings without consuming it.
     *
     * @param \stdClass $record Stored authorization code.
     * @param string $clientid Requesting client.
     * @param string $redirecturi Registered redirect.
     * @param string $codeverifier PKCE verifier.
     * @return bool Whether all bindings match.
     */
    private static function code_matches(\stdClass $record, string $clientid, string $redirecturi, string $codeverifier): bool {
        return $record->clientid === $clientid && $record->redirecturi === $redirecturi
            && self::verify_pkce($codeverifier, $record->codechallenge);
    }

    /**
     * Rotate a refresh token: invalidate the old pair and issue its successor.
     * Preserve the consumed refresh hash so a replay revokes the whole connection.
     *
     * @param string $refreshtoken
     * @param string $clientid
     * @return mixed[]|null null for an invalid, expired or revoked token
     *         or a client mismatch.
     */
    public static function rotate_refresh_token(string $refreshtoken, string $clientid): ?array {
        global $DB;

        $record = $DB->get_record(self::TOKEN_TABLE, ['refreshtokenhash' => self::token_hash($refreshtoken)]);
        if (!$record || $record->clientid !== $clientid) {
            return null;
        }

        $tokens = null;
        $transaction = $DB->start_delegated_transaction();
        try {
            // UPDATE locks the shared grant until commit. Revocation uses the same
            // row, so it either prevents issuance or invalidates its successor.
            if (empty($record->connectionid) || !self::lock_connection((int) $record->connectionid)) {
                $transaction->allow_commit();
                return null;
            }
            $state = self::refresh_state($record);
            if ($state === 'replayed') {
                self::revoke_locked_connection((int) $record->connectionid);
            }
            if ($state !== 'usable') {
                $transaction->allow_commit();
                return null;
            }
            // Preserve the consumed refresh hash and its proven family identity.
            $DB->set_field(self::TOKEN_TABLE, 'revoked', 1, ['id' => $record->id]);
            $tokens = self::issue_token_pair($clientid, (int) $record->userid, (int) $record->connectionid);
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();

        return $tokens;
    }

    /**
     * Inspect the current generation only while holding its grant lock.
     * A consumed hash proves replay even after its old expiry.
     *
     * @param \stdClass $record Refresh token generation.
     * @return string usable, replayed or invalid.
     */
    private static function refresh_state(\stdClass $record): string {
        global $DB;
        $current = $DB->get_record(self::TOKEN_TABLE, ['id' => $record->id]);
        if ($current && (int) $current->revoked === 1) {
            return 'replayed';
        }
        return $current && $current->refreshexpires >= time() ? 'usable' : 'invalid';
    }

    /**
     * Claim an authorization code exactly once using an unguessable CAS marker.
     *
     * @param string $value Original authorization code.
     * @return bool Whether this transaction claimed the code.
     */
    private static function claim_code(string $value): bool {
        global $DB;

        $claim = hash('sha256', $value . '|' . bin2hex(random_bytes(16)));
        $DB->execute(
            'UPDATE {' . self::CODE_TABLE . '} SET code = :claim, used = 1 WHERE code = :value AND used = 0',
            ['claim' => $claim, 'value' => $value]
        );
        return $DB->record_exists(self::CODE_TABLE, ['code' => $claim]);
    }

    /**
     * Verify PKCE-S256 (RFC 7636 §4.6): BASE64URL(SHA256(verifier)) must equal
     * the stored authorization challenge. hash_equals() prevents timing attacks.
     *
     * @param string $codeverifier
     * @param string $codechallenge
     * @return bool
     */
    private static function verify_pkce(string $codeverifier, string $codechallenge): bool {
        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeverifier, true)), '+/', '-_'), '=');
        return hash_equals($codechallenge, $computed);
    }

    /**
     * Create an access/refresh token pair in the RFC 6749 response shape.
     * Shared final step of exchange_code() and rotate_refresh_token().
     *
     * @param string $clientid
     * @param int $userid
     * @param int|null $connectionid Existing grant on rotation, otherwise a new authorisation.
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}
     */
    private static function issue_token_pair(string $clientid, int $userid, ?int $connectionid = null): array {
        global $DB;

        $now = time();
        $newconnection = $connectionid === null;
        if ($newconnection) {
            $connectionid = (int) $DB->insert_record(self::GRANT_TABLE, (object) [
                'userid' => $userid, 'clientid' => $clientid, 'revoked' => 0,
                'statehash' => bin2hex(random_bytes(32)), 'timecreated' => $now,
            ]);
        }
        $accesstoken = bin2hex(random_bytes(32));
        $refreshtoken = bin2hex(random_bytes(32));
        $record = new \stdClass();
        $record->connectionid = $connectionid;
        $record->accesstokenhash = self::token_hash($accesstoken);
        $record->refreshtokenhash = self::token_hash($refreshtoken);
        $record->clientid = $clientid;
        $record->userid = $userid;
        $record->expires = $now + self::ACCESS_TOKEN_TTL;
        $record->refreshexpires = $now + self::REFRESH_TOKEN_TTL;
        $record->revoked = 0;
        $record->timecreated = $now;
        $DB->insert_record(self::TOKEN_TABLE, $record);

        if ($newconnection) {
            // The code proves its owner's consent even on an anonymous token request.
            event\connection_granted::create([
                'objectid' => $connectionid, 'userid' => $userid, 'relateduserid' => $userid,
            ])->trigger();
        }

        return [
            'access_token' => $accesstoken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'refresh_token' => $refreshtoken,
        ];
    }

    /**
     * Token handler for oauth/token.php: check method, dispatch
     * authorization_code/refresh_token and authenticate client_secret_post.
     * Like handle_registration(), return a response without exit(). The shell
     * already read body and distinguishes form input from JSON.
     *
     * @param string $method
     * @param mixed[]|null $body
     * @return array{status: int, headers: array<string, string>, body: mixed[]}
     */
    public static function handle_token(string $method, ?array $body): array {
        if ($method !== 'POST') {
            return self::result(405, ['Allow' => 'POST'], ['error' => 'invalid_request']);
        }
        if ($body === null) {
            return self::result(400, [], ['error' => 'invalid_request']);
        }

        $clientid = (string) ($body['client_id'] ?? '');
        $retryafter = 0;
        $client = $clientid !== '' ? oauth_lib::get_client($clientid, $retryafter) : null;
        if ($retryafter > 0) {
            return self::result(429, ['Retry-After' => (string) $retryafter], ['error' => 'temporarily_unavailable']);
        }
        if (!$client) {
            return self::result(400, [], ['error' => 'invalid_client']);
        }
        if (!self::client_authenticated($client, (string) ($body['client_secret'] ?? ''))) {
            return self::result(401, [], ['error' => 'invalid_client']);
        }
        return self::handle_grant($body, $clientid);
    }

    /**
     * Public clients rely on PKCE; confidential clients also prove their secret.
     *
     * @param \stdClass $client Registered client.
     * @param string $secret Submitted client secret.
     * @return bool Whether client authentication succeeded.
     */
    private static function client_authenticated(\stdClass $client, string $secret): bool {
        return $client->tokenendpointauthmethod !== 'client_secret_post'
            || ($secret !== '' && hash_equals((string) $client->clientsecret, $secret));
    }

    /**
     * Dispatch a validated client's grant request, preserving OAuth error codes.
     *
     * @param mixed[] $body Decoded token request.
     * @param string $clientid Authenticated client.
     * @return array{status: int, headers: array<string, string>, body: mixed[]}
     */
    private static function handle_grant(array $body, string $clientid): array {
        $granttype = (string) ($body['grant_type'] ?? '');
        if ($granttype === 'authorization_code') {
            return self::handle_code_grant($body, $clientid);
        } else if ($granttype === 'refresh_token') {
            $refreshtoken = (string) ($body['refresh_token'] ?? '');
            if ($refreshtoken === '') {
                return self::result(400, [], ['error' => 'invalid_request']);
            }
            $tokens = self::rotate_refresh_token($refreshtoken, $clientid);
        } else {
            return self::result(400, [], ['error' => 'unsupported_grant_type']);
        }

        return self::token_response($tokens);
    }

    /**
     * Require the code, redirect and verifier before attempting an exchange.
     *
     * @param mixed[] $body Decoded authorization-code grant.
     * @param string $clientid Authenticated client.
     * @return array{status: int, headers: array<string, string>, body: mixed[]}
     */
    private static function handle_code_grant(array $body, string $clientid): array {
        $code = (string) ($body['code'] ?? '');
        $redirecturi = (string) ($body['redirect_uri'] ?? '');
        $codeverifier = (string) ($body['code_verifier'] ?? '');
        if ($code === '' || $redirecturi === '' || $codeverifier === '') {
            return self::result(400, [], ['error' => 'invalid_request']);
        }
        return self::token_response(self::exchange_code($code, $clientid, $redirecturi, $codeverifier));
    }

    /**
     * Token responses never expose the reason for an invalid grant.
     *
     * @param mixed[]|null $tokens Issued pair, or a refused exchange/rotation.
     * @return array{status: int, headers: array<string, string>, body: mixed[]}
     */
    private static function token_response(?array $tokens): array {
        if ($tokens === null) {
            return self::result(400, [], ['error' => 'invalid_grant']);
        }
        return self::result(200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'], $tokens);
    }

    /**
     * Resolve an OAuth access token to a Moodle user ID. Validity requires
     * a stored row, no revocation/rotation (revoked=0), no expiry and an active
     * grant. Moodle web service tokens are never queried here.
     *
     * Only performs database lookup. The caller dispatcher::authenticate()
     * establishes USER/session state, following the existing seam.
     *
     * @param string $accesstoken
     * @return int|null userid, or null for an unknown, revoked
     *         or expired token.
     */
    public static function authenticate_access_token(string $accesstoken): ?int {
        global $DB;

        self::$currentconnectionid = null;
        $record = $DB->get_record(self::TOKEN_TABLE, ['accesstokenhash' => self::token_hash($accesstoken)]);
        if (
            !$record || (int) $record->revoked === 1 || $record->expires < time()
                || empty($record->connectionid) || !self::grant_active((int) $record->connectionid, (int) $record->userid)
        ) {
            return null;
        }
        self::$currentconnectionid = (int) $record->connectionid;
        return (int) $record->userid;
    }

    /**
     * The site emergency switch blocks remote access without revoking tokens.
     * Call before resolving a bearer or consuming a download ticket.
     *
     * @return bool Whether the emergency switch allows remote access.
     */
    public static function remote_access_enabled(): bool {
        return (string) get_config('local_coursepilot', 'remoteaccessenabled') !== '0';
    }

    /**
     * Check the connection, account and remote grant in their contract order.
     * A ticket may refer to a stable grant, a legacy token row, or any connection
     * of its owner. The caller translates the refusal into its protocol error.
     *
     * @param int $userid Owner whose access is checked, never the request's identity.
     * @param int|null $connectionid Stable issuing connection, when known.
     * @param int|null $tokenid Legacy issuing token, used only without a stable id.
     * @return string|null Refusal string key, or null when access is allowed.
     */
    public static function access_error(int $userid, ?int $connectionid = null, ?int $tokenid = null): ?string {
        global $DB;

        $active = $connectionid !== null ? self::grant_active($connectionid, $userid)
            : ($tokenid !== null ? self::connection_active($tokenid, $userid) : self::has_active_connection($userid));
        if (!$active) {
            return 'workbenchticketconnectionrevoked';
        }
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0])) {
            return 'workbenchticketaccountinactive';
        }
        return remote_access::is_granted($userid) ? null : 'remoteaccessnotgranted';
    }

    /**
     * Resolve a legacy token-row reference to its stable connection.
     * Rotation consumes the pair without revoking the connection. Tickets have
     * their own expiry and require the grant, independently of token lifetimes.
     *
     * @param int $id Legacy local_coursepilot_oauth_token.id.
     * @param int $owneruserid Ticket owner.
     * @return bool
     */
    private static function connection_active(int $id, int $owneruserid): bool {
        global $DB;

        $record = $DB->get_record(self::TOKEN_TABLE, ['id' => $id, 'userid' => $owneruserid]);
        return $record && !empty($record->connectionid)
            && self::grant_active((int) $record->connectionid, (int) $record->userid);
    }

    /**
     * Whether the user still has any Coursepilot connection. Workbench tickets
     * issued without a known connection must
     * still be no stronger than their connection. Without this check, such a
     * ticket would be the only file access surviving mass revocation.
     *
     * @param int $userid
     * @return bool
     */
    private static function has_active_connection(int $userid): bool {
        global $DB;

        return $DB->record_exists(self::GRANT_TABLE, ['userid' => $userid, 'revoked' => 0]);
    }

    /**
     * Mass revocation: invalidate every active access/refresh token,
     * regardless of user/client, for incident response. Unlike the dispatcher
     * emergency stop (remoteaccessenabled), which blocks access while leaving
     * issued tokens unchanged.
     *
     * @return int Number of revoked tokens.
     */
    public static function revoke_all_tokens(): int {
        global $DB;

        $count = 0;
        $transaction = $DB->start_delegated_transaction();
        try {
            // Lock grants before tokens. The marker identifies precisely the grants
            // this transaction locked, so a concurrent revoker is not logged twice.
            $marker = bin2hex(random_bytes(32));
            $DB->set_field(self::GRANT_TABLE, 'statehash', $marker, ['revoked' => 0]);
            $grants = $DB->get_records(self::GRANT_TABLE, ['statehash' => $marker, 'revoked' => 0]);
            $DB->set_field(self::GRANT_TABLE, 'revoked', 1, ['statehash' => $marker, 'revoked' => 0]);
            $count = $DB->count_records(self::TOKEN_TABLE, ['revoked' => 0]);
            $DB->set_field(self::TOKEN_TABLE, 'revoked', 1, ['revoked' => 0]);
            foreach ($grants as $grant) {
                self::record_revocation($grant);
            }
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();
        return $count;
    }

    /**
     * Revoke a token family by token row ID.
     *
     * Enforce owneruserid in the query rather than relying on the caller.
     * Teacher self-management passes USER->id; the admin view omits it.
     *
     * @param int $id
     * @param int|null $owneruserid Set to restrict revocation to the owner's
     *        own tokens.
     * @return bool false if no matching active row exists
     *         (unknown ID, already revoked or, when
     *         owneruserid is supplied, a foreign token).
     */
    public static function revoke_token(int $id, ?int $owneruserid = null): bool {
        global $DB;

        $conditions = ['id' => $id];
        if ($owneruserid !== null) {
            $conditions['userid'] = $owneruserid;
        }
        $record = $DB->get_record(self::TOKEN_TABLE, $conditions);
        if (!$record || empty($record->connectionid)) {
            return false;
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            if (!self::lock_connection((int) $record->connectionid)) {
                $transaction->allow_commit();
                return false;
            }
            self::revoke_locked_connection((int) $record->connectionid);
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();
        return true;
    }

    /**
     * A user's active token rows for connection self-management. The
     * WHERE clause enforces ownership rather than leaving it to the view.
     *
     * @param int $userid
     * @return \stdClass[] Newest issuance first, each including
     *         clientname (possibly null).
     */
    public static function active_tokens_for_user(int $userid): array {
        global $DB;

        return $DB->get_records_sql(
            'SELECT t.id, t.clientid, t.userid, t.timecreated, t.expires, c.clientname
               FROM {' . self::TOKEN_TABLE . '} t
               JOIN {' . self::GRANT_TABLE . '} g ON g.id = t.connectionid AND g.revoked = 0
          LEFT JOIN {' . self::CLIENT_TABLE . '} c ON c.clientid = t.clientid
              WHERE t.userid = :userid AND t.revoked = 0
           ORDER BY t.timecreated DESC',
            ['userid' => $userid]
        );
    }

    /**
     * Active token rows across all users for the admin overview.
     *
     * @return \stdClass[] Newest issuance first, each including
     *         clientname and the user's name/email.
     */
    public static function active_tokens(): array {
        global $DB;

        // Supply every name field expected by fullname(); otherwise Moodle emits
        // debugging() messages for missing fields.
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

        return $DB->get_records_sql(
            'SELECT t.id, t.clientid, t.userid, t.timecreated, t.expires, c.clientname,
                    ' . $namefields . ', u.email
               FROM {' . self::TOKEN_TABLE . '} t
               JOIN {' . self::GRANT_TABLE . '} g ON g.id = t.connectionid AND g.revoked = 0
          LEFT JOIN {' . self::CLIENT_TABLE . '} c ON c.clientid = t.clientid
          LEFT JOIN {user} u ON u.id = t.userid
              WHERE t.revoked = 0
           ORDER BY t.timecreated DESC'
        );
    }

    /**
     * Stable issuing connection, independent of the token generation.
     */
    public static function current_connection_id(): ?int {
        return self::$currentconnectionid;
    }

    /**
     * Tickets keep their own expiry; require the grant and its owner.
     *
     * @param int $id The id.
     * @param int $userid The userid.
     */
    private static function grant_active(int $id, int $userid): bool {
        global $DB;
        return $DB->record_exists(self::GRANT_TABLE, ['id' => $id, 'userid' => $userid, 'revoked' => 0]);
    }

    /**
     * Acquire the shared connection row within a delegated transaction.
     *
     * @param int $id The id.
     */
    private static function lock_connection(int $id): bool {
        global $DB;
        $marker = bin2hex(random_bytes(32));
        $DB->execute(
            'UPDATE {' . self::GRANT_TABLE . '}
                         SET statehash = :marker WHERE id = :id AND revoked = 0',
            ['marker' => $marker, 'id' => $id]
        );
        return $DB->record_exists(self::GRANT_TABLE, ['id' => $id, 'statehash' => $marker, 'revoked' => 0]);
    }

    /**
     * Revoke all generations and bound tickets while holding the grant lock.
     *
     * @param int $id The id.
     */
    private static function revoke_locked_connection(int $id): void {
        global $DB;
        $grant = $DB->get_record(self::GRANT_TABLE, ['id' => $id], 'id, userid', MUST_EXIST);
        $DB->set_field(self::GRANT_TABLE, 'revoked', 1, ['id' => $id]);
        $DB->set_field(self::TOKEN_TABLE, 'revoked', 1, ['connectionid' => $id]);
        self::record_revocation($grant);
    }

    /**
     * Record the revocation's stable identity and owner without exposing token secrets.
     * Moodle buffers events until the enclosing transaction commits.
     *
     * @param \stdClass $grant Revoked connection with id and userid.
     */
    private static function record_revocation(\stdClass $grant): void {
        event\connection_revoked::create([
            'objectid' => (int) $grant->id, 'relateduserid' => (int) $grant->userid,
        ])->trigger();
    }

    /**
     * Database lookups use only a fixed SHA-256 hash of the secret. Tokens are
     * neither persisted nor used as plaintext query values; the unique index
     * remains usable for authentication.
     *
     * @param string $token
     * @return string
     */
    private static function token_hash(string $token): string {
        return hash('sha256', $token);
    }

    /**
     * Provides result.
     *
     * @param int $status The status.
     * @param string[] $headers
     * @param mixed[] $body
     * @return array{status: int, headers: array<string, string>, body: mixed[]}
     */
    private static function result(int $status, array $headers, array $body): array {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
