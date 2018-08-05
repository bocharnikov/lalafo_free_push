<?php

$login = 'lalala@gmail.com';
$pass  = 'lalala123456789';
$lalafo = new Lalafo;

try
{
    $lalafo->pushAll($login, $pass);
}
catch (Exception $ex)
{
    $lalafo->log($ex->getMessage());
    die();
}

class Lalafo
{
    public $wc;

    public function __construct()
    {
        $this->wc = new WebClient;
    }

    public function pushAll($login, $pass)
    {
        $this->log('Recieving csrf-token...');
        if (!$token = $this->getToken())
            throw new Exception('Cant get token');
        $this->log('Token recieved: ' . $token);

        $this->log('Log in...');
        if (!$this->login($login, $pass, $token))
            throw new Exception('Cant log in');
        $this->log('Logged as: ' . $login);

        $this->log('Recieving ads...');
        if (null === $ads = $this->getAds())
            throw new Exception('Cant parse ads');
        $this->log('Ads recieved: ' . $ads->getCount());
        $this->log('Available to push: ' . $ads->getUnpushedCount());

        while (true)
        {
            if (!$ad = $ads->getFirstUnpushed())
            {
                $this->log('No ads to push');
                break;
            }

            $this->log('Pushing ad: ' . $ad->title . ' ...');
            if (null === $ads = $this->pushAd($ad->pushLink, $ads->pushCsrf))
                throw new Exception('Cant parse ads');
            $this->log('Ads recieved: ' . $ads->getCount());
            $this->log('Available to push: ' . $ads->getUnpushedCount());
            sleep(5);
        }
    }

    public function getToken()
    {
        $resp = $this->wc->loadUrl('https://lalafo.kg/user/login');
        preg_match('/name="_csrf" value="(.+?)"/s', $resp, $matches);
        if (count($matches) < 2)
            return null;
        return $matches[1];
    }

    public function login($login, $pass, $token)
    {
        $post = "LoginForm[email]=$login&LoginForm[password]=$pass&_csrf=$token";
        $resp = $this->wc->loadUrl('https://lalafo.kg/user/login', $post);
        return $this->isLogged($resp);
    }

    public function isLogged($html)
    {
        return (bool)strstr($html, '/account"');
    }

    public function pushAd($link, $csrf)
    {
        $resp = $this->wc->loadUrl('https://lalafo.kg' . $link, "_csrf=$csrf");
        return $this->getAds();
    }

    public function getAds()
    {
        $resp = $this->wc->loadUrl('https://lalafo.kg/account/index?per-page=100');
        return $this->parseAds($resp);
    }

    public function parseAds($html)
    {
        try
        {
            $dom = $this->dom($html);
            $elems = $dom->query('//tr[@class=\'main-row\']');
            $ads = new LalafoAds;

            foreach ($elems as $elem)
            {
                $itemId    = $elem->getAttribute('data-key');
                $pushLink  = '/ad/free-push/' . $itemId;

                $titleElem = $dom->query('.//a[@class=\'item-title\']', $elem);
                if (!$titleElem->item(0))
                    return null;

                $pushFormElem = $dom->query(".//form[@action='$pushLink']", $elem)->item(0);
                if ($pushFormElem && !$ads->pushCsrf)
                {
                    $csrfElem = $dom->query('.//input[@name="_csrf"]', $pushFormElem)->item(0);
                    $ads->pushCsrf = $csrfElem->getAttribute('value');
                }

                $title    = trim($titleElem->item(0)->nodeValue);
                $pushLink = $pushFormElem ? $pushLink : null;

                $ads->add($title, $pushLink);
            }

            return $ads;
        }
        catch (Exception $ex)
        {
            return null;
        }
    }

    public function dom($html)
    {
        $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'utf-8');
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        return new DOMXPath($dom);
    }

    public function log($s)
    {
        echo $s . "\n";
    }
}

class LalafoAds
{
    public $ads;
    public $pushCsrf;

    public function __construct()
    {
        $this->ads = array();
    }

    public function add($title, $pushLink)
    {
        $ad = new stdClass;
        $ad->title = $title;
        $ad->pushLink = $pushLink;
        array_push($this->ads, $ad);
    }

    public function getCount()
    {
        return count($this->ads);
    }

    public function getUnpushedCount()
    {
        $count = 0;
        foreach ($this->ads as $ad)
            if ($ad->pushLink)
                $count++;
        return $count;
    }

    public function getFirstUnpushed()
    {
        foreach ($this->ads as $ad)
            if ($ad->pushLink)
                return $ad;
        return null;
    }
}

class WebClient
{
    public $curl;

    public function __construct()
    {
        $this->curl = curl_init();
        //$cookie = realpath(dirname(__FILE__)) . '/cookie.txt';
        $cookie = sys_get_temp_dir() . '/cookie.txt'; // сохраним cookie.txt в папку /tmp/ ибо нехуй писать на диск.
        @unlink($cookie);
        curl_setopt($this->curl, CURLOPT_COOKIESESSION, true);
        curl_setopt($this->curl, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($this->curl, CURLOPT_COOKIEFILE, $cookie);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/56.0.2924.87 Safari/537.36');
        curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($this->curl, CURLOPT_TIMEOUT, 30);
    }

    public function loadUrl($url, $post = null)
    {
        curl_setopt($this->curl, CURLOPT_URL, $url);

        if ($post)
        {
            curl_setopt($this->curl, CURLOPT_POST, true);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $post);
        }

        $resp = curl_exec($this->curl);
        if ($resp === false)
            throw new WebClientConnectException(curl_error($this->curl));

        $info = curl_getinfo($this->curl);
        if ($info['http_code'] != 200)
        {
            $info['body'] = $resp;
            throw new WebClientProtocolException('Server returned status code ' . $info['http_code'], $info);
        }

        return $resp;
    }
}

class WebClientProtocolException extends Exception
{
    public $info;

    public function __construct($message, $info)
    {
        parent::__construct($message);
        $this->info = $info;
    }
}

class WebClientConnectException extends Exception {}
?>