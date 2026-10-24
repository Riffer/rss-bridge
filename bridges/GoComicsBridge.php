<?php

declare(strict_types=1);

class GoComicsBridge extends BridgeAbstract
{
    const MAINTAINER = 'TReKiE';
    //const MAINTAINER = 'sky';
    const NAME = 'GoComics Unofficial RSS';
    const URI = 'https://www.gocomics.com/';
    const CACHE_TIMEOUT = 21600; // 6h
    const CIRCUIT_BREAKER_TIMEOUT = 21600; // 6h
    const DESCRIPTION = 'The Unofficial GoComics RSS';
    const PARAMETERS = [ [
        'comicname' => [
            'name' => 'comicname',
            'type' => 'text',
            'exampleValue' => 'heartofthecity',
            'required' => true
        ],
        'date-in-title' => [
            'name' => 'Add date and full name to each day\'s title',
            'type' => 'checkbox',
            'title' => 'Adds the date and the full name into the title of each day\'s comic',
        ],
        'limit' => [
            'name' => 'Limit',
            'type' => 'number',
            'title' => 'The number of recent comics to get',
            'defaultValue' => 2
        ]
    ]];

    public function collectData()
    {
        $retryAt = $this->loadCacheValue('circuit_breaker');
        if (is_int($retryAt)) {
            throwRateLimitException($this->getCircuitBreakerMessage($retryAt));
        }
        if ($retryAt) {
            throwRateLimitException('GoComics requests are paused after a temporary block. Please retry later.');
        }

        try {
            $this->collectDataInternal();
        } catch (HttpException $e) {
            $this->handleHttpException($e);
        }
    }

    private function collectDataInternal(): void
    {
        $link = $this->getURI();
        $header = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36'
        ];
        $landingpage = getSimpleHTMLDOM($link, $header);

        $element = $landingpage->find('div[data-post-url]', 0);
        $comicFound = false;
        if ($element) {
            $link = $element->getAttribute('data-post-url');
            $comicFound = true;
        } else {
            $conversationNode = $landingpage->find('vf-conversations', 0);
            $conversationId = $conversationNode ? $conversationNode->getAttribute('vf-container-id') : null;

            if ($conversationId !== null) {
                $containerDate = '/^' . preg_quote($this->getInput('comicname'), '/') . '-(\d{4})-(\d{2})-(\d{2})$/';
                if (preg_match($containerDate, $conversationId, $matches)) {
                    $year = $matches[1];
                    $month = $matches[2];
                    $day = $matches[3];
                    $link = sprintf('%s/%s/%s/%s', $link, $year, $month, $day);
                    $comicFound = true;
                }
            }
        }

        if (!$comicFound) { // fallback if both methods failed (assumes daily comic)
            $prevbutton = $landingpage->find('a[class*="ComicNavigation-module-scss-module__"]', 0);

            if (!$prevbutton || empty($prevbutton->href)) {
                throw new \Exception('Could not find the previous comic URL. Please create a new GitHub issue.');
            }
            if (preg_match('/(\d{4}\/\d{2}\/\d{2})/', $prevbutton->href, $nclmatches)) {
                $nextdate = new DateTime($nclmatches[1]);
                $nextdate = $nextdate->modify('+1 day')->format('Y/m/d');
                $link = $link . '/' . $nextdate;
            } else {
                throw new \Exception('Could not parse the previous comic URL. Please create a new GitHub issue.');
            }
        }

        for ($i = 0; $i < $this->getInput('limit'); $i++) {
            $html = getSimpleHTMLDOMCached($link, 86400, $header);

            $imagelink = $html->find('meta[property="og:image"]', 0)->content;

            $title = $html->find('meta[property="og:title"]', 0)->content;
            preg_match('/by (.*?) for/', $title, $authormatches);
            $author = $authormatches[1] ?? 'GoComics';

            $item = [];
            $item['id'] = $imagelink;
            $item['uri'] = $link;
            $item['author'] = $author;
            $item['title'] = 'GoComics ' . $this->getInput('comicname');
            if ($this->getInput('date-in-title') === true) {
                $item['title'] = $title;
            }

            $parts = explode('/', $link);
            $date = DateTime::createFromFormat('Y/m/d', implode('/', array_slice($parts, -3)));
            if ($date) {
                $item['timestamp'] = $date->setTime(0, 0, 0)->getTimestamp();
            }

            $item['content'] = '<img src="' . $imagelink . '" />';

            $this->items[] = $item;

            $button_previous = $html->find('a[class*="__controls__button_previous"]', 0);
            if (! $button_previous) {
                break;
            }
            $link = rtrim(self::URI, '/') . $button_previous->href;
        }
    }

    private function handleHttpException(HttpException $e): void
    {
        if ($e->getCode() !== 403 && $e->getCode() !== 429) {
            throw $e;
        }

        $timeout = $this->getCircuitBreakerTimeout($e);
        $retryAt = time() + $timeout;
        $this->saveCacheValue('circuit_breaker', $retryAt, $timeout);

        if ($e->getCode() === 403) {
            $message = <<<'MSG'
                The server returned 403 Forbidden.
                This is likely GoComics' use of Bunny Shield blocking requests from this bridge. Try reducing your feed update
                frequency, running your own RSS-Bridge instance, or hosting an instance from a different IP/location.
                MSG;
            throw new \Exception($message . ' ' . $this->getCircuitBreakerMessage($retryAt), 403);
        }

        throwRateLimitException('The server returned 429 Too Many Requests. ' . $this->getCircuitBreakerMessage($retryAt));
    }

    private function getCircuitBreakerMessage(int $retryAt): string
    {
        $retryAtUtc = gmdate('Y-m-d H:i:s', $retryAt);
        $remaining = max(0, $retryAt - time());

        return sprintf('GoComics requests are paused until %s UTC (about %d seconds remaining).', $retryAtUtc, $remaining);
    }

    private function getCircuitBreakerTimeout(HttpException $e): int
    {
        if ($e->getCode() !== 429) {
            return self::CIRCUIT_BREAKER_TIMEOUT;
        }

        $retryAfter = $e->response?->getHeader('retry-after');
        if (!is_string($retryAfter)) {
            return self::CIRCUIT_BREAKER_TIMEOUT;
        }

        if (ctype_digit($retryAfter) && (int) $retryAfter > 0) {
            return (int) $retryAfter;
        }

        $retryAt = DateTimeImmutable::createFromFormat(DATE_RFC7231, $retryAfter, new DateTimeZone('GMT'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($retryAt && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            $timeout = $retryAt->getTimestamp() - time();
            if ($timeout > 0) {
                return $timeout;
            }
        }

        return self::CIRCUIT_BREAKER_TIMEOUT;
    }

    public function getURI()
    {
        if (!is_null($this->getInput('comicname'))) {
            return self::URI . urlencode($this->getInput('comicname'));
        }

        return parent::getURI();
    }

    public function getName()
    {
        if (!is_null($this->getInput('comicname'))) {
            return $this->getInput('comicname') . ' - GoComics';
        }

        return parent::getName();
    }
}
