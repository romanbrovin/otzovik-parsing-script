<?php

// Функция для дебага
function debug($var)
{
    echo '<pre>';
    print_r($var);
    echo '</pre>';
}

class Parser
{

    private string $host = 'https://otzovik.com';

    private $curlProxy;

    // Прокси лист
    private string $curlProxyAuth = 'user:password';
    public array $proxyList = [
        'ip:port',
        'ip:port',
        'ip:port',
        'ip:port',
    ];

    public string $url;
    public string $response;
    public array $responseError;

    public function __construct()
    {
        // Берем первую прокси из списка
        $this->getProxyFromList();
    }

    public function getProxyFromList(): void
    {
        $this->curlProxy = array_shift($this->proxyList);
    }

    public function init($link): void
    {
        $this->url = $this->host . $link;

        $countProxies = count($this->proxyList);

        while ($countProxies > 0) {
            $this->curl();

            // Нет ошибок: прокси работает, ссылка не битая
            if ($this->responseError['number'] === 0) {
                break;
            }

            // Битая ссылка
            if ($this->responseError['number'] === 6) {
                break;
            }

            // Меняем прокси
            $this->getProxyFromList();

            // Задержка перед следующим запросом
            sleep(random_int(1, 3));
        }
    }

    public function curl(): void
    {
        $curlOptions = [
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
            CURLOPT_HTTPHEADER => [
                'Accept: text/xml,application/xml,application/xhtml+xml,text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5',
                'Cache-Control: max-age=0',
                'Connection: keep-alive',
                'Keep-Alive: 300',
                'Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7',
                'Accept-Language: en-us,en;q=0.5',
                'Pragma: ',
            ],
            CURLOPT_REFERER => 'http://www.google.com',
            CURLOPT_ENCODING => 'gzip,deflate',
            CURLOPT_AUTOREFERER => true,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROXY => $this->curlProxy,
            CURLOPT_PROXYUSERPWD => $this->curlProxyAuth,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5,
        ];

        $ch = curl_init($this->url);

        curl_setopt_array($ch, $curlOptions);

        $this->response = curl_exec($ch);

        $this->responseError['number'] = curl_errno($ch);
        $this->responseError['text'] = "Error #{$this->responseError['number']}<br>" . curl_error($ch);

        curl_close($ch);
    }

}

// Пример ссылки для парсинга
$link = '/reviews/myasoet_shop-internet_magazin_myasa/';

$obj = new Parser();
$obj->init($link);

// Если есть ошибки, завершение скрипта
if ($obj->responseError['number'] !== 0) {
    echo $obj->responseError['text'];
    exit;
}


$domHtml = new DOMDocument;
@ $domHtml->loadHTML($obj->response);

// Берем все теги <a>
$node = $domHtml->getElementsByTagName('a');

// Список всех ссылок
$hrefList = [];

for ($i = 0; $i < $node->length; $i++) {
    // Берем аттрибут href
    $hrefList[] = $node->item($i)->getAttribute('href');
}

// Чистый список ссылок для дальнейшего парсинга
$clearLinks = [];

// Убираем лишние ссылки
foreach ($hrefList as $item) {
    if ($item !== '') {
        $item = str_replace('#comments', '', $item);

        // Если есть вхождение, добавляем в чистый список
        if (str_contains($item, 'review_')) {
            $clearLinks[] = $item;
        }
    }
}

// Оставляем только уникальные ссылки
$clearLinks = array_unique($clearLinks);

// Если массив пуст, завершение скрипта
if (!$clearLinks) {
    echo 'Ссылок не найдено :-(';
    exit;
}

// Массив с html целевых страниц
$reviewsHtml = [];

foreach ($clearLinks as $link) {
    $obj = new Parser();
    $obj->init($link);

    if ($obj->responseError['number'] !== 0) {
        echo $obj->responseError['text'];
        exit;
    }

    $reviewsHtml[] .= $obj->response;
}

// Итоговый массив с данными
$data = [];

// Проход циклом по всем спаршенным отзывам
foreach ($reviewsHtml as $key => $item) {
    $dom = new DOMDocument;
    @ $dom->loadHTML($item);
    $xpath = new DOMXpath($dom);

    $element = $xpath->query("//*[@itemprop='datePublished']/@content");
    if ($element->count() > 0) {
        $data[$key]['date'] = $element[0]->nodeValue;

        $element = $xpath->query("//*[@itemprop='author']//*[@itemprop='name']");
        $data[$key]['name'] = $element[0]->nodeValue;

        $element = $xpath->query("//*[@class='review-body description']");
        $data[$key]['description'] = $element[0]->nodeValue;

        $element = $xpath->query("//*[@itemprop='reviewRating']//*[@itemprop='ratingValue']/@content");
        $data[$key]['rating'] = $element[0]->nodeValue;
    }
}


debug($data);
