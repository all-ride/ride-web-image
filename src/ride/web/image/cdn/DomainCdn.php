<?php

namespace ride\web\image\cdn;

/**
 * CDN to map domains to your system domain
 */
class DomainCdn implements Cdn {

    /**
     * Base URL's of the CDN
     * @var array
     */
    private $baseUrls;

    /**
     * Mapped path of the system URL
     * @var string
     */
    private $path;

    /**
     * Constructs a new CDN URL generator
     * @param string|array $baseUrl Base URL of the CDN
     * @param string $path Mapped path of the system URL to the CDN URL
     * @return null
     */
    public function __construct($baseUrl, $path = null) {
        if (!is_array($baseUrl)) {
            $baseUrl = array($baseUrl);
        }

        $this->baseUrls = $baseUrl;
        $this->path = $path;

        reset($this->baseUrls);
    }

    /**
     * Gets the full URL for the provided path
     * @param string $path Path relative to the base URL
     * @return string Full URL for the provided path
     */
    public function getUrl($path) {
        if ($this->path && strpos($path, $this->path) === 0) {
            $path = substr($path, strlen($this->path));
        }

        $baseUrl = current($this->baseUrls);
        if (next($this->baseUrls) === false) {
            reset($this->baseUrls);
        }

        return $baseUrl . $path;
    }

}