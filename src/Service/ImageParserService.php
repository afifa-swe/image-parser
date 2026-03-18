<?php

namespace App\Service;

use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ImageParserService
{
    private string $shareDir;
    private ImageManager $imageManager;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $projectDir,
    ) {
        $this->shareDir = $projectDir . '/' . ($_ENV['APP_SHARE_DIR'] ?? 'var/share');
        $this->imageManager = new ImageManager(new Driver());

        if (!is_dir($this->shareDir)) {
            mkdir($this->shareDir, 0777, true);
        }
    }

    public function parseAndSaveImages(string $url, int $minWidth, int $minHeight, string $overlayText): array
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9,ru;q=0.8',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Cache-Control' => 'max-age=0',
            ],
            'verify_peer' => false,
            'verify_host' => false,
            'max_redirects' => 10,
            'timeout' => 30,
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf('Сайт вернул ошибку %d. Возможно, сайт блокирует автоматические запросы или страница не существует.', $statusCode));
        }

        $html = $response->getContent(false);

        if (empty(trim($html))) {
            throw new \RuntimeException('Сайт вернул пустую страницу.');
        }

        $crawler = new Crawler($html, $url);
        $imageUrls = [];

        $crawler->filter('img')->each(function (Crawler $node) use (&$imageUrls, $url) {


            $best = null;
            foreach (['data-original', 'data-src', 'data-lazy-src', 'src'] as $attr) {
                $src = $node->attr($attr);
                if ($src && !str_starts_with(trim($src), 'data:')) {
                    $best = $src;
                    break;
                }
            }

            $srcset = $node->attr('data-srcset') ?? $node->attr('srcset');
            if ($srcset) {
                $this->parseSrcset($srcset, $url, $imageUrls);
            } elseif ($best) {
                $resolved = $this->resolveUrl($best, $url);
                if ($resolved) {
                    $imageUrls[] = $resolved;
                }
            }
        });

        $crawler->filter('picture source')->each(function (Crawler $node) use (&$imageUrls, $url) {
            $srcset = $node->attr('srcset') ?? $node->attr('data-srcset');
            if ($srcset) {
                $this->parseSrcset($srcset, $url, $imageUrls);
            }
        });

        $crawler->filter('a[href]')->each(function (Crawler $node) use (&$imageUrls, $url) {
            $href = $node->attr('href');
            if ($href && preg_match('/\.(jpe?g|png|gif|webp|bmp|svg)(\?|$)/i', $href)) {
                $resolved = $this->resolveUrl($href, $url);
                if ($resolved) {
                    $imageUrls[] = $resolved;
                }
            }
        });

        // Extract background-image URLs from style attributes
        $crawler->filter('[style]')->each(function (Crawler $node) use (&$imageUrls, $url) {
            $style = $node->attr('style');
            if ($style && preg_match_all('/background(?:-image)?\s*:\s*[^;]*url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', $style, $matches)) {
                foreach ($matches[1] as $src) {
                    if (!str_starts_with(trim($src), 'data:')) {
                        $resolved = $this->resolveUrl($src, $url);
                        if ($resolved) {
                            $imageUrls[] = $resolved;
                        }
                    }
                }
            }
        });

        $crawler->filter('meta[property="og:image"], meta[name="twitter:image"]')->each(function (Crawler $node) use (&$imageUrls, $url) {
            $content = $node->attr('content');
            if ($content) {
                $resolved = $this->resolveUrl($content, $url);
                if ($resolved) {
                    $imageUrls[] = $resolved;
                }
            }
        });

        $crawler->filter('video[poster]')->each(function (Crawler $node) use (&$imageUrls, $url) {
            $poster = $node->attr('poster');
            if ($poster && !str_starts_with(trim($poster), 'data:')) {
                $resolved = $this->resolveUrl($poster, $url);
                if ($resolved) {
                    $imageUrls[] = $resolved;
                }
            }
        });

        $imageUrls = array_unique($imageUrls);
        $savedImages = [];
        $seenHashes = [];

        foreach ($imageUrls as $imageUrl) {
            try {
                $result = $this->downloadAndProcessImage($imageUrl, $minWidth, $minHeight, $overlayText, $seenHashes);
                if ($result) {
                    $savedImages[] = $result;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $savedImages;
    }

    private function parseSrcset(string $srcset, string $baseUrl, array &$imageUrls): void
    {
        $parts = preg_split('/\s*,\s*/', $srcset);
        $best = null;
        $bestSize = 0;

        foreach ($parts as $part) {
            $tokens = preg_split('/\s+/', trim($part));
            if (empty($tokens[0])) {
                continue;
            }

            $size = 1;
            if (isset($tokens[1])) {
                if (preg_match('/^([\d.]+)[wx]$/i', $tokens[1], $m)) {
                    $size = (float) $m[1];
                }
            }

            if ($size >= $bestSize) {
                $bestSize = $size;
                $best = $tokens[0];
            }
        }

        if ($best) {
            $resolved = $this->resolveUrl($best, $baseUrl);
            if ($resolved) {
                $imageUrls[] = $resolved;
            }
        }
    }

    private function downloadAndProcessImage(string $imageUrl, int $minWidth, int $minHeight, string $overlayText, array &$seenHashes): ?string
    {
        $parsedImg = parse_url($imageUrl);
        $referer = ($parsedImg['scheme'] ?? 'https') . '://' . ($parsedImg['host'] ?? '') . '/';

        $response = $this->httpClient->request('GET', $imageUrl, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => $referer,
                'Sec-Fetch-Dest' => 'image',
                'Sec-Fetch-Mode' => 'no-cors',
                'Sec-Fetch-Site' => 'same-origin',
            ],
            'verify_peer' => false,
            'verify_host' => false,
            'timeout' => 20,
            'max_redirects' => 10,
        ]);

        $content = $response->getContent(false);
        if (empty($content)) {
            return null;
        }

        $contentHash = md5($content);
        if (isset($seenHashes[$contentHash])) {
            return null;
        }
        $seenHashes[$contentHash] = true;

        $image = $this->imageManager->read($content);

        $origWidth = $image->width();
        $origHeight = $image->height();

        if ($origWidth < $minWidth || $origHeight < $minHeight) {
            return null;
        }

        $image->scaleDown(height: 200);

        $currentWidth = $image->width();
        if ($currentWidth > 200) {
            $cropX = (int)(($currentWidth - 200) / 2);
            $image->crop(200, 200, $cropX, 0);
        } else {
            $image->crop($currentWidth, min(200, $image->height()));
        }

        if (!empty($overlayText)) {
            $image->text($overlayText, $image->width() / 2, $image->height() / 2, function ($font) {
                $font->size(18);
                $font->color('#ffffff');
                $font->align('center');
                $font->valign('middle');
                $font->wrap(180);
                $font->stroke('#000000', 2);
            });
        }

        $filename = md5($imageUrl . time() . random_int(0, 999999)) . '.png';
        $filepath = $this->shareDir . '/' . $filename;

        $image->toPng()->save($filepath);

        return $filename;
    }

    private function resolveUrl(string $src, string $baseUrl): ?string
    {
        $src = trim($src);

        if (str_starts_with($src, 'data:')) {
            return null;
        }

        if (str_starts_with($src, '//')) {
            $parsedBase = parse_url($baseUrl);
            return ($parsedBase['scheme'] ?? 'https') . ':' . $src;
        }

        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src;
        }

        $parsedBase = parse_url($baseUrl);
        $scheme = $parsedBase['scheme'] ?? 'https';
        $host = $parsedBase['host'] ?? '';
        $port = isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '';

        if (str_starts_with($src, '/')) {
            return $scheme . '://' . $host . $port . $src;
        }

        $basePath = $parsedBase['path'] ?? '/';
        $basePath = substr($basePath, 0, strrpos($basePath, '/') + 1);

        return $scheme . '://' . $host . $port . $basePath . $src;
    }

    public function getSavedImages(): array
    {
        if (!is_dir($this->shareDir)) {
            return [];
        }

        $files = glob($this->shareDir . '/*.png');
        $images = [];

        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));

        foreach ($files as $file) {
            $images[] = basename($file);
        }

        return $images;
    }
}
