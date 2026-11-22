<?php

namespace App\Controllers;

use App\Libraries\Catalog;
use App\Libraries\Http;
use App\Libraries\Text;
use CodeIgniter\Controller;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Florist;

/**
 * Product links for WhatsApp: GET /p/<slug> and GET /p/<slug>/photo.jpg.
 *
 * The website is a React app, so a product page's HTML carries no product details and WhatsApp's link preview
 * would show the generic home-page picture. The order message links here instead: this page has the product's
 * name, price and photo in its Open Graph tags (so the chat shows the bouquet), and it sends people who open it
 * on to the product page on the website.
 */
class Share extends Controller
{
    /** Longest side of the preview photo. WhatsApp shows previews small; this keeps the JPEG well under 300 KB. */
    private const PHOTO_SIZE = 800;

    /** The link put in the order message for a product. */
    public static function url(string $slug): string
    {
        return rtrim(config('App')->baseURL, '/') . '/p/' . rawurlencode($slug);
    }

    /** GET /p/<slug> */
    public function product(string $slug): ResponseInterface
    {
        $florist = config(Florist::class);
        $site    = rtrim($florist->siteUrl, '/');
        $product = $this->find($slug);
        if ($product === null) {
            return Http::redirect($site . '/shop');
        }

        $target = $site . '/product/' . rawurlencode($product['slug']);
        $p      = $product['pricing'];
        $price  = $p['on_request'] ? 'Ask for price' : Text::rupees($p['final']);
        $e      = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $title  = $product['name'] . ' · City Florist Hubballi';
        $desc   = $product['category']['name'] . ' · ' . $price;

        $image = '';
        if ($this->photoFile($product['id']) !== null) {
            $photo = self::url($product['slug']) . '/photo.jpg';
            $image = '<meta property="og:image" content="' . $e($photo) . '">' . "\n"
                . '<meta property="og:image:type" content="image/jpeg">' . "\n"
                . '<meta name="twitter:card" content="summary_large_image">' . "\n";
        }

        $html = '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>' . $e($title) . '</title>
<meta property="og:type" content="product">
<meta property="og:site_name" content="City Florist Hubballi">
<meta property="og:title" content="' . $e($title) . '">
<meta property="og:description" content="' . $e($desc) . '">
<meta property="og:url" content="' . $e($target) . '">
' . $image . '<link rel="canonical" href="' . $e($target) . '">
<meta http-equiv="refresh" content="0; url=' . $e($target) . '">
</head>
<body>
<p><a href="' . $e($target) . '">' . $e($product['name']) . '</a></p>
<script>location.replace(' . json_encode($target, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ');</script>
</body>
</html>
';

        return Http::cacheControl($this->response, 'max-age=300, public')
            ->setStatusCode(200)
            ->setContentType('text/html', 'utf-8')
            ->setBody($html);
    }

    /** GET /p/<slug>/photo.jpg : the product's first photo as a JPEG, which every WhatsApp version can preview. */
    public function photo(string $slug): ResponseInterface
    {
        $product = $this->find($slug);
        $file    = $product === null ? null : $this->photoFile($product['id']);
        if ($file === null || ! function_exists('imagecreatefromwebp')) {
            return $this->notFound();
        }

        $dir = WRITEPATH . 'cache' . DIRECTORY_SEPARATOR . 'share';
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $cached = $dir . DIRECTORY_SEPARATOR . md5($file . '|' . filemtime($file) . '|' . self::PHOTO_SIZE) . '.jpg';

        if (! is_file($cached)) {
            $jpeg = $this->toJpeg($file);
            if ($jpeg === null) {
                return $this->notFound();
            }
            @file_put_contents($cached, $jpeg);
        } else {
            $jpeg = (string) file_get_contents($cached);
        }

        return Http::cacheControl($this->response, 'max-age=86400, public')
            ->setStatusCode(200)
            ->setHeader('Content-Type', 'image/jpeg')
            ->setBody($jpeg);
    }

    /** A live product by slug, as the public catalogue shows it, or null. */
    private function find(string $slug): ?array
    {
        $found = Catalog::products(static fn (BaseBuilder $q) => $q->where('p.slug', $slug), ['p.id'], 1);

        return $found[0] ?? null;
    }

    /** The product's first photo on disk (inside <publicPath>/uploads), or null. */
    private function photoFile(int $productId): ?string
    {
        $row = db_connect()->table('product_images')->where('product_id', $productId)->orderBy('sort')->orderBy('id')->get(1)->getRowArray();
        if ($row === null) {
            return null;
        }
        $root    = realpath(rtrim(config(Florist::class)->publicPath, '/\\') . DIRECTORY_SEPARATOR . 'uploads');
        $file    = realpath(rtrim(config(Florist::class)->publicPath, '/\\') . DIRECTORY_SEPARATOR . ltrim((string) $row['path'], '/\\'));
        $inside  = $root !== false && $file !== false && str_starts_with($file, $root . DIRECTORY_SEPARATOR);

        return $inside && is_file($file) ? $file : null;
    }

    private function toJpeg(string $file): ?string
    {
        $src = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'webp'        => @imagecreatefromwebp($file),
            'jpg', 'jpeg' => @imagecreatefromjpeg($file),
            'png'         => @imagecreatefrompng($file),
            default       => false,
        };
        if ($src === false) {
            return null;
        }
        $w     = imagesx($src);
        $h     = imagesy($src);
        $scale = min(1, self::PHOTO_SIZE / max($w, $h));
        $dw    = max(1, (int) round($w * $scale));
        $dh    = max(1, (int) round($h * $scale));
        $out   = imagecreatetruecolor($dw, $dh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // transparent areas → white, not black
        imagecopyresampled($out, $src, 0, 0, 0, 0, $dw, $dh, $w, $h);
        ob_start();
        imagejpeg($out, null, 82);

        return (string) ob_get_clean();
    }

    private function notFound(): ResponseInterface
    {
        return Http::cacheControl($this->response, Http::NO_CACHE)
            ->setStatusCode(404)
            ->setContentType('text/plain', 'utf-8')
            ->setBody("Photo not found.\n");
    }
}
