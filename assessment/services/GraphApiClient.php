<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../models/Database.php';
require_once __DIR__ . '/../models/Crypto.php';

/**
 * Thin wrapper around Microsoft Graph REST calls. Uses the signed-in user's
 * delegated access token (refreshing via the stored refresh token when
 * expired) so every Graph call is scoped to what that user is authorized
 * to see - never a bare application-only token for user-facing operations.
 */
final class GraphApiClient
{
    private int $userId;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
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
            throw new RuntimeException("Graph API error (HTTP {$status}): {$message}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function accessToken(): string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM graph_tokens WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $this->userId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('No Microsoft Graph token on file for this user; please sign in again.');
        }

        if (strtotime($row['expires_at']) - 60 > time()) {
            return (string) Crypto::decrypt($row['access_token']);
        }

        return $this->refresh($row);
    }

    private function refresh(array $row): string
    {
        $refreshToken = Crypto::decrypt($row['refresh_token']);
        if (!$refreshToken) {
            throw new RuntimeException('Graph session expired and no refresh token is available; please sign in again.');
        }

        $tenant = config('azure.tenant_id');
        $ch = curl_init("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => config('azure.client_id'),
                'client_secret' => config('azure.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                'scope' => 'openid profile email ' . config('graph.scopes'),
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        $decoded = json_decode((string) $body, true);
        if (!isset($decoded['access_token'])) {
            throw new RuntimeException('Failed to refresh Microsoft Graph token; please sign in again.');
        }

        $expiresAt = (new DateTimeImmutable())->modify('+' . (int) ($decoded['expires_in'] ?? 3600) . ' seconds');
        $update = Database::connection()->prepare(
            'UPDATE graph_tokens SET access_token = :access_token, refresh_token = :refresh_token, expires_at = :expires_at WHERE user_id = :user_id'
        );
        $update->execute([
            'access_token' => Crypto::encrypt($decoded['access_token']),
            'refresh_token' => Crypto::encrypt($decoded['refresh_token'] ?? $refreshToken),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'user_id' => $this->userId,
        ]);

        return (string) $decoded['access_token'];
    }
}
