<?php
/**
 * Index Controller - ShipCannon public marketing pages
 */

namespace app;

use \Flight as Flight;

class Index extends BaseControls\Control {

    public function index() {
        $this->render('index/home', [
            'title' => 'CannonWMS by ShipCannon - The Modern Warehouse Management System for eCommerce',
            'description' => 'CannonWMS delivers multi-warehouse inventory management, real-time channel sync, and transparent usage-based pricing.',
            'currentPage' => 'home',
        ]);
    }

    public function pricing() {
        $this->render('index/pricing', [
            'title' => 'Pricing - CannonWMS Usage-Based Warehouse Management Pricing',
            'description' => 'Transparent, usage-based WMS pricing. $450/month base + metered rates. Use our calculator to see your exact cost.',
            'currentPage' => 'pricing',
        ]);
    }

    public function about() {
        $this->render('index/about', [
            'title' => 'About ShipCannon - Our Story and Mission',
            'description' => 'Learn about ShipCannon and the team behind CannonWMS warehouse management system.',
            'currentPage' => 'about',
        ]);
    }

    public function contact() {
        // Handle form submission before rendering
        if (Flight::request()->method === 'POST' && $this->getParam('contact_form_submit')) {
            $this->handleContactForm();
            return;
        }

        $this->render('index/contact', [
            'title' => 'Contact CannonWMS - Get in Touch',
            'description' => 'Contact the CannonWMS team for sales inquiries, support, or partnership opportunities.',
            'currentPage' => 'contact',
        ]);
    }

