<?php

namespace ride\web\image\cdn;

/**
 * Interface for CDN URL generation
 */
interface Cdn {

    /**
     * Gets the full URL for the provided path
     * @param string $path Path relative to the base URL
     * @return string Full URL for the provided path
     */
    public function getUrl($path);

}