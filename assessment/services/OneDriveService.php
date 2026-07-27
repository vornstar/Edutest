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
 */
final class OneDriveService
{
    private GraphApiClient $graph;

    public function __construct(int $actingUserId)
    {
        $this->graph = new GraphApiClient($actingUserId);
    }

    /**
     * Deliberately does NOT fall back to '/me/drive'. A file a teacher
     * uploads has to later be readable by that student, another marker
     * during moderation, etc. - people whose own delegated token has no
     * access to the uploader's personal drive at all. Every file must
     * therefore live in one shared drive (a SharePoint document library or
     * a dedicated shared OneDrive) that the whole school can reach via
     * ONEDRIVE_DRIVE_ID, or downloads from anyone but the uploader will
     * fail with a 403/404 from Graph.
     */
    private function driveSegment(): string
    {
        $driveId = config('onedrive.drive_id');
        if (!$driveId) {
            throw new RuntimeException(
                'ONEDRIVE_DRIVE_ID is not configured. The assessment platform needs a shared ' .
                'drive id (a SharePoint document library or dedicated shared OneDrive) so files ' .
                'one person uploads can be read by others - see assessment/config/config.php.'
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
