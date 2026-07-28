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

    /**
     * Uploads a PDF into {root}/Inbox/ - a holding area for bulk-uploaded
     * exam papers/mark schemes that haven't been attached to a specific
     * paper yet (see PaperController::inboxUpload/attachInbox). Auto-
     * disambiguates the filename against what's already sitting in Inbox so
     * two unrelated uploads of e.g. "markscheme.pdf" never overwrite each
     * other before either gets attached.
     */
    public function uploadToInbox(string $filename, string $binaryContent): string
    {
        $root = $this->resolveMasterFolder();
        $folderId = $this->ensurePath(array_merge($this->rootFolderSegments(), ['Inbox']));
        $uniqueName = $this->uniqueChildName($root['driveId'], $folderId, $filename);
        $path = "/drives/{$root['driveId']}/items/{$folderId}:/" . rawurlencode($uniqueName) . ':/content';
        $result = $this->graph->putBinary($path, $binaryContent, 'application/pdf');
        return (string) $result['id'];
    }

    /** Every real file (not folder) currently sitting in the Inbox, most recently modified first. */
    public function listInbox(): array
    {
        $root = $this->resolveMasterFolder();
        $folderId = $this->ensurePath(array_merge($this->rootFolderSegments(), ['Inbox']));
        $children = $this->graph->getAll("/drives/{$root['driveId']}/items/{$folderId}/children", [
            '$select' => 'id,name,size,lastModifiedDateTime,file',
        ]);
        $files = array_values(array_filter($children, static fn(array $c): bool => isset($c['file'])));
        usort($files, static fn(array $a, array $b): int => strcmp((string) $b['lastModifiedDateTime'], (string) $a['lastModifiedDateTime']));
        return $files;
    }

    /**
     * Moves+renames an Inbox file into {root}/Papers/{paperId}/{filename} -
     * i.e. "attaching" it, per PaperController::attachInbox(). Replaces
     * whatever already occupies that slot (same as uploading a fresh file
     * there directly via uploadPaperPdf), so re-attaching just swaps it out.
     */
    public function attachInboxItem(string $driveItemId, int $paperId, string $filename): string
    {
        $folderId = $this->ensurePath(array_merge($this->rootFolderSegments(), ['Papers', (string) $paperId]));
        $root = $this->resolveMasterFolder();
        $result = $this->graph->patch("/drives/{$root['driveId']}/items/{$driveItemId}", [
            'name' => $filename,
            'parentReference' => ['id' => $folderId],
            '@microsoft.graph.conflictBehavior' => 'replace',
        ]);
        return (string) ($result['id'] ?? $driveItemId);
    }

    /** Discards an unwanted Inbox upload outright (not a soft delete - it was never attached to anything, so there's nothing to preserve). */
    public function deleteInboxItem(string $driveItemId): void
    {
        $root = $this->resolveMasterFolder();
        $this->graph->delete("/drives/{$root['driveId']}/items/{$driveItemId}");
    }

    /** Appends " (2)", " (3)", ... before the extension until $filename doesn't collide with an existing child of the given folder. */
    private function uniqueChildName(string $driveId, string $folderId, string $filename): string
    {
        $children = $this->graph->getAll("/drives/{$driveId}/items/{$folderId}/children", ['$select' => 'name']);
        $existing = array_map(static fn(array $c): string => strtolower((string) $c['name']), $children);
        if (!in_array(strtolower($filename), $existing, true)) {
            return $filename;
        }

        $info = pathinfo($filename);
        $base = $info['filename'];
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        $n = 2;
        while (in_array(strtolower("{$base} ({$n}){$ext}"), $existing, true)) {
            $n++;
        }
        return "{$base} ({$n}){$ext}";
    }
}
