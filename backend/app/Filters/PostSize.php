<?php

namespace App\Filters;

use App\Libraries\Http;
use App\Libraries\Text;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * When an upload is bigger than PHP's post_max_size, PHP throws away every field and photo without a word.
 * Say so instead (413), like Laravel's ValidatePostSize.
 */
class PostSize implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $max    = Text::iniBytes((string) ini_get('post_max_size'));
        $length = (int) $request->getServer('CONTENT_LENGTH');

        if ($max > 0 && $length > $max) {
            return Http::earlyError(Http::messageFor(413), 413);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
