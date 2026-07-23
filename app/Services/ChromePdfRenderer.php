<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Print HTML to PDF via headless Chrome/Chromium so print CSS and webfonts match the browser.
 */
class ChromePdfRenderer
{
    /**
     * @return string Raw PDF binary
     */
    public function render(string $html): string
    {
        $binary = $this->resolveBinary();
        if ($binary === null) {
            throw new RuntimeException('Chrome/Chromium binary not found for PDF rendering.');
        }

        $htmlPath = tempnam(sys_get_temp_dir(), 'saprf-cert-').'.html';
        $pdfPath = tempnam(sys_get_temp_dir(), 'saprf-cert-').'.pdf';

        try {
            file_put_contents($htmlPath, $html);

            // Drop empty temp.pdf placeholders created by tempnam so Chrome can write cleanly.
            if (is_file($pdfPath)) {
                @unlink($pdfPath);
            }

            $userDataDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'saprf-chrome-'.getmypid();
            if (! is_dir($userDataDir)) {
                mkdir($userDataDir, 0700, true);
            }

            $args = [
                $binary,
                '--headless=new',
                '--disable-gpu',
                '--no-sandbox',
                '--disable-dev-shm-usage',
                '--disable-software-rasterizer',
                '--hide-scrollbars',
                '--no-pdf-header-footer',
                '--virtual-time-budget=10000',
                '--user-data-dir='.$userDataDir,
                '--print-to-pdf='.$pdfPath,
                $this->toFileUri($htmlPath),
            ];

            $result = Process::timeout(60)->run($args);

            // Older Chromium builds (Alpine) may not accept --headless=new.
            if ((! $result->successful() || ! is_file($pdfPath)) && str_contains($result->errorOutput().$result->output(), 'headless')) {
                $args[1] = '--headless';
                $result = Process::timeout(60)->run($args);
            }

            if (! $result->successful() || ! is_file($pdfPath) || filesize($pdfPath) < 100) {
                throw new RuntimeException(
                    'Chrome PDF render failed: '.trim($result->errorOutput() ?: $result->output() ?: 'unknown error')
                );
            }

            $pdf = file_get_contents($pdfPath);
            if ($pdf === false || $pdf === '') {
                throw new RuntimeException('Chrome produced an empty PDF.');
            }

            return $pdf;
        } finally {
            @unlink($htmlPath);
            @unlink($pdfPath);
        }
    }

    public function available(): bool
    {
        return $this->resolveBinary() !== null;
    }

    private function resolveBinary(): ?string
    {
        $configured = config('services.chrome.binary') ?: env('CHROME_PATH');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        $candidates = [
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function toFileUri(string $absolutePath): string
    {
        $normalized = str_replace('\\', '/', $absolutePath);

        if (preg_match('/^[A-Za-z]:\//', $normalized) === 1) {
            return 'file:///'.$normalized;
        }

        return 'file://'.$normalized;
    }
}
