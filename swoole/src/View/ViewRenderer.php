<?php
/**
 * ViewRenderer - Native view rendering for OpenSwoole
 *
 * Renders PHP templates with layout support, similar to FlightPHP's render system
 */

namespace Tiknix\Swoole\View;

/**
 * Flight compatibility class for templates that use Flight::get()
 * This provides a minimal mock of Flight for template compatibility
 */
class FlightMock
{
    private static array $data = [];

    public static function set(string $key, mixed $value): void
    {
        self::$data[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    public static function setAll(array $data): void
    {
        self::$data = $data;
    }

    public static function clear(): void
    {
        self::$data = [];
    }
}

class ViewRenderer
{
    private string $viewsPath;
    private array $globalData = [];

    public function __construct(string $viewsPath = null)
    {
        $this->viewsPath = $viewsPath ?? BASE_PATH . '/views';
    }

    /**
     * Set global data available to all views
     */
    public function setGlobal(string $key, mixed $value): void
    {
        $this->globalData[$key] = $value;
    }

    /**
     * Set multiple global values
     */
    public function setGlobals(array $data): void
    {
        $this->globalData = array_merge($this->globalData, $data);
    }

    /**
     * Render a view template
     */
    public function render(string $template, array $data = []): string
    {
        $filePath = $this->resolveTemplatePath($template);

        if (!file_exists($filePath)) {
            throw new \RuntimeException("View template not found: {$template} ({$filePath})");
        }

        // Merge global data with local data
        $data = array_merge($this->globalData, $data);

        return $this->renderFile($filePath, $data);
    }

    /**
     * Render with layout (header/body/footer sandwich)
     */
    public function renderWithLayout(string $template, array $data = []): string
    {
        // Merge global data
        $data = array_merge($this->globalData, $data);

        // Render header
        $headerContent = $this->renderFile(
            $this->resolveTemplatePath('layouts/header'),
            $data
        );

        // Render body (the main template)
        $bodyContent = $this->renderFile(
            $this->resolveTemplatePath($template),
            $data
        );

        // Render footer
        $footerContent = $this->renderFile(
            $this->resolveTemplatePath('layouts/footer'),
            $data
        );

        // Render the main layout with all parts
        return $this->renderFile(
            $this->resolveTemplatePath('layouts/layout'),
            array_merge($data, [
                'header_content' => $headerContent,
                'body_content' => $bodyContent,
                'footer_content' => $footerContent,
            ])
        );
    }

    /**
     * Render a partial (no layout)
     */
    public function partial(string $template, array $data = []): string
    {
        return $this->render($template, $data);
    }

    /**
     * Render a file with data using output buffering
     */
    private function renderFile(string $filePath, array $data): string
    {
        // Set up Flight mock for template compatibility
        $this->setupFlightMock($data);

        // Extract data to local variables for the template
        extract($data, EXTR_SKIP);

        ob_start();
        try {
            include $filePath;
            return ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    /**
     * Set up Flight compatibility for template rendering
     *
     * If Flight (FlightPHP) is loaded, sets values on it directly.
     * Otherwise, sets up the FlightMock for standalone usage.
     */
    private function setupFlightMock(array $data): void
    {
        // Determine which class to use for Flight::get() compatibility
        $useReal = class_exists('\\Flight', false);

        $values = [
            'debug' => $data['debug'] ?? false,
            'development' => $data['debug'] ?? false,
            'social.google_client_id' => $data['google_client_id'] ?? '',
            'social.google_client_secret' => $data['google_client_secret'] ?? '',
            'app.name' => $data['site_name'] ?? 'Tiknix',
            'baseurl' => $data['baseurl'] ?? '/',
        ];

        if ($useReal) {
            // Use real Flight class
            foreach ($values as $key => $value) {
                \Flight::set($key, $value);
            }
        } else {
            // Use mock Flight class
            if (!class_exists('Flight', false)) {
                class_alias(FlightMock::class, 'Flight');
            }
            foreach ($values as $key => $value) {
                FlightMock::set($key, $value);
            }
        }
    }

    /**
     * Resolve template name to full file path
     */
    private function resolveTemplatePath(string $template): string
    {
        // Remove leading slash if present
        $template = ltrim($template, '/');

        // Add .php extension if not present
        if (!str_ends_with($template, '.php')) {
            $template .= '.php';
        }

        return $this->viewsPath . '/' . $template;
    }

    /**
     * Check if a template exists
     */
    public function exists(string $template): bool
    {
        return file_exists($this->resolveTemplatePath($template));
    }
}
