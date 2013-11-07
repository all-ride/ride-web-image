<?php

namespace pallo\application\cache\control;

use pallo\web\image\ImageUrlGenerator;

/**
 * Cache control implementation for the image cache
 */
class ImageCacheControl extends AbstractCacheControl {

    /**
     * Name of this cache control
     * @var string
     */
    const NAME = 'image';

    /**
     * Instance of the image URL generator
     * @var pallo\web\image\ImageUrlGenerator
     */
    private $imageUrlGenerator;

    /**
     * Constructs a new image cache control
     * @param pallo\web\image\ImageUrlGenerator $imageUrlGenerator
     * @return null
     */
    public function __construct(ImageUrlGenerator $imageUrlGenerator) {
        $this->imageUrlGenerator = $imageUrlGenerator;
    }

    /**
     * Gets whether this cache is enabled
     * @return boolean
     */
    public function isEnabled() {
        return true;
    }

    /**
	 * Clears this cache
	 * @return null
     */
    public function clear() {
        $directory = $this->imageUrlGenerator->getCacheDirectory();
        if ($directory->exists()) {
            $directory->delete();
        }
    }

}