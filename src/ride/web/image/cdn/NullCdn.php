<?php

namespace ride\web\image\cdn;

/**
 * No CDN URL generation, use the system's base URL
 */
class NullCdn implements Cdn {

    /**
     * Base URL of the system
     * @var string
     */
    private $baseUrl;

    /**
     * Constructs a new CDN URL generator
     * @param string $baseUrl Base URL of the system
     * @return null
     */
    public function __construct($baseUrl) {
        $this->baseUrl = $baseUrl;
    }

    /**
     * Gets the full URL for the provided path
     * @param string $path Path relative to the base URL
     * @return string Full URL for the provided path
     */
    public function getUrl($path) {
        return $this->baseUrl . $path;
    }

}