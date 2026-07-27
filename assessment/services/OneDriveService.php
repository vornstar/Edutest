<?php
declare(strict_types=1);

require_once __DIR__ . '/GraphApiClient.php';
require_once __DIR__ . '/../config/config.php';

/**
 * Stores/retrieves exam paper PDFs, mark schemes, and scanned student
 * scripts on Microsoft OneDrive via Graph. Callers never receive a raw
 * OneDrive URL - files are only ever streamed back through
 * controllers/FileProxyController.php so access stays gated by our own
 * RBAC checks (see SRS 5.2 and 6.3).
 *
 * Uses the signed-in user's own delegated Graph token (GraphApiClient),
 * NOT an app-only/Application-permission connection - Files.ReadWrite.All
 * as an Application permission needs Azure AD admin consent this tenant
 * has not been able to grant. Instead this mirrors the school's other
 * OneDrive-integrated module (the DofE application system): a single
 * folder is shared once, manually, via a normal "anyone in the
 * organisation with the link can edit" OneDrive/SharePoint sharing link -
 * no Azure Portal or admin consent involved, just the same folder-sharing
 * action any staff member can already do. That link is resolved via
 * Graph's /shares/{id}/driveItem endpoint (redeeming the sharing link) to
 * get a driveId/itemId, and every file operation happens under that
 * folder using whichever signed-in user is currently making the request -
 * their own access comes from being able to see the shared folder at all,
 * not from any Graph API permission grant.
 */
final class OneDriveService
{
    private GraphApiClient $graph;

    /** @var array<string,array{driveId:string,itemId:string}> */
    private static array $masterFolderCache = [];

    public function __construct(int $actingUserId)
    {
        $this->graph = new GraphApiClient($actingUserId);
    }

    private static function encodeSharingUrl(string $url): string
    {
        $base64 = base64_encode($url);
        $base64 = str_replace(['+', '/'], ['-', '_'], $base64);
        return 'u!' . rtrim($base64, '=');
    }

    /**
     * Resolves ASSESSMENT_ONEDRIVE_FOLDER_LINK (a plain OneDrive/SharePoint
     * "anyone in the org with the link can edit" sharing URL) into a
     * driveId/itemId pair, redeeming it with the current signed-in user's
     * own token. Cached per request - every file operation resolves the
     * same master folder.
     */
    private function resolveMasterFolder(): array
    {
        $link = (string) config('onedrive.master_folder_link');
        if ($link === '') {
            throw new RuntimeException(
                'ASSESSMENT_ONEDRIVE_FOLDER_LINK is not configured. Share a OneDrive/SharePoint ' .
                'folder with "People in the organisation with the link can edit", copy that link, ' .
                'and set it in .env.php - see Admin > OneDrive setup for a walkthrough.'
            );
        }

        if (isset(self::$masterFolderCache[$link])) {
            return self::$masterFolderCache[$link];
        }

        $encoded = self::encodeSharingUrl($link);
        $result = $this->graph->get("/shares/{$encoded}/driveItem", [], ['Prefer: redeemSharingLink']);

        $driveId = $result['remoteItem']['parentReference']['driveId'] ?? ($result['parentReference']['driveId'] ?? null);
        $itemId = $result['remoteItem']['id'] ?? ($result['id'] ?? null);

        if (!$driveId || !$itemId) {
            throw new RuntimeException('Could not resolve ASSESSMENT_ONEDRIVE_FOLDER_LINK to a OneDrive folder - check the link is still valid and shared with the whole organisation.');
        }

        return self::$masterFolderCache[$link] = ['driveId' => $driveId, 'itemId' => $itemId];
    }

    /** Finds (or creates) a child folder by name under a known parent item, returning its id. */
    private function ensureFolder(string $driveId, string $parentItemId, string $name): string
    {
        $children = $this->graph->getAll("/drives/{$driveId}/items/{$parentItemId}/children", ['$select' => 'id,name']);
        foreach ($children as $child) {
            if (strcasecmp((string) $child['name'], $name) === 0) {
                return (string) $child['id'];
            }
        }

        $created = $this->graph->post("/drives/{$driveId}/items/{$parentItemId}/children", [
            'name' => $name,
            'folder' => new stdClass(),
            '@microsoft.graph.conflictBehavior' => 'rename',
        ]);
        return (string) $created['id'];
    }

    /** Walks/creates a list of nested folder name segments under the master folder, returning the final folder's item id. */
    private function ensurePath(array $segments): string
    {
        $root = $this->resolveMasterFolder();
        $itemId = $root['itemId'];
        foreach (array_filter($segments, static fn($s) => $s !== '') as $segment) {
            $itemId = $this->ensureFolder($root['driveId'], $itemId, $segment);
        }
        return $itemId;
    }

    private function rootFolderSegments(): array
    {
        return explode('/', trim((string) config('onedrive.root_folder'), '/'));
    }

    /**
     * Uploads an exam paper or mark scheme PDF into {root}/Papers/{paperId}/.
     */
    public function uploadPaperPdf(int $paperId, string $filename, string $binaryContent): string
    {
        $folderId = $this->ensurePath(array_merge($this->rootFolderSegments(), ['Papers', (string) $paperId]));
        $root = $this->resolveMasterFolder();
        $path = "/drives/{$root['driveId']}/items/{$folderId}:/" . rawurlencode($filename) . ':/content';
        $result = $this->graph->putBinary($path, $binaryContent, 'application/pdf');
        return (string) $result['id'];
    }

    /**
     * Uploads a scanned student script using the required folder convention:
     * {root}/{PaperID}/{StudentID}.pdf
     */
    public function uploadScannedScript(int $paperId, int $studentId, string $binaryContent, string $extension = 'pdf'): string
    {
        $folderId = $this->ensurePath(array_merge($this->rootFolderSegments(), [(string) $paperId]));
        $root = $this->resolveMasterFolder();
        $filename = "{$studentId}.{$extension}";
        $path = "/drives/{$root['driveId']}/items/{$folderId}:/" . rawurlencode($filename) . ':/content';
        $contentType = $extension === 'pdf' ? 'application/pdf' : 'image/' . $extension;
        $result = $this->graph->putBinary($path, $binaryContent, $contentType);
        return (string) $result['id'];
    }

    /** Streams file bytes by drive item id for the backend proxy to relay to the browser. */
    public function downloadById(string $driveItemId): string
    {
        $root = $this->resolveMasterFolder();
        return $this->graph->getBinary("/drives/{$root['driveId']}/items/{$driveItemId}/content");
    }

    public function metadata(string $driveItemId): array
    {
        $root = $this->resolveMasterFolder();
        return $this->graph->get("/drives/{$root['driveId']}/items/{$driveItemId}");
    }

    /** Diagnostic for Admin > OneDrive setup: resolves the configured sharing link and reports basic info about it, or throws with the actual Graph error. */
    public function testMasterFolder(): array
    {
        $root = $this->resolveMasterFolder();
        $meta = $this->graph->get("/drives/{$root['driveId']}/items/{$root['itemId']}", ['$select' => 'name,webUrl']);
        return [
            'driveId' => $root['driveId'],
            'itemId' => $root['itemId'],
            'name' => $meta['name'] ?? '(unknown)',
            'webUrl' => $meta['webUrl'] ?? null,
        ];
    }

    /** Writes back an annotated/flattened PDF, replacing the stored version. */
    public function replaceContent(string $driveItemId, string $binaryContent): void
    {
        $root = $this->resolveMasterFolder();
        $this->graph->putBinary("/drives/{$root['driveId']}/items/{$driveItemId}/content", $binaryContent, 'application/pdf');
    }
}
