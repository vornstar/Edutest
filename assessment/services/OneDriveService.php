<?php
declare(strict_types=1);

require_once __DIR__ . '/GraphAppClient.php';
require_once __DIR__ . '/../config/config.php';

/**
 * Stores/retrieves exam paper PDFs, mark schemes, and scanned student
 * scripts on Microsoft OneDrive via Graph. Callers never receive a raw
 * OneDrive URL - files are only ever streamed back through
 * controllers/FileProxyController.php so access stays gated by our own
 * RBAC checks (see SRS 5.2 and 6.3).
 *
 * Uses GraphAppClient - an app-only (client-credentials) Graph connection,
 * NOT the signed-in user's own delegated token. This is deliberate: with a
 * delegated token, whoever is currently browsing would need their own
 * Microsoft 365 permission on the storage drive/folder just to view a
 * file (which is exactly the "students need read/write on the Teams
 * folder" problem this replaces), defeating the point of proxying access
 * through this app's own RBAC in the first place. With an app-only token,
 * no student or teacher needs any Microsoft permission on the drive at
 * all - see GraphAppClient.php for the one-time Azure AD admin consent
 * this requires.
 */
final class OneDriveService
{
    private GraphAppClient $graph;

    public function __construct()
    {
        $this->graph = new GraphAppClient();
    }

    /**
     * Every file lives in one shared drive (a SharePoint document library
     * or a dedicated shared OneDrive), configured via ONEDRIVE_DRIVE_ID -
     * there is no per-user drive to fall back to with an app-only token.
     */
    private function driveSegment(): string
    {
        $driveId = config('onedrive.drive_id');
        if (!$driveId) {
            throw new RuntimeException(
                'ONEDRIVE_DRIVE_ID is not configured. The assessment platform needs a shared ' .
                'drive id (a SharePoint document library or dedicated shared OneDrive) - see ' .
                'Admin > OneDrive setup, or assessment/config/config.php.'
            );
        }
        return "/drives/{$driveId}";
    }

    /**
     * Uploads an exam paper or mark scheme PDF into /Assessments/Papers/{paperId}/.
     */
    public function uploadPaperPdf(int $paperId, string $filename, string $binaryContent): string
    {
        $folder = config('onedrive.root_folder') . "/Papers/{$paperId}";
        $path = $this->driveSegment() . ":{$folder}/{$filename}:/content";
        $result = $this->graph->putBinary($path, $binaryContent, 'application/pdf');
        return (string) $result['id'];
    }

    /**
     * Uploads a scanned student script using the required folder convention:
     * /Assessments/{PaperID}/{StudentID}.pdf
     */
    public function uploadScannedScript(int $paperId, int $studentId, string $binaryContent, string $extension = 'pdf'): string
    {
        $folder = config('onedrive.root_folder') . "/{$paperId}";
        $path = $this->driveSegment() . ":{$folder}/{$studentId}.{$extension}:/content";
        $contentType = $extension === 'pdf' ? 'application/pdf' : 'image/' . $extension;
        $result = $this->graph->putBinary($path, $binaryContent, $contentType);
        return (string) $result['id'];
    }

    /** Streams file bytes by drive item id for the backend proxy to relay to the browser. */
    public function downloadById(string $driveItemId): string
    {
        return $this->graph->getBinary($this->driveSegment() . "/items/{$driveItemId}/content");
    }

    public function metadata(string $driveItemId): array
    {
        return $this->graph->get($this->driveSegment() . "/items/{$driveItemId}");
    }

    /** Writes back an annotated/flattened PDF, replacing the stored version. */
    public function replaceContent(string $driveItemId, string $binaryContent): void
    {
        $this->graph->putBinary($this->driveSegment() . "/items/{$driveItemId}/content", $binaryContent, 'application/pdf');
    }
}
