<?php
namespace app\mcptools\workbench;

use app\mcptools\BaseTool;
use \app\Bean;

class UploadScreenshotTool extends BaseTool {

    public static string $name = 'upload_screenshot';

    public static string $description = 'Upload a screenshot to a task. You can either provide a file path to an existing screenshot (from browser_take_screenshot) or base64-encoded image data. The screenshot will be attached to a comment visible to the user.';

    public static array $inputSchema = [
        'type' => 'object',
        'properties' => [
            'task_id' => [
                'type' => 'integer',
                'description' => 'The task ID'
            ],
            'file_path' => [
                'type' => 'string',
                'description' => 'Path to the screenshot file (from browser_take_screenshot or other source)'
            ],
            'base64_data' => [
                'type' => 'string',
                'description' => 'Base64-encoded image data (alternative to file_path)'
            ],
            'caption' => [
                'type' => 'string',
                'description' => 'Optional caption or description for the screenshot'
            ],
            'image_type' => [
                'type' => 'string',
                'description' => 'Image type when using base64_data (png, jpeg, gif). Defaults to png.',
                'enum' => ['png', 'jpeg', 'jpg', 'gif']
            ]
        ],
        'required' => ['task_id']
    ];

    public function execute(array $args): string {
        if (!$this->member) {
            throw new \Exception("Authentication required");
        }

        $taskId = (int)($args['task_id'] ?? 0);
        if (!$taskId) {
            throw new \Exception("task_id is required");
        }

        $task = Bean::load('workbenchtask', $taskId);
        if (!$task->id) {
            throw new \Exception("Task not found: {$taskId}");
        }

        $accessControl = new \app\TaskAccessControl();
        if (!$accessControl->canEdit((int)$this->member->id, $task)) {
            throw new \Exception("No permission to update task {$taskId}");
        }

        $filePath = $args['file_path'] ?? null;
        $base64Data = $args['base64_data'] ?? null;
        $caption = trim($args['caption'] ?? '');
        $imageType = $args['image_type'] ?? 'png';

        // Must provide either file_path or base64_data
        if (empty($filePath) && empty($base64Data)) {
            throw new \Exception("Either file_path or base64_data is required");
        }

        // Ensure uploads directory exists
        $projectRoot = defined('PROJECT_ROOT') ? PROJECT_ROOT : dirname(dirname(dirname(__DIR__)));
        $uploadsDir = $projectRoot . '/uploads/workbench/' . $taskId;
        if (!is_dir($uploadsDir)) {
            if (!mkdir($uploadsDir, 0755, true)) {
                throw new \Exception("Failed to create uploads directory");
            }
        }

        $imageData = null;
        $extension = $imageType === 'jpg' ? 'jpeg' : $imageType;

        if ($filePath) {
            // Handle file path - could be absolute or relative to workspace
            $actualPath = $filePath;

            // If relative, try various locations in workspace
            if (!str_starts_with($filePath, '/') && !empty($task->projectPath)) {
                $workspaceRoot = rtrim($task->projectPath, '/');

                // Try locations in order of likelihood:
                $possiblePaths = [
                    $workspaceRoot . '/' . $filePath,                           // Direct relative path
                    $workspaceRoot . '/.playwright-mcp/' . $filePath,           // Playwright MCP output dir
                    $workspaceRoot . '/.playwright-mcp/' . basename($filePath), // Just filename in playwright dir
                ];

                foreach ($possiblePaths as $tryPath) {
                    if (file_exists($tryPath)) {
                        $actualPath = $tryPath;
                        break;
                    }
                }
            }

            if (!file_exists($actualPath)) {
                throw new \Exception("Screenshot file not found: {$filePath}");
            }

            $imageData = file_get_contents($actualPath);
            if ($imageData === false) {
                throw new \Exception("Failed to read screenshot file: {$filePath}");
            }

            // Detect extension from file
            $pathInfo = pathinfo($actualPath);
            if (!empty($pathInfo['extension'])) {
                $extension = strtolower($pathInfo['extension']);
                if ($extension === 'jpg') $extension = 'jpeg';
            }

        } else {
            // Handle base64 data
            // Remove data URL prefix if present (data:image/png;base64,...)
            $base64Data = preg_replace('#^data:image/[a-z]+;base64,#i', '', $base64Data);
            $imageData = base64_decode($base64Data, true);

            if ($imageData === false) {
                throw new \Exception("Invalid base64 image data");
            }
        }

        // Validate it's actually an image
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageData);

        $allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        if (!in_array($mimeType, $allowedTypes)) {
            throw new \Exception("Invalid image type: {$mimeType}. Allowed: png, jpeg, gif, webp");
        }

        // Map mime to extension
        $mimeToExt = [
            'image/png' => 'png',
            'image/jpeg' => 'jpeg',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        $extension = $mimeToExt[$mimeType] ?? 'png';

        // Generate unique filename
        $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $savePath = $uploadsDir . '/' . $filename;

        if (file_put_contents($savePath, $imageData) === false) {
            throw new \Exception("Failed to save screenshot");
        }

        // Store relative path for database (from project root)
        $relativePath = 'uploads/workbench/' . $taskId . '/' . $filename;

        // Build comment message
        $message = "**Screenshot from Claude:**";
        if (!empty($caption)) {
            $message .= "\n\n" . $caption;
        }

        // Create comment with image
        $comment = Bean::dispense('taskcomment');
        $comment->taskId = $taskId;
        $comment->memberId = $this->member->id;
        $comment->content = $message;
        $comment->imagePath = $relativePath;
        $comment->isFromClaude = 1;
        $comment->isInternal = 0;
        $comment->createdAt = date('Y-m-d H:i:s');
        Bean::store($comment);

        // Log the upload
        $log = Bean::dispense('tasklog');
        $log->taskId = $taskId;
        $log->memberId = $this->member->id;
        $log->logLevel = 'info';
        $log->logType = 'screenshot';
        $log->message = 'Claude uploaded screenshot' . ($caption ? ": {$caption}" : '');
        $log->createdAt = date('Y-m-d H:i:s');
        Bean::store($log);

        return json_encode([
            'success' => true,
            'task_id' => $taskId,
            'comment_id' => $comment->id,
            'image_path' => $relativePath,
            'message' => 'Screenshot uploaded successfully'
        ], JSON_PRETTY_PRINT);
    }
}