    private function handleContactForm() {
        $name = $this->sanitize($this->getParam('name', ''));
        $email = $this->sanitize($this->getParam('email', ''));
        $phone = $this->sanitize($this->getParam('phone', ''));
        $company = $this->sanitize($this->getParam('company', ''));
        $orders = $this->sanitize($this->getParam('orders', ''));
        $interest = $this->sanitize($this->getParam('interest', ''));
        $message = $this->sanitize($this->getParam('message', ''));
        $newsletter = $this->getParam('newsletter') ? 'Yes' : 'No';

        // Spam detection
        $honeypot = trim($this->getParam('website_url', ''));
        $bscore = (int)$this->getParam('_bscore', 0);
        $btime = (int)$this->getParam('_btime', 0);
        $isSpam = !empty($honeypot) || $bscore < 30 || $btime < 3;

        $timestamp = date('Y-m-d H:i:s');
        $data = "========================================\n";
        $data .= "Contact Form Submission" . ($isSpam ? " [SPAM - score:{$bscore} time:{$btime}s honeypot:" . ($honeypot ? 'FILLED' : 'empty') . "]" : "") . "\n";
        $data .= "Timestamp: $timestamp\n";
        $data .= "========================================\n";
        $data .= "Name: $name\nEmail: $email\nPhone: $phone\n";
        $data .= "Company: $company\nMonthly Orders: $orders\n";
        $data .= "Interest: $interest\nNewsletter: $newsletter\n";
        $data .= "Message:\n$message\n";
        $data .= "========================================\n\n";

        // Save to file as backup
        $viewsPath = Flight::get('flight.views.path');
        $filePath = $viewsPath . '/index/contact_submissions.txt';
        file_put_contents($filePath, $data, FILE_APPEND | LOCK_EX);

        // Send email via Mailgun (skip for spam)
        if (!$isSpam) {
            $mailgunIni = dirname($viewsPath) . '/../cannonwms/conf/mailgun.ini';
            $mailgunConfig = file_exists($mailgunIni) ? parse_ini_file($mailgunIni) : null;

            if ($mailgunConfig && !empty($mailgunConfig['key'])) {
                $mgDomain = $mailgunConfig['domain'];
                $mgKey = $mailgunConfig['key'];
                $fromEmail = $mailgunConfig['fromEmail'];
                $fromName = $mailgunConfig['fromName'] ?? 'CannonWMS';

                $subject = "ShipCannon Contact: {$name}" . ($company ? " ({$company})" : '');

                $htmlBody = "<h2>New Contact Form Submission</h2>"
                    . "<table style='border-collapse:collapse;font-family:sans-serif;'>"
                    . "<tr><td style='padding:6px 12px;font-weight:bold;'>Name:</td><td style='padding:6px 12px;'>{$name}</td></tr>"
                    . "<tr><td style='padding:6px 12px;font-weight:bold;'>Email:</td><td style='padding:6px 12px;'><a href='mailto:{$email}'>{$email}</a></td></tr>"
                    . "<tr><td style='padding:6px 12px;font-weight:bold;'>Phone:</td><td style='padding:6px 12px;'>{$phone}</td></tr>"
                    . "<tr><td style='padding:6px 12px;font-weight:bold;'>Company:</td><td style='padding:6px 12px;'>{$company}</td></tr>"
                    . "<tr><td style='padding:6px 12px;font-weight:bold;'>Monthly Orders:</td><td style='padding:6px 12px;'>{$orders}</td></tr>"
                    . "<tr><td style='padding:6px 12px;font-weight:bold;'>Interest:</td><td style='padding:6px 12px;'>{$interest}</td></tr>"
                    . "<tr><td style='padding:6px 12px;font-weight:bold;'>Newsletter:</td><td style='padding:6px 12px;'>{$newsletter}</td></tr>"
                    . "</table>"
                    . "<h3>Message</h3>"
                    . "<p>" . nl2br($message) . "</p>"
                    . "<hr><p style='color:#888;font-size:12px;'>Submitted at {$timestamp} from shipcannon.com</p>";

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://api.mailgun.net/v3/{$mgDomain}/messages");
                curl_setopt($ch, CURLOPT_USERPWD, "api:{$mgKey}");
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_POSTFIELDS, [
                    'from' => "{$fromName} <{$fromEmail}>",
                    'to' => 'm.fred@clicksimple.com',
                    'subject' => $subject,
                    'html' => $htmlBody,
                    'h:Reply-To' => $email,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        }

        Flight::redirect('/thank-you');
    }

    public function blog($params = null) {
        // Check if URL has a slug: /blog/some-post-slug
        $path = trim(Flight::request()->url, '/');
        $slug = '';
        if (preg_match('#^blog/([a-z0-9\-]+)$#', $path, $m)) {
            $slug = $m[1];
        }

        // No slug — show listing
        if (empty($slug)) {
            $this->render('index/blog', [
                'title' => 'Blog - CannonWMS Warehouse Management Insights',
                'description' => 'Warehouse management tips, eCommerce fulfillment strategies, and product updates from the CannonWMS team.',
                'currentPage' => 'blog',
            ]);
            return;
        }

        // Individual post
        $postsDir = Flight::get('flight.views.path') . '/index/posts';
        $mdFile = $postsDir . '/' . $slug . '.md';
        if (!file_exists($mdFile)) {
            Flight::notFound();
            return;
        }

        $raw = file_get_contents($mdFile);

        // Parse front matter
        $meta = [];
        $markdown = $raw;
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)/s', $raw, $m)) {
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^(\w[\w_]*)\s*:\s*(.+)$/', trim($line), $kv)) {
                    $val = trim($kv[2]);
                    if (preg_match('/^["\'](.*)["\']\s*$/', $val, $q)) {
                        $val = $q[1];
                    }
                    $meta[$kv[1]] = $val;
                }
            }
            $markdown = $m[2];
        }

        // AI bot detection — serve raw markdown
        $aiCrawlers = ['GPTBot', 'ChatGPT', 'ClaudeBot', 'Anthropic', 'PerplexityBot', 'Google-Extended', 'Bingbot', 'cohere-ai'];
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        foreach ($aiCrawlers as $bot) {
            if (stripos($userAgent, $bot) !== false) {
                header('Content-Type: text/markdown; charset=utf-8');
                header('X-Robots-Tag: index, follow');
                echo $raw;
                return;
            }
        }

        $htmlContent = $this->mdToHtml($markdown);

        $this->render('index/post', [
            'title' => ($meta['title'] ?? $slug) . ' - CannonWMS Blog',
            'description' => $meta['description'] ?? '',
            'canonical' => '/blog/' . $slug,
            'currentPage' => 'blog',
            'slug' => $slug,
            'meta' => $meta,
            'htmlContent' => $htmlContent,
        ]);
    }

    /**
     * Simple Markdown to HTML converter
     */
    private function mdToHtml(string $md): string {
        $lines = explode("\n", $md);
        $html = '';
        $inCodeBlock = false;
        $codeBuffer = '';
        $codeLang = '';
        $inList = false;
        $listType = '';
        $inBlockquote = false;
        $inTable = false;
        $tableHeader = false;

        foreach ($lines as $line) {
            if (preg_match('/^```(\w*)/', $line, $cm)) {
                if ($inCodeBlock) {
                    $html .= '<pre><code' . ($codeLang ? ' class="language-' . $codeLang . '"' : '') . '>' . htmlspecialchars($codeBuffer) . '</code></pre>' . "\n";
                    $codeBuffer = '';
                    $codeLang = '';
                    $inCodeBlock = false;
                } else {
                    if ($inList) { $html .= "</{$listType}>\n"; $inList = false; }
                    if ($inBlockquote) { $html .= "</blockquote>\n"; $inBlockquote = false; }
                    $inCodeBlock = true;
                    $codeLang = $cm[1];
                }
                continue;
            }
            if ($inCodeBlock) {
                $codeBuffer .= $line . "\n";
                continue;
            }

            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($inList) { $html .= "</{$listType}>\n"; $inList = false; }
                if ($inBlockquote) { $html .= "</blockquote>\n"; $inBlockquote = false; }
                continue;
            }

            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
                if ($inList) { $html .= "</{$listType}>\n"; $inList = false; }
                $html .= "<hr>\n";
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $hm)) {
                if ($inList) { $html .= "</{$listType}>\n"; $inList = false; }
                $level = strlen($hm[1]);
                $html .= "<h{$level}>" . $this->mdInline($hm[2]) . "</h{$level}>\n";
                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $trimmed, $bq)) {
                if ($inList) { $html .= "</{$listType}>\n"; $inList = false; }
                if (!$inBlockquote) {
                    $html .= "<blockquote>\n";
                    $inBlockquote = true;
                }
                $html .= '<p>' . $this->mdInline($bq[1]) . "</p>\n";
                continue;
            }
            if ($inBlockquote) {
                $html .= "</blockquote>\n";
                $inBlockquote = false;
            }

            if (preg_match('/^\|(.+)\|$/', $trimmed)) {
                if (preg_match('/^\|[\s\-\|:]+\|$/', $trimmed)) {
                    $tableHeader = true;
                    continue;
                }
                if ($inList) { $html .= "</{$listType}>\n"; $inList = false; }
                if (!$inTable) {
                    $html .= "<div class=\"table-responsive\"><table class=\"blog-table\">\n";
                    $inTable = true;
                    $tableHeader = false;
                    $cells = array_map('trim', explode('|', trim($trimmed, '|')));
                    $html .= "<thead><tr>\n";
                    foreach ($cells as $cell) {
                        $html .= '<th>' . $this->mdInline($cell) . "</th>\n";
                    }
                    $html .= "</tr></thead>\n<tbody>\n";
                    continue;
                }
                $cells = array_map('trim', explode('|', trim($trimmed, '|')));
                $html .= "<tr>\n";
                foreach ($cells as $cell) {
                    $html .= '<td>' . $this->mdInline($cell) . "</td>\n";
                }
                $html .= "</tr>\n";
                continue;
            }
            if ($inTable) { $html .= "</tbody></table></div>\n"; $inTable = false; }

            if (preg_match('/^[\-\*]\s+(.+)$/', $trimmed, $ul)) {
                if ($inList && $listType !== 'ul') { $html .= "</{$listType}>\n"; $inList = false; }
                if (!$inList) { $html .= "<ul>\n"; $inList = true; $listType = 'ul'; }
                $html .= '<li>' . $this->mdInline($ul[1]) . "</li>\n";
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $trimmed, $ol)) {
                if ($inList && $listType !== 'ol') { $html .= "</{$listType}>\n"; $inList = false; }
                if (!$inList) { $html .= "<ol>\n"; $inList = true; $listType = 'ol'; }
                $html .= '<li>' . $this->mdInline($ol[1]) . "</li>\n";
                continue;
            }

            if ($inList) { $html .= "</{$listType}>\n"; $inList = false; }

            $html .= '<p>' . $this->mdInline($trimmed) . "</p>\n";
        }

        if ($inCodeBlock) {
            $html .= '<pre><code>' . htmlspecialchars($codeBuffer) . '</code></pre>' . "\n";
        }
        if ($inList) { $html .= "</{$listType}>\n"; }
        if ($inBlockquote) { $html .= "</blockquote>\n"; }
        if ($inTable) { $html .= "</tbody></table></div>\n"; }

        return $html;
    }

    private function mdInline(string $text): string {
        $text = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1" style="max-width:100%;border-radius:8px;margin:1rem 0;">', $text);
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" style="color:var(--primary-color);">$1</a>', $text);
        $text = preg_replace('/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/`([^`]+)`/', '<code style="background:var(--dark-color);padding:2px 6px;border-radius:4px;font-size:0.9em;">$1</code>', $text);
        return $text;
    }

    public function casestudyLinentablecloth() {
        $this->render('index/casestudy-linentablecloth', [
            'title' => 'Case Study: How Linentablecloth.com Saved $180,000 Annually with CannonWMS',
            'description' => 'Discover how Internet Retailer 500 company Linentablecloth.com transformed their warehouse operations and saved $180,000 annually after switching to CannonWMS.',
            'currentPage' => 'casestudy',
        ]);
    }

    public function blogeditor() {
        if (!$this->requireLevel(LEVELS['ADMIN'])) return;

        $postsDir = Flight::get('flight.views.path') . '/index/posts';
        $action = $this->getParam('action', 'list');
        $message = '';

        // Handle POST
        if (Flight::request()->method === 'POST') {
            if (!$this->validateCSRF()) return;

            $slug = preg_replace('/[^a-z0-9\-]/', '', $this->getParam('slug', ''));

            if ($action === 'save' && !empty($slug)) {
                $content = $this->getParam('content', '');
                $content = str_replace("\r\n", "\n", $content);
                file_put_contents($postsDir . '/' . $slug . '.md', $content);
                $message = 'Post saved: ' . $slug;
                $action = 'list';
            }

            if ($action === 'delete' && !empty($slug)) {
                $filePath = $postsDir . '/' . $slug . '.md';
                if (file_exists($filePath)) {
                    unlink($filePath);
                    $message = 'Post deleted: ' . $slug;
                }
                $action = 'list';
            }
        }

        // Load post for editing
        $editSlug = $this->getParam('edit', '');
        $editContent = '';
        if (!empty($editSlug)) {
            $editFile = $postsDir . '/' . preg_replace('/[^a-z0-9\-]/', '', $editSlug) . '.md';
            if (file_exists($editFile)) {
                $editContent = file_get_contents($editFile);
                $action = 'edit';
            }
        }

        // List posts
        $posts = [];
        if (is_dir($postsDir)) {
            foreach (glob($postsDir . '/*.md') as $file) {
                $raw = file_get_contents($file);
                $meta = ['slug' => basename($file, '.md')];
                if (preg_match('/^---\s*\n(.*?)\n---\s*\n/s', $raw, $m)) {
                    foreach (explode("\n", $m[1]) as $line) {
                        if (preg_match('/^(\w[\w_]*)\s*:\s*(.+)$/', trim($line), $kv)) {
                            $val = trim($kv[2]);
                            if (preg_match('/^["\'](.*)["\']\s*$/', $val, $q)) $val = $q[1];
                            $meta[$kv[1]] = $val;
                        }
                    }
                }
                $posts[] = $meta;
            }
            usort($posts, fn($a, $b) => strcmp($b['date'] ?? '', $a['date'] ?? ''));
        }

        $this->render('index/blogeditor', [
            'title' => 'Blog Editor - ShipCannon',
            'action' => $action,
            'posts' => $posts,
            'editSlug' => $editSlug,
            'editContent' => $editContent,
            'message' => $message,
        ]);
    }

    public function getstarted() {
        Flight::redirect('https://setup.cannonwms.com/signup/');
    }

    public function thankyou() {
        $this->render('index/thankyou', [
            'title' => 'Thank You - ShipCannon',
            'description' => 'Thank you for contacting ShipCannon.',
            'currentPage' => 'contact',
        ]);
    }

    public function privacy() {
        $this->render('index/privacy', [
            'title' => 'Privacy Policy - ShipCannon',
            'description' => 'ShipCannon privacy policy.',
            'currentPage' => 'privacy',
        ]);
    }

    public function terms() {
        $this->render('index/terms', [
            'title' => 'Terms of Service - ShipCannon',
            'description' => 'ShipCannon terms of service.',
            'currentPage' => 'terms',
        ]);
    }
}
