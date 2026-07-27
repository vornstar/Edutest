<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * Thin wrapper around Microsoft Graph REST calls. Uses the *session's*
 * delegated access token - the one the root /auth_handler.php already
 * placed in $_SESSION['access_token'] when the user signed in - refreshing
 * it via $_SESSION['refresh_token'] when expired. There is no separate
 * token store in the assessment database: this app never runs its own
 * OAuth login, so the site's own session is the single source of truth for
 * Graph credentials, exactly as the rest of the site already does it.
 */
final class GraphApiClient
{
    private int $localUserId;

    /** @param int $localUserId The assessment-local users.id of whoever should be making this call - must be the current session's user. */
    public function __construct(int $localUserId)
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        require_once __DIR__ . '/../models/User.php';
        $current = !empty($_SESSION['user_id'])
            ? User::syncFromSession((int) $_SESSION['user_id'], (string) ($_SESSION['user_email'] ?? ''), (string) ($_SESSION['user_name'] ?? ''))
            : null;

        if (!$current || (int) $current['id'] !== $localUserId) {
            throw new RuntimeException('Graph API calls may only be made on behalf of the currently signed-in user.');
        }

        $this->localUserId = $localUserId;
    }

    public function get(string $path, array $query = [], array $extraHeaders = []): array
    {
        return $this->request('GET', $path, $query, null, null, null, $extraHeaders);
    }

    /**
     * Fetches every page of a Graph collection, following @odata.nextLink -
     * needed because education/classes rosters etc. can exceed a single
     * page. Returns the combined 'value' array across all pages.
     */
    public function getAll(string $path, array $query = []): array
    {
        $url = rtrim((string) config('graph.base'), '/') . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $items = [];
        $next = $url;
        $guard = 0;
        while ($next && $guard < 25) {
            $page = $this->requestAbsoluteUrl('GET', $next);
            if (!empty($page['value'])) {
                $items = array_merge($items, $page['value']);
            }
            $next = $page['@odata.nextLink'] ?? null;
            $guard++;
        }
        return $items;
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, [], $body);
    }

    public function patch(string $path, array $body): array
    {
        return $this->request('PATCH', $path, [], $body);
    }

    /** Uploads raw binary content (e.g. a PDF) to a OneDrive path via PUT. */
    public function putBinary(string $path, string $binaryContent, string $contentType = 'application/octet-stream'): array
    {
        return $this->request('PUT', $path, [], null, $binaryContent, $contentType);
    }

    /** Streams a file's bytes back; used by the download proxy so raw OneDrive URLs are never exposed to the browser. */
    public function getBinary(string $path): string
    {
        $url = rtrim((string) config('graph.base'), '/') . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->accessToken()],
            CURLOPT_TIMEOUT => 60,
            // Graph's .../content endpoint responds with a redirect to the
            // actual (pre-authenticated) blob storage URL rather than the
            // file bytes directly - without this, curl returns the
            // redirect response itself (empty/tiny) as if it had
            // succeeded, producing a corrupt file with no error anywhere.
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new RuntimeException("Graph download failed for {$path} (HTTP {$status}).");
        }
        return $body;
    }

    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null, ?string $rawBody = null, ?string $contentType = null, array $extraHeaders = []): array
    {
        $url = rtrim((string) config('graph.base'), '/') . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        return $this->requestAbsoluteUrl($method, $url, $jsonBody, $rawBody, $contentType, $extraHeaders);
    }

    private function requestAbsoluteUrl(string $method, string $url, ?array $jsonBody = null, ?string $rawBody = null, ?string $contentType = null, array $extraHeaders = []): array
    {
        $headers = array_merge(['Authorization: Bearer ' . $this->accessToken()], $extraHeaders);
        $payload = null;

        if ($rawBody !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
            $payload = $rawBody;
        } elseif ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($jsonBody);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("Graph API request failed: {$err}");
        }

        $decoded = json_decode($body, true);
        if ($status >= 400) {
            $message = $decoded['error']['message'] ?? $body;
            throw new RuntimeException("Graph API error (HTTP {$status}): {$message}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function accessToken(): string
    {
        $expiresAt = (int) ($_SESSION['token_expires_at'] ?? 0);
        if (!empty($_SESSION['access_token']) && $expiresAt > time()) {
            return (string) $_SESSION['access_token'];
        }

        return $this->refresh();
    }

    /**
     * Mirrors refreshMicrosoftToken() from the root auth_handler.php so the
     * two stay behaviourally identical, without requiring that whole file
     * (with its db.php/mysqli side effects) to be included here.
     */
    private function refresh(): string
    {
        if (empty($_SESSION['refresh_token'])) {
            throw new RuntimeException('Microsoft Graph session expired; please sign in again.');
        }

        $ch = curl_init('https://login.microsoftonline.com/' . config('azure.tenant_id') . '/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => config('azure.client_id'),
                'client_secret' => config('azure.client_secret'),
                'refresh_token' => $_SESSION['refresh_token'],
                'grant_type' => 'refresh_token',
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string) $body, true);
        if (!isset($decoded['access_token'])) {
            throw new RuntimeException('Failed to refresh Microsoft Graph token; please sign in again.');
        }

        $_SESSION['access_token'] = $decoded['access_token'];
        if (isset($decoded['refresh_token'])) {
            $_SESSION['refresh_token'] = $decoded['refresh_token'];
        }
        $_SESSION['token_expires_at'] = time() + (int) ($decoded['expires_in'] ?? 3600) - 300;

        return (string) $decoded['access_token'];
    }
}
