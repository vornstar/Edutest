<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * App-only Microsoft Graph client (client-credentials flow), used
 * exclusively for the assessment platform's own OneDrive file storage
 * (see OneDriveService). Unlike GraphApiClient, this is NOT tied to
 * whoever is signed in - it authenticates as the application itself, using
 * an Application permission grant rather than acting on behalf of a
 * delegated user.
 *
 * This is what makes the "backend stream proxy" architecture in the SRS
 * actually hold: no student, teacher, or anyone else needs ANY Microsoft
 * 365 permission on the storage drive/folder - FileProxyController's own
 * RBAC checks are the only gate. With a delegated (per-user) token
 * instead, whoever is currently signed in would need their own Microsoft
 * permission on the drive just to view a file, which defeats the point of
 * proxying it through this app at all.
 *
 * Requires an Application permission (not Delegated) granted with admin
 * consent in the Azure AD app registration - Files.ReadWrite.All is the
 * one that covers everything OneDriveService does. In the Azure Portal:
 * Entra ID > App registrations > (this app) > API permissions >
 * Add a permission > Microsoft Graph > Application permissions >
 * Files.ReadWrite.All > Add permissions > Grant admin consent.
 *
 * NOT verified against a live tenant - built strictly from Microsoft's
 * documented client-credentials flow, since this app has no way to test
 * against a real Azure AD tenant directly. Test the first upload/download
 * carefully.
 */
final class GraphAppClient
{
    private static ?string $cachedToken = null;
    private static int $cachedTokenExpiresAt = 0;

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, [], $body);
    }

    public function putBinary(string $path, string $binaryContent, string $contentType = 'application/octet-stream'): array
    {
        return $this->request('PUT', $path, [], null, $binaryContent, $contentType);
    }

    public function getBinary(string $path): string
    {
        $url = rtrim((string) config('graph.base'), '/') . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->accessToken()],
            CURLOPT_TIMEOUT => 60,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new RuntimeException("Graph download failed for {$path} (HTTP {$status}).");
        }
        return $body;
    }

    private function request(string $method, string $path, array $query = [], ?array $jsonBody = null, ?string $rawBody = null, ?string $contentType = null): array
    {
        $url = rtrim((string) config('graph.base'), '/') . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = ['Authorization: Bearer ' . $this->accessToken()];
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
            throw new RuntimeException(
                "Graph API error (HTTP {$status}): {$message}. If this is an authorization " .
                "error, confirm the Files.ReadWrite.All APPLICATION permission has been granted " .
                "admin consent in the Azure AD app registration (Application permissions, not " .
                "Delegated - see the comment at the top of GraphAppClient.php)."
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** Cached for the lifetime of the PHP process/request; refetched once it's within 60s of expiring. */
    private function accessToken(): string
    {
        if (self::$cachedToken !== null && self::$cachedTokenExpiresAt > time() + 60) {
            return self::$cachedToken;
        }

        $ch = curl_init('https://login.microsoftonline.com/' . config('azure.tenant_id') . '/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => config('azure.client_id'),
                'client_secret' => config('azure.client_secret'),
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string) $body, true);
        if (!isset($decoded['access_token'])) {
            $message = $decoded['error_description'] ?? $body;
            throw new RuntimeException(
                "Could not obtain an app-only Microsoft Graph token: {$message}. This usually means " .
                "the Files.ReadWrite.All Application permission hasn't been granted admin consent yet " .
                "for this app registration in Azure AD."
            );
        }

        self::$cachedToken = (string) $decoded['access_token'];
        self::$cachedTokenExpiresAt = time() + (int) ($decoded['expires_in'] ?? 3600);

        return self::$cachedToken;
    }
}
